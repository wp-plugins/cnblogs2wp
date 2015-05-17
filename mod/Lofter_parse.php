<?php
function lofter_parse($str, $map)
{
	if (false == ($xml = xml2array($str)))
	{
		throw new Exception('不是一个有效的XML文件');
	}

	if (isset($xml['lofterBlogExport']))
	{
		$xml = $xml['lofterBlogExport'];
	}
	else
	{
		throw new Exception('导入的数据不正确');
	}

	$data = array(
		'base_url' => $xml['description']['BlogDomain'],
		'author' => '',
		'category_map' => $map,
		'category' => array(),
		'post_tag' => array(),
		'posts' => array()
	);
	
	if (!strstr($data['base_url'], 'lofter.com'))
	 {
		throw new Exception('导入的数据不正确');
	}

	$fomat = array('video' => 'video', 'music' => 'audio', 'photo' => 'image', 'text' => 'standard');
	postFomat();

	$tnum = get_option('gmt_offset') * HOUR_IN_SECONDS;
	foreach ($xml['PostItem'] as $item)
	{
		$type = strtolower($item['type']);
		$value = array(
			'terms' => array(),
			'type' => isset($fomat[$type]) ? $fomat[$type] : 'standard',
			'title' => $item['title'],
			'url' => sprintf('%s/%s', $data['base_url'], (string)$item['permalink']),
			'pubDate' => date_i18n('Y-m-d H:i:s', $item['publishTime'] / 1000 + $tnum),
			'terms' => array(),
			'content' => ''
		);

		if (!empty($data['category_map']['slug']))
		{
			$value['terms'][] = array(
				'name' => $data['category_map']['data'],
				'slug' => $data['category_map']['slug'],
				'domain' => 'category'
			);
		}

		if (isset($item['tag']))
		{
			$tags = explode(',', $item['tag']);
			foreach ($tags as $tag)
			{
				$value['terms'][] = array(
					'name' => $tag,
					'slug' => urlencode($tag),
					'domain' => 'post_tag'
				);
			}
		}

		if (isset($item['embed']))
		{
			$embed = json_decode($item['embed']);
			if (isset($embed->type) && $embed->type == 'cloudmusic')
			{
				$value['content'] .= sprintf('<dt>专辑名称：</dt><dd>%s<dd>', urldecode($embed->album_name));
				$value['content'] .= sprintf('<dt>艺术家：</dt><dd>%s<dd>', urldecode($embed->artist_name));
				$value['content'] .= sprintf('<dt>专辑封面：</dt><dd><img src="%s" alt="" /><dd>', $embed->album_logo);
				$value['content'] .= sprintf('<dt>音乐名称：</dt><dd>%s<dd>', urldecode($embed->song_name));
				$value['content'] .= sprintf('<dt>在线收听：</dt><dd>
												<object width="257" height="34" classid="clsid:D27CDB6E-AE6D-11cf-96B8-444553540000" codebase="http://download.macromedia.com/pub/shockwave/cabs/flash/swflash.cab#version=9,0,28,0">
													<param name="movie" value="http://s1.music.126.net/style/swf/LofterMusicPlayer.swf?0003 ">
													<param name="wmode" value="transparent">
													<param name="quality" value="high">
													<param name="flashvars" value="loop=&amp;autoPlay=false&amp;url=%s&amp;trackId=%d&amp;trackName=%s&amp;artistName=%s">
													<embed flashvars="loop=&amp;autoPlay=false&amp;url=%1$s&amp;trackId=%2$d&amp;trackName=%3$s&amp;artistName=%4$s" src="http://s1.music.126.net/style/swf/LofterMusicPlayer.swf?0003 " type="application/x-shockwave-flash" pluginspage="http://www.macromedia.com/go/getflashplayer" wmode="transparent" quality="high" allowscriptaccess="always" allownetworking="all" width="257" height="34">
												</object><dd>', $embed->listenUrl, $embed->song_id, urldecode($embed->song_name), urldecode($embed->artist_name));

				$value['content'] = sprintf('<dl>%s</dl>', $value['content']);
			}
			elseif (isset($embed->flashurl))
			{
				$value['content'] .= sprintf('<object width="637" height="530" data-aspect="0.832" style="width: 637px; height: 530px;">
												<param name="allowscriptaccess" value="sameDomain">
												<param name="wmode" value="transparent">
												<param name="movie" value="%s">
												<param name="allowfullscreen" value="true">
												<embed src="%1$s" width="637" height="530" allowscriptaccess="sameDomain" allowfullscreen="true" wmode="transparent" type="application/x-shockwave-flash" data-aspect="0.832" style="width: 637px; height: 530px;">
											</object>',
					$embed->flashurl);
			}
		}

		if (isset($item['photoLinks']))
		{
			$photo = json_decode($item['photoLinks']);
			foreach ($photo as $link)
			{
				$value['content'] .= sprintf('<p><img src="%s" alt="" /></p>', $link->orign);
			}
		}

		$content = '';
		isset($item['caption']) && $item['caption'] && $content .= $item['caption'];
		isset($item['content']) && $item['content'] && $content .= $item['content'];

		$value['content'] .= $content;
		if (empty($value['title']))
		{
			$content = strip_tags($content);
			if (!empty($content) && function_exists('mb_substr'))
			{
				$value['title'] = mb_substr($content, 0, 20);
			}
			else
			{
				$value['title'] = sprintf('%s 小记一篇', $value['pubDate']);
			}
		}

		$value['content'] = str_replace('\\', '\\\\', $value['content']);
		$data['posts'][] = $value;
	}

	return $data;
}

function lofter_filter($press, $post_id, $postdata, $post, $fetch)
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
}