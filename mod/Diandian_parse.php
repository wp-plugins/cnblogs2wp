<?php
class Diandian_parse
{
	public $pic = array();
	public $cont = '';
	
	public function get($str, $map)
	{
		$data = array(
			'base_url' => '',
			'author' => '',
			'category_map' => $map,
			'category' => array(),
			'post_tag' => array(),
			'posts' => array()
		);
		
		if (!($arr = xml2array($str)) || !isset($arr['DiandianBlogBackup'])) 
		{
			throw new Exception('导入的数据不正确');
		}
		
		if ($data['category_map']['type'] == 1)
		{
			$data['category'][] = $data['category_map']['data'];
		}
		
		$xml = $arr['DiandianBlogBackup'];
		if (isset($xml['BlogInfo']) && isset($xml['BlogInfo']['BlogUrl'])) 
		{
			$data['base_url'] = sprintf('http://%s.diandian.com/', $xml['BlogInfo']['BlogUrl']);
		}
		else 
		{
			throw new Exception('找不到博客地址');
		}
			
		if (!isset($xml['Posts']) || !isset($xml['Posts']['Post']) || !count($xml['Posts']['Post']))
		{
			throw new Exception('没有找到需要导入的博客文章');
		}
		
		postFomat();
		foreach ($xml['Images']['Image'] as $img) 
		{
			$pid = $img['Id'];
			$this->pic[$pid] = $img['Url'];
		}
		
		foreach ($xml['Posts']['Post'] as $item)
		{
			if (false != ($value = $this->_parseContent($item, $data['base_url'], get_option('gmt_offset') * HOUR_IN_SECONDS))) 
			{
				$value['terms'][] = array(
					'name' => $map['data'],
					'slug' => $map['slug'],
					'domain' => 'category'
				);
				
				$data['posts'][] = $value;
			}
		}
		
		return $data;
	}
	
	public function postRaw($post, $map, $post_exists, $step)
	{
		$mp3 = (strpos($post['content'], '[useraudio-mp3]') !== false);
		$img = (strpos($post['content'], '[useraudio-img]') !== false);
		$pic = (strpos($post['content'], '[userphoto-img=') !== false);
		
		$c_img = preg_match('/<img[^>]+id=["|\']?([^"\'\s>]+)[^>]*>/is', $post['content']);
		
		if (!$mp3 && !$img && !$pic && !$c_img) 
		{
			return $post;
		}
		
		// 不重复下载
		if ($post_exists && get_post_type($post_exists) == 'post')
		{
			return $post;
		}
		
		$load = new LoadRemoteUrl();
		$aid = $load->get($post['url']);
		
		if (false != ($content = $load->query("//body")))
		{
			$replace = '';
			$this->cont = $content->ownerDocument->saveXML($content);
			if ($mp3 && preg_match('/src:"(http:\/\/[^"]+?)"/is', $this->cont, $url)) 
			{
				$replace = sprintf('<p>[embed]%s[/embed]</p>', $url[1]);
				$post['content'] = str_replace('[useraudio-mp3]', $replace, $post['content']);
			}
			
			if ($img && preg_match('/<div class="cover" style="background-image:url\(([^\)]+)\)"/is', $this->cont, $url)) 
			{
				$replace = sprintf('<dt>专辑封面：</dt><dd><img src="%s" alt="" /><dd>', $url[1]);
				$post['content'] = str_replace('[useraudio-img]', $replace, $post['content']);
			}
			
			if ($pic && preg_match('/\[userphoto-img="(.+?)"\sdesc="(.*?)"\]/is', $post['content'], $pic_ids)) 
			{
				$cont_pics = '';
				$desc = array_combine(explode('|', $pic_ids[1]), explode('|', $pic_ids[2]));
				
				if(false != ($url = $this->reqImgAft($pic_ids, true))) 
				{
					foreach ($url[1] as $key => $link)
					{
						$pot = $url[2][$key];
						$cont_pics .= sprintf('<p><img src="%s" alt="%s" /></p>', $link, isset($desc[$pot]) ? $desc[$pot] : '');
					}
				}
				
				$post['content'] = preg_replace('/\[userphoto-img=".+?"\sdesc=".*?"\]/is', $cont_pics, $post['content']);
			}
			
			if ($c_img) 
			{
				$post['content'] = preg_replace_callback('/<img[^>]+id=["|\']?([^"\'\s>]+)[^>]*>/is', array($this, 'reqImgAft'), $post['content']);
			}
		}
		
		wp_import_cleanup($aid);
		return $post;
	}
	
	public function postFilter($press, $post_id, $postdata, $post, $fetch)
	{
		if (is_wp_error($post_id))
		{
			return ;
		}
		
		if ($post['type'] != 'standard')
		{
			$id = set_post_format($post_id, $post['type']);
			$press->step->write(is_wp_error($id) ? '设置文章形式失败' : '设置文章形式成功');
		}
		
		return;
	}
	
	public function reqImgBf($match) 
	{
		$pid = $match[1];
		return isset($this->pic[$pid]) ? sprintf('<img src="%s" alt="" />', $this->pic[$pid]) : '';
	}
	
	public function reqImgAft($match, $call = false) 
	{
		$pid = $match[1];
		
		// 优先匹配a标签中的link，因为img中有可能是缩小后的图片
		if (preg_match_all('/<a[^>]*href="([^"]+('.$pid.')[^"]+)".*?>/is', $this->cont, $url) ||
			preg_match_all('/<img[^>]*src="([^"]+('.$pid.')[^"]+)".*?>/is', $this->cont, $url)) 
		{
			return $call ? $url : sprintf('<img src="%s" alt="" />', $url[1][0]);
		}
		else 
		{
			return $call ? array() : '';
		}
	}
	
	private function _parseContent($post, $url, $tnum = 0) 
	{
		$id = isset($post['Id']) ? (int)$post['Id'] : 0;
		$privacy = isset($post['Privacy']) ? (int)$post['Privacy'] : 0;
		$time = $post['CreateTime'] / 1000 + $tnum;
		
		$data = array(
			'title' => isset($post['Title']) && !empty($post['Title']) ? trim($post['Title']) : '',
			'status' => $privacy > 0 ? false : true,
			'pubDate' => date_i18n('Y-m-d H:i:s', $time),
			'url' => sprintf('%spost/%s/%d', $url, date_i18n('Y-m-d', $time), $id),
			'content' => ''
		);
		
		if (isset($post['Tags']) && isset($post['Tags']['Tag'])) 
		{
			if (is_array($post['Tags']['Tag'])) 
			{
				foreach ($post['Tags']['Tag'] as $tag)
				{
					$data['terms'][] = array(
						'name' => $tag,
						'slug' => urlencode($tag),
						'domain' => 'post_tag'
					);
				}
			}
			elseif (!empty($post['Tags']['Tag'])) 
			{
				$data['terms'][] = array(
					'name' => $post['Tags']['Tag'],
					'slug' => urlencode($post['Tags']['Tag']),
					'domain' => 'post_tag'
				);
			}
		}

		$content = '';
		$type = $post['PostType'];
		
		// 需要下载远程的图片和mp3
		switch ($type) 
		{
			case 'audio':
			case 'useraudio':
				$data['type'] = 'audio';
				isset($post['AlbumName']) && $data['content'] .= sprintf('<dt>专辑名称：</dt><dd>%s<dd>', $post['AlbumName']);
				isset($post['ArtistName']) && $data['content'] .= sprintf('<dt>艺术家：</dt><dd>%s<dd>', $post['ArtistName']);
				if (isset($post['Cover'])) 
				{
					$data['content'] .= sprintf('<dt>专辑封面：</dt><dd><img src="%s" alt="" /><dd>', $post['Cover']);
				}
				elseif (isset($post['CoverImageId'])) 
				{
					// 从URL中获取专辑封面
					$data['content'] .= '[useraudio-img]';
				}
				
				$song = isset($post['SongName']) ? $post['SongName'] : '';
				$song && $data['content'] .= sprintf('<dt>音乐名称：</dt><dd>%s<dd>', $song);
				if (isset($post['SongId'])) 
				{
					$data['content'] .= sprintf('<dt>在线收听：</dt><dd>
													<object width="257" height="33" codebase="http://download.macromedia.com/pub/shockwave/cabs/flash/swflash.cab#version=9,0,28,0" classid="clsid:D27CDB6E-AE6D-11cf-96B8-444553540000">
														<param value="http://www.xiami.com/widget/0_%s/singlePlayer.swf" name="movie"></param>
														<param value="transparent" name="wmode"></param>
														<param value="high" name="quality"></param>
														<embed width="257" height="33" type="application/x-shockwave-flash" pluginspage="http://www.adobe.com/shockwave/download/download.cgi?P1_Prod_Version=ShockwaveFlash" quality="high" wmode="transparent" menu="false" src="http://www.xiami.com/widget/0_%1$s/singlePlayer.swf" />
													</object><dd>', $post['SongId']);
				} 
				elseif (isset($post['MusicId'])) 
				{
					$data['content'] .= '[useraudio-mp3]';
				}
				
				$data['content'] = sprintf('<dl>%s</dl>', $data['content']);
				!empty($post['Comment']) && $data['content'] .= htmlspecialchars_decode($post['Comment']);
				
				$content = $data['content'];
				empty($data['title']) && ($data['title'] = $song ? $song : '');
				break;
			case 'link': 
				$data['type'] = $type;
				if (isset($post['Link'])) 
				{
					$data['content'] = sprintf('<a href="%s" target="_blank">%1$s</a>', $post['Link']);
				}
				else 
				{
					return array();
				}
				
				empty($data['title']) && $data['title'] = sprintf('%s 链接一篇', date_i18n('Y-m-d H:i', $time));
				break;
			case 'photo':
				$data['type'] = 'image';
				$img = array();
				$desc = array();
				
				if (isset($post['PhotoItem']['Id']))
				{
					$pid = $post['PhotoItem']['Id'];
					$alt = empty($post['PhotoItem']['Desc']) ? '' : $post['PhotoItem']['Desc'];
					
					if ($post['Privacy'] == 0) 
					{
						$img[] = $pid;
						$desc[] = $alt;
					}
					elseif (isset($this->pic[$pid]))
					{
						$data['content'] .= sprintf('<p><img src="%s" alt="%s" /></p>', $this->pic[$pid], $alt);
					}
				}
				else
				{
					foreach ($post['PhotoItem'] as $photo)
					{
						$pid = $photo['Id'];
						$alt = empty($photo['Desc']) ? '' : $photo['Desc'];
						
						if ($post['Privacy'] == 0) 
						{
							$img[] = $pid;
							$desc[] = $alt;
						}
						elseif (isset($this->pic[$pid]))
						{
							$data['content'] .= sprintf('<p><img src="%s" alt="%s" /></p>', $this->pic[$pid], $alt);
						}
					}
				}
				
				count($img) && $data['content'] .= sprintf('[userphoto-img="%s" desc="%s"]', implode('|', $img), implode('|', $desc));
				
				$content = empty($post['Desc']) ? '' : htmlspecialchars_decode($post['Desc']);
				$data['content'] .= $content;
				break;
			case 'text':
				$data['type'] = 'standard';
				$data['content'] = htmlspecialchars_decode($post['Text']);
				
				$content = $data['content'];
				break;
			case 'video':
				$data['type'] = $type;
				if (isset($post['VideoFlashUrl'])) 
				{
					$data['content'] .= sprintf(
						'<object width="637" height="530" data-aspect="0.832" style="width: 637px; height: 530px;"><param name="allowscriptaccess" value="sameDomain"><param name="wmode" value="transparent"><param name="movie" value="%s"><param name="allowfullscreen" value="true"><embed src="%1$s" width="637" height="530" allowscriptaccess="sameDomain" allowfullscreen="true" wmode="transparent" type="application/x-shockwave-flash" data-aspect="0.832" style="width: 637px; height: 530px;"></object>', 
						$post['VideoFlashUrl']
					);
				}
				
				if (isset($post['VideoImgUrl']))
				{
					$data['content'] .= sprintf('<dt>视频图片：</dt><dd><img src="%s" alt="" /></dd>', $post['VideoImgUrl']);
				}
				
				$content = empty($post['Content']) ? '' : sprintf('<dt>视频介绍：</dt><dd>%s</dd>', htmlspecialchars_decode($post['Content']));
				$content && $data['content'] .= $content;
				
				$data['content'] = sprintf('<dl>%s</dl>', $data['content']);
				empty($data['title']) && !empty($post['VideoName']) && $data['title'] = $post['VideoName'];
				break;
			default:
				return array();
		}
		
		// 如果点点文章为隐私，那么将文章中的图片替换为压缩的图片；如果文章是公开的，那么随后抓取页面的时候获取原图
		if ($post['Privacy'] != 0 && !empty($data['content']))
		{
			$data['content'] = preg_replace_callback('/<img[^>]+id=["|\']?([^"\'\s>]+)[^>]*>/is', array($this, 'reqImgBf'), $data['content']);
		}
		
		if (empty($data['title'])) 
		{
			$content = strip_tags($content);
			if (!empty($content) && function_exists('mb_substr')) 
			{
				$data['title'] = mb_substr($content, 0, 20);
			}
			else 
			{
				// 精确到秒，避免标题一模一样
				$data['title'] = sprintf('%s 小记一篇', date_i18n('Y-m-d H:i:s', $time));
			}
		}
		
		$data['content'] = str_replace('\\', '\\\\', $data['content']);
		return $data;
	}
}