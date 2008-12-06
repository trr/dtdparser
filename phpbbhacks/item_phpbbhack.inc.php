<?php

// item_phpbbhack.inc.php
// developed for phpbbhacks.com

class item_phpbbhack extends item
{
	function view()
	{
		return 'Not yet implemented';
	}
	
	function geteditfields(&$easyform, $isnew = false)
	{
		$easyform->addinput('html', 'item_phpbbhack_longdescription', $isnew ? NULL : $this->nodedata['item_phpbbhack_longdescription'], 'Long Description', 'Enter all relevant information about this hack for displaying on the hack page.', 65535, true);
	}
	
	function gettabledefs()
	{
		$tabledefs = array();
		
		/* item_phpbbhack */
		
		$tabledefs['item_phpbbhack']['fields'] = array(
			'item_phpbbhack_nodeID'          => array('int(11)', '0'),
			'item_phpbbhack_body'            => array('mediumtext'),
			'item_phpbbhack_minphpbbversion' => array('varchar(16)', ''),
			'item_phpbbhack_maxphpbbversion' => array('varchar(16)', ''),
			'item_phpbbhack_downloads'       => array('int(11)', '0'),
			);
		$tabledefs['item_phpbbhack']['indexes'] = array(
			'PRIMARY' => array('PRIMARY', 'item_phpbbhack_nodeID'),
			);
		return $tabledefs;
	}
}

?>
