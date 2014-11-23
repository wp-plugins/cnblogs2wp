<?php
/**
 * Cnblog xml file parser implementations
 */

class XML_Parse
{
	private $_data;
	private $_xpath;
	
	public function __construct($xml, $type)
	{
		$str = file_get_contents($xml);
		if ($type == 2)
		{
			if (!strstr($str, 'oschina')) 
			{
				throw new Exception('导入的数据文件不正确');
			}
			
			$xml = new DOMDocument();
			@$xml->loadHTML($str);
			
			$this->_xpath = new DOMXPath($xml);
			$this->_data = $this->_rollInOsc();
		}
		else
		{
			$xml_parser = xml_parser_create();
			if (!xml_parse($xml_parser, $str, true)) 
			{
				xml_parser_free($xml_parser);
				throw new Exception('不是一个有效的XML文件');
			}
			
			$this->_data = $this->_rollInCnblog(simplexml_load_string($str));
		}
		
		if (!$this->_data) 
		{
			throw new Exception('系统没有找到有效的导入数据');
		}
	}
	
	public function get() 
	{
		return $this->_data;
	}
	
	public function query($node, $item = 0)
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
	
	private function _rollInCnblog($xml) 
	{
		$data = array(
			'base_url' => (string)$xml->channel->link,
			'author' => (string)$xml->channel->item[0]->author,
			'category_map' => $this->_getCategoryMap(),
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
				'title' => (string)$item->title,
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
	
	private function _rollInOsc() 
	{
		$self = $this;
		$data = array(
			'base_url' => '',
			'author' => '',
			'category_map' => $this->_getCategoryMap(),
			'category' => array(),
			'post_tag' => array(),
			'posts' => array()
		);
		
		if ($data['category_map']['type'] == 1)
		{
			$data['category'][] = $data['category_map']['data'];
		}
		
		if (false != ($title = $this->query('//title'))) 
		{
			$node = explode('的', $title->nodeValue);
			$data['author'] = $node[0];
		}
		
		if (false != ($link = $this->query('//h1//@href')))
		{
			$data['base_url'] = $link->nodeValue;
		}
		
		if (false != ($elements = $this->query("//*[contains(@class,'blog')]", -1)))
		{
			foreach ($elements as $key => $emt) 
			{
				if (!$key)
				{
					continue;
				}
				
				$value = array();
				if (false != ($title = $this->query(array('.//h2/*', $emt), 1))) 
				{
					$value['title'] = $title->nodeValue;
					if (false != ($url = $this->query(array('.//@href', $title)))) 
					{
						$value['url'] = $url->nodeValue;
					}
				}
				
				if (false != ($time = $this->query(array(".//*[contains(@class,'date')]", $emt)))) 
				{
					$node = explode('：', $time->nodeValue);
					$value['pubDate'] = $node[1];
				}
				
				if (empty($data['category_map']['slug'])) 
				{
					if (false != ($catelog = $this->query(array(".//*[contains(@class,'catalog')]", $emt))))
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
				
				if (false != ($tags = $this->query(array(".//*[contains(@class,'tags')]", $emt)))) 
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
				
				if (false != ($content = $this->query(array(".//*[contains(@class,'content')]", $emt))))
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
	
	private function _getCategoryMap() 
	{
		$terms = get_terms('category');
		$map = array(
			'type' => isset($_POST['selet_category']) ? (int)$_POST['selet_category'] : 0,
			'slug' => ''
		);
		
		$map['type'] = max(min(3, $map['type']), 1);
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
						$t->term_id == $map['data'] && $map['data'] = $t->name;
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