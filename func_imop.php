<?php
function bump_request_timeout()
{
	return 60;
}

function bump_request_ua()
{
	return 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_10_3) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/42.0.2311.135 Safari/537.36';
}

function cnblog2wp_lv_importer_init()
{
	global $cnblogs, $step;
	
	// 这个必须放在这里，作为后置引用，否则上传的文件格式会被覆盖
	add_filter('plupload_init', 'set_plupload_init', 99);

	register_importer('cn_blog', '.博客搬家.', '支持：博客园、OSChina、CSDN、点点、LOFTER', array($cnblogs, 'dispatch'));
	wp_register_script('cnblog2wp', plugins_url('js/cnblog2wp.js', __FILE__), array('jquery', 'plupload-all'));
	wp_register_script('press_data_init', plugins_url('js/press_data_init.js', __FILE__), array('jquery'));

	wp_register_style('cnblog2wp', plugins_url('css/cnblog2wp.css', __FILE__));

	add_filter('blogs_levi_import_post_data_raw_'.Cnblog2wp::$type, array($step, 'skip'), 10, 3);
	add_action('blogs_levi_import_insert_post_'.Cnblog2wp::$type, array($step, 'roll'), 10, 3);
	add_action('blogs_levi_import_insert_attach', array($step, 'rollAttach'), 10, 2);
	add_action('blogs_levi_import_insert_terms', array($step, 'rollTerms'), 10, 2);
	add_action('blogs_levi_import_set_post_terms', array($step, 'setPostTerms'), 10, 5);
}

function set_plupload_init($info)
{
	$info['filters']['mime_types'][0]['extensions'] = 'xml,html';
	return $info;
}

function get_import_file_id()
{
	return isset($_POST['import_loadfile_id']) ? (int)$_POST['import_loadfile_id'] : 0;
}

function check_imp_dir()
{
	global $cnblogs, $step;

	$path = $step->getPath();
	$dir = $path['data'];

	if (!is_dir($dir) && !(is_writable(dirname($dir).'/') && mkdir($dir)))
	{
		$cnblogs->template('mod_err', array('type' => 1, 'dir' => $dir));
		include ABSPATH.'wp-admin/admin-footer.php';
		exit;
	}

	if (!is_writable($dir) || !($fp = fopen($dir.'test.txt', 'a')))
	{
		$cnblogs->template('mod_err', array('type' => 2, 'dir' => $dir));
		include ABSPATH.'wp-admin/admin-footer.php';
		exit;
	}

	fclose($fp);
	unlink($dir.'test.txt');
}

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

function postFomat()
{
	$fomat = array(
		'standard' => '标准',
		'image' => '图像',
		'link' => '链接',
		'audio' => '音频',
		'video' => '视频'
	);

	if (false != ($diff = array_diff_key($fomat, get_post_format_strings())))
	{
		foreach ($diff as $type => $name)
		{
			register_post_type($type, array(
			'public' => true, 'label' => $name
			));
		}
	}
}

function get_dom($str)
{
	$xml = new DOMDocument();
	@$xml->loadHTML($str);

	return new DOMXPath($xml);
}

function xml2array($contents, $get_attributes = 1, $priority = 'tag')
{
	if (!function_exists('xml_parser_create'))
	{
		return array();
	}

	$parser = xml_parser_create('');

	xml_parser_set_option($parser, XML_OPTION_TARGET_ENCODING, "UTF-8");
	xml_parser_set_option($parser, XML_OPTION_CASE_FOLDING, 0);
	xml_parser_set_option($parser, XML_OPTION_SKIP_WHITE, 1);
	xml_parse_into_struct($parser, trim($contents), $xml_values);
	xml_parser_free($parser);
	if (!$xml_values)
	{
		return; //Hmm...
	}

	$xml_array = array();
	$parents = array();
	$opened_tags = array();
	$arr = array();

	$current = &$xml_array;
	$repeated_tag_index = array();

	foreach ($xml_values as $data)
	{
		unset($attributes, $value);
		extract($data);

		$result = array();
		$attributes_data = array();

		if (isset($value))
		{
			if ($priority == 'tag')
			{
				$result = $value;
			}
			else
			{
				$result['value'] = $value;
			}
		}

		if (isset($attributes) and $get_attributes)
		{
			foreach ($attributes as $attr => $val)
			{
				if ($priority == 'tag')
				{
					$attributes_data[$attr] = $val;
				}
				else
				{
					// Set all the attributes in a array called 'attr'
					$result['attr'][$attr] = $val;
				}
			}
		}

		if ($type == "open")
		{
			$parent[$level - 1] = &$current;
			if (!is_array($current) or (!in_array($tag, array_keys($current))))
			{
				$current[$tag] = $result;
				if ($attributes_data)
				{
					$current[$tag . '_attr'] = $attributes_data;
				}

				$repeated_tag_index[$tag . '_' . $level] = 1;
				$current = &$current[$tag];
			}
			else
			{
				if (isset ($current[$tag][0]))
				{
					$current[$tag][$repeated_tag_index[$tag . '_' . $level]] = $result;
					$repeated_tag_index[$tag . '_' . $level]++;
				}
				else
				{
					$current[$tag] = array (
						$current[$tag],
						$result
					);

					$repeated_tag_index[$tag . '_' . $level] = 2;
					if (isset($current[$tag . '_attr']))
					{
						$current[$tag]['0_attr'] = $current[$tag . '_attr'];
						unset($current[$tag . '_attr']);
					}
				}

				$last_item_index = $repeated_tag_index[$tag . '_' . $level] - 1;
				$current = &$current[$tag][$last_item_index];
			}
		}
		elseif ($type == "complete")
		{
			if (!isset($current[$tag]))
			{
				$current[$tag] = $result;
				$repeated_tag_index[$tag . '_' . $level] = 1;
				if ($priority == 'tag' and $attributes_data)
				{
					$current[$tag . '_attr'] = $attributes_data;
				}
			}
			else
			{
				if (isset ($current[$tag][0]) and is_array($current[$tag]))
				{
					$current[$tag][$repeated_tag_index[$tag . '_' . $level]] = $result;
					if ($priority == 'tag' and $get_attributes and $attributes_data)
					{
						$current[$tag][$repeated_tag_index[$tag . '_' . $level] . '_attr'] = $attributes_data;
					}

					$repeated_tag_index[$tag . '_' . $level]++;
				}
				else
				{
					$current[$tag] = array (
						$current[$tag],
						$result
					);

					$repeated_tag_index[$tag . '_' . $level] = 1;
					if ($priority == 'tag' and $get_attributes)
					{
						if (isset ($current[$tag . '_attr']))
						{
							$current[$tag]['0_attr'] = $current[$tag . '_attr'];
							unset ($current[$tag . '_attr']);
						}

						if ($attributes_data)
						{
							$current[$tag][$repeated_tag_index[$tag . '_' . $level] . '_attr'] = $attributes_data;
						}
					}

					// 0 and 1 index is already taken
					$repeated_tag_index[$tag . '_' . $level]++;
				}
			}
		}
		elseif ($type == 'close')
		{
			$current = &$parent[$level -1];
		}
	}

	return ($xml_array);
}