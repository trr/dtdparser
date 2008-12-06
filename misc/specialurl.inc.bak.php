<?php

// specialurl.inc.php

// provides functions for working with special urls


// Format of special urls:
//  [path] [location] [query]

//  path: includes protocol, hostname and path.  Ends with slash.
//  location: can contain letters, numbers, and any of the characters '_-.' only
//  query: begins with question mark, can contain any query string.

// The special url:               http://www.host.com/path/page1?query=1
// translates to the regular url: http://www.host.com/path/?l=page1&query=1

// If special urls is enabled, you must have URL rewriting to match. 

class specialurl
{
  // public
	
	function specialurl($specialurls = false)
	{
	  $this->specialurls = $specialurls;
		$this->queryadd = '';
		$this->comppath = '';
		
		// get server vars
		$port = $_SERVER['SERVER_PORT'];
		$host = $_SERVER['HTTP_HOST'];
		$uri = $_SERVER['REQUEST_URI'];
		
		// decide protocol
		$protocol = 'http:';
		if ($port == 443)	$protocol = 'https:';
		
		// validate location
		if (isset($_REQUEST['l'])) $location = $_REQUEST['l'];
		  else $location = NULL;
		if ($location and !(ereg("^[a-zA-Z0-9_.-]+$", $location))) $location = NULL;
		
		// get vars from url
    $url_array = explode('/', $uri);
	  
    $lastbit = array_pop($url_array);
			
		$path = implode('/', $url_array) . '/';
		$this->path = $protocol . '//' . $host . $path;
		
		if (!$location)
		{
		 	list($location) = explode('?', $lastbit);
		}
		$this->location = $location;
		if (empty($_COOKIE)) $this->alwaysadd = true;
			else $this->alwaysadd = false;
	}
	
	function alwayssid()
	// if this returns false, it indicates that a session id will only be used on
	// links with external paths
	{
		return $this->alwaysadd and !empty($this->queryadd);
	}
	
	function usedpath()
	// returns true if an external path has been generated since the last call to
	// usedpath()
	// indicates the result is not cacheable because some users may need sid applied
	{
		$out = $this->usedpath and !empty($this->queryadd);
		$this->usedpath = false;
		return $out or ($this->alwaysadd and !empty($this->queryadd));
	}
	
	function setsid($value, $rootcookie = false)
	// sets the query string append string
	{
  	$this->queryadd = 's=' . $value;
  	$this->comppath = $rootcookie ? $this->rootpath : $this->path;
	}
	
	function dourl($path='', $file='', $query='')
	// this takes a url and processes it.  Any of the parameters may be blank
	// note: this can be optimised further
	{
		$samepath = (!$path or substr($path, 0, strlen($this->comppath)) == $this->comppath);
		if (!$samepath) $this->usedpath = true;
		if ($path == $this->path) $path = '';
		if ($this->specialurls)
		{
			$out = $path . $file;
			if (!$out) $out = '.';
			if ($samepath and !$this->alwaysadd) return $query ? "$out?$query" : $out;
			if (!$this->queryadd) return $query ? "$out?$query" : $out;
			return $query ? "$out?$query&$this->queryadd" : "$out?$this->queryadd";
		}
		$out = $path ? $path : '.';
		if ((!$samepath or $this->alwaysadd) and $this->queryadd) $query .= $query ? '&' . $this->queryadd : $this->queryadd;
		if ($query) return $file ? "$out?l=$file&$query" : "$out?$query";
		return $file ? "$out?l=$file" : $out;
	}
	
	function dospecialurl($url)
	// takes a special url and adds the query string if necessary, and converts it
	// to a regular url if special urls is turned off
	{
		$endpath = strrpos($url, '/');
		if ($this->specialurls)
		{
			if ($endpath === false and (!$this->alwaysadd or !$this->queryadd)) return $url;
			$samepath = (substr($url, 0, strlen($this->comppath)) == $this->comppath);
			if ($samepath) $this->usedpath = true;
			if (substr($url, 0, $endpath + 1) == $this->path) $url = substr($url, $endpath + 1);
			if (!$this->queryadd or ($samepath and !$this->alwaysadd)) return $url;
			$startquery = strrpos($url, '?');
			return ($startquery === false) ? "$url?$this->queryadd" : "$url&$this->queryadd";
		}
	  $startquery = strrpos($url, '?');
		$query = $path = '';
		if ($startquery !== false)
		{
			$query = substr($url, $startquery + 1);
			$url = substr($url, 0, $startquery);
		}
		if ($endpath !== false)
		{
			$path = substr($url, 0, $endpath + 1);
			$url = substr($url, $endpath + 1);
		}
		elseif ($url == '.') $url = '';
		return $this->dourl($path, $url, $query);
	}
	
	function getlocation()
	{
	  return $this->location;
	}
	
	function getpath()
	{
	  return $this->path;
	}
	
	function &getinstance($specialurls = false, $queryadd='')
	// singleton implementation
	// these parameters are used to create the object if the object doesn't exist
  {
    static $instance;
  	if (!isset($instance)) $instance = new specialurl($specialurls, $queryadd); 
  	return $instance;
  }

	// private
	var $specialurls;
	var $queryadd;
	var $alwaysadd;
	var $path;
	var $rootpath;
	var $comppath;
	var $location;
	var $usedpath = false;
}

?>
