	function parsetext($text)
	// turn a crude text representation of HTML into HTML
	// this should be moved to a different module eventually, as it isn't anything
	// to do with parsing XHTML/HTML
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
		return "<body>$data</body>";	
	}