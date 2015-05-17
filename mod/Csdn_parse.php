<?php
class CSDN_parse
{
	const NAME = 'csdn';
	private $_aid;
	private $_id;
	
	public function get($str, $map) 
	{
		if (false == ($info = get_transient('rss_parse_key')) || !$info['url']) 
		{
			throw new Exception('导入的数据无效');
		}
		
		$load = new LoadRemoteUrl(get_dom($str));
		$data = array(
			'base_url' => $info['url'],
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
					'url' => 'http://blog.csdn.net'.$url->nodeValue,
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
			$post['content'] = str_replace('\\', '\\\\', trim($content));
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
	
	public function display($prom) 
	{
		if ($prom) 
		{
			$ck = 1;
		}
		else 
		{
			$ck = isset($_GET['ck']) ? (int)$_GET['ck'] : 0;
		}
		
		if ($ck) 
		{
			if (isset($_POST['send_url'])) 
			{
				// 优先POST请求，效验远程URL
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
			else 
			{
				// 否则是GET请求，和已入库的数据进行校对
				if (false != ($rss = get_transient('rss_parse_key')) && $rss['key'] && $rss['url'] && $rss['id']) 
				{
					// 这里只要key和url不为空即可，在之前posts时已验证过了
					$this->_id = $rss['id'];
					return add_action('remote_file', array($this, 'remoteFrom'));
				}
				
				echo '<p class="error">博客验证不正确，请重新提交验证。</p>';
			}
		}
		
		$key = wp_generate_password();
		set_transient('rss_parse_key', array('key' => $key, 'url' => '', 'id' => 0), DAY_IN_SECONDS);

		include dirname(__DIR__).'/template/checkout.htm';
		include ABSPATH.'wp-admin/admin-footer.php';
		exit;
	}
	
	public function remoteFrom($num) 
	{
		$data = array(
			'id' => $this->_id
		);
		
		include dirname(__DIR__).'/template/mod_rss.htm';
		include ABSPATH.'wp-admin/admin-footer.php';
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
			
			if (filter_var($url, FILTER_VALIDATE_URL) === FALSE || !strstr($url, 'http://blog.csdn.net/')) 
			{
				throw new Exception('请输入正确的CSDN博客地址');
			}

			if (!($rss = get_transient('rss_parse_key')))
			{
				throw new Exception('效验字符不存在');
			}

			$load = new LoadRemoteUrl();
			$id = $load->get(trim($url, '/').'/article/list/10000');
			
			if (!($title = $load->query('//h2/a[1]')) || !strstr($title->nodeValue, $rss['key'])) 
			{
				wp_import_cleanup($id);
				throw new Exception('导入数据前，请修改博客名，在名称后面添加系统指定的字符');
			}
			
			// 更新数据
			set_transient('rss_parse_key', array('key' => $rss['key'], 'url' => $url, 'id' => $id), DAY_IN_SECONDS);
			return $id;
		}
		catch (Exception $e) 
		{
			return new WP_Error('RSS_parse_error', $e->getMessage());
		}
	}
}