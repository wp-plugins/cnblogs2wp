<?php
/**
 * Plugin Name: 博客搬家到wordpress
 * Plugin URI: http://levi.cg.am
 * Description: 支持从以下站点搬家到wordpress：博客园、OSChina、CSDN、点点、LOFTER
 * Author: Levi
 * Version: 0.5.1
 * Author URI: http://levi.cg.am
 * Text Domain: cnblogs-importer
 * License: GPL version 2 or later - http://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 */

function cnblog2wp_plugin_action_links($links, $file)
{
	static $this_plugin;
	
	if (!$this_plugin) $this_plugin = plugin_basename(__FILE__);
	if ($file == $this_plugin)
	{
		array_unshift($links, '<a href="' . esc_url(admin_url('admin.php?import=cn_blog')) . '">开始导入</a>');
	}

	return $links;
}
add_filter('plugin_action_links', 'cnblog2wp_plugin_action_links', 2, 2 );
if (!defined( 'WP_LOAD_IMPORTERS') && strpos($_SERVER['REQUEST_URI'], 'wp-admin/admin-ajax.php') === false)
	return;

/** Display verbose errors */
define('IMPORT_DEBUG', true);
define('CNBLOGS_IMPORT', true);

class Lv_ui
{
	public function getPath($target = '')
	{
		return plugin_dir_path(__FILE__).$target;
	}
	
	public function getURL($target = '')
	{
		plugins_url('images/icon.png', __FILE__);
	}
	
	public function template($file)
	{
		include $this->getPath(sprintf('template/%s.htm', $file));
	}
}

class StepImport 
{
	private static $count = 0;
	
	private $_writable = false;
	private $_file = '';
	private $_fp;
	
	public function init($filename) 
	{
		$dir = get_temp_dir();
		$file = $dir.$filename.'.txt';
		if (false != ($this->_writable = is_writable($dir)) && false != ($fp = fopen($file, 'w+'))) 
		{
			$this->_file = $file;
			$this->_fp = &$fp;
			
			fwrite($this->_fp, '初始化成功，等待数据导入...');
		}
	}
	
	public function getFile() 
	{
		return $this->_file;
	}
	
	public function write($str) 
	{
		$this->_fp && !fseek($this->_fp, -1, SEEK_END) && fwrite($this->_fp, PHP_EOL.$str.PHP_EOL);
	}
	
	public function closed() 
	{
		fclose($this->_fp);
	}
	
	public function roll($press, $id, $post) 
	{
		if (!is_wp_error($id)) 
		{
			$this->write(sprintf('文章“%s”已成功导入到当前博客中。', $post['post_title']));
			self::$count++ && !(self::$count % 18) && sleep(3);
		}
	}
	
	public function rollAttach($id, $url) 
	{
		if (is_wp_error($id)) 
		{
			$str = '远程图片下载失败，附件地址：'.$url;
			if (defined('IMPORT_DEBUG') && IMPORT_DEBUG)
			{
				$str .= '<br />失败原因：'.$id->get_error_message();
			}
		}
		else 
		{
			$str = '远程图片下载成功，附件地址：'.$url;
		}
		
		$this->write($str);
	}
	
	public function rollTerms($id, $data) 
	{
		if (is_wp_error($id))
		{
			$str = sprintf('不能导入分类：%s', esc_html($data));
			if (defined('IMPORT_DEBUG') && IMPORT_DEBUG)
			{
				$str .= '<br />失败原因：'.$id->get_error_message();
			}
		}
		else 
		{
			$str = sprintf('分类导入成功，分类名称：%s', esc_html($data));
		}
		
		$this->write($str);
	}
	
	public function setPostTerms() 
	{
		$str = sprintf('文章归属分类完成');
	}
	
	public function testing($msg) 
	{
		$this->write($msg);
	}
}

class RemoteAttach
{
	private $_url_remap;
	
	public function __construct()
	{
		add_filter('http_request_timeout', 'bump_request_timeout');
		add_filter('http_headers_useragent', 'bump_request_ua');
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
		if (!isset($this->_url_remap[$url]))
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

class ParseImport 
{
	private $_id;
	private $_base_url;
	private $_importData;
	private $_selet_author;
	private $_author_mapping;
	
	private $_obj;
	
	public static $post;
	public $step;
	public $remot;
	
	public static function status() 
	{
		if (false != ($option = get_option('cnblog2wp-levi'))) 
		{
			return $option['status'];
		}
		
		return 0;
	}
	
	public function __construct($obj, $step)
	{
		$this->_obj = $obj;
		$this->step = $step;
		$this->remot = new RemoteAttach();
	}
	
	public function stop() 
	{
		echo '已强制终止系统导入数据，请稍等片刻';
		set_transient('blogs_import_stop', 1, HOUR_IN_SECONDS);
		exit;
	}

	public function fetchAtta($match)
	{
		$url = $match[1];
		$url[0] == '/' && ($url = rtrim($this->_base_url, '/') . $url);
		$this->remot->fetch(self::$post, $url);
	}
	
	public function import()
	{
		try 
		{
			$nonce = isset($_POST['_wpnonce']) ? trim($_POST['_wpnonce']) : '';
			$filename = isset($_POST['filename']) ? trim($_POST['filename']) : '';
			if (wp_verify_nonce($nonce, 'parse_import_cnblogs2wp') && $filename && $this->_importStart()) 
			{
				$this->step->init($filename);
				if (function_exists('fastcgi_finish_request')) 
				{
					fastcgi_finish_request();
				} 
				else 
				{
					ignore_user_abort();
					set_time_limit(0);
				}
				
				update_option('cnblog2wp-levi', array(
					'status' => 1,
					'msg' => '',
					'author' => $this->_selet_author,
					'path' => $this->step->getFile(),
					'time' => time()
				));
				
				$this->_importing();
				$this->_importEnd();
			}
			else 
			{
				throw new Exception('提交数据不正确');
			}
		}
		catch (Exception $e) 
		{
			update_option('cnblog2wp-levi', array(
				'status' => -1,
				'msg' => $e->getMessage(),
				'author' => $this->_selet_author,
				'path' => '',
				'time' => time()
			));
		}
	}
	
	private function _importStart() 
	{
		$this->_id = isset($_POST['attach_id']) ? $_POST['attach_id'] : 0;
		if (!$this->_id || !($file = get_attached_file($this->_id))) 
		{
			throw new Exception('<p>系统无法找到上传的数据文件</p>');
		}
		
		$import_data = $this->_parse($file);
		if (is_wp_error($import_data))
		{
			$str = sprintf('<p><strong>上传文件出现错误，请重新上传！错误原因：</strong><br />%s</p>', esc_html($import_data->get_error_message()));
			
			wp_import_cleanup($this->_id);
			throw new Exception($str);
		}
		
		$import_data['author'] && $import_data['author'] = sanitize_user($import_data['author']);
		$import_data['base_url'] && $this->_base_url = esc_url($import_data['base_url']);
		
		$this->_importData = $import_data;
		$this->_getAuthorMapping();
		
		return true;
	}
	
	private function _importing() 
	{
		add_filter('import_post_meta_key', array($this->_obj, 'is_valid_meta_key'));
		
		wp_defer_term_counting(true);
		wp_defer_comment_counting(true);
		do_action('import_start');
		
		// 暂停缓存
		wp_suspend_cache_invalidation(true);
// 		$this->_process();
// 		$this->_process(2);
		$this->_processPosts();
		wp_suspend_cache_invalidation(false);
		
		// update incorrect/missing information in the DB
		$this->_backfileAttachmentUrls();
	}

	private function _importEnd()
	{
		// 数据需要在这里获取，否则被清空了
		$stop = get_transient('blogs_import_stop');
		wp_import_cleanup($this->_id);
		wp_cache_flush();
		
		foreach (get_taxonomies() as $tax )
		{
			delete_option("{$tax}_children");
			_get_term_hierarchy($tax);
		}
	
		wp_defer_term_counting(false);
		wp_defer_comment_counting(false);
		
		update_option('cnblog2wp-levi', array(
			'status' => $stop ? 3 : 2,
			'msg' => $stop ? '已强制终止系统导入数据' : '数据已经全部导入完毕',
			'time' => time()
		));

		$stop && delete_transient('blogs_import_stop');
		
		$this->step->closed();
		do_action('import_end');
	}
	
	/**
	 * 解析XML文件
	 *
	 * @param string $file Path to XML file for parsing
	 * @return array Information gathered from the XML file
	 */
	private function _parse($file)
	{
		try
		{
			$str = file_get_contents($file);
			$data = apply_filters('parse_import_data_'.Cnblog2wp::$type, $str, $this->_getCategoryMap());
			if ($str == $data) 
			{
				throw new Exception('导入的数据无效');
			}
			
			return $data;
		}
		catch (Exception $e)
		{
			return new WP_Error( 'WXR_parse_error', $e->getMessage());
		}
	}
	
	private function _getAuthorMapping() 
	{
		$user_id = 0;
		$select = isset($_POST['selet_author']) ? (int)$_POST['selet_author'] : 0;
		
		if ($select == 1) 
		{
			$user_new = isset($_POST['user_new']) ? trim($_POST['user_new']) : '';
			if ($user_new && false != ($user = username_exists($user_new))) 
			{
				$user_id = $user;
			}
			elseif (false != ($create_users = $this->_obj->allow_create_users())) 
			{
				$user_id = wp_create_user($user_new, wp_generate_password());
				$this->_selet_author = 1;
			}
		} 
		else 
		{
			$user_map = isset($_POST['user_map']) ? (int)$_POST['user_map'] : 0;
			if ($user_map && false != ($user = get_userdata($user_map))) 
			{
				$user_id = $user->ID;
			}
		}
		
		if ($user_id && !is_wp_error($user_id)) 
		{
			$this->_author_mapping = $user_id;
		}
		else 
		{
			$this->_author_mapping = get_current_user_id();
			$this->_selet_author = 0;
		}
	}

	private function _process($type = 1)
	{
		$name = $type == 1 ? 'category' : 'post_tag';
		$group = apply_filters('blogs_levi_import_'.$name, $this->_importData[$name]);
		if (empty($group))
		{
			return ;
		}
	
		$group = array_flip(array_flip($group));
		foreach ($group as $data)
		{
			if (term_exists($data, $name))
			{
				continue;
			}
	
			do_action('blogs_levi_import_insert_terms', wp_insert_term($data, $name), $data);
		}
	}
	
	private function _processPosts()
	{
		$fetch = $this->_obj->allow_fetch_attachments() && isset($_POST['fetch_attachments']) ? (int)$_POST['fetch_attachments'] : 0;
		$posts = apply_filters('blogs_levi_import_posts', $this->_importData['posts']);
		if (empty($posts))
		{
			return;
		}

		foreach ($posts as $post)
		{
			if (get_transient('blogs_import_stop')) 
			{
				$this->step->write('写入停止数据数据：'.get_transient('blogs_import_stop'));
				break;
			}
			
			if (empty($post['title'])) 
			{
				continue;
			}
			
			try 
			{
				$post_exists = post_exists($post['title']);
				$post = apply_filters('blogs_levi_import_post_data_raw_'.Cnblog2wp::$type, $post, $this->_importData['category_map'], $post_exists, $this->step);
			}
			catch (Exception $e) 
			{
				$this->step->write('获取文章数据失败，跳过导入；失败原因: ' . $e->getMessage());
				continue;
			}
			
			if ($post_exists && get_post_type($post_exists) == 'post')
			{
				$post_id = $post_exists;
				$this->step->write(sprintf('文章跳过导入，“%s”已存在。', $post['title']));
			}
			else
			{
				$open = isset($post['status']) ? $post['status'] : true;
				$postdata = array(
					'import_id' => '',
					'post_author' => $this->_author_mapping,
					'post_date' => $post['pubDate'],
					'post_date_gmt' => $post['pubDate'],
					'post_content' => $post['content'],
					'post_excerpt' => '',
					'post_title' => $post['title'],
					'post_status' => $open ? 'publish' : 'private',
					'post_name' => urlencode($post['title']),
					'comment_status' => $open ? 'open' : 'closed',
					'ping_status' => 'open',
					'guid' => '',
					'post_parent' => 0,
					'menu_order' => 0,
					'post_type' => 'post',
					'post_password' => ''
				);
	
				$postdata = apply_filters('blogs_levi_import_post_data_processed', $postdata, $post);
				$post_id = wp_insert_post($postdata, true);

				do_action('blogs_levi_import_insert_post_'.Cnblog2wp::$type, $this, $post_id, $postdata, $post, $fetch);
				if (is_wp_error($post_id))
				{
					$post_type_object = get_post_type_object('post');
					$this->step->write(sprintf(
						'&#8220;%s&#8221;导入&#8220;%s&#8221;失败！',
						esc_html($post['title']),
						$post_type_object->labels->singular_name
					));
						
					if (defined('IMPORT_DEBUG') && IMPORT_DEBUG)
					{
						$this->step->write('失败原因: ' . $post_id->get_error_message());
					}
					
					continue;
				}

				if ($fetch && preg_match('/png|gif|jpe?g/is', $postdata['post_content']))
				{
					$postdata['post_parent'] = $post_id;
					self::$post = $postdata;
					
					$pattern = '/src=["|\']?((https?:\/\/[^\/]+\.[^\/\.]{2,6})?\/[^\'">]*\w+\.(png|gif|jpe?g))["|\']?[^>]*>/is';
					$data = preg_replace_callback($pattern, array($this, 'fetchAtta'), $postdata['post_content']);
				}
			}
				
			if (!empty($post['terms']))
			{
				$terms_to_set = array();
				foreach ($post['terms'] as $term)
				{
					$taxonomy = $term['domain'];
					$term_exists = term_exists($term['name'], $taxonomy);
					$term_id = is_array($term_exists) ? $term_exists['term_id'] : $term_exists;
					if (!$term_id)
					{
						$t = wp_insert_term($term['name'], $taxonomy, array('slug' => $term['slug']));
						if (!is_wp_error($t))
						{
							$term_id = $t['term_id'];
							do_action('blogs_levi_import_insert_term', $t, $term, $post_id, $post);
						}
						else
						{
							$this->step->write(sprintf(
								'<p>导入%s失败，%1$s关键字：%s</p>',
								$taxonomy == 'category' ? '分类' : '标签',
								esc_html($term['name'])
							));
								
							if (defined('IMPORT_DEBUG') && IMPORT_DEBUG)
							{
								$this->step->write(sprintf('失败原因：%s', $t->get_error_message()));
							}
								
							do_action('blogs_levi_import_insert_term_failed', $t, $term, $post_id, $post);
							continue;
						}
					}
						
					$terms_to_set[$taxonomy][] = intval($term_id);
				}
	
				foreach ($terms_to_set as $tax => $ids)
				{
					$tt_ids = wp_set_post_terms($post_id, $ids, $tax);
					do_action('blogs_levi_import_set_post_terms', $tt_ids, $ids, $tax, $post_id, $post);
				}
			}
		}
	}
	
	private function _backfileAttachmentUrls()
	{
		if (false == ($url_remap = $this->remot->get()))
		{
			return false;
		}
	
		// 根据URL长度排列，长的排在前面，避免长的URL中包含短URL被先行替换
		uksort($url_remap, array($this->_obj, 'sort_url'));
	
		global $wpdb;
		foreach ($url_remap as $from_url => $to_url)
		{
			$wpdb->query($wpdb->prepare("UPDATE `{$wpdb->posts}` SET `post_content` = REPLACE(`post_content`, %s, %s);", $from_url, $to_url));
			$wpdb->query($wpdb->prepare("UPDATE `{$wpdb->postmeta}` SET `meta_value` = REPLACE(`meta_value`, %s, %s) WHERE `meta_key`='enclosure';", $from_url, $to_url));
		}
	}

	private function _getCategoryMap()
	{
		$map = array(
			'type' => isset($_POST['selet_category']) ? (int)$_POST['selet_category'] : 0,
			'slug' => ''
		);
	
		$map['type'] = max(min(3, $map['type']), 1);
		$terms = get_terms('category', array('hide_empty' => 0));
		switch ($map['type'])
		{
			case 1:
				$map['data'] = isset($_POST['category_new']) ? trim($_POST['category_new']) : '';
				if (empty($map['data']))
				{
					$map['type'] = 3;
					break;
				}
				elseif (false != ($term = term_exists($map['data'], 'category')))
				{
					$map['type'] = 2;
					$map['data'] = is_array($term) ? $term['term_id'] : (int)$term;
					foreach ($terms as $t)
					{
						($t->term_id == $map['data']) && $map['data'] = $t->name;
					}
				}
	
				$map['slug'] = urlencode($map['data']);
				break;
			case 2:
				$map['data'] = isset($_POST['category_map']) ? (int)$_POST['category_map'] : 0;
				if (!$map['data'] || !term_exists($map['data'], 'category'))
				{
					$map['type'] = 3;
				}
				else
				{
					foreach ($terms as $t)
					{
						$t->term_id == $map['data'] && $map['data'] = $t->name;
					}
						
					$map['slug'] = urlencode($map['data']);
				}
	
				break;
			case 3: $map['data'] = '';
		}
	
		return $map;
	}
}

class Cnblog2wp extends Lv_ui 
{
	public static $type = '';
	public $val = array();
	
	public function __construct()
	{
		self::$type = isset($_GET['type']) ? strtolower(trim($_GET['type'])) : 'cnblogs';
		add_filter('add_import_method', array($this, 'append'));
	}
	
	public function append($value) 
	{
		$slug = isset($value['slug']) ? $value['slug'] : '';
		
		if (!$slug) return false;
		$this->val[$slug] = array(
			'slug' => $slug,
			'title' => isset($value['title']) && $value['title'] ? $value['title'] : $slug,
			'category' => isset($value['category']) ? $value['category'] : true,
			'description' => isset($value['description']) ? $value['description'] : '',
			'sort' => isset($value['sort']) ? (int)$value['sort'] : 10
		);
	}
	
	public function dispatch() 
	{
		uasort($this->val, array($this, 'sort_num'));
		if (!array_key_exists(self::$type, $this->val)) 
		{
			$type = array_keys($this->val);
			self::$type = $type[0];
		}

		$this->template('header');
		if (ParseImport::status() == 1) 
		{
			$this->_showStatus();
			return ;
		}

		$step = isset($_GET['step']) ? intval($_GET['step']) : 0;
		if ($step) 
		{
			check_admin_referer('import-upload');
			if (false != ($id = $this->_handleUpload()))
			{
				$this->_showStatus($id);
			}
			else
			{
				echo '<p>没有找到提交的数据文件</p></div>';
			}
			
			return ;
		}

		delete_transient('blogs_import_stop');
		do_action('import_display_start_'.self::$type);
		
		wp_enqueue_script('cnblog2wp');
		$this->template('mod');
	}
	
	/**
	 * 导入完成后的页面顶部的消息提醒
	 */
	public function importOverMessage() 
	{
		$option = get_option('cnblog2wp-levi');
		$status = $option['status'];
		
		if (in_array($status, array(-1, 2, 3)))
		{
			printf('<div id="message" class="%s">', $status == -1 ? 'error' : 'updated');
			switch ($status) 
			{
				case -1: $msg = '导入数据失败，失败原因：'.$option['msg']; break;
				case 2: $msg = '数据已导入完毕'; break;
				case 3: $msg = '已终止数据导入，您还可以继续导入未完成的数据'; break;
			}

			delete_option('cnblog2wp-levi');
			printf('<p><strong>%s</strong></p></div>', $msg);
		}
	}
	
	/**
	 * ajax 获取导入状态
	 */
	public function getImportProgress() 
	{
		if (false != ($option = get_option('cnblog2wp-levi')) && false != ($status = $option['status'])) 
		{
			$option['type'] = self::$type;
			$path = isset($option['path']) ? str_replace('\\', '', $option['path']) : '';
			if ($status == 1) 
			{
				if (empty($path) || !is_file($path)) 
				{
					$option['msg'] = '当前系统不能存储日志记录，请耐心等待，若长时间未反应，您可以手动终止数据导入。';
				}
				elseif (filemtime($path) + MINUTE_IN_SECONDS * 10 < time() || !($data = file_get_contents($path))) 
				{
					$option['msg'] = '数据导入好像出现异常了，若长时间未反应，您可以手动终止数据导入。';
				}
				else 
				{
					$option['msg'] = $data;
				}
			}
			
			$option['path'] = $path;
			
			echo json_encode($option);
			exit;
		}
		
		echo json_encode(array('status' => 0, 'type' => self::$type, 'msg' => '请继续等待...'));
		exit;
	}
	
	public function sort_num($a, $b) 
	{
		if ($a['sort'] == $b['sort']) return 0;
		return $a['sort'] > $b['sort'] ? 1 : -1;
	}
	
	public function sort_url($a, $b) 
	{
		return strlen($b) - strlen($a);
	}
	
	public function allow_create_users()
	{
		return apply_filters('import_allow_create_users', true );
	}
	
	public function allow_fetch_attachments()
	{
		return apply_filters('import_allow_fetch_attachments', true);
	}
	
	public function is_valid_meta_key($key)
	{
		// skip attachment metadata since we'll regenerate it from scratch
		// skip _edit_lock as not relevant for import
		if (in_array($key, array('_wp_attached_file', '_wp_attachment_metadata', '_edit_lock')))
			return false;
		return $key;
	}
	
	/**
	 * Added to http_request_timeout filter to force timeout at 60 seconds during import
	 * @return int 60
	 */
	public function bump_request_timeout()
	{
		return 60;
	}
	
	/**
	 * 处理WXR上传和为文件初步分析作准备
	 * 显示作者导入选项
	 *
	 * @return bool False if error uploading or invalid file, true otherwise
	 */
	private function _handleUpload()
	{
		if (false != ($id = apply_filters('get_import_file_'.self::$type, 0))) 
		{
			return $id;
		}
		
		$file = wp_import_handle_upload();
		$str = '上传文件出现错误，请重新上传！错误原因：';
		if (isset($file['error']))
		{
			// 上传错误
			printf('<p><strong>%s</strong><br />%s</p></div>', $str, esc_html( $file['error'] ));
			return false;
		}
		else if (!file_exists( $file['file'] ) )
		{
			// 没有找到上传的文件
			$err = sprintf('没有找到导入的xml文件：%s', esc_html( $file['file'] ) );
				
			// esc_html：将 < > & " '（小于号，大于号，&，双引号，单引号）编码，转成HTML 实体，已经是实体的并不转换
			printf('<p><strong>%s</strong><br />%s</p></div>', $str, $err);
			return false;
		}
		
		return (int)$file['id'];
	}
	
	private function _showStatus($id = 0) 
	{
		if (get_transient('blogs_import_stop')) 
		{
			echo '<p>已强行终止数据导入，请耐心等待...</p>';
			return ;
		}
		
		$data = $id ? array(
			// attach
			'attach_id' => $id,
			'fetch_attachments' => isset($_POST['fetch_attachments']) ? (int)$_POST['fetch_attachments'] : 0,
				
			// author
			'selet_author' => isset($_POST['selet_author']) ? (int)$_POST['selet_author'] : 0,
			'user_new' => isset($_POST['user_new']) ? trim($_POST['user_new']) : '',
			'user_map' => isset($_POST['user_map']) ? (int)$_POST['user_map'] : 0,
			
			// category
			'selet_category' => isset($_POST['selet_category']) ? (int)$_POST['selet_category'] : 0,
			'category_new' => isset($_POST['category_new']) ? trim($_POST['category_new']) : '',
			'category_map' => isset($_POST['category_map']) ? (int)$_POST['category_map'] : 0,
				
			// other
			'_wpnonce' => wp_create_nonce('parse_import_cnblogs2wp'),
			'stop_nonce' => wp_create_nonce('parse_import_stop'),
			'filename' => time()
		) : array(
			'attach_id' => 0
		);
		
		$data['type'] = self::$type;
		
		wp_localize_script('press_data_init', 'press_data', $data);
		wp_enqueue_script('press_data_init');
		
		$this->template('import');
	}
}

function cnblog2wp_lv_importer_init() 
{
	global $cnblogs, $step;
	
	register_importer('cn_blog', '.博客搬家.', '支持：博客园、OSChina、CSDN、点点、LOFTER', array($cnblogs, 'dispatch'));	
	wp_register_script('cnblog2wp', plugins_url('cnblog2wp.js', __FILE__), array('jquery'));
	wp_register_script('press_data_init', plugins_url('press_data_init.js', __FILE__), array('jquery'));

	add_action('blogs_levi_import_insert_post_'.Cnblog2wp::$type, array($step, 'roll'), 10, 3);
	add_action('blogs_levi_import_insert_attach', array($step, 'rollAttach'), 10, 2);
	add_action('blogs_levi_import_insert_terms', array($step, 'rollTerms'), 10, 2);
}


$cnblogs = new Cnblog2wp();
$step = new StepImport();

$import = new ParseImport($cnblogs, $step);

add_action('wp_ajax_stop_import', array($import, 'stop'));
add_action('wp_ajax_press_import', array($import, 'import'));
add_action('wp_ajax_get_import_progress', array($cnblogs, 'getImportProgress'));

add_action('admin_notices',  array($cnblogs, 'importOverMessage'));
add_action('admin_init', 'cnblog2wp_lv_importer_init', 15);

include dirname( __FILE__ ) . '/XML_Parse.php';
add_action('admin_init', 'add_importer_method');

add_action('import_test_msg', array($step, 'testing'));
