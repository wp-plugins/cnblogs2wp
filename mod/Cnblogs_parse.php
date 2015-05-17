<?php
function cnblogs_parse($str, $map)
{
	if (false == ($xml = xml2array($str)))
	{
		throw new Exception('不是一个有效的XML文件');
	}

	if (isset($xml['rss']))
	{
		$xml = $xml['rss'];
	}
	else
	{
		throw new Exception('导入的数据不正确');
	}

	if (empty($xml['channel']['item']))
	{
		throw new Exception('没有可导入的数据');
	}

	$data = array(
		'base_url' => $xml['channel']['link'],
		'author' => $xml['channel']['item'][0]['author'],
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

	foreach ($xml['channel']['item'] as $item)
	{
		$value = array(
			'terms' => array(),
			'title' => trim($item['title']),
			'url' => $item['guid'],
			'pubDate' => date_i18n('Y-m-d H:i:s', strtotime($item['pubDate'])),
			'content' => str_ireplace(
				array('<br>', '<hr>', '\\'), array('<br />', '<hr />', '\\\\'), htmlspecialchars_decode($item['description']))
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