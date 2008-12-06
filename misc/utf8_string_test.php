<?php

require_once('../../www/resources/includes/utf8_string.inc.php');

$random = '';
for ($i = 0; $i < 100; $i++)
	$random .= utf8_string::chr(mt_rand(40, 60) + (300 * mt_rand(0,1)) + (6000 * mt_rand(0,1)));
$randominvalid = '';
for ($i = 0; $i < 100; $i++)
	$randominvalid .= chr(mt_rand(0,255));
	
$text = '';
for ($i = 0; $i < 2200; $i++)
	$text .= "The quick brown fox $random $randominvalid";
	
$str = new utf8_string($text);

echo strlen($text);
echo "ready\n";

list($sec, $usec) = explode(' ', microtime());

for ($i = 0; $i < 10; $i++)
	$result = $str->tolower("", false);
	
list($xsec, $xusec) = explode(' ', microtime());
$elapsed = ($xsec - $sec) + ($xusec - $usec);
echo "\n$elapsed\n";

echo substr(var_export($result, true), 0, 1000);

?>