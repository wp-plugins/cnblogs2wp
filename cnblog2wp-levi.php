<?php
/**
 * Plugin Name: 转换博客园、开源中国博客文章到wordpress
 * Plugin URI: http://levi.cg.am
 * Description: 将博客园（http://www.cnblogs.com/）以及开源中国-博客（http://www.oschina.net/blog）数据转换至wordpress中
 * Author: Levi
 * Version: 0.2.2
 * Author URI: http://levi.cg.am
 * Text Domain: wordpress-importer
 * License: GPL version 2 or later - http://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 */
if ( ! defined( 'WP_LOAD_IMPORTERS' ) )
	return;

/** Display verbose errors */
define('IMPORT_DEBUG', true);

include dirname( __FILE__ ) . '/XML_Parse.php';

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
	
	public function js($file)
	{
		$target = sprintf('js/%s.js', $file);
		printf('<script type="text/javascript" src="%s"></script>', $this->getURL($target));
	}
}

class CNblogToWP extends Lv_ui
{
	public $view;
	public $type;
	public $id;	// XML attachment ID

	public $base_url;
	public $importData;
	public $author_mapping;
	public $url_remap = array();
	
	private $_fetch_attachments;
	private $_selet_author;
	
	public function __construct()
	{
		$name = isset($_GET['import']) ? trim($_GET['import']) : '';
		
		$this->view = new Lv_ui;
		$this->type = $name == 'osc' ? 2 : 1;
	}
	
	public function dispatch() 
	{
		$this->template('header');
		$step = isset($_GET['step']) ? intval($_GET['step']) : 0;
		if ($step) 
		{
			check_admin_referer('import-upload');
			if (!$this->_handleUpload()) return;
			
			$fetch = isset($_POST['fetch_attachments']) ? (int)$_POST['fetch_attachments'] : 0;
			$this->_fetch_attachments = ($fetch && $this->allow_fetch_attachments());
			
			$this->_import();
			return;
		}
		
		wp_enqueue_script('cnblog2wp');
		$this->template('greet');
	}
	
	public function allow_create_users() 
	{
		return apply_filters('import_allow_create_users', true );
	}
	
	public function allow_fetch_attachments() 
	{
		return apply_filters('import_allow_fetch_attachments', true );
	}
	
	public function max_attachment_size() 
	{
		return apply_filters('import_attachment_size_limit', 0 );
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
	
	public function processAttachment($post, $url)
	{
		$url[0] == '/' && ($url = rtrim($this->base_url, '/') . $url);
		$upload = $this->_fetchRemoteFile($url);
		if (is_wp_error($upload)) 
		{
			return $upload;
		}
		
		if (false != ($info = wp_check_filetype($upload['file']))) 
		{
			$post = array_merge($post, array(
				'post_mime_type' => $info['type'],
				'post_status' => 'inherit'
			));
		}
		else 
		{
			return new WP_Error('attachment_processing_error', '无效的文件类型');
		}
		
		$post['guid'] = $upload['url'];
		$post_id = wp_insert_attachment($post, $upload['file']);
		$meta = wp_generate_attachment_metadata($post_id, $upload['file']);
		
		wp_update_attachment_metadata($post_id,  $meta);
		$this->url_remap[$url] = $upload['url'];
		
		return $post_id;
	}
	
	private function _import() 
	{
		set_time_limit(0);
		add_filter('import_post_meta_key', array($this, 'is_valid_meta_key'));
		add_filter('http_request_timeout', array($this, 'bump_request_timeout'));
		
		wp_defer_term_counting(true);
		wp_defer_comment_counting(true);
	
		do_action('import_start');
		
		// 暂停缓存
		wp_suspend_cache_invalidation(true);
		$this->_process();
		$this->_process(2);
		$this->_processPosts();
		wp_suspend_cache_invalidation(false);
		
		// update incorrect/missing information in the DB
		$this->_backfileAttachmentUrls();
		$this->_importEnd();
	}
	
	private function _importEnd() 
	{
		wp_import_cleanup($this->id);
		wp_cache_flush();
		
		foreach (get_taxonomies() as $tax ) 
		{
			delete_option("{$tax}_children");
			_get_term_hierarchy($tax);
		}
		
		wp_defer_term_counting(false);
		wp_defer_comment_counting(false);

		printf('<p>数据已经全部导入完毕，<a href="%s">点击返回管理中心首页</a></p>', admin_url());
		if ($this->_selet_author)
		{
			echo '<p>小提示：别忘了修改导入作者的密码哦~</p>';
		}
		
		do_action('import_end');
		echo '<br />';
	}
	
	/**
	 * 处理WXR上传和为文件初步分析作准备
	 * 显示作者导入选项
	 *
	 * @return bool False if error uploading or invalid file, true otherwise
	 */
	private function _handleUpload()
	{
		$file = wp_import_handle_upload();
		$str = '上传文件出现错误，请重新上传！错误原因：';
		if (isset($file['error'])) 
		{
			// 上传错误
			printf('<p><strong>%s</strong><br />%s</p>', $str, esc_html( $file['error'] ));
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
		
		$this->id = (int)$file['id'];
		$import_data = $this->_parse($file['file']);
		if (is_wp_error($import_data)) 
		{
			printf('<p><strong>%s</strong><br />%s</p>', $str, esc_html( $import_data->get_error_message() ));
			echo '<a href="javascript:void(0);" onclick="history.go(-1)">返回重新修改</a></div>';
			return false;
		}
		
		isset($import_data['author']) && ($import_data['author'] = sanitize_user($import_data['author']));
		$this->base_url = esc_url($import_data['base_url']);
		$this->importData = $import_data;
		
		$this->_getAuthorMapping();
		return true;
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
			$data = new XML_Parse($file, $this->type);
			return $data->get();
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
			elseif (false != ($create_users = $this->allow_create_users())) 
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
			$this->author_mapping = $user_id;
		}
		else 
		{
			$this->author_mapping = get_current_user_id();
			$this->_selet_author = 0;
		}
	}
	
	private function _process($type = 1)
	{
		$name = $type == 1 ? 'category' : 'post_tag';
		$group = apply_filters('blogs_levi_import_'.$name, $this->importData[$name]);
		if (empty($group)) 
		{
			return ;
		}
		
		$group = array_flip(array_flip($group));
		foreach ($group as $data) 
		{
			if (false != ($term_id = term_exists($data, $name))) 
			{
				continue;
			}
			
			$id = wp_insert_term($data, $name);
			if (is_wp_error($id))
			{
				printf('不能导入分类 %s', esc_html($data));
				if (defined('IMPORT_DEBUG') && IMPORT_DEBUG)
				{
					echo '：'.$id->get_error_message();
				}
			
				echo '<br />';
				continue;
			}
		}
	}
	
	private function _processPosts()
	{
		$posts = apply_filters('blogs_levi_import_posts', $this->importData['posts']);
		if (empty($posts)) 
		{
			return;
		}
		
		foreach ($posts as $post) 
		{
			$post = apply_filters('blogs_levi_import_post_data_raw', $post);
			$post_exists = post_exists($post['title'], '', $post['pubDate'] );
			
			if ($post_exists && get_post_type($post_exists) == 'post') 
			{
				printf('<p>博客中已存在日志：%s</p>', esc_html($post['post_title']));
				$post_id = $post_exists;
			}
			else 
			{
				$author = $this->author_mapping;
				$postdata = array(
					'import_id' => '',
					'post_author' => $author, 
					'post_date' => $post['pubDate'],
					'post_date_gmt' => $post['pubDate'], 
					'post_content' => $post['content'],
					'post_excerpt' => '', 
					'post_title' => $post['title'],
					'post_status' => 'publish', 
					'post_name' => urlencode($post['title']),
					'comment_status' => 'open', 
					'ping_status' => 'open',
					'guid' => '', 
					'post_parent' => 0, 
					'menu_order' => 0,
					'post_type' => 'post', 
					'post_password' => ''
				);
				
				$postdata = apply_filters('blogs_levi_import_post_data_processed', $postdata, $post);
				$post_id = wp_insert_post($postdata, true);
				
				do_action('blogs_levi_import_insert_post', $post_id, $postdata, $post);
				if (is_wp_error($post_id)) 
				{
					$post_type_object = get_post_type_object('post');
					printf(
						'&#8220;%s&#8221;导入&#8220;%s&#8221;失败！', 
						esc_html($post['title']), 
						$post_type_object->labels->singular_name
					);
					
					if (defined('IMPORT_DEBUG') && IMPORT_DEBUG) 
					{
						echo ': ' . $post_id->get_error_message();
					}
					
					echo '<br />';
					continue;
				}
				
				if ($this->_fetch_attachments && preg_match_all('/png|gif|jpe?g/is', $post['content'])) 
				{
					$self = $this;
					$post['post_parent'] = $post_id;
					
					$pattern = '/(https?:\/\/[^\/]+\.[^\/\.]{2,6})?\/[^">]*\w+\.(png|gif|jpe?g)/is';
					$data = preg_replace_callback(
						$pattern, 
						function($match) use($self, $post) 
						{
							$url = $match[0];
							!isset($self->url_remap[$url]) && $self->processAttachment($post, $match[0]);
						}, 
						$post['content']
					);
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
							printf(
								'<p>导入%s失败，%1$s关键字：%s</p>', 
								$taxonomy == 'category' ? '分类' : '标签', 
								esc_html($term['name'])
							);
							
							if (defined('IMPORT_DEBUG') && IMPORT_DEBUG) 
							{
								printf('<p>原因：%s</p>', $t->get_error_message());
							}
							
							do_action('blogs_levi_import_insert_term_failed', $t, $term, $post_id, $post);
							continue;
						}
					}
					
					$terms_to_set[$taxonomy][] = intval($term_id);
				}
				
				foreach ($terms_to_set as $tax => $ids) 
				{
					$tt_ids = wp_set_post_terms( $post_id, $ids, $tax);
					do_action('blogs_levi_import_set_post_terms', $tt_ids, $ids, $tax, $post_id, $post);
				}
			}
		}
	}
	
	private function _fetchRemoteFile($url) 
	{
		$file_name = basename($url);
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
			return new WP_Error('import_file_error', sprintf('远程服务器返回错误响应 %1$d %2$s', esc_html($headers['response']), get_status_header_desc($headers['response'])));
		}
		
		$filesize = filesize($upload['file']);
		if (isset($headers['content-length']) && $filesize != $headers['content-length']) 
		{
			@unlink($upload['file']);
			return new WP_Error('import_file_error', '文件大小不正确');
		}
		
		$max_size = (int)$this->max_attachment_size();
		if ($max_size && $filesize > $max_size) 
		{
			@unlink($upload['file']);
			return new WP_Error('import_file_error', sprintf('附件大小限制为：%s', size_format($max_size)));
		}
		
		return $upload;
	}
	
	private function _backfileAttachmentUrls() 
	{
		if (false == ($url_remap = $this->url_remap)) 
		{
			return false;
		}
		
		// 根据URL长度排列，长的排在前面，避免长的URL中包含短URL被先行替换
		uksort($this->url_remap, function($a, $b) 
		{
			return strlen($b) - strlen($a);
		});

		global $wpdb;
		foreach ($this->url_remap as $from_url => $to_url) 
		{
			$wpdb->query($wpdb->prepare("UPDATE `{$wpdb->posts}` SET `post_content` = REPLACE(`post_content`, %s, %s);", $from_url, $to_url));
			$wpdb->query($wpdb->prepare("UPDATE `{$wpdb->postmeta}` SET `meta_value` = REPLACE(`meta_value`, %s, %s) WHERE `meta_key`='enclosure';", $from_url, $to_url));
		}
	}
}

function cnblog2wp_lv_importer_init() 
{
	// 加载语言包
	// load_plugin_textdomain('cnblog2wp-importer-zh_CN', false, dirname(plugin_basename( __FILE__ )).'/languages' );
	
	/**
	 * Cnblog Importer object for registering the import callback
	 * @global Cnblog2wp $cnblogs2wp
	*/
	$GLOBALS['cnblogs2wp'] = new CNblogToWP();
	$GLOBALS['osc2wp'] = new CNblogToWP();
	
	register_importer('cnblog', '博客园 (cnblog)', '将博客园中的随笔文章导入到wordpress中', array( $GLOBALS['cnblogs2wp'], 'dispatch'));
	register_importer('osc', '开源中国 (osc)', '将开源中国的博客内容导入wordpress中', array( $GLOBALS['osc2wp'], 'dispatch'));
	
	$name = isset($_GET['import']) ? trim($_GET['import']) : '';
	wp_register_script('cnblog2wp', plugins_url('cnblog2wp.js', __FILE__), array('jquery'));
}

add_action('admin_init', 'cnblog2wp_lv_importer_init');