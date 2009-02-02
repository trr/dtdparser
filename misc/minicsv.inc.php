<?php

// minicsv.inc.php

// for reading and writing tabular data in CSV format, for small amounts of data
// (ie, all in-memory)

class minicsv
{
	//static
	function csvencode($values)
	// encode a two dimensional array into CSV format and return the CSV as a string
	// does multiple rows at a time
	// you COULD use this multiple times to work on a large CSV file
	// does not encode array keys - if keys are needed try using first row
	{
		$output = '';
		foreach ($values as $row)
		{
			$rowstart = true;
			foreach ($row as $value)
			{
				if (!$rowstart)
					$output .= ',';
				else
					$rowstart = false;
				
				if (is_int($value) || is_float($value))
					$output .= $value;
				elseif ($value === null)
					$output .= '';
				elseif (is_bool($value))
					$output .= ($value ? '1' : '0');				
				elseif ($value === '')
					$output .= '""';
				elseif (strcspn($value, "\",\n") === strlen($value) && $value[0] != ' ' 
					&& $value[strlen($value)-1] != ' ' && !is_numeric($value))
					$output .= $value;
				else
					$output .= '"' . str_replace('"', '""', $value) . '"';
			}
			$output .= "\n";
		}
		return $output;
	}
	
}


$myarr = array(
	array('5', 5, 'five'),
	array('This has a "quote" in it',5,5.5),
	array('"This is all quote"',6, 6),
	array(" This has leading spaces",7,7),
	array("This has trailing spaces ",8,8),
	array("This\nhas\r\nmultiple\nlines\tand\ttabs",9,9),
	array("next is empty string",'',10),
	array("next is null",null,9),
	array('true and false',true,false,10),
	array("UTF8 2-byte chars: \xc2\xa2\xc2\xa2\xc2\xa5\xc2\xa2\xc2\xa9\xc2\xa2\xc2\xa2\xc2\xa3\xc2\xa2", 1),
	array('key' => 'value'),
	);
	
$jsonencoded = json_encode($myarr);
$serialized = serialize($myarr);
	
list($sec, $usec) = explode(' ', microtime());

set_time_limit(12);

for ($i = 0; $i < 1000; $i++)
{
	//$result = minicsv::csvencode($myarr);
	//$result = serialize($myarr);
	//$result = json_encode($myarr);
	$result = json_decode($jsonencoded);
	//$result = unserialize($serialized);
}

list($xsec, $xusec) = explode(' ', microtime());

$elapsed = ($xsec - $sec) + ($xusec - $usec);
echo count($result) . " " . strlen((string)$result);
echo "\n$elapsed\n";
echo substr(var_export($result, true), 0, 6000);
exit;

?>