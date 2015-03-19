<?php
// 下载远程文件
class RemoteAttach
{
	private $_url_remap;
	private $_base_up;
	
	public function __construct()
	{
		add_filter('http_request_timeout', 'bump_request_timeout');
		add_filter('http_headers_useragent', 'bump_request_ua');
		
		$dir = wp_upload_dir();
		$up = explode('uploads', $dir['baseurl']);
		
		$this->_base_up = $up.'uploads';
	}
	
	public function set($url, $attach) 
	{
		$this->_url_remap[$url] = $attach;
	}
	
	public function get() 
	{
		return $this->_url_remap;
	}
	
	public function fetch($post, $url, $ext = '', $limit = true) 
	{
		if (!isset($this->_url_remap[$url]) && !strstr($url, $this->_base_up))
		{
			$upload = $this->fetchRemoteFile($url, $ext, $limit);
			$aid = $this->_processAttachment($post, $upload);
			
			do_action('blogs_levi_import_insert_attach', $aid, $url);
			return $aid;
		}
		
		return 0;
	}
	
	protected function fetchRemoteFile($url, $ext = '', $limit = true) 
	{
		$file_name = empty($ext) ? basename($url) : sprintf('remote_%s%s', time(), $ext);
		$upload = wp_upload_bits($file_name, 0, '');
		if ($upload['error'])
		{
			return new WP_Error('upload_dir_error', $upload['error']);
		}
		
		if (false == ($headers = wp_get_http($url, $upload['file'])))
		{
			@unlink($upload['file']);
			return new WP_Error('import_file_error', '远程服务器未响应');
		}
		
		if ($headers['response'] != '200')
		{
			@unlink($upload['file']);
			return new WP_Error(
				'import_file_error',
				sprintf('远程服务器返回错误响应 %1$d %2$s', esc_html($headers['response']), get_status_header_desc($headers['response']))
			);
		}
		
		$filesize = filesize($upload['file']);
		if (isset($headers['content-length']) && $filesize != $headers['content-length'])
		{
			@unlink($upload['file']);
			return new WP_Error('import_file_error', '文件大小不正确');
		}
		
		$max_size = $limit ? (int)apply_filters('import_attachment_size_limit', 0) : 0;
		if ($max_size && $filesize > $max_size)
		{
			@unlink($upload['file']);
			return new WP_Error('import_file_error', sprintf('附件大小限制为：%s', size_format($max_size)));
		}
		
		$upload['origin'] = $url;
		return $upload;
	}
	
	private function _processAttachment($post, $upload)
	{
		if (is_wp_error($upload))
		{
			return $upload;
		}
	
		if (false != ($info = wp_check_filetype($upload['file'])))
		{
			$post['post_title'] .= '-attach';
			$post['post_mime_type'] = $info['type'];
			$post += array(
				'post_status' => 'inherit',
				'guid' => $upload['url']
			);
		}
		else
		{
			return new WP_Error('attachment_processing_error', '无效的文件类型');
		}
	
		$post_id = wp_insert_attachment($post, $upload['file']);
		$meta = wp_generate_attachment_metadata($post_id, $upload['file']);
		wp_update_attachment_metadata($post_id,  $meta);
		
		$url = $upload['origin'];
		$this->_url_remap[$url] = $upload['url'];
		
		return $post_id;
	}
}

class LoadRemoteUrl extends RemoteAttach
{
	private $_data;

	public function __construct($xpath = null)
	{
		parent::__construct();

		add_filter('upload_mimes', array($this, 'addType'));
		$xpath && $this->_data['xpath'] = $xpath;
	}

	public function addType($t)
	{
		$t['txt'] = 'text/plain';
		return $t;
	}

	public function query($node, $item = 0)
	{
		if (false == ($xpath = $this->_data['xpath']))
		{
			throw new Exception('Xpath 数据为空');
		}

		$node = is_array($node) ? call_user_func_array(array($xpath, 'query'), $node) : $xpath->query($node);
		if ($item > -1)
		{
			return $node->item($item) ? $node->item($item) : null;
		}
		else
		{
			return $node->length ? $node : null;
		}
	}

	public function get($url, $ext = '.txt', $temp = DAY_IN_SECONDS)
	{
		$upload = $this->fetchRemoteFile($url, $ext, false);
		if (is_wp_error($upload))
		{
			throw new Exception('文件下载失败：'.$upload->get_error_message());
		}

		// Construct the object array
		$object = array(
			'post_title' => basename($upload['file'], $ext),
			'post_content' => $upload['url'],
			'post_mime_type' => 'text/plain',
			'guid' => $upload['url'],
			'context' => 'import',
			'post_status' => 'private'
		);

		// Save the data
		$id = wp_insert_attachment($object, $upload['file']);

		$upload['xpath'] = get_dom(file_get_contents($upload['file']));
		$this->_data = $upload;

		$temp && clean_imp($id,  $temp);
		return $id;
	}
}