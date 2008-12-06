<?php

// htmlfilter.inc.php

// for filtering html (for instance, to make it compliant)

// this filters it in a way that converts it to well formed even if it's not well
// formed.  Instead of breaking, it filters out that which is not valid, so you
// will always end up with something valid.  

// this does not currently support checking of valid characters in the given
// character set.  This should be done prior to using this filter.

class htmlfilter_element
{
	function htmlfilter_element($element, $data = NULL)
	{
		$this->element = $element;
		if ($data) $this->data = $data;
	} 
	var $element;
	var $open = false;
	var $close = false;
	var $data = NULL; 
	var $attributes = array();
	var $contents = array();
}

define('PATTERNMATCH_OK', 1);
define('PATTERNMATCH_LASTMATCH', 2);
define('PATTERNMATCH_TOOEARLY', -1);
define('PATTERNMATCH_TOOLATE', -2);
define('PATTERNMATCH_BADMATCH', -3);
define('PATTERNMATCH_NOMATCH', -4);

class htmlfilter_elementpattern
{
	function htmlfilter_elementpattern($pattern)
	{
		if (is_array($pattern) and !is_array($pattern['contents'])) $pattern['contents'] = array($pattern['contents']);
		if (is_array($pattern)) foreach ($pattern['contents'] as $key => $val)
		{
			if (is_array($val)) $pattern['contents'][$key] = new htmlfilter_elementpattern($val);
		}
		$this->pattern = $pattern;
		$this->original = $pattern;
	}
	
	function required()
	{
		if ($this->pattern == 'EMPTY' or $this->pattern == '#PCDATA') return NULL;
		if (!is_array($this->pattern)) return $this->pattern;
		if (!$this->pattern['quant']) return NULL;
		if ($this->pattern['contents'] == $this->original['contents'] and ($this->pattern['quant'] == '*' or $this->pattern['quant'] == '?'))
		{
			return NULL;
		}
		$temp = $this->pattern['contents'];
		do
		{
			$el = current($this->pattern['contents']);
			if (is_object($el)) $required = $this->pattern['contents'][key($this->pattern['contents'])]->required();
      	else $required = $el;
      if (!$required) array_shift($this->pattern['contents']);
 		} while (!$required and count($this->pattern['contents']));
		$this->pattern['contents'] = $temp;
		if ($required == '#PCDATA') return NULL;
		return $required;
	}
	
	function trymatch($element, $proceed = true)
	{
		if ($element == '_comment') return PATTERNMATCH_OK;
		if ($element == '#CDATA') $element = '#PCDATA';
		if (!is_array($this->pattern))
		{
			if ($this->pattern != $element) return PATTERNMATCH_BADMATCH;
			else
			{
				if ($proceed) $this->pattern = array('type' => 'seq', 'quant' => NULL, 'contents' => array());
				return PATTERNMATCH_LASTMATCH;
			}
		}
		if (!$this->pattern['quant']) return PATTERNMATCH_NOMATCH;
		if ($this->pattern['type'] == 'sel')
		{
			$match = PATTERNMATCH_BADMATCH;
			if (in_array($element, $this->pattern['contents'])) $match = PATTERNMATCH_LASTMATCH;
			else 
			{
				foreach ($this->pattern['contents'] as $key => $val)
				{
					if (!is_object($val)) continue;
					$match = $this->pattern['contents'][$key]->trymatch($element, $proceed);
					if ($match > 0)
					{
						if ($proceed and ($this->pattern['quant'] == '1' or $this->pattern['quant'] == '?'))
						{
							$this->pattern['contents'] = array($key => $this->pattern['contents'][$key]);
						}
						break;
					}
				}
			}
			if ($this->pattern['quant'] == '*' or $this->pattern['quant'] == '?')
			{
				if ($match <= 0) return $this->pattern['quant'] == '*' ? PATTERNMATCH_NOMATCH : PATTERNMATCH_LASTMATCH;
			}
			if ($match > 0)
			{
				if ($proceed and $match == PATTERNMATCH_LASTMATCH and $this->pattern['quant'] == '+') $this->pattern['quant'] = '*';
				elseif ($proceed and $match == PATTERNMATCH_LASTMATCH and ($this->pattern['quant'] == '?' or $this->pattern['quant'] == '1'))
				{
					$this->pattern['quant'] = NULL;
					return PATTERNMATCH_LASTMATCH;
				}
				return PATTERNMATCH_OK;
			}
			return $match;
		}
		if ($this->pattern['type'] == 'seq')
		{               
			if (!$proceed) $temp = $this->pattern;
			do
			{
				$el = reset($this->pattern['contents']);
				$match = false;
				if (is_object($el)) $match = $this->pattern['contents'][key($this->pattern['contents'])]->trymatch($element, $proceed);
				else $match = ($el == $element) ? PATTERNMATCH_OK : PATTERNMATCH_BADMATCH;
				if ($match != PATTERNMATCH_OK and $match != PATTERNMATCH_NOMATCH and $this->pattern == $this->original and ($this->pattern['quant'] == '*' or $this->pattern['quant'] == '?'))
				{
					if (!$proceed) $this->pattern = $temp;
					return PATTERNMATCH_NOMATCH;
				}
				$continue = false;
				if ($match == PATTERNMATCH_NOMATCH)
				{
					array_shift($this->pattern['contents']);
					$continue = true;
				}
				else
				{
					if ($proceed and $match == PATTERNMATCH_OK and !is_object($el))
					{
						array_shift($this->pattern['contents']);
						if (empty($this->pattern['contents']))
						{
							if ($proceed and $this->pattern['quant'] == '+') $this->pattern['quant'] = '*';
							elseif ($proceed and $this->pattern['quant'] == '?' or $this->pattern['quant'] == '1')
							{
								$this->pattern['quant'] = NULL;
								$match = PATTERNMATCH_LASTMATCH;
							}
							$this->pattern['contents'] = $this->original['contents'];
						}
					}
					if (!$proceed) $this->pattern = $temp;
					if ($match < 0 and $element != '#PCDATA')
					{
						// try lookahead
						$found = false;
						foreach ($this->pattern['contents'] as $key => $val)
						{
							if (is_object($val)) $found = ($this->pattern['contents'][$key]->trymatch($element, false) > 0);
								else $found = ($val == $element);
							if ($found) break;
						}
						if ($found) return PATTERNMATCH_TOOEARLY;
						// try lookbehind
						foreach ($this->original['contents'] as $key => $val)
						{
							if (is_object($val)) $found = ($this->original['contents'][$key]->trymatch($element, false) > 0);
								else $found = ($val == $element);
							if ($found) break;
						}
						if ($found) return PATTERNMATCH_TOOLATE;
					}
					return $match;
				}
			} while ($continue); 
		}
		
		return PATTERNMATCH_BADMATCH;
	}
	
	var $pattern;
	var $original;
}

class htmlfilter
{
	function htmlfilter($value = NULL)
	{
		$this->value = $value;
		require(RESOURCE_DIR . 'includes/dtd-xhtml1-strict.php');
	}
	
	function setelementhandler($element, $callback)
	{
		$this->handlers[$element] = $callback;
	}
	
	function setwhitespace($keepwhitespace)
	{
		$this->keepwhitespace = $keepwhitespace;
	}
	
	function &getrootelement()
	{
		if ($this->rootelement !== NULL) return $this->rootelement;
		$this->rootelement = &$this->getelement();
		return $this->rootelement; 
	}
	
	function gethtml($skiproot = false, $element = NULL)
	{
		if ($element === NULL) $element = $this->getrootelement();
		if ($element->element == '_invalid') return '';
	  $body = (isset($element->data)) ? $element->data : '';
	  if ($element->element == '#PCDATA') return $body;
	  if ($element->element == '#CDATA') return "<![CDATA[$body]]>";
		if ($element->element == '_comment') return $body ? "<!$body>" : '';
		if (isset($element->contents)) foreach ($element->contents as $inner)
	  {
	  	$body .= $this->gethtml(false, $inner);
	  }
	  $attributes = '';
	  if (!empty($element->attributes)) foreach ($element->attributes as $key => $val)
	  {
	  	$attributes .= " $key=\"$val\"";
	  }
	  if ($skiproot) return "$body";
	  if (!strlen($body) and $this->autoclose($element)) return "<$element->element{$attributes} />";
	  return "<$element->element{$attributes}>$body</$element->element>";
	}
	
	function gettext()
	{
		$element = &$this->getrootelement();
		if (empty($element->contents)) return '';
		$blocks = array();
		foreach ($element->contents as $inner)
		{
			if (empty($inner->attributes) and ($inner->element == 'ul' or $inner->element == 'ol'))
			{
				$char = '*';
				if ($inner->element == 'ol') $char = '#';
				$li = array();
				if (!empty($inner->contents)) foreach ($inner->contents as $item)
				{
					$skip = (empty($item->attributes) and $item->element == 'li') ? true : false;
					$data = $this->gethtml($skip, $item);
					$data = str_replace("\r", '', $data);
					$data = str_replace("\n", ' ', $data);
					$li[] = "$char $data";					
				}
				if (!empty($li)) $blocks[] = implode("\n", $li);
				continue;
			}
			
			if (empty($inner->attributes) and in_array($inner->element, array('h1', 'h2', 'h3', 'h4', 'h5', 'h6')))
			{
				$level = $inner->element[1];
				$prefix = str_repeat('!', $level);
				$data = $this->gethtml(true, $inner);
				$data = str_replace("\r\n", "\n", $data);
				$data = str_replace("\r", "\n", $data);
				$data = str_replace("\n", ' ', $data);
				$data = str_replace("&amp;", '&', $data);
				$data = str_replace("&quot;", '"', $data);
				$data = preg_replace('/\s*<br \/>\s*/', "\n", $data);
				$data = trim($data);
				if (!$data) continue;
				if ($data[strlen($data) - 1] == "\n") $data = substr($data, 0, strlen($data) - 1) . '<br />';
				if ($data[0] == "\n") $data = '<br />' . substr($data, 1);
				if ($data) $blocks[] = "$prefix $data";
				continue;
			}
			
			$skip = (empty($element->attributes) and $inner->element == 'p') ? true : false; 

			$data = $this->gethtml($skip, $inner);
			if ($skip and !preg_match('/^\s*[^\!\*\#\<]/', $data))
			{
				$skip = false;
				$data = $this->gethtml(false, $inner);
			}
			$data = str_replace("\r\n", "\n", $data);
			$data = str_replace("\r", "\n", $data);
			$data = str_replace("\n", ' ', $data);
			if ($skip) $data = str_replace("&amp;", '&', $data);
			if ($skip) $data = str_replace("&quot;", '"', $data);
			if ($skip) $data = preg_replace('/\s*<br \/>\s*/', "\n", $data);
			$data = trim($data);
			if (!$data) continue;
			if ($data[strlen($data) - 1] == "\n") $data = substr($data, 0, strlen($data) - 1) . '<br />';
			if ($data[0] == "\n") $data = '<br />' . substr($data, 1);
			if ($data) $blocks[] = $data;
		}
		return implode("\n\n", $blocks);
	}
	
	function sethtml($value)
	{
		$this->value = $value;
		$this->rootelement = NULL;
		$this->i = 0; 
		$this->currenttag = NULL;
	}
	
	function settext($text)
	{
	  $text = str_replace("\r\n", "\n", $text);
		$text = str_replace("\r", "\n", $text);
		$blocks = explode("\n\n", $text);
		$data = '';
		foreach ($blocks as $key => $block)
		{
			if (!strlen($block)) continue;
			$result = preg_match('/^(\s*)(.)/', $block, $matches);
			if (!$result) continue;
			$char = $matches[2];
			$block = substr($block, strlen($matches[1]));
			switch($char)
			{
				case '<':
					$data .= $block;
					break;
				case '*':
					$block = preg_replace('/^\*\s*/', '', $block);
					$data .= '<ul><li>' . preg_replace("/\n\s*\*\s*/", '</li><li>', $block) . '</li></ul>';
					break;
				case '#':
					$block = preg_replace('/^\#\s*/', '', $block);
					$data .= '<ol><li>' . preg_replace("/\n\s*\#\s*/", '</li><li>', $block) . '</li></ol>';
					break;
				case '!':
					$result = preg_match('/^(\!+)\s*/', $block, $matches);
					$level = strlen($matches[1]);
					if ($level > 6) $level = 6;
					$in = substr($block, strlen($matches[0]));
					$in = str_replace("\n", '<br />', $in);
					$data .= "<h$level>$in</h$level>";
					break;
				default:
					$data .= '<p>' . str_replace("\n", '<br />', $block) . '</p>';
			}
		}
		$this->value = "<body>$data</body>";
		$this->rootelement = NULL;
		$this->i = 0;
		$this->currenttag = NULL; 
	}
	
	function fixentities($value)
	// fixes up any broken entities which would show up as problems on an XHTML validator
	{
		$value = str_replace(array('<', '>', '"'), array('&lt;', '&gt;', '&quot;'), $value);
		$value = preg_replace('/&(?!([a-zA-Z\#][a-zA-Z0-9]*){1,16}\;)/S', '&amp;', $value);
		if (preg_match_all('/&([a-zA-Z\#][a-zA-Z0-9]*)\;/', $value, $matches))
		{
			foreach ($matches[1] as $key => $val)
			{
				if ($val[0] == '#')
				{
					if (!preg_match('/^\#(x[0-9a-fA-F]{1,6}|[0-9]{1,6})$/', $val)) $value = str_replace($matches[0][$key], '', $value);
				}
				else
				{
					if (!isset($this->xmlentities[$val])) $value = str_replace($matches[0][$key], '', $value);
				}
			}
		}
		return $value;
	}
	
	function &gettag($advance = true)
	{
		$tag = &$this->currenttag;
		if ($advance) $this->currenttag = NULL;
		if ($tag) return $tag;
		if (strlen($this->value) <= $this->i) return NULL;
		$i = $this->i;
		$char = $this->value[$i];
		if ($char != '<' or !preg_match('/^[a-zA-Z\/\!]$/S', $this->value[$i + 1]))
		// string literal
		{
			$end = strpos($this->value, '<', $i + 1);
			if ($end === false) $end = strlen($this->value);
			$strval = $this->fixentities(substr($this->value, $i, $end - $i));
			$i = $end;
			$tag = &new htmlfilter_element('#PCDATA', $strval);
			if ($advance)	$this->i = $i;
				else $this->currenttag = $tag;
			return $tag;
		}
		$i++;
		$char = $this->value[$i];
		if ($char == '!')
		// comment or CDATA
		{
			$i++;
			$data = '';
			$tagtype = '_comment';
			if (substr($this->value, $i, 2) == '--')
			{
				$i += 2;
				$end = strpos($this->value, '--', $i);
				if ($end === false) $end = strlen($this->value);
				$data .= '--' . substr($this->value, $i, $end - $i) . '--';
				$i = $end + 2;
			}
			elseif (substr($this->value, $i, 7) == '[CDATA[')
			{
				$tagtype = '#CDATA';
				$end = strpos($this->value, ']]>', $i);
				if ($end === false) $end = strlen($this->value);
				$data = substr($this->value, $i + 7, $end - $i - 7);
				$i = $end + 2;
			}
			$end = strpos($this->value, '>', $i);
			if ($end === false) $end = strlen($this->value);
			$i = $end + 1;
			$tag = &new htmlfilter_element($tagtype, $data);
			if ($advance)	$this->i = $i;
				else $this->currenttag = $tag;
			return $tag;
		}
		$result = preg_match('/^(\/?)([a-zA-Z][a-zA-Z0-9_:-]*)\s*/S', substr($this->value, $i, 24), $matches);
		$element = new htmlfilter_element(strtolower($matches[2]));
		$i += strlen($matches[2]);
		$element->close = !empty($matches[1]);
		$element->open = empty($matches[1]);
		while (preg_match('/^\s*([a-zA-Z][a-zA-Z0-9_:-]{0,63})\s*(=?)\s*/S', substr($this->value, $i, 24), $matches))
		{
			$i += strlen($matches[0]);
			$attr = strtolower($matches[1]);
			$value = $attr;
			if (strlen($matches[2]))
			{
				$char = $this->value[$i];
				if ($char == '"' or $char == "'")
				{
					$i++;
					$end = strpos($this->value, "$char", $i);
					if ($end === false) $end = strlen($this->value);
					$value = substr($this->value, $i, $end - $i);
					$i = $end + 1;
				}
				else
				{
					$result = preg_match('/^[^\s>]*/', substr($this->value, $i, 64), $matches);
					$value = $matches[0];
					$i += strlen($value); 
				}
				$value = $this->fixentities($value);
			}
			$element->attributes[$attr] = $value;
		}
		$result = preg_match('/^\s+/', substr($this->value, $i, 48), $matches);
		if ($result) $i += strlen($matches[0]);
		if ($i >= strlen($this->value)) return $element;
		if ($this->value[$i] == '/') $element->close = true;
		$end = strpos(substr($this->value, $i), '>');
		$end =($end === false) ? $end = strlen($this->value) - $i : $end + 1;
		$i += $end;
		if ($advance)	$this->i = $i;
			else $this->currenttag = $element;
		return $element;
	}
	
	function denychild($parent, $child)
	{
		if (in_array($parent, array('p', 'li', 'option', 'td')) and $parent == $child) return true;
		if (in_array($parent, array('dt', 'dd')) and in_array($child, array('dt', 'dd'))) return true;
		if (in_array($parent, array('head', 'body')) and in_array($child, array('head', 'body'))) return true;
		return false;
	}
	
	function &getelement()
	{
		static $tagstack = array();
		if (count($tagstack) and in_array(end($tagstack), array('p', 'li', 'option', 'dd', 'dt', 'head', 'body', 'td')))
		{                                   
			$element = &$this->gettag(false);
			if ($element->open and $this->denychild(end($tagstack), $element->element)) return NULL;
		}
		$element = &$this->gettag();
		if ($element->element == '_invalid' or ($element->close and !$element->open and in_array($element->element, $tagstack))) return NULL;
		if ($element->close and !$element->open) return new htmlfilter_element('_invalid');
		if (!$element->open or $element->close or $this->autoclose($element) or $element->element == '#PCDATA') return $element;	
		$tagstack[] = $element->element;
		if (count($tagstack) > 160) exit('Error: too much recursion');
		while ($inner = &$this->getelement())
		{
			$element->contents[] = &$inner;
		}
		array_pop($tagstack);
		return $element;
	}
	
	function filter_xhtml()
	{
		$element = &$this->getrootelement();
		$this->dofilterxhtml($this->rootelement);
	}
	
	function dofilterxhtml(&$element)
	{
		// exit quickly if this element is a terminal
		if ($element->element == '#PCDATA' or $element->element == '_comment' or $element->element == '_invalid') return;
		
		// check attribute validity
	  foreach ($element->attributes as $key => $val)
	  {
	  	if (!isset($this->attlist[$element->element][$key]))
	  	{
	  		unset($element->attributes[$key]);
	  	}
	  	else
	  	{
	  		$pattern = $this->attlist[$element->element][$key];
	  		if (!$this->checkattribute($pattern, $val)) unset($element->attributes[$key]);
	  	}
	  }
	  
	  // check required attributes
	  if (!empty($this->attreq[$element->element])) foreach ($this->attreq[$element->element] as $key => $val)
	  {
	  	if (!isset($element->attributes[$key]))
			{
				$element->attributes[$key] = $val;
				if (!$val and isset($this->attlist[$element->element][$key]))
					$this->fixattribute($this->attlist[$element->element][$key], $element->attributes[$key]);
			}
	  }
	  
	  // check sub-elements
		$filterbuffer = $element->contents;
	  $outputbuffer = array();
	  $waitbuffer = array();
	  $patt = (isset($this->elements[$element->element])) ? $this->elements[$element->element] : 'EMPTY';
		$pattern = new htmlfilter_elementpattern($patt);
		$tries = (count($element->contents) * 2) + 2;
		$rejigs = 2;
		while (count($filterbuffer) and ($tries-- > 0))
	  {
	  	$child = array_shift($filterbuffer);
	  	$match = $pattern->trymatch($child->element);
	  	if ($match > 0)
	  	{
	  		array_push($outputbuffer, $child);
	  		if (!empty($waitbuffer)) $filterbuffer = array_merge($waitbuffer, $filterbuffer);
	  	}
	  	else
	  	{
	  		// deal with whitespace?
  			if ($child->element == '#PCDATA' and !trim($child->data))
  			{
  				if ($this->keepwhitespace) array_push($outputbuffer, $child);
  				continue;
  			}
				
				if ($match == PATTERNMATCH_TOOEARLY)
	  		{
	  			array_push($waitbuffer, $child);
	  		}
	  		else
	  		{
 	  			if ($match == PATTERNMATCH_TOOLATE and $rejigs)
	  			// can re-jig
					{
  					$rejigs--;
						$rejiggables = array($child);
						foreach ($filterbuffer as $newkey => $newchild)
						{
							if ($pattern->trymatch($newchild->element, false) == PATTERNMATCH_TOOLATE)
							{
								array_push($rejiggables, $newchild);
								unset($filterbuffer[$newkey]);
							}
						}
						$tries += count($outputbuffer);
						$filterbuffer = array_merge($rejiggables, $outputbuffer, $filterbuffer);
						$outputbuffer = array();
						$pattern = new htmlfilter_elementpattern($patt);
						continue;
					} 
	  			
					// is stuff outside the body tag?
	  			if ($element->element == 'html')
	  			{
						if (count($outputbuffer) == 2)
						{
							end($outputbuffer);
							array_push($outputbuffer[key($outputbuffer)]->contents, $child);
						}
						else
						{
							$newelement = new htmlfilter_element('body');
							$newelement->contents[] = $child;
							array_push($filterbuffer, $newelement);
						}
						continue;
	  			}
	  			
	  			// can hint?
	  			$hint = NULL;
					if ($this->quickelementcheck('p', $child->element) and $pattern->trymatch('p', false) > 0) $hint = 'p';
	  			elseif ($this->quickelementcheck('li', $child->element) and $pattern->trymatch('li', false) > 0) $hint = 'li';
	  			elseif ($child->element == 'table' and $pattern->trymatch('ins', false) > 0) $hint = 'ins';
	  			if ($hint)
					{
	  				$goahead = true;
	  				$hintbuffer = array($child);
	  				$next = reset($filterbuffer);
	  				while ($pattern->trymatch($next->element, false) < 0 and $this->quickelementcheck($hint, $next->element))
	  				{
	  					array_push($hintbuffer, $next);
	  					array_shift($filterbuffer);
	  					if (!$goahead) $goahead = ($next->element != '#PCDATA' or trim($next->data));
	  					$next = reset($filterbuffer);
	  				}
	  				if ($goahead)
	  				{
	  					$newelement = new htmlfilter_element($hint);
							$newelement->contents = $hintbuffer;
							array_unshift($filterbuffer, $newelement);
	  				}
					}
					else
					{
						if (!empty($waitbuffer))
						{
							$el = $pattern->required();
							if ($el)
							{
								$pattern->trymatch($el);
								array_push($outputbuffer, new htmlfilter_element($el));
							}
						}
					}
					// strip
					if (!$hint and !empty($child->contents)) 
					{
						$filterbuffer = array_merge($child->contents, $filterbuffer);
						$tries += (count($child->contents) * 2);
					}
	  		}
	  	}
	  	if (empty($filterbuffer))
	  	{
				if ($el = $pattern->required())
				{
					$pattern->trymatch($el);
					array_push($outputbuffer, new htmlfilter_element($el));
				}
				$filterbuffer = $waitbuffer;
	  		$waitbuffer = array();
	  	}
	  }
	  // check required sub-elements
		while ($el = $pattern->required())
		{
			$pattern->trymatch($el);
			array_push($outputbuffer, new htmlfilter_element($el));
		}
				
		$element->contents = $outputbuffer;

	  // proceed to children
	  $keys = array_keys($element->contents);
	  foreach ($keys as $key) $this->dofilterxhtml($element->contents[$key]);
	}
	
	function quickelementcheck($hinttype, $element)
	{
		if ($element == '#CDATA') $element = '#PCDATA';
		$patt = (isset($this->elements[$hinttype])) ? $this->elements[$hinttype] : 'EMPTY';
		return is_array($patt) and $patt['quant'] == '*' and $patt['type'] == 'sel' and in_array($element, $patt['contents']);
	}
	
	function checkattribute($pattern, &$value)
	{
		if (is_array($pattern))
		{
			if (!in_array($value, $pattern)) return false;
				else return true;
		}
		switch ($pattern)
		{
			case 'CDATA':
				return true;
			case 'ID':
				if (!preg_match('/^[a-zA-Z][a-zA-Z0-9._:-]*$/', $value)) return false;
				if (isset($this->elementids[$value])) return false;
				$this->elementids[$value] = true;
				return true;
			case 'IDREF':
				if (!preg_match('/^[a-zA-Z][a-zA-Z0-9._:\s-]*$/', $value)) return false;
				return true;
			case 'IDREFS':
				if (!preg_match('/^[a-zA-Z][a-zA-Z0-9._:-]*$/', $value)) return false;
				return true;
			case 'NMTOKEN':
				return preg_match('/^[a-zA-Z0-9._:-]*$/', $value);
			case 'NMTOKENS':
				return preg_match('/^[a-zA-Z0-9._:\s-]*$/', $value);
		}
		return false;
	}
	
	function fixattribute($pattern, &$value)
	{
		if (is_array($pattern))
		{
			if (!in_array($value, $pattern)) $value = reset($pattern);
			return;
		}
		switch ($pattern)
		{
			case 'ID':
				$value = uniqid('id', true);
				return;
		}
		// note: later, we will fix the values of the attribute
	}
	
	function dohandler(&$element)
	{
		if (!empty($this->handlers[$element->element]))
		{
			$handler = $this->handlers[$element->element];
			if (is_array($handler)) return $handler[0]->{$handler[1]}($element);
			return $handler($element);
		}
		return true;
	}
	
	function autoclose(&$tag)
	// automatically closes any tag for an element that is incapable of containing any children
	{
		$element = $tag->element;
		if (isset($this->elements[$element]))
		{
			return $this->elements[$element] == 'EMPTY';
		}
		// else, tags which are legacy or proprietary but which nevertheless break if not auto closed
		return in_array($element, array('spacer'));
	}
	
	function filter_stripscripts()
	// allowsimples will text-align, etc
	{
		$element = &$this->getrootelement();
		$this->dofilterstripscripts($this->rootelement);
	}
		
	function dofilterstripscripts(&$element)
	{
		if ($element->element == 'script')
		{
			$element = new htmlfilter_element('_invalid');
			return;
			
		}
		
		static $attrstrip = array(
			'onclick', 'ondblclick', 'onkeydown', 'onkeypress', 'onkeyup', 'onmousedown',
			'onmousemove', 'onmouseout', 'onmouseover', 'onmouseup', 'onload', 'onunload',
			'onreset', 'onsubmit', 'onblur', 'onfocus', 'onchange', 'onselect'
			);
		
		if (!empty($element->attributes)) foreach ($element->attributes as $key => $val)
		{
			if (in_array($key, $attrstrip)) unset($element->attributes[$key]);
		}
		if (!empty($element->contents)) foreach ($element->contents as $key => $val)
		{
			if ($val->element == '_invalid') unset($element->contents[$key]);
				$this->dofilterstripscripts($element->contents[$key]);
		}
	}
	
	function filter_legacystyles()
	// allowsimple will text-align, etc
	{
		$element = &$this->getrootelement();
		$this->dofilterlegacystyles($this->rootelement);
	}
		
	function dofilterlegacystyles(&$element)
	{
		if (!empty($element->attributes)) foreach ($element->attributes as $key => $val)
		{
			if ($key == 'align' and in_array($val, array('left', 'center', 'right', 'justify')))
			{
				if (!empty($element->attributes['style']))
				{
					$style = trim($element->attributes['style']);
					if ($style{strlen($style) - 1} != ';') $style .= ';';
					$element->attributes['style'] = $style . " text-align: $val;";
				}
				else
				{
					$element->attributes['style'] = "text-align: $val;";
				}
			}
		}
		if (!empty($element->contents)) foreach ($element->contents as $key => $val)
		{
			if ($val->element == '_invalid') unset($element->element[$key]);
				$this->dofilterlegacystyles($element->contents[$key]);
	  }
	}
	
	function filter_stripstyles($allowsimple = false, $allowquasi = false)
	// allowsimple will text-align, etc
	{
		$element = &$this->getrootelement();
		$this->dofilterstripstyles($this->rootelement, $allowsimple, $allowquasi);
	}
		
	function dofilterstripstyles(&$element, $allowsimple, $allowquasi)
	{
		if ($element->element == 'style')
		{
			$element = new htmlfilter_element('_invalid');
			return;
		}
		
		static $attrstrip = array('style', 'class');
			
		static $quasistrip = array(
			'border', 'cellpadding', 'cellspacing', 'width', 'height', 'charoff');
		
		if (!empty($element->attributes)) foreach ($element->attributes as $key => $val)
		{
			if (in_array($key, $attrstrip) or (!$allowquasi and in_array($key, $quasistrip)))
			{
				if ($allowsimple and $key == 'style')
				{              
					if (preg_match('/text\-align\:\s*([a-z]+)/', strtolower($val), $matches) and $matches[1] != 'left') $element->attributes[$key] = "text-align: $matches[1];"; 
						else unset($element->attributes[$key]);
				}
				else unset($element->attributes[$key]);
			}
		}
		if (!empty($element->contents)) foreach ($element->contents as $key => $val)
		{
			if ($val->element == '_invalid') unset($element->element[$key]);
				$this->dofilterstripstyles($element->contents[$key], $allowsimple, $allowquasi);
		}
	}
	
	function filter_cleanparagraphs()
	{
		$element = &$this->getrootelement();
		$this->dofiltercleanparagraphs($this->rootelement);
	}
		
	function dofiltercleanparagraphs(&$element)
	// removes empty paragraphs, converts consecutive <br> to paragraph break
	// todo : strip useless divs, convert divs to p
	{
		if ($element->element == 'style')
		{
			$element = new htmlfilter_element('_invalid');
			return;
		}
		
		if (!empty($element->contents))
		{
			$newchildren = array();
			foreach ($element->contents as $key => $val)
			{
				if ($val->element == 'div' and empty($val->attributes))
				{
					$converttop = true;
					if (!empty($val->contents)) foreach($val->contents as $ckey => $cval)
					{
						if (($cval->element != '#PCDATA' or trim($cval->data)) and !$this->quickelementcheck('p', $cval->element)) $converttop = false;
					}
					if ($converttop and $this->quickelementcheck($element->element, 'p'))
					{
						$element->contents[$key]->element = 'p';
						$val->element = 'p';
					}
				}
				if (($val->element == 'div' or $val->element == 'span') and empty($val->attributes))
				{
					$strip = true;
					if (!empty($val->contents)) foreach($val->contents as $ckey => $cval)
					{
						if (($cval->element != '#PCDATA' or trim($cval->data)) and !$this->quickelementcheck($element->element, $cval->element)) $strip = false;
					}
					if ($strip)
					{                         
						unset($newelement);
						$newelement = new htmlfilter_element($element->element);
						$newelement->contents = $val->contents;
						$this->dofiltercleanparagraphs($newelement);
						if (!empty($newelement->contents)) foreach($newelement->contents as $ckey => $cval)
						{
					  	if ($cval->element != '#PCDATA' or trim($cval->data)) $newchildren[] = &$newelement->contents[$ckey];
					  }
					  continue;
					}
				}
				if ($val->element == 'p' and empty($val->attributes) and !empty($val->contents))
				{                      
					reset($val->contents);
					$firstone = each($val->contents);
					$collected = array($firstone[1]);
					while ($thisthing = each($val->contents))
					{
						$previous = end($collected);
						$thiscontent = $thisthing[1];
						if ($previous->element != 'br' or !empty($previous->contents))
						{
							$collected[] = $thiscontent;
							continue;
						} 
						// consecutive br
						if ($thiscontent->element == 'br' and empty($thiscontent->contents))
						{
							array_pop($collected);
							$this->cleanpcontents($collected);
							if (!empty($collected))
							{
								$newelement = &new htmlfilter_element('p');
								$newelement->contents = $collected;
								$newchildren[] = &$newelement;
							}
							//$temp = each($val->contents);
							//$collected = array($temp[1]);
							$collected = array();
						}
						if ($thiscontent->element != '#PCDATA' or trim($thiscontent->data))
							$collected[] = $thiscontent;
					}
					$this->cleanpcontents($collected);
					if (!empty($collected))
					{
						$newelement = &new htmlfilter_element('p');
						$newelement->contents = $collected;
						$newchildren[] = &$newelement;
					}
				}
				else
				{
					if ($val->element == 'div')
						$this->cleanpcontents($val->contents);
					if (($val->element != 'p' and $val->element != 'div') or !empty($val->contents)) $newchildren[] = $val;
				}
			}
			$element->contents = $newchildren;
		}

		if (!empty($element->contents)) foreach ($element->contents as $key => $val)
		{
			if ($val->element == '_invalid') unset($element->contents[$key]);
				$this->dofiltercleanparagraphs($element->contents[$key]);
		}
	}
	
	function cleanpcontents(&$collected)
	{
		// remove whitespace at start
		if (!empty($collected))
		{
			$child = reset($collected);
			if ($child->element == '#PCDATA' and !trim($child->data)) array_shift($collected);
		}
		// remove whitespace at end
		if (!empty($collected))
		{
			$child = end($collected);
			if ($child->element == '#PCDATA' and !trim($child->data)) array_pop($collected);
		}
		// remove br at start
		if (!empty($collected))
		{
			$child = reset($collected);
			if ($child->element == 'br') array_shift($collected);
		}
		// remove br at end
		if (!empty($collected))
		{
			$child = end($collected);
			if ($child->element == 'br') array_pop($collected);
		}
		if (count($collected) == 1)
		{
			$child = current($collected);
			if ($child->element == '#PCDATA' and trim($child->data) == '&nbsp;') array_pop($collected);
		}
	}
	
	function filter_stripids()
	{
		$element = &$this->getrootelement();
		$this->dofilterstripids($this->rootelement);
	}
		
	function dofilterstripids(&$element)
	{
		if (!empty($element->attributes)) foreach ($element->attributes as $key => $val)
		{
			if ($key == 'id' or ($key == 'for' and $element->element == 'label')) unset($element->attributes[$key]);
			if ($key == 'id' and $element->element == 'map') $element->attributes[$key] = 'aid'.md5(uniqid('', true)); 
		}
		if (!empty($element->contents)) foreach ($element->contents as $key => $val)
		{
			if ($val->element == '_invalid') unset($element->contents[$key]);
				else $this->dofilterstripids($element->contents[$key]);
		}
	}

	var $i = 0;
	var $value;
	var $rootelement = NULL;
	var $handlers = array();
	var $currenttag = NULL;
	var $keepwhitespace = false;
	
	var $elementids = array();
}

define('RESOURCE_DIR', '../');

set_time_limit(1);
$htmlfilter = new htmlfilter('<body><![CDATA[abc]]><b><!-- test1-->test of bold</b>');

list($sec, $usec) = explode(' ', microtime());
 
$htmlfilter->filter_xhtml();
$htmlfilter->filter_stripstyles();
$htmlfilter->filter_stripscripts();
$htmlfilter->filter_stripids();
$htmlfilter->filter_cleanparagraphs();

print_r($htmlfilter->gethtml());

list($xsec, $xusec) = explode(' ', microtime());

$elapsed = ($xsec - $sec) + ($xusec - $usec);
echo "\n$elapsed\n";  
    
?>
