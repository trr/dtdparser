<?php

// item_xhtmlfilter_interface.inc.php

class item_xhtmlfilter_interface extends item
{
	function gethandlers()
	{
		return array(
			'filterxhtml' => array('handler' => 'filterxhtml', 'permissiontype' => 'view'),
		  );
	}
	
	function view()
	{
		switch ($this->task)
		{
			case 'preview':
				return $this->preview($this->parms[2]);
			case 'download':
				return $this->download($this->parms[2]);
			case 'result':
				return $this->showresult($this->parms[2]);
			default:
				if ($this->data) return $this->gotoresult();
				return $this->showinterface();
		}
	}
	
	function showinterface()
	{
		if (!$this->uploadform) $this->initforms();
		
		$this->template->setvars(array(
			'item-uploadform' => $this->uploadform->execute(),
			'item-remoteform' => $this->remoteform->execute(),
			'item-optionform' => $this->optionform->execute()
			));
		
		return $this->template->run('type-xhtmlfilter-interface');
	}
	
	function preview($hash)
	{
		$hash = preg_replace('/[^a-zA-Z0-9]/', '', $hash);
		
		if (!$this->fetchprevious($hash) or $this->filterdate < (TIMENOW - $this->expiry))
		{
			$this->template->insert('notices', 'Error: Unable to display the preview you requested.  XHTMLFilter stores a preview of your filtered document for up to ' . floor($this->expiry / 60) . ' minutes only.  Please try filtering your document again.');
			$this->metadata['cache_ttl'] = 0;
			return $this->showinterface();
		}
		
		$isxml = preg_match('#^xhtml(?!1-)#i', $this->dtd);
		$contenttype = $isxml ? 'application/xhtml+xml' : 'text/html';
			   
		$this->metadata['content_type'] = "$contenttype; charset=utf-8"; 
		$this->metadata['cache_ttl'] = ($this->filterdate + $this->expiry) - TIMENOW;
		return $this->previewdata;
	}
	
	function download($hash)
	{
		$hash = preg_replace('/[^a-zA-Z0-9]/', '', $hash);
		
		if (!$this->fetchprevious($hash) or $this->filterdate < (TIMENOW - $this->expiry))
		{
			$this->template->insert('notices', 'Error: Unable to fetch the document you requested.  XHTMLFilter stores a copy of your filtered document for up to ' . floor($this->expiry / 60) . ' minutes only.  Please try filtering your document again.');
			$this->metadata['cache_ttl'] = 0;
			return $this->showinterface();
		}
		
		$isxml = preg_match('#^xhtml#i', $this->dtd);
		$contenttype = $isxml ? 'application/xhtml+xml' : 'text/html';
		$extension = $isxml ? 'xhtml' : 'html';
		
		$this->metadata['content_type'] = "$contenttype; charset=utf-8";
		$this->metadata['http_headers'] = array("Content-Disposition: attachment; filename=xhtmlfilter_output.$extension");
		$this->metadata['cache_ttl'] = ($this->filterdate + $this->expiry) - TIMENOW;
		return $this->data;
	}
	
	function gotoresult()
	{
		//header('Content-Type: text/html; charset=utf-8');
		//exit($this->previewdata);
		
		if (!$this->hash) trigger_error('No hash in XHTMLFilter Interface gotoresult()', E_USER_ERROR);
		
		$redirecturl = $this->specialurl->doexporturl($this->nodedata['fullurl'] . '.result.' . $this->hash, true);
		$this->metadata['redirect_url'] = $redirecturl;
		
		return 'This page should redirect to ' . $redirecturl;
	}
	
	function showresult($hash)
	{
		$hash = preg_replace('/[^a-zA-Z0-9]/', '', $hash);
		
		if (!$this->fetchprevious($hash) or $this->filterdate < (TIMENOW - $this->expiry))
		{
			$this->template->insert('notices', 'Error: Unable to fetch the document you requested.  XHTMLFilter stores a copy of your filtered document for up to ' . floor($this->expiry / 60) . ' minutes only.  Please try filtering your document again.');
			$this->metadata['cache_ttl'] = 0;
			return $this->showinterface();
		}
		
		$this->template->setvars(array(
			'item-data' => $this->data,
			'item-dtdtitle' => $this->getdtdtitle($this->dtd),
			'item-hash' => $this->hash
			));
		
		$this->metadata['cache_ttl'] = ($this->filterdate + $this->expiry) - TIMENOW;
		return $this->template->run('type-xhtmlfilter-interface-result');
	}
	
	function fetchprevious($hash)
	{
		$entry = $this->db->query_single("SELECT * FROM {$this->prefix}data_xhtmlfilter_interface WHERE data_xhtmlfilter_interface_hash='$hash'");
		$date = TIMENOW;
			
		if ($entry)
		{
			$this->data = gzuncompress($entry['data_xhtmlfilter_interface_result']);
			$this->previewdata = gzuncompress($entry['data_xhtmlfilter_interface_preview']);
			$this->dtd = $entry['data_xhtmlfilter_interface_dtd'];
			$this->hash = $hash;
			$this->filterdate = $entry['data_xhtmlfilter_interface_date'];
			return true;
		}
		return false;
	}
	
	function filterxhtml()
	{
		$this->initforms();
		
		$values = array(
			$this->uploadform->getvalues(), 
			$this->remoteform->getvalues(), 
			$this->optionform->getvalues()
			);
			
		foreach ($values as $val) if (!is_array($val))
		{
			$this->template->insert('notices', 'There was an error with the below form.  Please correct the error and try again.');
			return;
		}
		
		if ($values[0]['uploadfile'] == null and !$values[1]['remotefile'])
		{
			$this->template->insert('notices', "Please enter either a file or web address for filtering.");
			return;
		}
		
		//flood control
		$floodcontrol = &$this->controls->getnodecontrol('floodcontrol');
		$floodcontrol->setuserid($this->userdata['details']['user_ID']);
		if ($delay = $floodcontrol->getdelay('item_xhtmlfilter_interface_filter', 20, 4))
		{
			$this->template->insert('notices', "Error: The page could not be filtered because we have limited the number of pages which can be filtered in a short time.  Please wait $delay seconds before trying again.");
			return;
		}
			
		$debug = &debug::getinstance();
		$taskid = $debug->starttask('xhtmlfilter_interface', 'Receiving file');
		
		$remotefile = null;
				
		if ($values[0]['uploadfile'] !== null)
		{
			$data = $values[0]['uploadfile'];
		}
		elseif ($values[1]['remotefile'] and $values[1]['remotefile'] != 'http://')
		{
			$remotefile = $values[1]['remotefile'];
			
			$olduseragent = ini_get('user_agent');
			ini_set('user_agent', 'XHTMLFilter/0.9');
			ini_set('default_socket_timeout', 20);
			set_time_limit(30);
			$starttime = time();
			
			// fetch remote file
			$fp = @fopen($remotefile, 'rb');
			if (!$fp)
			{
				$this->template->insert('notices', "The web page {$values[1]['remotefile']} cannot be opened.  Please check the address and try again.");
				return;
			}
			$data = '';
			while (!feof($fp) and strlen($data) < $this->maxfilesize and (time() - $starttime) < 60)
			{
				$data .= @fread($fp, $this->maxfilesize - strlen($data));
				set_time_limit(30);
			}
			$meta = stream_get_meta_data($fp);
			if (!empty($meta['wrapper_data'])) foreach ($meta['wrapper_data'] as $key => $val)
			{
				// get redirection url if it exists
				if (preg_match('/^Location:\s*(https?\:[^\s]*)/i', $val, $matches)) $remotefile = $matches[1];
			}
			if (!feof($fp))
			{
				if (strlen($data) >= $this->maxfilesize) $this->template->insert('notices', "Error: The web page {$values[1]['remotefile']} is too large to be filtered using XHTMLFilter.  The maximum size of page that can be filtered is " . floor($this->maxfilesize / 1024) . " Kilobytes");
				elseif ((time() - $starttime) >= 60) $this->template->insert('notices', "Error: The web page {$values[1]['remotefile']} did not load within 60 seconds.");
				else $this->template->insert('notices', "Error: There was a problem reading from the web page {$values[1]['remotefile']}.");
				fclose($fp);
				return;
			}
			fclose($fp);
			
			if (!strlen($data))
			{
				$this->template->insert('notices', "Error: When requesting the web page {$values[1]['remotefile']}, the server returned no data.");
				return;
			}
			ini_set('user_agent', $olduseragent);
			
			// add trailing slash to address if it contains only a hostname like http://www.example.com
			if (preg_match('/^https?\:\/\/[^\/]+$/', $remotefile)) $remotefile .= '/';
		}
		
		$debug->endtask($taskid);
		
		if (!strlen($data))
		{
			$this->template->insert('notices', "The document failed to open.  Please make enter either a file or web address for filtering.");
			return;
		}
		
		// find root element
		$match = preg_match('/^(\s*(\<[^\>]*\>\s*)*)\<html/i', substr($data, 0, 1024), $matches);
		
		if ($match) $data = substr($data, strlen($matches[1]));
			else $data = '<html>' . $data;
			
		$hash = md5($data . "::::" . $remotefile . "::::" . $values[2]['dtd']);
		$this->hash = $hash;
		
		// see if this is in the database already

		$entry = $this->db->query_single("SELECT * FROM {$this->prefix}data_xhtmlfilter_interface WHERE data_xhtmlfilter_interface_hash='$hash'");
		$this->filterdate = $date = TIMENOW;
			
		if ($entry)
		{
			$this->data = gzuncompress($entry['data_xhtmlfilter_interface_result']);
			$this->previewdata = gzuncompress($entry['data_xhtmlfilter_interface_preview']);
			$this->dtd = $entry['data_xhtmlfilter_interface_dtd'];
			
			// uncomment to disable cache
			$this->dofilter($data, $remotefile, $values[2]['dtd']);
			
			$this->db->query("UPDATE {$this->prefix}data_xhtmlfilter_interface SET data_xhtmlfilter_interface_date=$date WHERE data_xhtmlfilter_interface_hash='$hash'");
		}
		else
		{
			$this->dofilter($data, $remotefile, $values[2]['dtd']);
			
			$resultslashed = addslashes(gzcompress($this->data, 1));
			$previewslashed = addslashes(gzcompress($this->previewdata, 1));
			$pathslashed = addslashes($remotefile);
			
			$this->db->query("
				INSERT IGNORE INTO
					{$this->prefix}data_xhtmlfilter_interface
				SET
					data_xhtmlfilter_interface_hash='$hash',
					data_xhtmlfilter_interface_result='$resultslashed',
					data_xhtmlfilter_interface_preview='$previewslashed', 
					data_xhtmlfilter_interface_dtd='$this->dtd',
					data_xhtmlfilter_interface_path='$pathslashed',
					data_xhtmlfilter_interface_date=$date
				");
				
			list($totallength) = $this->db->query_single("
				SELECT
					SUM(LENGTH(data_xhtmlfilter_interface_result) * 2) AS length
				FROM
					{$this->prefix}data_xhtmlfilter_interface	
				");
		  if ($totallength > $this->maxstorage)
			{
				$this->db->query("
					SELECT
						data_xhtmlfilter_interface_date AS date,
						LENGTH(data_xhtmlfilter_interface_result) * 2 AS length
					FROM
						{$this->prefix}data_xhtmlfilter_interface
					ORDER BY
						data_xhtmlfilter_interface_date DESC
					");
				$total = 0;
				$cutoff = null;
				$newlimit = floor($this->maxstorage * 0.8);
				while ($row = $this->db->fetch_array())
				{
					$total += $row['length'];
					if ($total > $newlimit and $cutoff === null) $cutoff = $row['date'];				
				}
				$this->db->free_result();
				if ($cutoff > ($date - 900)) $cutoff = null;
			
				if ($cutoff)
				{
					$this->db->query("DELETE FROM {$this->prefix}data_xhtmlfilter_interface WHERE data_xhtmlfilter_interface_date<=$cutoff");
					$this->db->query("OPTIMIZE TABLE {$this->prefix}data_xhtmlfilter_interface");
				}
			}
		}
	}
	
	function getdtdpublic($dtd)
	{
		if ($dtd == 'html')   return array('-//IETF//DTD HTML 2.0//EN', null);
		if ($dtd == 'html32')	return array('-//W3C//DTD HTML 3.2 Final//EN', null);
		
		if ($dtd == 'html4-strict')		
			return array('-//W3C//DTD HTML 4.01//EN', 'http://www.w3.org/TR/html4/strict.dtd');
		
		if ($dtd == 'html4-loose')		
			return array('-//W3C//DTD HTML 4.01 Transitional//EN', 'http://www.w3.org/TR/html4/loose.dtd');
		
		if ($dtd == 'html4-frameset')		
			return array('-//W3C//DTD HTML 4.01 Frameset//EN', 'http://www.w3.org/TR/html4/frameset.dtd');
		
		if ($dtd == '15445')
			return array('ISO/IEC 15445:2000//DTD HyperText Markup Language//EN', null);
		
		if (preg_match('#^xhtml1-#', $dtd))
			return array("-//W3C//DTD " . $this->getdtdtitle($dtd) . "//EN", "http://www.w3.org/TR/xhtml1/DTD/$dtd.dtd");

		if ($dtd == 'xhtml11')
			return array('-//W3C//DTD XHTML 1.1//EN', 'http://www.w3.org/TR/xhtml11/DTD/xhtml11.dtd');
			
		if (preg_match('#^xhtml-basic#', $dtd))
			return array("-//W3C//DTD " . $this->getdtdtitle($dtd) . "//EN", "http://www.w3.org/TR/xhtml-basic/$dtd");

		return array(null, null);
	}
	
	function getdoctype($dtd)
	{
		$public = $this->getdtdpublic($dtd);
		$doctype = "<!DOCTYPE html ";
		if ($public[0]) $doctype .= "\n     PUBLIC \"" . $public[0] . "\"";		
		if ($public[1]) $doctype .= "\n     \"" . $public[1] . "\"";
		$doctype .= ">\n";
		
		// 		"<!DOCTYPE html \n     PUBLIC \"-//W3C//DTD $dtdtitle//EN\"\n     \"http://www.w3.org/TR/xhtml1/DTD/$dtd.dtd\">\n"

		
		return $doctype;
	}
	
	function getdtdtitle($dtd)
	{
		static $dtdtitles = array(
			'xhtml11' => 'XHTML 1.1',
			'xhtml-basic11' => 'XHTML Basic 1.1',
			'xhtml-basic10' => 'XHTML Basic 1.0',
			'xhtml1-strict' => 'XHTML 1.0 Strict',
			'xhtml1-transitional' => 'XHTML 1.0 Transitional',
			'xhtml1-frameset' => 'XHTML 1.0 Frameset',
			'15445' => 'ISO HTML - ISO/IEC 15445:2000(E)',
			'html4-strict' => 'HTML 4.01 Strict',
			'html4-loose' => 'HTML 4.01 Transitional',
			'html4-frameset' => 'HTML 4.01 Frameset',
			'html32' => 'HTML 3.2',
			'html' => 'HTML 2.0'
			);
		return isset($dtdtitles[$dtd]) ? $dtdtitles[$dtd] : null;
	}
	
	// private
	function dofilter(&$data, $uploadfile, $dtd)
	{
		$debug = &debug::getinstance();
		
		//filter for invalid characters
		require_once(RESOURCE_DIR . 'includes/utf8_string.inc.php');
		$utf8 = new utf8_string($data);
		
		// UTF8 filtering				
		$taskid = $debug->starttask('xhtmlfilter_interface', 'UTF8 Filtering', strlen($data) . " bytes");
		$data = $utf8->filter();
		$debug->endtask($taskid);
				
		require_once(RESOURCE_DIR . 'includes/xhtmlfilter.inc.php');
		$xhtmlparse = new xhtmlparse($data);
		
		// parsing
		$taskid = $debug->starttask('xhtmlfilter_interface', 'XHTML Parsing');
		
		if ($dtd == 'auto')
		{
			require_once(RESOURCE_DIR . 'includes/xhtmldetect.inc.php');
			$xhtmldetect = new xhtmldetect($xhtmlparse->getrootelement());
			$dtd = $xhtmldetect->isframeset() ? 'html4-frameset' : ($xhtmldetect->istransitional() ? 'html4-loose' : 'html4-strict');
		}
		
		$dtdtitle = $this->getdtdtitle($dtd);
		if (!$dtdtitle)
		{
			$dtd = 'html4-strict';
			$dtdtitle = 'HTML 4.01 Strict';
		}
		
		$this->dtd = $dtd;
				
		$xhtmlfilter = new xhtmlfilter($xhtmlparse->getrootelement(), $dtd);
		
		$debug->endtask($taskid);
		
		// filtering
		$taskid = $debug->starttask('xhtmlfilter_interface', 'XHTML Filtering');
		$xhtmlfilter->filter_xhtml();
		$debug->endtask($taskid);
		
		$xhtmlfilter->filter_fixcontenttype();
		
		$doctype = $this->getdoctype($dtd);
		
		// render to html
		$taskid = $debug->starttask('xhtmlfilter_interface', 'Rendering');
		$this->data = $doctype . $xhtmlfilter->gethtml();
			
		// get preview version
		$baseurl = $uploadfile ? $uploadfile : 'file:///';
		$notice = $uploadfile ?
			'This is only a preview of your filtered web page.  Scripts such as JavaScript have been removed from this preview version.'
			:
			'This is only a preview of your filtered file.  Scripts such as JavaScript have been removed from this preview version.  Stylesheets, images or objects with relative URLs may not appear.';
		$xhtmlfilter->filter_stripscripts();
		$xhtmlfilter->filter_specifybase($baseurl);
		$xhtmlfilter->filter_addnotice('Filtered by XHTMLFilter', $notice, $this->specialurl->doexporturl($this->nodedata['thisurl'] . '.result.' . $this->hash), 'Back To Result Page');
		$this->previewdata = $doctype . $xhtmlfilter->gethtml();

		$debug->endtask($taskid);		
	}
	
	// private
	function initforms()
	{
		$this->uploadform = &$this->controls->getcommoncontrol('easyform');
		$this->uploadform->addinput('binary', 'uploadfile', '', 'File to Filter', 'Please choose the file you would like to filter', $this->maxfilesize, false);
		
		$this->remoteform = &$this->controls->getcommoncontrol('easyform');
		$this->remoteform->addinput('string', 'remotefile', '', 'Web Page Address', 'Please type the complete URL of the page you would like to filter', 255, false);
		$this->remoteform->setvalidator('remotefile', array(&$this, 'validateremotefile'));
		
		$this->optionform = &$this->controls->getcommoncontrol('easyform');
	  $this->optionform->addinput('select', 'dtd', 'auto', 'Output Document Type', 'Transitional and Frameset variants allow for additional compatibility with older documents', 32, true, false, array(
	    'auto' => 'Auto Detect',
			'html4-strict' => 'HTML 4.01 Strict',
			'html4-loose' => 'HTML 4.01 Transitional',
			'html4-frameset' => 'HTML 4.01 Frameset',
			'15445' => 'ISO HTML - ISO/IEC 15445:2000(E)',
			'html32' => 'HTML 3.2',
			'html' => 'HTML 2.0',
			'xhtml11' => 'XHTML 1.1',
			//'xhtml-basic11' => 'XHTML Basic 1.1',
			'xhtml-basic10' => 'XHTML Basic 1.0',
			'xhtml1-strict' => 'XHTML 1.0 Strict',
			'xhtml1-transitional' => 'XHTML 1.0 Transitional',
			'xhtml1-frameset' => 'XHTML 1.0 Frameset',
			));
	}
	
	function validateremotefile($control)
	{                        
		$val = $control['value'];
		if (!$val) return true;
		if (!preg_match('/^[a-zA-Z_-]{1,32}\:/', $val)) $control['value'] = $val = "http://" . $val;
		if (substr($val, 0, 7) !== 'http://' and substr($val, 0, 8) !== 'https://')
		{
			$control['errormsg'] = "This protocol is not supported.  Please try another address.";
			return false;
		}
		return true;
	}
	
	var $uploadform, $remoteform, $optionform;
	
	var $data = null;
	var $previewdata = null; 
	var $dtd = null;
	var $hash = null;
	var $filterdate = null;
	var $maxfilesize = 524288;  // maximum (uncompressed) size for single document
	var $maxstorage = 10485760; // maximum total (compressed) size of all cached documents
															// - may grow bigger than this if more than this this amount is stored within 15 minutes
	var $expiry = 900;          // after this number of seconds, a result will expire from user access (but will remain cached)
	
	function getmoduleproperties()
	{
		return array(
			'type_title' => 'XHTMLFilter filter page',
			'type_visible' => 1,
			'type_ttl' => 86400,
			'type_icon' => 'general.form',
			);
	}
}

?>