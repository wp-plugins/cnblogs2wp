<?php
/**
 * Cnblog xml file parser implementations
 */
function check_xml($str)
{
	$xml_parser = xml_parser_create();
	if (!xml_parse($xml_parser, $str, true))
	{
		xml_parser_free($xml_parser);
		throw new Exception('不是一个有效的XML文件');
	}
	
	return simplexml_load_string($str);
}

function bump_request_timeout() 
{
	return 60;
}

function bump_request_ua() 
{
	return 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_10_1) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/38.0.2125.111 Safari/537.36';
}

function get_dom($str) 
{
	$xml = new DOMDocument();
	@$xml->loadHTML($str);
	
	return new DOMXPath($xml);
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
	
	// http://blog.csdn.net/liutengteng130/article/list/10000
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
		
		/*
		 * Schedule a cleanup for one day from now in case of failed
		 * import or missing wp_import_cleanup() call.
		 */
		$temp && wp_schedule_single_event(time() + $temp, 'importer_scheduled_cleanup', array($id));
		return $id;
	}
}

// csdn_parse
class CSDN_parse
{
	const NAME = 'csdn';
	private $_base_url = 'http://blog.csdn.net/';
	private $_aid;
	private $_id;
	
	public function get($str, $map) 
	{
		$load = new LoadRemoteUrl(get_dom($str));
		$data = array(
			'base_url' => $this->_base_url,
			'author' => '',
			'category_map' => $map,
			'category' => array(),
			'post_tag' => array(),
			'posts' => array()
		);
		
		if (false != ($elements = $load->query('//h1', -1))) 
		{
			foreach ($elements as $emt) 
			{
				$title = $load->query(array('.//a', $emt))->nodeValue;
				$url = $load->query(array('.//a//@href', $emt));
				$data['posts'][] = array(
					'url' => rtrim($data['base_url'], '/').$url->nodeValue,
					'stick' => strstr($title, '[置顶]') ? 1 : 0,
					'title' => trim(str_replace(array('[置顶]', '&#13;', '&#10;'), '', $title))
				);
			}
		}
		else 
		{
			throw new Exception('没有找到可以导入的数据');
		}
			
		return $data;
	}
	
	public function postRaw($post, $map, $post_exists, $step) 
	{
		// 不重复下载
		if ($post_exists && get_post_type($post_exists) == 'post') 
		{
			return $post;
		}
		
		$load = new LoadRemoteUrl();
		
		$this->_aid = $load->get($post['url']);
		$step->write('开始爬取博客文章：'.esc_html($post['title']));
		
		if (empty($map['slug'])) 
		{
			if (false != ($link = $load->query("//*[contains(@class,'link_categories')]")) && 
				false != ($categories = $load->query(array('.//a', $link), -1))) 
			{
				foreach ($categories as $category) 
				{
					$term = trim($category->nodeValue);
					$post['terms'][] = array(
						'name' => $term,
						'slug' => urlencode($term),
						'domain' => 'category'
					);
				}
			}
		}
		else 
		{
			$post['terms'][] = array(
				'name' => $map['data'],
				'slug' => $map['slug'],
				'domain' => 'category'
			);
		}
		
		if (false != ($link = $load->query("//*[contains(@class,'tag2box')]")) && 
			false != ($tags = $load->query(array('.//a', $link), -1)))
		{
			foreach ($tags as $tag)
			{
				$term = trim($tag->nodeValue);
				$post['terms'][] = array(
					'name' => $term,
					'slug' => urlencode($term),
					'domain' => 'post_tag'
				);
			}
		}
		
		if (false != ($date = $load->query("//*[contains(@class,'link_postdate')]"))) 
		{
			$post['pubDate'] = trim($date->nodeValue).':00';
		}
		else 
		{
			$post['pubDate'] = time();
		}
		
		if (false != ($content = $load->query("//*[@id='article_content']"))) 
		{
			$content = $content->ownerDocument->saveXML($content);
			$post['content'] = trim($content);
		}
		else 
		{
			$post['content'] = $post['title'];
		}
		
		return $post;
	}
	
	public function postFilter($press, $post_id, $postdata, $post, $fetch) 
	{
		wp_import_cleanup($this->_aid);
		if (is_wp_error($post_id))
		{
			return ;
		}
		
		if ($post['stick']) 
		{
			$press->step->write('置顶文章：'.$post['title']);
			stick_post($post_id);
		}

		if ($fetch && strstr($postdata['post_content'], 'http://img.blog.csdn.net'))
		{
			$postdata['post_parent'] = $post_id;
			
			$mimes = array('png', 'gif', 'jpg', 'jpeg', 'jpe');
			$pattern = '/src=(["|\'])(http:\/\/img\.blog\.csdn\.net.+?)\1/is';
			
			if (preg_match_all($pattern, $postdata['post_content'], $imgs)) 
			{
				foreach ($imgs[2] as $img)
				{
					$path = null;
					if (!($info = wp_get_http($img))) 
					{
						$press->step->write('远程图片下载失败：'.$img);
						continue;
					}
					
					if (!isset($info['content-type'])) 
					{
						$press->remot->fetch($postdata, $img, '.jpg');
						continue;
					}
					
					$ctype = explode('/', $info['content-type']);
					if (isset($ctype[1]) && false !== ($key = array_search($ctype[1], $mimes))) 
					{
						$ext = $key > 2 ? '.'.$ctype[1] : '.jpg';
						$press->remot->fetch($postdata, $img, $ext);
					}
					else 
					{
						$press->step->write('远程图片下载失败：图片类型不正确');
					}
				}
			}
		}
	}
	
	public function display() 
	{
		if (isset($_POST['send_url'])) 
		{
			$this->_id = $this->_upload();
			if (is_wp_error($this->_id)) 
			{
				printf('<p class="error">%s</p>', $this->_id->get_error_message());
			}
			else 
			{
				return add_action('remote_file', array($this, 'remoteFrom'));
			}
		}
		
		$key = wp_generate_password();
		set_transient('rss_parse_key', $key, DAY_IN_SECONDS);

		include 'template/checkout.htm';
		exit;
	}
	
	public function remoteFrom($num) 
	{
		$data = array(
			'id' => $this->_id,
			'type' => Cnblog2wp::$type
		);
		
		include 'template/mod_rss.htm';
		exit;
	}
	
	private function _upload() 
	{
		try 
		{
			check_admin_referer('load_xml_url');
			$url = isset($_POST['url']) ? trim($_POST['url']) : '';
			if (empty($url))
			{
				throw new Exception('请输入地址');
			}

			if (!($rss = get_transient('rss_parse_key')))
			{
				throw new Exception('预准备的效验字符不存在');
			}

			$load = new LoadRemoteUrl();
			$id = $load->get(trim($url, '/').'/article/list/10000');
			
			if (!($title = $load->query('//h2/a[1]')) || !strstr($title->nodeValue, $rss)) 
			{
				wp_import_cleanup($id);
				throw new Exception('导入数据前，请修改博客名，在名称后面添加系统指定的字符');
			}
			
			return $id;
		}
		catch (Exception $e) 
		{
			return new WP_Error('RSS_parse_error', $e->getMessage());
		}
	}
}

// osc_parse
class Osc_parse
{
	private $_xpath;
	
	public function get($str, $map) 
	{
		if (!strstr($str, 'oschina'))
		{
			throw new Exception('导入的数据文件不正确');
		}
			
		$xml = new DOMDocument();
		@$xml->loadHTML($str);
			
		$this->_xpath = new DOMXPath($xml);
		return $this->_roll($map);
	}
	
	private function _query($node, $item = 0)
	{
		if (false == ($xpath = $this->_xpath))
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
	
	private function _roll($map)
	{
		$data = array(
			'base_url' => '',
			'author' => '',
			'category_map' => $map,
			'category' => array(),
			'post_tag' => array(),
			'posts' => array()
		);
	
		if ($data['category_map']['type'] == 1)
		{
			$data['category'][] = $data['category_map']['data'];
		}
	
		if (false != ($title = $this->_query('//title')))
		{
			$node = explode('的', $title->nodeValue);
			$data['author'] = $node[0];
		}
	
		if (false != ($link = $this->_query('//h1//@href')))
		{
			$data['base_url'] = $link->nodeValue;
		}
	
		if (false != ($elements = $this->_query("//*[contains(@class,'blog')]", -1)))
		{
			foreach ($elements as $key => $emt)
			{
				if (!$key)
				{
					continue;
				}
	
				$value = array();
				if (false != ($title = $this->_query(array('.//h2/*', $emt), 1)))
				{
					$value['title'] = trim($title->nodeValue);
				}
	
				if (false != ($time = $this->_query(array(".//*[contains(@class,'date')]", $emt))))
				{
					$node = explode('：', $time->nodeValue);
					$value['pubDate'] = $node[1];
				}
	
				if (empty($data['category_map']['slug']))
				{
					if (false != ($catelog = $this->_query(array(".//*[contains(@class,'catalog')]", $emt))))
					{
						$node = explode('：', $catelog->nodeValue);
						$data['category'][] = $node[1];
						$value['terms'][] = array(
							'name' => $node[1],
							'slug' => urlencode($node[1]),
							'domain' => 'category'
						);
					}
				}
				else
				{
					$value['terms'][] = array(
						'name' => $data['category_map']['data'],
						'slug' => $data['category_map']['slug'],
						'domain' => 'category'
					);
				}
	
				if (false != ($tags = $this->_query(array(".//*[contains(@class,'tags')]", $emt))))
				{
					$node = explode('：', $tags->nodeValue);
					$node = explode(',', $node[1]);
						
					$node = array_flip(array_flip($node));
					$data['post_tag'] = array_merge($data['post_tag'], $node);
					foreach ($node as $name)
					{
						$value['terms'][] = array(
							'name' => $name,
							'slug' => urlencode($name),
							'domain' => 'post_tag'
						);
					}
				}
	
				if (false != ($content = $this->_query(array(".//*[contains(@class,'content')]", $emt))))
				{
					$value['content'] = $content->ownerDocument->saveXML($content);
				}
	
				$value && $data['posts'][] = $value;
			}
		}
	
		if (empty($data['posts']))
		{
			throw new Exception('导入的数据为空');
		}
	
		return $data;
	}
}

// cnblogs_parse
function cnblogs_parse($str, $map)
{
	$xml = check_xml($str);
	$data = array(
		'base_url' => (string)$xml->channel->link,
		'author' => (string)$xml->channel->item[0]->author,
		'category_map' => $map,
		'category' => array(),
		'post_tag' => array(),
		'posts' => array()
	);
	
	if (!strstr($data['base_url'], 'cnblogs.com'))
	{
		throw new Exception('导入的数据不正确');
	}
	
	if ($data['category_map']['type'] == 1)
	{
		$data['category'][] = $data['category_map']['data'];
	}
	
	foreach ($xml->channel->item as $item)
	{
		$value = array(
			'terms' => array(),
			'title' => trim($item->title),
			'url' => (string)$item->guid,
			'pubDate' => date('Y-m-d H:i:s', strtotime($item->pubDate)),
			'content' => str_replace(
				array('<description><![cdata[', ']]></description>', '<br>', '<hr>'),
				array('', '', '<br />', '<hr />'),
				strtolower($item->description->asXML()))
		);
			
		if (!empty($data['category_map']['slug']))
		{
			$value['terms'][] = array(
				'name' => $data['category_map']['data'],
				'slug' => $data['category_map']['slug'],
				'domain' => 'category'
			);
		}
			
		$data['posts'][] = $value;
	}
	
	return $data;
}

// lofter_parse
function lofter_parse() 
{
}

// diandian_parse
function diandian_parse($str, $map)
{
	$data = array(
		'base_url' => '',
		'author' => '',
		'category_map' => $map,
		'category' => array(),
		'post_tag' => array(),
		'posts' => array()
	);
	
	if ($data['category_map']['type'] == 1)
	{
		$data['category'][] = $data['category_map']['data'];
	}

	$xml = check_xml($str);
	if (!isset($xml->Posts) || !isset($xml->Posts->Post) || !count($xml->Posts->Post)) 
	{
		throw new Exception('导入的数据不正确');
	}
	
	foreach ($xml->Posts->Post as $item) 
	{
		$title = (string)$item->Title;
		
		$value = array(
			'terms' => array(),
			'title' => trim($item->title),
			'url' => (string)$item->guid,
			'pubDate' => date('Y-m-d H:i:s', strtotime($item->pubDate)),
			'content' => str_replace(
				array('<description><![cdata[', ']]></description>', '<br>', '<hr>'),
				array('', '', '<br />', '<hr />'),
				strtolower($item->description->asXML()))
		);
	}
}

function get_import_file_id() 
{
	return isset($_POST['import_loadfile_id']) ? (int)$_POST['import_loadfile_id'] : 0;
}

function waiting_import() 
{
	echo '<p class="waiting">即将开放，尽情期待...</p></div>';
	exit;
}

function add_importer_method()
{
	add_filter('get_import_file_csdn', 'get_import_file_id');
	add_action('import_display_start_lofter', 'waiting_import');
	add_action('import_display_start_diandian', 'waiting_import');
	
	
	add_filter('parse_import_data_lofter', 'lofter_parse', 10, 2);
	apply_filters('add_import_method', array(
		'slug' => 'lofter',
		'title' => 'Lofter',
		'category' => false,
		'description' => '将CSDN（csdn.net）博客中的文章导入到当前wordpress'
	));

	add_filter('parse_import_data_diandian', 'diandian_parse', 10, 2);
	apply_filters('add_import_method', array(
		'slug' => 'diandian',
		'title' => '点点',
		'category' => false,
		'description' => '将发表在点点（diandian.com）中的文章导入到当前wordpress'
	));

	$csdn = new CSDN_parse();
	add_action('import_display_start_csdn', array($csdn, 'display'));
	add_filter('blogs_levi_import_post_data_raw_csdn', array($csdn, 'postRaw'), 10, 4);
	add_action('blogs_levi_import_insert_post_csdn', array($csdn, 'postFilter'), 10, 5);
	
	add_filter('parse_import_data_csdn', array($csdn, 'get'), 10, 2);
	apply_filters('add_import_method', array(
		'slug' => 'csdn',
		'title' => 'CSDN',
		'category' => true,
		'description' => '将CSDN（csdn.net）博客中的文章导入到当前wordpress'
	));

	add_filter('parse_import_data_osc', array(new Osc_parse(), 'get'), 10, 2);
	apply_filters('add_import_method', array(
		'slug' => 'osc',
		'title' => '开源中国',
		'category' => true,
		'description' => '将开源中国（oschina.net）博客中的文章导入到当前wordpress中'
	));
	
	add_filter('parse_import_data_cnblogs', 'cnblogs_parse', 10, 2);
	apply_filters('add_import_method', array(
		'slug' => 'cnblogs',
		'title' => '博客园',
		'category' => false,
		'description' => '将博客园（cnblogs.com）博客中的文章导入到当前wordpress中'
	));
}