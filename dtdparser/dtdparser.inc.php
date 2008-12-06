<?php

// dtdparser.inc.php

// for parsing DTDs

if (!defined('RESOURCE_DIR')) define('RESOURCE_DIR', '../../www/resources/');
require_once(RESOURCE_DIR . 'includes/elementpattern.inc.php');

class dtdparser
{
	function dtdparser($filename)
	{
		$value = implode('', file('dtd/' . $filename));
		$this->process($value);
	}
	
	function outputtofile($dtdname)
	{
		$filename = "dtd-$dtdname.inc.php";
		$output = '<' . "?php\n\n";
		$output .= "// $filename\n";
		$output .= "// This file was automatically generated from the given DTD file.  For use in\n";
		$output .= "// DTD based filtering routines\n\n";
		
		$output .= 'if (isset($cache[\''.$dtdname.'\'])) trigger_error("DTD '
			.$dtdname.' already included", E_USER_ERROR);';
		
		$output .= '$cache[\''.$dtdname.'\'][\'elements\']=unserialize(\'' .
			str_replace("'", '\\\'', serialize($this->elementpatterns)) . "');";
		$output .= '$cache[\''.$dtdname.'\'][\'xmlentities\']=unserialize(\'' .
			str_replace("'", '\\\'', serialize($this->xmlentities)) . "');";
		$output .= '$cache[\''.$dtdname.'\'][\'attlist\']=unserialize(\'' .
			str_replace("'", '\\\'', serialize($this->attlist)) . "');";
		$output .= '$cache[\''.$dtdname.'\'][\'attreq\']=unserialize(\'' .
			str_replace("'", '\\\'', serialize($this->attreq)) . "');";
		$output .= '$cache[\''.$dtdname.'\'][\'incl\']=unserialize(\'' .
			str_replace("'", '\\\'', serialize($this->incl)) . "');";
		$output .= "\n?" . '>';
		$file = fopen($filename, 'wb');
		fwrite($file, $output);
		fclose($file);
	}
	
	function getobjectcontents($pattern, $init = false)
	// $pattern must be an associative array of
	// 'contents' => array(...), 'type' => 'sel'|'mul'|'seq',
	// 'quant' => '+','1','*','?'
	{						
		if (is_scalar($pattern))
		{
			$canrecycle = false;
			$isrequired = true;
			$type = 'sel';
			$contents = array($pattern);
		}
		else
		{
			$canrecycle = $pattern['quant'] == '+' || $pattern['quant'] == '*';
			$isrequired = $pattern['quant'] == '+' || $pattern['quant'] == '1';
			$type = $pattern['type'];
			$contents = is_scalar($pattern['contents'])
				? array($pattern['contents']) : $pattern['contents'];
			foreach ($contents as $key => $val) if (!is_scalar($val))
				$contents[$key] = $this->getobjectcontents($val, true);
		}
		$obj = new elementpattern($contents, $canrecycle, $isrequired, $type, $init);
		return $obj;
	}
	
	function createobjects()
	{
		$this->elementpatterns = array();
		foreach ($this->elements as $elementname => $pattern)
			$this->elementpatterns[$elementname] = $this->getobjectcontents($pattern);
	}	
	
	// public
	function lowercaseelements()
	// converts element names to lowercase - useful with case-insensitive (HTML)
	// or all-lowercase (XHTML) languages
	{
		// lowercase keys
		$this->elements = array_change_key_case($this->elements, CASE_LOWER);
		$this->attlist = array_change_key_case($this->attlist, CASE_LOWER);
		$this->attreq = array_change_key_case($this->attreq, CASE_LOWER);
		$this->incl = array_change_key_case($this->incl, CASE_LOWER);
		
		$this->process_att_lower($this->attlist);
		$this->process_att_lower($this->attreq);
		$this->process_elementcontent_lower($this->elements);
		$this->process_incl_lower($this->incl);
	}
	
	function process_incl_lower(&$elements)
	{
		foreach ($elements as $id => $inclgroup)
		{
			foreach ($inclgroup as $name => $val)
			{
				$newname = $this->translateelementname($name);
				if ($newname != $name)
				{
					unset($elements[$id][$name]);
					$elements[$id][$newname] = $val;					
				}
			}
		}
	}
	
	function translateelementname($name)
	{
		if ($name == 'EMPTY') return $name;
		if ($name == 'CDATA') return 'CDATA';
		if (preg_match('#^[a-zA-Z:_]#', $name)) return strtolower($name);
		return $name;
	}
	
	// private
	function process_att_lower(&$elements)
	{
		foreach ($elements as $id => $val) $elements[$id] = array_change_key_case($val, CASE_LOWER);
	}
	
	// private
	function process_elementcontent_lower(&$elements)
	{
		foreach ($elements as $id => $val)
		{
			if (is_string($val))
			{
				$elements[$id] = $this->translateelementname($val);
			}
			else
			{
				$contents = &$elements[$id]['contents'];
				if (is_string($contents))
				{
					$contents = $this->translateelementname($contents);
				}
				elseif (is_array($contents)) 
					$this->process_elementcontent_lower($contents);
				else
					exit('Unknown content type');
			}
		}
	}
	
	function process(&$value)
	{
		$i = 0;
		while ($this->gettag($value, $i, strlen($value)))
		{
		}
	}
		
	function gettag(&$value, &$i, $max)
	{
		while ($i < $max && $value[$i] != '<' && $value[$i] != '%')
		{
			$postag = strpos($value, '<', $i);
			$posent = strpos($value, '%', $i);
			if ($postag !== false and $posent !== false) $i = $postag < $posent ? $postag : $posent;
			elseif ($postag === false) $i = $posent;
			elseif ($posent === false) $i = $postag;
			else $i = false;
			if ($i === false) return NULL;
		}
		if ($i >= $max) return null;
		if ($value[$i] == '%')
		{
			$i++;
			$ent = $this->parser_getidentifier($value, $i);
			if (isset($this->externalentities[$ent]))
			{
				$filename = $this->externalentities[$ent];
				if (!preg_match('#^[\w._-]+$#', $filename))
					exit("Unsupported external entity reference to: $filename\nURLs should be for local files only.");
				$data = implode('', file('dtd/' . $filename));
			}
			else
			{
				if (!isset($this->entities[$ent]))
					trigger_error("Unknown entity $ent at position $i", E_USER_ERROR);
				$data = $this->entities[$ent];
			}
			$in = 0;
			$this->process($data, true);
			$i++;
			return true;
		}
		$i++;
		if ($value[$i] == '!')
		{
			$i++;
			if (substr($value, $i, 2) == '--')
			{
				// comment
				$i += 2;
				$i = strpos($value, '--', $i);
				if ($i === false) return NULL;
				$i += 2;
			}
			elseif ($value[$i] == '[')
			{
				// conditional section
				$i++;
				$startrulei = $i;
				$sectionrule = $this->parser_getvalue($value, $i);
				if ($sectionrule != 'INCLUDE' && $sectionrule != 'IGNORE')
				{
					$this->halt_error("INCLUDE or IGNORE expected", substr($value, $startrulei, 128));
				}
				if ($sectionrule == 'INCLUDE')
				{
					// INCLUDE section
					while (!$this->parser_getendconditional($value, $i)
						&& $this->gettag($value, $i, strlen($value)))
					{
					}
					//echo "=========\n" . substr($value, $i, 260) . "\n";
				}
				else
				{
					// IGNORE section
					$nestlevel = 1;
					while ($nestlevel > 0 && $i < $max)
					{
						$posbeg = strpos($value, '<![', $i);
						$posend = strpos($value, ']]>', $i);
						if ($posend === false) $this->halt_error('Cannot find ]]> (end conditional)', substr($value, $i, 128));
						if ($posbeg === false || $posbeg > $posend)
						{
							$nestlevel--;
							$i = $posend + 3;
						}
						else
						{
							$nestlevel++;
							$i = $posbeg + 3;
						}
					}
				}
				return true;
			}
			else
			{
				// tag
				$tagtype = $this->parser_getidentifier($value, $i);
				if ($tagtype == 'ENTITY')
				{
					$val = $this->parser_getidentifier($value, $i);
					if ($val == '%')
					{
						$key = $this->parser_getidentifier($value, $i);
						$val = $this->parser_getvalue($value, $i);
						if ($val == 'PUBLIC')
						{
							$externalid = $this->parser_getvalue($value, $i);
							if ($this->parser_tokentype($value, $i) == 'value')
								$externalvalue = $this->parser_getvalue($value, $i);
							else
								$externalvalue = NULL;
							
							$this->externalentities[$key] = $this->getpublicentity($externalid);
							if (!$this->externalentities[$key])
								$this->externalentities[$key] = $externalvalue;
						}
						else
						{
							if (empty($this->entities[$key]))
								$this->entities[$key] = $val;
						}
					}
					else
					{
						$key = $val;
						$this->parser_getentitytype($value, $i);
						$val = $this->parser_getvalue($value, $i);
						$this->xmlentities[$key] = $val;
					}
				}
				elseif ($tagtype == 'ELEMENT')
				{
					$elementnames = $this->parser_getelementnames($value, $i);
					$this->parser_getminimisationrules($value, $i);
					$val = $this->parser_getrestoftag($value, $i);
					foreach ($elementnames as $key)
					{
						$data = $val;
						$this->parseselector($data, $key);
					}
				}
				elseif ($tagtype == 'ATTLIST')
				{
					$elementnames = $this->parser_getelementnames($value, $i);
					$val = $this->parser_getrestoftag($value, $i);
					foreach ($elementnames as $key)
					{
						$this->parser_parseattributes($key, $val);
					}
				}
				else
				{
					echo "Warning: Unknown tag type: $tagtype\n";
					$this->parser_getrestoftag($value, $i);
					
				}
			}
		}
		while ($value[$i] != '>' and $i < $max)
		{
			$i = strpos($value, '>', $i);
			if ($i === false) return NULL;
		}
		if ($i >= $max) return NULL;
		$i++;
		return true;
	}
	
	function parseselector(&$data, $elementname)
	{
		if (preg_match('/^[a-zA-Z0-9#]+$/', trim($data))) return $this->elements[$elementname] = trim($data);
		// inclusions/exclusions
		$incl = array();
		$excl = array();
		while (preg_match('#(\+|\-)\s*\(([^\)]*)\)\s*$#', $data, $matches, PREG_OFFSET_CAPTURE))
		{
			$data = substr($data, 0, $matches[0][1]);
			$sign = $matches[1][0] == '+' ? true : false;
			$inclelements = preg_split('#\s*(,|&|\|)\s*#', trim($matches[2][0]));
			foreach ($inclelements as $id => $var)
			{
				if ($sign) $incl[$var] = true;
					else $excl[$var] = false;
			}	
		}
		$incl = array_merge($incl, $excl); // $incl contains both inclusions and exclusions now
		if ($incl) $this->incl[$elementname] = $incl;
		$data = preg_replace('#\s+#', '', $data);		 
		$char = $data[0];
		if ($char == '(')
		{
			$data = substr($data, 1);
			$contents = array();
			$quant = '1';
			$typechar = '';
			while ($data !== '' and $data[0] != ')')
			{
				$char = $data[0];
				if ($char == '(') $contents[] = dtdparser::parseselector($data, $elementname);
				else
				{
					if (preg_match('/^([a-zA-Z0-9#]+)([\+\*\?]?)/', $data, $matches))
					{
						if ($matches[2]) $contents[] = array('type' => 'seq', 'quant' => $matches[2], 'contents' => $matches[1]);			
							else $contents[] = $matches[1];
						$data = substr($data, strlen($matches[0]));
					}
					else exit("Unknown inner selector in '$data'");
				}
				if ($data === '') break;
				$char = $data[0];
				if ($char == ')') break;
				if ($typechar != '' and $char != $typechar) exit("Error: unexpected $char, expecting $typechar");
				$typechar = $char;
				$data = substr($data, 1);
			} 
			$data = substr($data, 1);
			$quant = '1';
			$char = $data[0];
			if ($char == '+' or $char == '*' or $char == '?')
			{
				$data = substr($data, 1);
				$quant = "$char";
			}
			$type = $typechar == '|' ? 'sel' : ($typechar == '&' ? 'mul' : 'seq');
			if ($quant == '1' and count($contents) == 1) return $this->elements[$elementname] = current($contents);
			
			return $this->elements[$elementname] = array('type' => $type, 'quant' => $quant, 'contents' => $contents);
		}
		elseif (preg_match('/$([a-zA-Z0-9#]+)([\+\*\?]?)/', $data, $matches))
		{
			$quant = $matches[2] ? $matches[2] : '1';
			$data = substr($data, strlen($matches[0]));
			return $this->elements[$elementname] = array('type' => 'seq', 'quant' => $quant, 'contents' => $matches[1]);			
		}
		exit("Unknown selector in '$data'");
	}
	
	function parser_parseattributes($element, $data)
	{
		$i = 0;
		// strip comments
		$data = preg_replace('#--([^-]|-[^-])*--#', ' ', $data);
		while ($i < strlen($data))
		{
 			$attrname = $this->parser_getidentifier($data, $i);
 			$value = $this->parser_getattributevalue($data, $i);
 			if ($value[0] == '(')
 			{
 				$value = explode('|', preg_replace('/[\s\(\)]+/', '', $value));
 			}
 			$requirement = $this->parser_getvalue($data, $i);
 			$fixedval = '';
			if ($requirement == '#FIXED')
				$fixedval = $this->parser_getvalue($data, $i);
 			
			switch ($requirement)
			{
				case '#FIXED':
					$this->attlist[$element][$attrname] = array($fixedval);
					break;
				case '#REQUIRED':
					$this->attlist[$element][$attrname] = $value;
					$this->attreq[$element][$attrname] = '';
					break;
				case '#IMPLIED':
				  $this->attlist[$element][$attrname] = $value;
					break;
				default:
					$this->attlist[$element][$attrname] = $value;			
			}
		}
	}
	
	function parser_getendconditional(&$value, &$i)
	{
		if ($i > strlen($value)) return NULL;
		$endpos = strpos($value, ']]>', $i);
		if ($endpos === false) $this->halt_error('Cannot find ]]> (end conditional)', substr($value, $i, 128));
		$postag = strpos($value, '<', $i);
		$posent = strpos($value, '%', $i);
		if (($postag === false || $postag > $endpos)
			&& ($posent === false || $posent > $endpos))
		{
			$i = $endpos + 3;
			return true;
		}
		return NULL;		
	}
	
	function parser_getexpression(&$value, &$i)
	{
		if ($i > strlen($value)) return NULL;
		$char = $value[$i];
		if ($char == '(')
		{
			$i++;
			// temporary - halt on this - debug
			$this->halt_error('Unexpected expression bracket', substr($value, $i, 128));
			return $parser_getexpression($value, $i);
		}
		
	}
	
	function parser_getrestoftag(&$value, &$i)
	{
		$result = preg_match('/^(([^">]("[^"]+")*(<[^">]+>)*)+)\s*/', substr($value, $i, 32768), $matches);
		if (!$result) $this->halt_error('Restoftag expected', substr($value, $i, 128));
		$i += strlen($matches[0]);
		$data = $matches[1];
		// strip comments
		$data = preg_replace('#--([^-]|-[^-])*--#', ' ', $data);
		$data = $this->replaceentities($data);
		return $data;	
	}
	
	function parser_getattributevalue(&$value, &$i)
	{
		$result = preg_match('/^\s*(\([^)]+\))\s*/', substr($value, $i, 32768), $matches);
		if (!$result) $result = preg_match('/^\s*([^\s>]+)\s*/', substr($value, $i, 32768), $matches);
		if (!$result) $this->halt_error('Attribute value expected', substr($value, $i, 128));
		$i += strlen($matches[0]);
		$data = $matches[1];
		$data = $this->replaceentities($data);
		return preg_replace('/\s+/', '', $data);	
	}
	
	function parser_getelementnames(&$value, &$i)
	{
		$result = preg_match('/^\s*/', substr($value, $i, 256), $matches);
		if ($result) $i += strlen($matches[0]);
		if ($value[$i] == '(')
		{
			$checkdata = substr($value, $i, 32768);
			$result = preg_match('#^\s*(\((([^\)])*)\))\s*#', $checkdata, $matches);
			// sample: '   (string in brackets)   '
			if (!$result) $this->halt_error('String in brackets expected', substr($value, $i, 128));
			$i += strlen($matches[0]);
			$data = $matches[2];
			$data = $this->replaceentities($data);
			// $data is now a list of elements separated by | and with whitespace
			$elementnames = preg_split('#\s*(\&|,|\|)\s*#', trim($data));
		}
		else
		{
			$elementnames = array($this->parser_getvalue($value, $i));
		}
		return $elementnames;
	}
	
	function parser_getwhitespace(&$value, &$i)
	{
		$l = strspn($value, " \t\n\r\f", $i);
		if ($l) $i += $l;
		return $l ? true : NULL;
	}
	
	function parser_getidentifier(&$value, &$i)
	{
		//$this->parser_getcomments($value, $i);
		$result = preg_match('/^\s*([a-zA-Z0-9_:%\.-]+)\s*/', substr($value, $i, 256), $matches);
		if (!$result)
		{
			$this->halt_error('Identifier expected', substr($value, $i, 128));
		}
		$i += strlen($matches[0]);
		return $matches[1];
	}
	
	function parser_getminimisationrules(&$value, &$i)
	// these are used in SGML but not XML DTDs.  returns null if they're not found
	// but you can ignore that null if you are using XML
	{
		$checkdata = substr($value, $i, 512);
		$result = preg_match('#^\s*(-|O)\s*(-|O)\s*#', $checkdata, $matches);
		if (!$result) return NULL;
		$i += strlen($matches[0]);
		$result = array(
			'start' => $matches[1] == '-',
			'end' => $matches[2] == '-'
			);
		return $result;
	}
	
	function parser_getentitytype(&$value, &$i)
	// used in SGML but not XML.  returns null if it's not found but you can
	// ignore that null if you are using XML
	{
		$checkdata = substr($value, $i, 32768);
		$result = preg_match('/^\s*(CDATA)\s*/', $checkdata, $matches);
		if (!$result) return NULL;
		$i += strlen($matches[0]);
		return $matches[1];
	}
	
	function parser_getvalue(&$value, &$i)
	{
		$checkdata = substr($value, $i, 32768);
	
	
		$result = preg_match('/^\s*(\"(([^\"]|\\\")*)\")\s*/', $checkdata, $matches);
			// sample: '   "string in quotes"   '
		if (!$result) $result = preg_match('/^\s*(["\'])((?:\\\\1|.)*)\\1\s*/', $checkdata, $matches);
			// sample: "   'string in single quotes'   "
		if (!$result) $result = preg_match('/^\s*(([^>\[\]\s]+))\s*/', $checkdata, $matches);
			// sample: '   unbrokenstring   '
		if (!$result) $this->halt_error('Value expected', substr($value, $i, 128));
		$i += strlen($matches[0]);
		$data = $matches[2];
		$data = $this->replaceentities($data);
		return $data;	
	}
	
	function replaceentities($data)
	{
		while (preg_match('/\%([^\;\s|]+)(\;|\b)/', $data, $matches))
		{
			if (!isset($this->entities[$matches[1]]))
				$this->halt_error('Unknown entity: ' . $matches[1]);
			$data = str_replace($matches[0], $this->entities[$matches[1]], $data);
		}
		return $data;
	}
	
	function parser_tokentype(&$value, &$i)
	{
		if ($value[$i] == '"' or $value[$i] == "'") return 'value';
		if ($value[$i] == '>') return 'endoftag';
		else return 'identifier';
	}
	
	function halt_error($message, $near = NULL, $element = NULL)
	{
		$msg = "Error: $message";
		if ($near !== NULL) $msg .= " near \"$near\""; 
		if ($element) $msg .= " in element \"$element\"";
		exit($msg);
	}
	
	function getpublicentity($externalid)
	{
		$knownpublicentities = array(
			'-//W3C//DTD HTML 4.01 Transitional//EN' => 'html4-loose.dtd',
			'-//W3C//DTD HTML 4.01//EN' => 'html4-strict.dtd',
			'-//W3C//DTD HTML 4.01 Frameset//EN' => 'html4-frameset.dtd',
			'-//W3C//DTD XHTML 1.1//EN' => 'xhtml11.dtd',
			'-//W3C//ELEMENTS XHTML Inline Style 1.0//EN' => 'xhtml-inlstyle-1.mod',
			'-//W3C//ENTITIES XHTML 1.1 Document Model 1.0//EN' => 'xhtml11-model-1.mod',
			'-//W3C//ENTITIES XHTML Modular Framework 1.0//EN' => 'xhtml-framework-1.mod',
			'-//W3C//ELEMENTS XHTML Text 1.0//EN' => 'xhtml-text-1.mod',
			'-//W3C//ELEMENTS XHTML Hypertext 1.0//EN' => 'xhtml-hypertext-1.mod',
			'-//W3C//ELEMENTS XHTML Lists 1.0//EN' => 'xhtml-list-1.mod',
			'-//W3C//ELEMENTS XHTML Editing Elements 1.0//EN' => 'xhtml-edit-1.mod',
			'-//W3C//ELEMENTS XHTML BIDI Override Element 1.0//EN' => 'xhtml-bdo-1.mod',
			'-//W3C//ELEMENTS XHTML Ruby 1.0//EN' => 'xhtml-ruby-1.mod',
			'-//W3C//ELEMENTS XHTML Presentation 1.0//EN' => 'xhtml-pres-1.mod',
			'-//W3C//ELEMENTS XHTML Link Element 1.0//EN' => 'xhtml-link-1.mod',
			'-//W3C//ELEMENTS XHTML Metainformation 1.0//EN' => 'xhtml-meta-1.mod',
			'-//W3C//ELEMENTS XHTML Base Element 1.0//EN' => 'xhtml-base-1.mod',
			'-//W3C//ELEMENTS XHTML Scripting 1.0//EN' => 'xhtml-script-1.mod',
			'-//W3C//ELEMENTS XHTML Style Sheets 1.0//EN' => 'xhtml-style-1.mod',
			'-//W3C//ELEMENTS XHTML Images 1.0//EN' => 'xhtml-image-1.mod',
			'-//W3C//ELEMENTS XHTML Client-side Image Maps 1.0//EN' => 'xhtml-csismap-1.mod',
			'-//W3C//ELEMENTS XHTML Server-side Image Maps 1.0//EN' => 'xhtml-ssismap-1.mod',
			'-//W3C//ELEMENTS XHTML Param Element 1.0//EN' => 'xhtml-param-1.mod',
			'-//W3C//ELEMENTS XHTML Embedded Object 1.0//EN' => 'xhtml-object-1.mod',
			'-//W3C//ELEMENTS XHTML Tables 1.0//EN' => 'xhtml-table-1.mod',
			'-//W3C//ELEMENTS XHTML Forms 1.0//EN' => 'xhtml-form-1.mod',
			'-//W3C//ELEMENTS XHTML Target 1.0//EN' => 'xhtml-target-1.mod',
			'-//W3C//ELEMENTS XHTML Legacy Markup 1.0//EN' => 'xhtml-legacy-1.mod',
			'-//W3C//ELEMENTS XHTML Document Structure 1.0//EN' => 'xhtml-struct-1.mod',
			'-//W3C//ENTITIES XHTML Basic 1.1 Document Model 1.0//EN' => 'xhtml-basic11-model-1.mod',
			'-//W3C//ELEMENTS XHTML Basic Tables 1.0//EN' => 'xhtml-basic-table-1.mod',
			'-//W3C//ELEMENTS XHTML Inputmode 1.0//EN' => 'xhtml-inputmode-1.mod',
			'-//W3C//DTD HTML 3.2 Final//EN' => 'html32.dtd',
			'ISO 8879-1986//ENTITIES Added Latin 1//EN//HTML' => 'entitieslatin1.ent',
			'-//W3C//ENTITIES XHTML Basic 1.0 Document Model 1.0//EN' => 'xhtml-basic10-model-1.mod',
			'-//W3C//ELEMENTS XHTML Basic Forms 1.0//EN' => 'xhtml-basic-form-1.mod',
			'-//W3C//ENTITIES Full Latin 1//EN//HTML' => 'htmlfulllat1.ent',
			'-//W3C//ENTITIES Symbolic//EN//HTML' => 'htmlentitiessymbolic.ent',
			'-//W3C//ENTITIES Special//EN//HTML' => 'htmlentitiesspecial.ent',
			);
		
		if (!isset($knownpublicentities[$externalid]))
			return null;
	
		return $knownpublicentities[$externalid];
	}
	
	//static
	function rebuildall()
	{
		$dtds = array(
			'html', // html 2.0
			'html32',
			'html4-strict',
			'html4-loose',
			'html4-frameset',
			'15445', // iso 15445:2000 (ISO HTML, a subset of HTML 4)
			'xhtml1-strict',
			'xhtml1-transitional',
			'xhtml1-frameset',
			'xhtml-basic10',
			'xhtml11',
			'xhtml-basic11'
			);
			
		foreach ($dtds as $dtdname)
		{
			echo "Parsing $dtdname...\n";
			$dtdparser = new dtdparser("$dtdname.dtd");
			$dtdparser->lowercaseelements();
			$dtdparser->createobjects();
			$dtdparser->outputtofile($dtdname);
		}	
		echo "All parsing complete.\n";
	}
	
	var $entities = array();
	var $xmlentities = array();
	var $externalentities = array();
	var $elements = array();
	var $attlist = array();
	var $attreq = array();
	var $incl = array();
}

dtdparser::rebuildall();

?>