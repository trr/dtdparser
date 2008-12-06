<?php

// xhtmlfilter.inc.php

// for filtering xhtml (for instance, to make it compliant)

// this filters it so that it conforms to a given DTD.
// Instead of breaking on errors, it filters out that which is not valid, so you
// will always end up with something valid.  

// this does not support checking of valid characters in the given
// character set.  This should be done prior to using this filter.

if (!defined('RESOURCE_DIR')) define('RESOURCE_DIR', '../');
include_once(RESOURCE_DIR . 'includes/xhtmlparse.inc.php');

define('PATTERNMATCH_OK', 1);
define('PATTERNMATCH_LASTMATCH', 2);
define('PATTERNMATCH_TOOEARLY', -1);
define('PATTERNMATCH_TOOLATE', -2);
define('PATTERNMATCH_BADMATCH', -3);
define('PATTERNMATCH_NOMATCH', -4);

class xhtmlfilter_elementpattern
{
	function xhtmlfilter_elementpattern($pattern, $inclbranch)
	{
		if (is_array($pattern) and !is_array($pattern['contents'])) $pattern['contents'] = array($pattern['contents']);
		if (is_array($pattern)) foreach ($pattern['contents'] as $key => $val)
		{
			if (is_array($val)) $pattern['contents'][$key] = new xhtmlfilter_elementpattern($val, null);
		}
		$this->pattern = $pattern;
		$this->original = $pattern;
		$this->inclbranch = $inclbranch;
	}
	
	function required()
	// if any element is required in order to satisfy the content model, this returns
	// the name of the required element.  if more than one are required, this returns
	// the first one that is required or the first in the pattern as appropriate
	{
		if ($this->pattern == 'EMPTY' or $this->pattern == '#PCDATA' or $this->pattern == '#CDATA' or $this->pattern == 'CDATA') return null;
		if (!is_array($this->pattern)) return $this->pattern;
		if (!$this->pattern['quant']) return null;
		if (($this->pattern['quant'] == '*' or $this->pattern['quant'] == '?') and $this->pattern['contents'] == $this->original['contents'])
		{
			return null;
		}
		// preferred elements - by default in a 'sel', the first one is always returned as the
		// 'required' one unless it's in this list
		$pref = array('frame', 'td', 'p');
		if ($this->pattern['type'] == 'sel') foreach ($this->pattern['contents'] as $cont)
			if (!is_object($cont) && in_array($cont, $pref)) return $cont;
		$temp = $this->pattern['contents'];
		do
		{
			$el = current($this->pattern['contents']);
			$required = is_object($el)
				? $this->pattern['contents'][key($this->pattern['contents'])]->required()
				: $el;
			if (!$required) array_shift($this->pattern['contents']);
 		} while (!$required and count($this->pattern['contents']));
		$this->pattern['contents'] = $temp;
		if ($required == '#PCDATA' || $required == '#CDATA' || $required == 'CDATA') return NULL;
		return $required;
	}
	
	function iscdataexpected()
	// returns true if the next expected element is html style CDATA
	{
		if (!is_array($this->pattern))
			return $this->pattern == 'CDATA';
		if (!$this->pattern['quant']) return false;
		if ($this->pattern['type'] == 'sel')
			return ($this->pattern['quant'] == 1 && reset($this->pattern['contents']) == 'CDATA');
		if ($this->pattern['type'] == 'seq')
			return (reset($this->pattern['contents']) == 'CDATA');
		return false;
	}
	
	function copyof($something)
	// return a clone of something
	{
		if (!is_object($something) || version_compare(phpversion(), '5.0') < 0)
			return $something;
		return clone($something);
	}
	
	function trymatch($element, $proceed = true)
	// determines if the element with name $element can fit into this position of 
	// the content model.  return values:
	// PATTERNMATCH_LASTMATCH: element is accepted, but this must be the last one
	// PATTERNMATCH_OK: element is accepted
	// PATTERNMATCH_BADMATCH: element is not accepted, pattern needs other element
	// PATTERNMATCH_NOMATCH: element is not accepted, but pattern is satisfied
	// PATTERNMATCH_TOOEARLY: element is not accepted, but may be valid later
	// PATTERNMATCH_TOOLATE: element is not accepted, but may be valid earlier
	// if $proceed is true, then if the element matches the position in the
	// pattern is incremented for the next element
	{
		// comments are fine
		if ($element == '_comment') return PATTERNMATCH_OK;
		// treat CDATA like PCDATA
		if ($element == '#CDATA') $element = '#PCDATA';
		
		// inclusions/exclusions
		if (isset($this->inclbranch[$element]))
		{
			return $this->inclbranch[$element] ? PATTERNMATCH_OK
				: PATTERNMATCH_BADMATCH; 
		}
		
		// plain text (treated like seq with quant 1)
		if (!is_array($this->pattern))
		{
			if ($this->pattern == $element)
			{
				// return lastmatch
				if ($proceed) $this->pattern = array(
					'type' => 'seq', 'quant' => null, 'contents' => array());
				return PATTERNMATCH_LASTMATCH;
			}
			if ($this->pattern == '#PCDATA')
				return PATTERNMATCH_NOMATCH;
			return PATTERNMATCH_BADMATCH;
		}
		
		// quant of zero
		if (!$this->pattern['quant']) return PATTERNMATCH_NOMATCH;
		
		$canrecycle =
			$this->pattern['quant'] == '+' || $this->pattern['quant'] == '*';
		$canskip =
			$this->pattern['quant'] == '*' || $this->pattern['quant'] == '?';
				
		// multiple (&) - must satisfy all, but in any order		
		if ($this->pattern['type'] == 'mul')
		{
			$tooearly = $toolate = false;
			$nomatchcount = 0;
			
			foreach ($this->pattern['contents'] as $key => $cmp)
			{
				$match = is_object($cmp)
					? $this->pattern['contents'][$key]->trymatch($element, $proceed)
					: ($cmp == $element ? PATTERNMATCH_LASTMATCH : 
						($cmp == '#PCDATA' ? PATTERNMATCH_NOMATCH : PATTERNMATCH_BADMATCH));
							
				if ($match > 0)
				{
					$result = ($match == PATTERNMATCH_LASTMATCH 
						&& count($this->pattern['contents']) == 1 
						&& !$canrecycle) ? PATTERNMATCH_LASTMATCH : PATTERNMATCH_OK;
					if ($proceed && $match == PATTERNMATCH_LASTMATCH)
					{
						unset($this->pattern['contents'][$key]);
						if (empty($this->pattern['contents']))
						{
							$this->pattern['quant'] = $canrecycle ? '*' : null;
							if ($canrecycle)
								$this->pattern['contents'] = $this->original['contents'];
						}
					}
					return $result;
				}
				if ($match == PATTERNMATCH_TOOEARLY) $tooearly = true;
				elseif ($match == PATTERNMATCH_TOOLATE) $toolate = true;
				elseif ($match == PATTERNMATCH_NOMATCH) $nomatchcount++;
			}
			if ($nomatchcount >= count($contents))
			{
				// all are satisfied, try recycling
				// TODO trials, see if it wraps around and matches again
				return PATTERNMATCH_NOMATCH;
			}
			if ($tooearly) return PATTERNMATCH_TOOEARLY;
			if ($toolate) return PATTERNMATCH_TOOLATE;
			if ($canskip && $this->pattern == $this->original)
				return PATTERNMATCH_NOMATCH;
			return PATTERNMATCH_BADMATCH;
		}
		
		// selection (|) - can satisfy any one and only one
		if ($this->pattern['type'] == 'sel')
		{
			$tooearly = $toolate = false;
			$nomatchcount = 0;
			
			foreach ($this->pattern['contents'] as $key => $cmp)
			{
				$match = is_object($cmp)
					? $this->pattern['contents'][$key]->trymatch($element, $proceed)
					: ($cmp == $element ? PATTERNMATCH_LASTMATCH : 
						($cmp == '#PCDATA' ? PATTERNMATCH_NOMATCH : PATTERNMATCH_BADMATCH));
				
				if ($match > 0)
				{
					$result = ($match == PATTERNMATCH_LASTMATCH 
						&& !$canrecycle) ? PATTERNMATCH_LASTMATCH : PATTERNMATCH_OK;
					if ($proceed && $match == PATTERNMATCH_LASTMATCH)
					{
						$this->pattern['quant'] = $canrecycle ? '*' : null;
						if ($canrecycle)
							$this->pattern['contents'] = $this->original['contents'];
					}
					return $result;
				}
				if ($match == PATTERNMATCH_TOOEARLY) $tooearly = true;
				elseif ($match == PATTERNMATCH_TOOLATE) $toolate = true;
				elseif ($match == PATTERNMATCH_NOMATCH) $nomatchcount++;
			}
			if ($tooearly) return PATTERNMATCH_TOOEARLY;
			if ($toolate) return PATTERNMATCH_TOOLATE;
			if ($nomatchcount)
			{
				// one is satisfied, try recycling
				// TODO trials, see if it wraps around and matches again
				return PATTERNMATCH_NOMATCH;
			}
			if ($canskip) return PATTERNMATCH_NOMATCH;
			return PATTERNMATCH_BADMATCH;
		}
		
		// sequence (,) - must satisfy all in order
		if ($this->pattern['type'] == 'seq')
		{            
			if (empty($this->pattern['contents'])) return PATTERNMATCH_NOMATCH;
			
			$preserve = $this->copyof($this->pattern);
			
			do
			{
				$cmp = reset($this->pattern['contents']);
				$key = key($this->pattern['contents']);
				
				$match = is_object($cmp)
					? $this->pattern['contents'][$key]->trymatch($element, $proceed)
					: ($cmp == $element ? PATTERNMATCH_LASTMATCH : 
						($cmp == '#PCDATA' ? PATTERNMATCH_NOMATCH : PATTERNMATCH_BADMATCH));
				
				if ($match > 0)
				{
					$result = ($match == PATTERNMATCH_LASTMATCH 
						&& count($this->pattern['contents']) == 1 
						&& !$canrecycle) ? PATTERNMATCH_LASTMATCH : PATTERNMATCH_OK;
					if ($proceed && $match == PATTERNMATCH_LASTMATCH)
					{
						array_shift($this->pattern['contents']);
						if (empty($this->pattern['contents']))
						{
							$this->pattern['quant'] = $canrecycle ? '*' : null;
							if ($canrecycle)
								$this->pattern['contents'] = $this->original['contents'];
						}
					}
					return $result;
				}
				$continue = $match == PATTERNMATCH_NOMATCH;
				if ($continue)
					array_shift($this->pattern['contents']);

			} while ($continue && is_);
			
			$nomatch = empty($this->pattern['contents']);
			
			$this->pattern = $this->copyof($preserve);
			
			if ($nomatch) return PATTERNMATCH_NOMATCH;
			return PATTERNMATCH_BADMATCH;
			
			
				
				
			{	
				
				// if there's no match, and we're not already part way through a sequence, return NOMATCH
				if ($match < 0 && $match != PATTERNMATCH_NOMATCH && ($this->pattern['quant'] == '*' or $this->pattern['quant'] == '?') && $this->pattern == $this->original)
				{
					$this->pattern = $preserve;
					return PATTERNMATCH_NOMATCH;
				}
				
				if ($match == PATTERNMATCH_NOMATCH)
				{
					array_shift($this->pattern['contents']);
					$continue = true;
				}
				else
				{
					$continue = false;
					if ($match == PATTERNMATCH_LASTMATCH) //FIXME
					{
						$match = PATTERNMATCH_OK;
						if ($proceed)
						{
							array_shift($this->pattern['contents']);
							if (empty($this->pattern['contents']))
							{
								$this->pattern['contents'] = $this->original['contents'];
								if ($proceed and $this->pattern['quant'] == '+') $this->pattern['quant'] = '*';
								elseif ($proceed and $this->pattern['quant'] == '?' or $this->pattern['quant'] == '1')
								{
									$this->pattern['quant'] = NULL;
									$match = PATTERNMATCH_LASTMATCH;
								}
							}
						}
					}
					if (!$proceed) $this->pattern = $preserve;
					if ($match < 0 and $element != '#PCDATA')
					{
						// try lookahead
						$found = false;
						foreach ($this->pattern['contents'] as $key => $val)
						{
							$found = is_object($val)
								? ($this->pattern['contents'][$key]->trymatch($element, false) > 0)
								: ($val == $element);
							if ($found) break;
						}
						if ($found) return PATTERNMATCH_TOOEARLY;
						// try lookbehind
						foreach ($this->original['contents'] as $key => $val)
						{
							$found = is_object($val)
							 	? ($this->original['contents'][$key]->trymatch($element, false) > 0)
							 	: ($val == $element);
							if ($found) break;
						}
						if ($found) return PATTERNMATCH_TOOLATE;
					}
					if ($match < 0) $this->pattern = $preserve;
					return $match;
				}
			} while ($continue);
		}
		return PATTERNMATCH_BADMATCH;
	}
	
	function trymatch_old($element, $proceed = true)
	// determines if the element with name $element can fit into this position of the
	// content model.  return values:
	// PATTERNMATCH_LASTMATCH: element is accepted, but this must be the last one
	// PATTERNMATCH_OK: element is accepted
	// PATTERNMATCH_BADMATCH: element is not accepted, pattern needs some other element
	// PATTERNMATCH_NOMATCH: element is not accepted, but pattern is satisfied
	// PATTERNMATCH_TOOEARLY: element is not accepted, but may be valid later
	// PATTERNMATCH_TOOLATE: element is not accepted, but may be valid earlier
	// if $proceed is false, then the pointer won't progress even if the element
	// fits - it is treated as a 'trial' or 'hypothetical' match
	{
		if ($element == '_comment') return PATTERNMATCH_OK;
		if ($element == '#CDATA') $element = '#PCDATA';
		// inclusions/exclusions
		if (isset($this->inclbranch[$element]))
		{
			return $this->inclbranch[$element] ? PATTERNMATCH_OK
				: PATTERNMATCH_BADMATCH; 
		}
		if (!is_array($this->pattern))
		{
			if ($this->pattern == 'EMPTY' || $this->pattern != $element) return PATTERNMATCH_BADMATCH;
			elseif ($this->pattern == '#PCDATA') return PATTERNMATCH_NOMATCH;
			else
			{
				if ($proceed) $this->pattern = array('type' => 'seq', 'quant' => NULL, 'contents' => array());
				return PATTERNMATCH_LASTMATCH;
			}
		}
		if (!$this->pattern['quant']) return PATTERNMATCH_NOMATCH;
		if ($this->pattern['type'] == 'mul')
		{
			// look for a match in a multiple (options separated by '&')
			// not necessarily optimised
			$tooearly = $toolate = false;
			foreach ($this->pattern['contents'] as $key => $el)
			{
				$match = is_object($el)
					? $this->pattern['contents'][$key]->trymatch($element, $proceed)
					: ($el == $element ? PATTERNMATCH_LASTMATCH : 
							($el == '#PCDATA' ? PATTERNMATCH_NOMATCH : PATTERNMATCH_BADMATCH));
				if ($match > 0)
				{
					if ($proceed && $match == PATTERNMATCH_LASTMATCH)
						unset($this->pattern['contents'][$key]);
					$match = PATTERNMATCH_OK;
					if (empty($this->pattern['contents']))
					{
						if ($proceed && $this->pattern['quant'] == '+') $this->pattern['quant'] = '*';
						elseif ($this->pattern['quant'] == '?' or $this->pattern['quant'] == '1')
						{
							if ($proceed) $this->pattern['quant'] = NULL;
							$match = PATTERNMATCH_LASTMATCH;
						}
						if ($proceed) $this->pattern['contents'] = $this->original['contents'];
					}
				}
				if ($match > 0) return $match;
				if ($match == PATTERNMATCH_TOOEARLY) $tooearly = true;
				elseif ($match == PATTERNMATCH_TOOLATE) $toolate = true;
			}
			if ($tooearly) return PATTERNMATCH_TOOEARLY;
			if ($toolate) return PATTERNMATCH_TOOLATE;
			// TODO if one is patternmatch_nomatch, try decreasing quant and trying
			// afresh
			return PATTERNMATCH_BADMATCH;
		}
		if ($this->pattern['type'] == 'sel')
		{
			// look for a match in a selection (separated by '|')
			$match = PATTERNMATCH_BADMATCH;
			if (in_array($element, $this->pattern['contents'])) $match = PATTERNMATCH_LASTMATCH;
			else 
			{
				foreach ($this->pattern['contents'] as $key => $val) 
				{
					if (is_object($val))
					{
						$match = $this->pattern['contents'][$key]->trymatch($element, $proceed);
						if ($proceed && $match == PATTERNMATCH_OK)
						{
							$this->pattern['contents'] = array($key => $this->pattern['contents'][$key]);
						}
						if ($match > 0) break;
					}
					elseif ($val == '#PCDATA') ; // TODO special case
				}
			}
			if ($match > 0)
			{   
				if ($proceed and $match == PATTERNMATCH_LASTMATCH)
				{
					$this->pattern['contents'] = $this->original['contents'];
					if ($this->pattern['quant'] == '+')
						$this->pattern['quant'] = '*';
					elseif ($this->pattern['quant'] == '?' or $this->pattern['quant'] == '1')
					{
						$this->pattern['quant'] = NULL;
						return PATTERNMATCH_LASTMATCH;
					}
				}
				return PATTERNMATCH_OK;
			}
			else
			{
				if ($this->pattern['quant'] == '*' || $this->pattern['quant'] == '?')
				{
					return PATTERNMATCH_NOMATCH;
				}
			}
			return $match;
		}
		if ($this->pattern['type'] == 'seq')
		{            
			$preserve = $this->copyof($this->pattern);
			do
			{
				$el = reset($this->pattern['contents']);
				
				$match = is_object($el)
					? $this->pattern['contents'][key($this->pattern['contents'])]->trymatch($element, $proceed)
					: ($el == $element ? PATTERNMATCH_LASTMATCH : PATTERNMATCH_BADMATCH);
				
				// if there's no match, and we're not already part way through a sequence, return NOMATCH
				if ($match < 0 && $match != PATTERNMATCH_NOMATCH && ($this->pattern['quant'] == '*' or $this->pattern['quant'] == '?') && $this->pattern == $this->original)
				{
					$this->pattern = $preserve;
					return PATTERNMATCH_NOMATCH;
				}
				
				if ($match == PATTERNMATCH_NOMATCH)
				{
					array_shift($this->pattern['contents']);
					$continue = true;
				}
				else
				{
					$continue = false;
					if ($match == PATTERNMATCH_LASTMATCH) //FIXME
					{
						$match = PATTERNMATCH_OK;
						if ($proceed)
						{
							array_shift($this->pattern['contents']);
							if (empty($this->pattern['contents']))
							{
								$this->pattern['contents'] = $this->original['contents'];
								if ($proceed and $this->pattern['quant'] == '+') $this->pattern['quant'] = '*';
								elseif ($proceed and $this->pattern['quant'] == '?' or $this->pattern['quant'] == '1')
								{
									$this->pattern['quant'] = NULL;
									$match = PATTERNMATCH_LASTMATCH;
								}
							}
						}
					}
					if (!$proceed) $this->pattern = $preserve;
					if ($match < 0 and $element != '#PCDATA')
					{
						// try lookahead
						$found = false;
						foreach ($this->pattern['contents'] as $key => $val)
						{
							$found = is_object($val)
								? ($this->pattern['contents'][$key]->trymatch($element, false) > 0)
								: ($val == $element);
							if ($found) break;
						}
						if ($found) return PATTERNMATCH_TOOEARLY;
						// try lookbehind
						foreach ($this->original['contents'] as $key => $val)
						{
							$found = is_object($val)
							 	? ($this->original['contents'][$key]->trymatch($element, false) > 0)
							 	: ($val == $element);
							if ($found) break;
						}
						if ($found) return PATTERNMATCH_TOOLATE;
					}
					if ($match < 0) $this->pattern = $preserve;
					return $match;
				}
			} while ($continue);
		}
		return PATTERNMATCH_BADMATCH;
	}
	
	var $pattern;
	var $original;
}

class xhtmlfilter
{
	function xhtmlfilter($rootelement, $dtd = 'xhtml1-strict')
	{
		$this->rootelement = $rootelement;
		if (!preg_match('#^[a-z0-9_-]{1,32}$#', $dtd))
			trigger_error('Invalid DTD', E_USER_ERROR);
		$this->dtd = $dtd;
		$this->isxhtml = preg_match('#^xhtml#i', $dtd);
		$this->iscompat = preg_match('#^xhtml1-#i', $dtd);
		require(RESOURCE_DIR . "includes/dtd-$dtd.php");
	}
	
	// public
	function getrootelement()
	{
		return $this->rootelement;
	}
	
	function setwhitespace($keepwhitespace)
	{
		$this->keepwhitespace = $keepwhitespace;
	}
	
	function gethtml($skiproot = false, $element = NULL)
	{
		$isxhtml = $this->isxhtml; $iscompat = $this->iscompat;
		if ($element === NULL) $element = &$this->rootelement;
		if ($element->element == '_invalid') return '';
	  $body = (isset($element->data)) ? $element->data : '';
	  if ($element->element == '#PCDATA') return $body;
	  if ($element->element == '#CDATA')
			return $isxhtml ? "<![CDATA[$body]]>" : $this->fixentities($body);
		if ($element->element == 'CDATA') return $body;
		if ($element->element == '_comment') return $body ? "<!--$body-->" : '';
		if (isset($element->contents)) foreach ($element->contents as $inner)
	  {
	  	$body .= $this->gethtml(false, $inner);
	  }
	  $attributes = '';
	  if ($element->element == 'html' && $isxhtml)
	  	$element->attributes['xmlns'] = 'http://www.w3.org/1999/xhtml';	  	
		if (!empty($element->attributes)) foreach ($element->attributes as $key => $val)
	  	$attributes .= " $key=\"$val\"";
	  if ($skiproot) return "$body";
  	
		if ($iscompat || !$isxhtml)
		{
			if (!strlen($body) and $this->autoclose($element))
				return "<$element->element{$attributes}" . ($isxhtml ? (" />") : ">");
	  	return "<$element->element{$attributes}>$body</$element->element>";
	  }
	  if (!strlen($body))
			return "<$element->element{$attributes}/>";
	  return "<$element->element{$attributes}>$body</$element->element>";
	}
	
	function gettext()
	{
		$element = &$this->rootelement;
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
	
	function fixentities($value)
	// fixes up any broken entities which would show up as problems on a validator
	{
		$value = preg_replace('/&([a-zA-Z\#][a-zA-Z0-9]{0,15})(\r|\n|$)/Sm', '&\\1;', $value);
		if (preg_match_all('/&([a-zA-Z\#][a-zA-Z0-9]*)\;/', $value, $matches))
		{
			foreach ($matches[1] as $key => $val)
			{
				if ($val[0] == '#')
				{
					if (!preg_match('/^\#(x[0-9a-fA-F]{1,6}|[0-9]{1,6})$/', $val, $numparts)) $value = str_replace($matches[0][$key], '', $value);
					else
					{
						$n = $numparts[1]{0} == 'x' ? hexdec(substr($numparts[1], 1)) : (int)$numparts[1];
						static $codepage = array( 
							// microsoft's 1252 codepage: 0x80 to 0x9F
							0x80 => 0x20AC, 0x82 => 0x201A, 0x83 => 0x0192, 0x84 => 0x201E,
							0x85 => 0x2026,	0x86 => 0x2020, 0x87 => 0x2021, 0x88 => 0x02C6,
							0x89 => 0x2030, 0x8A => 0x0160, 0x8B => 0x2039, 0x8C => 0x0152,
							0x8E => 0x017D, 0x91 => 0x2018, 0x92 => 0x2019, 0x93 => 0x201C,
							0x94 => 0x201D, 0x95 => 0x2022, 0x96 => 0x2013, 0x97 => 0x2014,
							0x98 => 0x02DC, 0x99 => 0x2122, 0x9A => 0x0161, 0x9B => 0x203A,
							0x9C => 0x0153, 0x9E => 0x017E, 0x9F => 0x0178,
							);
						if (isset($codepage[$n]))
						{
							$value = str_replace($matches[0][$key], '&#x' . dechex($codepage[$n]) . ';', $value);
						}
						elseif (($n < 32 and $n != 9 and $n != 10 and $n != 13) or ($n >= 127 and $n <= 159) or $n >= 0xfffe)
						{
							$value = str_replace($matches[0][$key], '', $value);
						}
					}
				}
				else
				{
					if (!isset($this->xmlentities[$val])) $value = str_replace($matches[0][$key], '', $value);
				}
			}
		}
		$value = str_replace(array('<', ']]>'), array('&lt;', ']]&gt;'), $value);
		$value = preg_replace('/&(?!([a-zA-Z\#][a-zA-Z0-9]{0,15}\;))/S', '&amp;', $value);
		return $value;
	}
	
	function filter_xhtml()
	{
		$element = &$this->rootelement;
		$this->inclbranch = array();
		$this->dofilterxhtml($this->rootelement);
		$this->dofilterfor($this->rootelement);
	}
	
	function dofilterfor(&$element)
	{
		if (isset($element->attributes['for']) and empty($this->elementids[strtolower($element->attributes['for'])]))
			unset($element->attributes['for']);
		
		// proceed to children
	  $keys = array_keys($element->contents);
	  foreach ($keys as $key) $this->dofilterfor($element->contents[$key]);
	}
	
	function doconvertembed(&$element, &$parentelement)
	// used by this->dofilterxhtml()
	// returns array of elements to replace $element with
	{
		$out = array();
		
		if ($parentelement->element == 'object') $out[] = new xhtml_element('_comment', '[if !IE]> <');  // IE compatibility
		
		$object = new xhtml_element('object');
		$object->attributes = $element->attributes;
		if (!empty($object->attributes['src'])) $object->attributes['data'] = $object->attributes['src'];
		if (!empty($object->attributes['alt'])) $object->contents[] = new xhtml_element('#PCDATA', $object->attributes['alt']);
		
		if ($parentelement->element != 'object' and !empty($object->attributes['type']) and !empty($object->attributes['data']) and strtolower($object->attributes['type']) == 'application/x-shockwave-flash')
		{
			$param = new xhtml_element('param');
			$param->attributes['name'] = 'movie';
			$param->attributes['value'] = $object->attributes['data'];
			$object->contents[] = $param;
		}
		
		$out[] = $object;		
		
		if ($parentelement->element == 'object') $out[] = new xhtml_element('_comment', '> <![endif]');  // IE compatibility
		
		return $out;
	}
	
	function filterhtmlcdata($data)
	// filters html style cdata sections so that they don't contain "</"
	{
		// including </ in one of these is illegal - not much we can do but
		// try and figure out how to get around it based on context
		$data = preg_replace("#('([^']|\\')*?)</#", "\\1<'+'/", $data);
    $data = preg_replace('#("([^"]|\\")*?)</#', '\\1<"+"/', $data);
    // last resort is to replace </ with < / - entities are clearly unwanted in
		// html cdata and there is no clear way to escape </ (it can't be included
		// in valid code)
		$data = str_replace('</', '< /', $data);
		return $data;
	}
	
	function dofilterxhtml(&$element)
	{
		$elementname = $element->element;
		if ($elementname == '#PCDATA')
		{
			$element->data = $this->fixentities($element->data);
			return;
		}
		if ($elementname == '_invalid' or $elementname == '_comment' or $elementname == '#CDATA') return;
		
		if ($elementname == 'head' and !$this->headelement) $this->headelement = &$element;
		
		// check attribute validity
		$thiselementid = null;
	  while (list($key, $val) = each($element->attributes))
		{
	  	$element->attributes[$key] = str_replace('"', '&quot;', $this->fixentities($val));
	  	if (!isset($this->attlist[$elementname][$key]))
	  	{
	  		// transitional corrections
	  		if (in_array($this->dtd, array('xhtml1-transitional', 'xhtml1-frameset', 'html4-transitional', 'html4-frameset')))
	  		{
					if ($key == 'background' and isset($this->attlist[$elementname]['style']))
					{
						unset($element->attributes[$key]);
						if (!isset($element->attributes['style'])) $element->attributes['style'] = '';
						$val = str_replace(array('(', ')', ',', "\t", "\n", "\r", "'", '"'), array('\\(', '\\)', '\,', "\\\t", "\\\n", "\\\r", "\\'", '\\&quot;'), $this->fixentities($val));
						$element->attributes['style'] = "background: url('$val');" . $element->attributes['style'];
						continue;
					}
					if (in_array($key, array('topmargin', 'leftmargin')) and isset($this->attlist[$elementname]['style']))
					{
						unset($element->attributes[$key]);
						if (!isset($element->attributes['style'])) $element->attributes['style'] = '';
						$val = preg_replace('[^0-9]', '', $val);
						if (!is_numeric($val)) continue;
						if ($key == 'topmargin') $a = 'top' and $b = 'bottom';
							else $a = 'left' and $b = 'right';
						$element->attributes['style'] = "margin-$a: {$val}px;margin-$b: {$val}px;" . $element->attributes['style'];
					}
				}
				unset($element->attributes[$key]);
	  	}
	  	else
	  	{
	  		$pattern = $this->attlist[$elementname][$key];
	  		if (!$this->checkattribute($pattern, $val))
				{
					// attribute should be a different case?
					if (is_array($pattern))
					{
						foreach ($pattern as $cmp) if (strtolower($cmp) == strtolower($val))
						{
							$val = $cmp;
							continue;
						}
						if ($this->checkattribute($pattern, $val))
						{
							$element->attributes[$key] = $val;
							continue;
						}
					}
					// attribute can be replaced?
					if ($val == 'middle' and $this->checkattribute($pattern, 'center'))
					{
						$element->attributes[$key] = 'center';
						continue;
					}
					// non numeric characters can be removed?
					if (preg_match('#[^0-9]#', $val))
					{
						$newval = preg_replace('#[^0-9]#', '', $val);
						if ($this->checkattribute($pattern, $newval))
						{
							$element->attributes[$key] = $newval; 
							continue;
						}
					}
					// give up; remove the attribute
					unset($element->attributes[$key]);
				}
				// check uniqueness of id (and some name) attributes
				// all known html/xhtml variants have these restrictions I believe
				if ($key == 'id' || ($key == 'name' && in_array($elementname, array('a', 'applet', 'form', 'frame', 'iframe', 'img', 'map'))))
				{
					if (strlen($thiselementid))
					{
						// multiple identifiers on one element are allowed but must be equal
						// if they exist (if different case then we just substitute for 1st)
						if ($val != $thiselementid)
						{
							if (strtolower($val) === strtolower($thiselementid))
								$element->attributes[$key] = $thiselementid;
							else unset($element->attributes[$key]);
						}
					}
					else
					{
						// we store elementids in lowercase, because they must not only be
						// unique, but they must not conflict with other ids that differ
						// only in case
						// these elementids should be used for comparison only.
						if (isset($this->elementids[strtolower($val)]))
							unset($element->attributes[$key]);
						$this->elementids[strtolower($val)] = true;
						$thiselementid = $val;
					}
				}
	  	}
	  }
	  
	  // check required attributes
	  if (isset($this->attreq[$elementname])) foreach($this->attreq[$elementname] as $key => $val)
		{
	  	if (!isset($element->attributes[$key]))
			{
				$element->attributes[$key] = $val;
				if (!strlen($val) and isset($this->attlist[$elementname][$key]))
					$this->fixattribute($this->attlist[$elementname][$key], $element->attributes[$key], $elementname, $key);
			}
	  }
	  
	  // check sub-elements
		$filterbuffer = $element->contents;
	  $outputbuffer = array();
	  $waitbuffer = array();
	  $patt = (isset($this->elements[$elementname])) ? $this->elements[$elementname] : 'EMPTY';
	  $inclbak = $this->inclbranch;  // recursive inclusions/exclusions
	  if (isset($this->incl[$elementname]))
			$this->inclbranch = array_merge($this->inclbranch, $this->incl[$elementname]);
		$pattern = new xhtmlfilter_elementpattern($patt, $this->inclbranch);
		
		$tries = (count($element->contents) * 2) + 2;
		$rejigs = 3;
		while (count($filterbuffer) and ($tries-- > 0))
	  {
			// html-style CDATA expected?
			if ($pattern->iscdataexpected())
			{
				$data = '';
				foreach ($filterbuffer as $fid => $fel)
				{
					if ($fel->element == '#CDATA')
						$data .= $fel->data;
					else $data .= $this->gethtml(false, $fel);
				}
				$data = $this->filterhtmlcdata($data);
				$el = new xhtml_element('CDATA');
				$el->data = $data;
				$filterbuffer = array();
				array_push($outputbuffer, $el);
				continue;
			}	
			
			$child = array_shift($filterbuffer);
	  	
	  	$childname = $child->element;
	  	
			if ($this->keepwhitespace and $childname == '#PCDATA' and strlen(trim($child->data)) == 0)
			// if just whitespace
  		{
  			array_push($outputbuffer, $child);
  			continue;
  		}	
  		
  		$match = $pattern->trymatch($childname);
			if ($match > 0)
	  	{
	  		array_push($outputbuffer, $child);
				
				// follow character data in
	  		if (($childname == '#PCDATA' or $childname == '#CDATA') and count($filterbuffer))
	  		{
	  			$a = reset($filterbuffer);
	  			while ($a and ($a->element == '#PCDATA' or $a->element == '#CDATA' or $a->element == '_comment'))
	  			{
	  				array_push($outputbuffer, array_shift($filterbuffer));
	  				$a = count($filterbuffer) ? reset($filterbuffer) : null;
	  			}
	  		}
	  		
	  		if (!empty($waitbuffer))
				{
					$filterbuffer = array_merge($waitbuffer, $filterbuffer);
					$waitbuffer = array();
				}	  
	  	}
	  	else
	  	{
				// did not match
				
				if ($match == PATTERNMATCH_TOOEARLY)
	  		{
	  			array_push($waitbuffer, $child);
	  			if (empty($filterbuffer))
			  	{
						if ($el = $pattern->required())
						{
							$pattern->trymatch($el);
							array_push($outputbuffer, new xhtml_element($el));
						}
						$filterbuffer = $waitbuffer;
			  		$waitbuffer = array();
			  	}
					continue;
	  		}

  			if ($match == PATTERNMATCH_TOOLATE and $rejigs and $elementname != 'head')
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
					$pattern = new xhtmlfilter_elementpattern($patt, $this->inclbranch);
					continue;
				}
				
				// can be replaced?
				if ($childname == 'image')
				{
					$el = new xhtml_element('img');
					$el->attributes = $child->attributes;
					array_unshift($filterbuffer, $el);
					continue;
				} 
				
				// is stuff outside the body/frameset tag?
  			if (in_array($elementname, array('html', 'noframes')))
  			{
					// is there already a body/frameset tag?
					$body = (in_array($this->dtd, array('xhtml1-frameset', 'html4-frameset')) and $elementname == 'html') ? 'frameset' : 'body';
					$bodyelement = false;
					foreach ($outputbuffer as $bkey => $bval) if ($bval->element == $body)
					{
            $bodyelement = $bkey;
            break;
					}
					// collect elements to put in the body
					$bodybuffer = array($child);
					$next = reset($filterbuffer);
					while ($next and $pattern->trymatch($next->element, false) < 0)
  				{
  					array_push($bodybuffer, $next);
  					array_shift($filterbuffer);
  					$next = reset($filterbuffer);
  				}
  				if ($bodyelement !== false)
					{
						// put them into the existing body
						$outputbuffer[$bodyelement]->contents = array_merge($outputbuffer[$bodyelement]->contents, $bodybuffer);
					}
					else
					{
						// create a new body and put them in it
						$newelement = new xhtml_element($body);
						$newelement->contents = $bodybuffer;
						array_push($filterbuffer, $newelement);
					}
					continue;
  			}
  			
  			// is there a duplicate title element?
  			if ($childname == 'title' && $elementname == 'head' && $this->headelement !== null)
  			{
  				// find existing title and merge
  				foreach ($this->headelement->contents as $id => $tel) if ($tel->element == 'title')
  				{
  					// append contents to existing title
						$this->headelement->contents[$id]->contents = array_merge($tel->contents, $child->contents);
						$this->dofilterxhtml($this->headelement->contents[$id]);
  				}
  			}
  			
  			// is it an element that should be in <head>?
  			if (in_array($childname, array('style', 'link', 'title', 'meta')) and $elementname != 'head')
  			{
  				// does the <head> exist previously?
					if ($this->headelement !== null)
  				{
						$this->headelement->contents[] = $child;
						$this->dofilterxhtml($this->headelement);
  					continue;
  				}
  			}
  			
  			// is <embed>?
  			if ($childname == 'embed' and $pattern->trymatch('object', false))
  			{
					$newcontents = $this->doconvertembed($child, $element);
					$filterbuffer = array_merge($newcontents, $filterbuffer);
					continue;
  			}
  			
  			// is <tr> in <table>? (HTML needs <tbody>)
  			if ($childname == 'tr' and $elementname == 'table')
  			{
  				if (count($outputbuffer))
  				{
  					$tbody = end($outputbuffer);
  					$tbodykey = key($outputbuffer);
  					if ($tbody->element == 'tbody')
  					{
							$outputbuffer[$tbodykey]->contents[] = $child;
							array_shift($filterbuffer);
							continue;
  					}
  				}
  			}
  			
  			// can hint?
  			$hint = NULL;
				if ($this->quickelementcheck('p', $childname) and $pattern->trymatch('p', false) > 0) $hint = 'p';
  			elseif ($this->quickelementcheck('li', $childname) and $pattern->trymatch('li', false) > 0) $hint = 'li';
  			elseif ($childname == 'li' and $pattern->trymatch('ul', false) > 0) $hint = 'ul';
  			elseif (($childname == 'dt' or $childname == 'dd') and $pattern->trymatch('dl', false) > 0) $hint = 'dl';
  			elseif (in_array($childname, array('td', 'th')) and $pattern->trymatch('tr', false) > 0) $hint = 'tr';
  			elseif ($childname == 'tr' && $pattern->trymatch('tbody', false) > 0) $hint = 'tbody';
  			elseif ($this->quickelementcheck('noframes', $childname) && $pattern->trymatch('noframes', false) > 0) $hint = 'noframes';
				if ($hint)
				{
  				$hintbuffer = array($child);
  				$next = reset($filterbuffer);
  				while
						(
							$next
							and $pattern->trymatch($next->element, false) < 0
							and
							(
								($next->element == '#PCDATA' and !strlen(trim($next->data)))
								or !isset($this->elements[$next->element])
								or $this->quickelementcheck($hint, $next->element)
							)
						)
  				{
  					array_push($hintbuffer, $next);
  					array_shift($filterbuffer);
  					$next = reset($filterbuffer);
  				}
  				$newelement = new xhtml_element($hint);
					$newelement->contents = $hintbuffer;
					array_unshift($filterbuffer, $newelement);
				}
				else // we cannot hint
				{
					if (!empty($waitbuffer))
					{
						$el = $pattern->required();
						if ($el)
						{
							$pattern->trymatch($el);
							array_push($outputbuffer, new xhtml_element($el));
						}
					}   
				}
				// we have tried everything else, this element simply can't fit!  so
				// we strip it.  that means we get rid of it and use its contents
				// instead				
				if (!$hint and !empty($child->contents)) 
				{
					$filterbuffer = array_merge($child->contents, $filterbuffer);
					$tries += (count($child->contents) * 2);
				}
	  	}
	  	if (empty($filterbuffer))
	  	{
				if ($el = $pattern->required())
				{
					$pattern->trymatch($el);
					array_push($outputbuffer, new xhtml_element($el));
				}
				$filterbuffer = $waitbuffer;
	  		$waitbuffer = array();
	  	}
	  }
	  // check required sub-elements
		while ($el = $pattern->required())
		{
			$pattern->trymatch($el);
			array_push($outputbuffer, new xhtml_element($el));
		}
		
		$element->contents = $outputbuffer;

	  // proceed to children 
	  $keys = array_keys($element->contents);
	  while (list(,$key) = each($keys)) $this->dofilterxhtml($element->contents[$key]);
	  $this->inclbranch = $inclbak;
	}
	
	function quickelementcheck($hinttype, $element)
	{
		if ($element == '#CDATA') $element = '#PCDATA';
		$patt = (isset($this->elements[$hinttype])) ? $this->elements[$hinttype] : 'EMPTY';
		if ($patt['type'] == 'sel') return is_array($patt) and ($patt['quant'] == '+' or $patt['quant'] == '*') and in_array($element, $patt['contents']);
		return $patt['type'] == 'seq' and ($patt['quant'] == '+' or $patt['quant'] == '*') and count($patt['contents']) == 1 and $element == current($patt['contents']);
	}
	
	function checkattribute($pattern, $value)
	{
		// TODO : if an attribute value like a NAME or IDREF etc contains invalid
		// chars, strip them out so it has a hope of working rather than remove
		// the attribute altogether
		
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
			case 'NAME':
			case 'IDREF':
				if (!preg_match('/^[a-zA-Z][a-zA-Z0-9._:-]*$/', $value)) return false;
				return true;
			case 'NAMES':
			case 'IDREFS':
				if (!preg_match('/^[a-zA-Z][a-zA-Z0-9._:\s-]*$/', $value)) return false;
				return true;
			case 'NMTOKEN':
				return preg_match('/^[a-zA-Z0-9._:-]*$/', $value);
			case 'NMTOKENS':
				return preg_match('/^[a-zA-Z0-9._:\s-]*$/', $value);
			case 'NUMBER':
				return preg_match('#^[0-9]+$#', $value);	
			default:
				trigger_error('Unknown attribute pattern: ' . $pattern, E_USER_ERROR);
		}
		return false;
	}
	
	function fixattribute($pattern, &$value, $elementname = NULL, $attname = NULL)
	{
		if (is_array($pattern))
		{
			if (!in_array($value, $pattern)) $value = reset($pattern);
			return;
		}
		if ($elementname == 'textarea')
		{
			if ($attname == 'rows') return $value = 3; //firefox 3 ie 2
			if ($attname == 'cols') return $value = 22; //firefox 22 ie 20
		}
		if ($elementname == 'script' && $attname = 'type') return $value = "text/javascript";
		if ($elementname == 'style' && $attname = 'type') return $value = "text/css";
		if ($elementname == 'bdo' && $attname = 'dir') return $value = "ltr";
		if ($elementname == 'basefont' && $attname = 'size') return $value = "3";
		if ($elementname == 'applet')
		{
			if ($attname == 'height') return $value = 150;
			if ($attname == 'width') return $value = 300;
		}
		switch ($pattern)
		{
			case 'ID':
				$value = uniqid('id', true);
				return;
			case 'NUMBER':
				return $value = 10;
		}
	}
	
	function autoclose(&$tag)
	// automatically closes any tag for an element that is incapable of containing any children
	{
		$element = $tag->element;
		if (isset($this->elements[$element])) return $this->elements[$element] == 'EMPTY';

		// else, tags which are legacy or proprietary but which nevertheless break if not auto closed
		return in_array($element, array('spacer'));
	}
	
	function filter_stripscripts()
	// allowsimples will text-align, etc
	{
		$element = &$this->rootelement;
		$this->dofilterstripscripts($this->rootelement);
	}
		
	function dofilterstripscripts(&$element)
	{
		if ($element->element == 'script')
		{
			$element = new xhtml_element('_invalid');
			return;
		}
		
		if ($element->element == 'noscript')
		{
			$contents = $element->contents;
			$element = new xhtml_element('div');
			$element->contents = $contents;
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
		$element = &$this->rootelement;
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
			if ($val->element == '_invalid') unset($element->contents[$key]);
				else $this->dofilterlegacystyles($element->contents[$key]);
	  }
	}
	
	function filter_stripstyles($allowsimple = false, $allowquasi = false)
	// allowsimple will text-align, etc
	{
		$element = &$this->rootelement;
		$this->dofilterstripstyles($this->rootelement, $allowsimple, $allowquasi);
	}
		
	function dofilterstripstyles(&$element, $allowsimple, $allowquasi)
	{
		if ($element->element == 'style')
		{
			$element = new xhtml_element('_invalid');
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
	
	function filter_fixcontenttype()
	{
		// should be valid already or won't work
		$el = &$this->rootelement;
		if ($el->element != 'html') return;
		
		$head = null;
		
		foreach ($el->contents as $key => $val) if ($val->element == 'head')
		{
			$head = &$el->contents[$key];
			break;
		}
		if (!$head) return;
		
		// change it if already exists
		// we don't want to make any assumptions as to whether the XHTML document
		// will or will not be sent as text/html so we don't change the content-type
		// or add one if it's not specified
		foreach ($head->contents as $key => $val)
		{
			if ($val->element == 'meta' and isset($val->attributes['http-equiv']) and strtolower($val->attributes['http-equiv']) == 'content-type')
			{
				$head->contents[$key]->attributes['content'] = preg_replace('/;\s*charset=[\'\"]?[a-z0-9_-]*[\'\"]?/i', '; charset=utf-8', $head->contents[$key]->attributes['content']);
			}
		}
	}
	
	function filter_specifybase($base)
	// specify a new value for the 'base' element.
	{
    // should be valid already or won't work
    
    // add new base element to head
		$el = &$this->rootelement;
		if ($el->element != 'html') return;
		
		$head = null;
		
		foreach ($el->contents as $key => $val) if ($val->element == 'head')
		{
			$head = &$el->contents[$key];
			break;
		}
		if (!$head) return;
		
		$headbase = $base;
		foreach ($head->contents as $key => $val) if ($val->element == 'base')
		{
			if (!empty($val->attributes['href'])) $headbase = $val->attributes['href'];
			unset($head->contents[$key]);
		}
		$newbaseel = &new xhtml_element('base');
		$newbaseel->attributes['href'] = str_replace('"', '&quot;', $this->fixentities($headbase));
	
		array_unshift($head->contents, $newbaseel);
		
		if (!preg_match('/^((https?|file)\:\/\/[^\/\s]*)/', $base, $matches)) return;
		$host = $matches[1];
		
		$endpath = strrpos($base, '/');
		$hostpath = ($endpath === false) ? ($host . '/') : substr($base, 0, $endpath + 1);
		
		// search tree for other things I can change base of
		$this->dofilterbase($this->rootelement, $host, $hostpath);
	}
	
	function dofilterbase(&$element, $host, $hostpath)
	{
		// flash movies, activex style
		if ($element->element == 'object' and !empty($element->attributes['classid']) and $element->attributes['classid'] == 'clsid:D27CDB6E-AE6D-11cf-96B8-444553540000')
		{
			$keys = array_keys($element->contents);
			while (list(,$key) = each($keys)) if ($element->contents[$key]->element == 'param')
			{
				if (empty($element->contents[$key]->attributes['name']) or !in_array(strtolower($element->contents[$key]->attributes['name']), array('src', 'movie'))) continue;
				if (empty($element->contents[$key]->attributes['value'])) continue;
				$element->contents[$key]->attributes['value'] = $this->doconvertrelativeurl($element->contents[$key]->attributes['value'], $host, $hostpath);
			}
		}
		
		$keys = array_keys($element->contents);
	  while (list(,$key) = each($keys)) $this->dofilterbase($element->contents[$key], $host, $hostpath);
	}
	
	function doconvertrelativeurl($url, $host, $hostpath)
	// used by this->dofilterbase()
	{
		if (preg_match('/^([a-zA-Z_-]{1,32}\:)/', $url)) return $url; // skip absolute url with or without protocol
		if ($url{0} == '/') $url = $host . $url;
			else $url = $hostpath . $url;
		return $url;
	}
	
	function filter_addnotice($heading, $content, $linkurl = null, $linktext = null)
	// notice: all input must be valid utf-8.  If the user will be submitting this data,
	// be sure to filter it first
	{
		$el = &$this->rootelement;
		if ($el->element != 'html' or empty($el->contents)) return;
		
		foreach ($el->contents as $bodykey => $body) if ($body->element == 'body') break;
		if (empty($body) or $body->element != 'body') return;
		
		$div = new xhtml_element('div');
		$div->attributes = array('style' => 'position: absolute; z-index: 9999; text-align: left; width: 16%; top: 4px; right: 4px; background: #FFFFF0; color: #000000; font-size: small; padding: 4px; border: 1px solid #000000; font-family: Arial, Geneva, sans-serif;');
		$hd = new xhtml_element('div');
		$hd->attributes = array('style' => 'margin: 0 0 0.5em 0; font-weight: bold;');
		$hd->contents[] = new xhtml_element('#PCDATA', $this->fixentities($heading));
		$p = new xhtml_element('div');
		$p->contents[] = new xhtml_element('#PCDATA', $this->fixentities($content));
		$div->contents = array($hd, $p);
				
		if ($linkurl !== null and $linktext !== null)
		{
			$ln = new xhtml_element('div');
			$ln->attributes = array('style' => 'margin: 0.5em 0 0 0;');
			$a = new xhtml_element('a');
			$a->attributes = array('href' => str_replace('"', '&quot;', $this->fixentities($linkurl)), 'style' => 'color: #0000FF; text-decoration: underline;');
			$a->contents[] = new xhtml_element('#PCDATA', $this->fixentities($linktext));
			$ln->contents[] = $a;
			$div->contents[] = $ln;
		}
		
		array_unshift($el->contents[$bodykey]->contents, $div);
	}
	
	function filter_cleanparagraphs()
	{
		$element = &$this->rootelement;
		$this->dofiltercleanparagraphs($this->rootelement);
	}
		
	function dofiltercleanparagraphs(&$element)
	// removes empty paragraphs, converts consecutive <br> to paragraph break
	// todo : strip useless divs, convert divs to p
	{
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
						$newelement = new xhtml_element($element->element);
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
								$newelement = &new xhtml_element('p');
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
						$newelement = &new xhtml_element('p');
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
			if ($child->element == '#PCDATA' and !strlen(trim($child->data))) array_shift($collected);
		}
		// remove whitespace at end
		if (!empty($collected))
		{
			$child = end($collected);
			if ($child->element == '#PCDATA' and !strlen(trim($child->data))) array_pop($collected);
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
	
	function filter_cdatacompat()
	{
		// cdata compatibility is only an issue with xhtml served as html, which is
		// xhtml 1.0 only
		if ($this->iscompat)
			$this->dofiltercdatacompat($this->rootelement);
	}

	function dofiltercdatacompat(&$element)
	{
		// cdata script hack
		$out = array();
		foreach ($element->contents as $key => $val)
		{
			if ($val->element == '#CDATA')
			{
				if ($element->element == 'script' || $element->element == 'style')
				{
					if (!preg_match('#^([^/]|/[^*])*\*/.*/\*([^*]|\*[^/])*$#',$val->data))
					{
						$pre = new xhtml_element('#PCDATA', '/*');
						$val->data = '*/' . $val->data . '/*';
						$pos = new xhtml_element('#PCDATA', '*/');
						$out[] = $pre;
						$out[] = $val;
						$out[] = $pos;
					}
					else $out[] = $val;
				}
				else
				{
					$el = new xhtml_element('#PCDATA', $this->fixentities($val->data));
					$out[] = $el;
				}
			}
			else $out[] = $val;
		}
		$element->contents = $out;
		if (!empty($element->contents)) foreach ($element->contents as $key => $val)
			$this->dofiltercdatacompat($element->contents[$key]);
	}
	
	function filter_stripids()
	{
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
	var $keepwhitespace = true;
	
	var $elementids = array();
	var $headelement = NULL;
	var $dtd;
}

set_time_limit(5);

// TODO - fix strange behaviour with <p> elements inside <fieldset> in html4

// If a #PCDATA is required in a sequence, then a trymatch at that point on anything other than the PCDATA should succeed as if there was an empty PCDATA


$xhtmlparse = new xhtmlparse('<table><tfoot><tr><th></th></tr></tfoot></table>');
$xhtmlfilter = new xhtmlfilter($xhtmlparse->getrootelement(), 'html4-strict');

list($sec, $usec) = explode(' ', microtime());
 
//$xhtmlfilter->setwhitespace(true);
//$xhtmlfilter->filter_legacystyles();
$xhtmlfilter->filter_xhtml();
//$xhtmlfilter->filter_cdatacompat();
//$xhtmlfilter->filter_fixcontenttype();
//$xhtmlfilter->filter_addnotice('sss','dd');
//$xhtmlfilter->filter_stripstyles();
//$xhtmlfilter->filter_stripscripts();
//$xhtmlfilter->filter_stripids();
//$xhtmlfilter->filter_cleanparagraphs();

print_r($xhtmlfilter->gethtml());

list($xsec, $xusec) = explode(' ', microtime());

$elapsed = ($xsec - $sec) + ($xusec - $usec);
echo "\n$elapsed\n";
	  
?>