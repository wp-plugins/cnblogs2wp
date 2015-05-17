<?php
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
					$value['content'] = str_replace('\\', '\\\\', $content->ownerDocument->saveXML($content));
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