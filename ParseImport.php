<?php
// 执行导入
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

	public function __construct()
	{
		global $cnblogs, $step;

		$this->_obj = $cnblogs;
		$this->step = $step;
	}

	public function stop()
	{
		update_option('cnblog2wp-levi', array(
			'status' => 0,
			'type' => Cnblog2wp::$type,
			'fetch_attachments' => 0,
			'msg' => '强行系统停止导入数据'
		));

		$this->step->closed();
	}

	public function fetchAtta($match)
	{
		$url = $match[1];
		$url[0] == '/' && ($url = rtrim($this->_base_url, '/') . $url);
		$this->remot->fetch(self::$post, $url);
	}

	public function import()
	{
		$path = $this->step->getPath();
		$time = is_multisite() ? 180 : 60;
		$info = get_option('cnblog2wp-levi');

		// 数据文件不存在
		if (!file_exists($path['imp']))
		{
			if ($info && $info['status'] == 1)
			{
				$this->stop();
			}
				
			die('0');
		}
		else
		{
			if ($info && $info['status'] != 1)
			{
				$this->step->closed();
				die('0');
			}
				
		}

		// 单博客每隔1分钟执行一次，多站点的博客每3分钟一次，每次导入50条数据
		if (time() - filemtime($path['imp']) < $time && file_exists($path['log']))
		{
			// 正在导入数据
			die('2');
		}

		if ($time == 180 && false != ($handle = opendir($path['data'])))
		{
			$num = 0;
			while (false != ($file = readdir($handle)))
			{
				if (strrpos($file, '.tmp') && time() - filemtime($path['data'].$file) < $time)
				{
					$num++;
				}
			}
				
			// 多站点的博客最多允许5个博客同时导入数据
			if ($num >= 6)
			{
				die('3');
			}
		}

		$nonce = isset($_POST['_wpnonce']) ? trim($_POST['_wpnonce']) : '';
		if (!wp_verify_nonce($nonce, 'parse_import_cnblogs2wp') || !$this->step->init())
		{
			die('0');
		}

		$this->_importData = unserialize(file_get_contents($path['imp']));
		if ($this->_importData['base_url'])
		{
			$this->step->base = $this->_importData['base_url'];
		}
		else
		{
			die('0');
		}

		set_time_limit(0);
		$this->_importing();
	}

	/**
	 * status: -1.异常终止；0.终止；1.进行中；2.完成
	 * @param int $id
	 * @throws Exception
	 * @return boolean
	 */
	public function impPress($id)
	{
		if (!($file = get_attached_file($id)))
		{
			throw new Exception('<p>系统无法找到上传的数据文件</p>');
		}

		$import_data = $this->_parse($file);
		wp_import_cleanup($id);

		if (is_wp_error($import_data))
		{
			$str = sprintf('<p><strong>上传文件出现错误，请重新上传！错误原因：</strong><br />%s</p>', esc_html($import_data->get_error_message()));
			throw new Exception($str);
		}

		$import_data['author'] && $import_data['author'] = sanitize_user($import_data['author']);
		$import_data['base_url'] && $this->_base_url = esc_url($import_data['base_url']);

		$this->_getAuthorMapping();
		$this->step->save($import_data);

		update_option('cnblog2wp-levi', array(
			'status' => 1,
			'type' => Cnblog2wp::$type,
			'fetch_attachments' => isset($_POST['fetch_attachments']) ? (int)$_POST['fetch_attachments'] : 0,
			'msg' => '开始导入数据...'
		));
	}

	private function _importing()
	{
// 		add_filter('import_post_meta_key', array($this->_obj, 'is_valid_meta_key'));

		wp_defer_term_counting(true);
		wp_defer_comment_counting(true);
		do_action('import_start');

		// 暂停缓存
		wp_suspend_cache_invalidation(true);

// 		$this->_process();
// 		$this->_process(2);
		$info = $this->_processPosts();
		wp_suspend_cache_invalidation(false);

		// update incorrect/missing information in the DB
		$this->_importEnd($info);
	}

	private function _importEnd($info)
	{
		// 数据需要在这里获取，否则被清空了
		wp_cache_flush();

		foreach (get_taxonomies() as $tax )
		{
			delete_option("{$tax}_children");
			_get_term_hierarchy($tax);
		}

		wp_defer_term_counting(false);
		wp_defer_comment_counting(false);

		update_option('cnblog2wp-levi', $info);
		do_action('import_end');

		if ($info['status'] != 1)
		{
			$this->step->closed();
			die('0');
		}
		else
		{
			$this->step->write('系统已成功导入一批数据，请等待后续导入...');
			die('1');
		}
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
		$num = 0;
		$this->_importData['posts'] = apply_filters('blogs_levi_import_posts', $this->_importData['posts']);

		foreach ($this->_importData['posts'] as $key => $post)
		{
			if ($num++ >= 50)
			{
				break;
			}
				
			try
			{
				$mod = get_option('cnblog2wp-levi');
				if ($mod['status'] != 1)
				{
					return $mod;
				}

				unset($this->_importData['posts'][$key]);
				$this->step->save($this->_importData);
			}
			catch (Exception $e)
			{
				$mod['status'] = -1;
				$mod['msg'] = '不能更新写入数据至导入文件中';

				return $mod;
			}
				
				
			if ($this->_obj->allow_fetch_attachments())
			{
				$fetch = isset($mod['fetch_attachments']) ? $mod['fetch_attachments'] : 0;
			}
			else
			{
				$fetch = 0;
			}
				
			if (empty($post['title']))
			{
				continue;
			}
				
			try
			{
				$post_exists = post_exists($post['title'], '', $post['pubDate']);
				$post = apply_filters('blogs_levi_import_post_data_raw_'.$mod['type'], $post, $this->_importData['category_map'], $post_exists, $this->step);
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

				do_action('blogs_levi_import_insert_post_'.$mod['type'], $this, $post_id, $postdata, $post, $fetch);
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
					$this->remot = new RemoteAttach();
					
					$postdata['post_parent'] = $post_id;
					self::$post = $postdata;
						
					$pattern = '/src=["|\']?((https?:\/\/[^\/]+\.[^\/\.]{2,6})?\/[^\'">]*\w+\.(png|gif|jpe?g))["|\']?[^>]*>/is';
					$data = preg_replace_callback($pattern, array($this, 'fetchAtta'), $postdata['post_content']);
					
					$this->_backfileAttachmentUrls();
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
						do_action('blogs_levi_import_insert_terms', $t, $term);

						if (!is_wp_error($t))
						{
							$term_id = $t['term_id'];
						}
						else
						{
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

		$total = count($this->_importData['posts']);

		$mod['msg'] = $total ? '已完成导入一轮数据，待系统后续处理..' : '完成数据导入';
		$mod['status'] = $total ? 1 : 2;

		return $mod;
	}

	private function _backfileAttachmentUrls()
	{
		global $wpdb;
		
		$url_remap = $this->remot->get();
		switch (count($url_remap)) 
		{
			case 0: return false;
			case 1: break;
			default: 
				// 根据URL长度排列，长的排在前面，避免长的URL中包含短URL被先行替换
				uksort($url_remap, array($this->_obj, 'sort_url'));
		}
		
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