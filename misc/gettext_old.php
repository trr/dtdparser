	
	function gettext()
	{  // TODO low priority - move this to another file
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
	