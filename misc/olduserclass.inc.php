function user(&$db, $sessionlength, $rootcookie = false)
	{
		$this->db = &$db;
		$this->context = &context::getinstance();
		$this->debug = &debug::getinstance();
		$this->prefix = $db->get_prefix();
		$cookiepath = $rootcookie ? '/' : ''; 
		
		$this->data = array();
		
		$session_exists = false;
		$this->data['loginfailure'] = false;
		$this->data['logged_in'] = false;
		
		if (!defined('TIMENOW')) define('TIMENOW', time());
	  		
		$expire = TIMENOW - $sessionlength;
		$timenow = TIMENOW;

		if ($this->context->load_var('user-action', 'POST', 'name') == 'login')
		{
			// user is attempting to log in.  Check username and password
			$this->debug->notice('user', 'login attempt');
			$loginuser = $this->context->load_var('username', 'REQUEST', 'name');
			$loginpassword = $this->context->load_var('password', 'REQUEST', 'password');
			$md5password = md5($loginpassword);

			$this->data['details'] = $this->db->query_single("
				SELECT
					*
				FROM 
					{$this->prefix}user
				WHERE
					user_name='$loginuser'
					AND user_password='$md5password'
				");
			
			if ($this->data['details']['user_ID'] > 0)
				$this->data['logged_in'] = true;
			else 
				$this->data['loginfailure'] = true;
	    }
	    
	    if (!$this->data['logged_in'])
		{
			// user didn't just login.  See if they have a session cookie			
			
			$sessionhash = $this->context->load_var('s', 'GET', 'location');
			if (!$sessionhash)
				$sessionhash = $this->context->load_var('s', 'REQUEST', 'location');
			// see if a session exists
	  		if ($sessionhash)
	  		{
				$ipmask = $this->getipmask();
  		  
				$this->data['details'] = $this->db->query_single("
					SELECT
						u.*,
						session_hash
					FROM 
						{$this->prefix}session s
						LEFT JOIN {$this->prefix}user u ON
							user_ID=session_userID
					WHERE
						session_hash='$sessionhash'
						AND session_IP='$ipmask'
						AND session_lastvisited >= $expire
					");
	  			if ($this->data['details']['user_ID'] > 0)
	  			{
					$this->data['logged_in'] = true;
					$session_exists = true;
	  			}
				else
				// if this was a cookie, clear it because it's not valid
				{
					if ($sessionhash == $this->context->load_var('s', 'COOKIE', 'location')) 
						$this->context->setcookie('s', '', TIMENOW, $cookiepath);
				}
			}
		}
		
		if (!$this->data['logged_in'])
		{
			// user didn't just login and has no session cookie.  check for long-term
			// cookie
			$loginuser = $this->context->load_var('u', 'REQUEST', 'location');
			if ($loginuser && preg_match('#^\d+\.\w{32}$#', $loginuser))
			{
				$this->debug->notice('user', 'long term cookie detected');
				$details = explode('.', $loginuser);
				$userid = array_shift($details);
				$farhash = array_shift($details);
				
				// look up the claimed user's details
				$this->data['details'] = $this->db->query_single("
					SELECT
						*
					FROM
						{$this->prefix}user
					WHERE
						user_id='$userid'
					");
				$nearhash = md5('f8b2dae3bed16ed0e268a93fe0a0b684'
					. $this->data['details']['user_seed']
					. $this->data['details']['user_password']
					. $this->getipmask());
				if (($nearhash == $farhash) and ($this->data['details']['user_ID'] > 0))
				{
				  $this->data['logged_in'] = true;
					$this->debug->notice('user', 'login with long term cookie');
				}
				else
				// if this was a cookie, clear it because it's not valid
				{
				  unset($this->data['details']);
					$this->context->setcookie('u', '', TIMENOW, $cookiepath);
				}
			}
	  	}

	  	if ($this->data['logged_in'] 
			&& ($this->context->load_var('user-action', 'POST', 'name') == 'logout'))
	    {
			// user is attempting to log out.  destroy their session and clean up
			// their cookies  	  
			$this->db->query('DELETE FROM ' . $this->prefix . "session 
				WHERE session_userID = " . $this->data['details']['user_ID']);
			$this->data['logged_in'] = false;
			$session_exists = false;
			$this->context->setcookie('s', '', TIMENOW, $cookiepath);
			$this->context->setcookie('u', '', TIMENOW, $cookiepath);
	  	}
	  	
		if ($this->data['logged_in'])
		{
			// user is now considered to be logged in			
			$userid = $this->data['details']['user_ID'];
						
			if (!$session_exists)
			{
				// occasionally delete expired sessions
				if (mt_rand(0,100 == 50))
				$this->db->query("DELETE FROM {$this->prefix}session 
					WHERE session_lastvisited < $expire");
				
				$sessionhash = $this->makesessionhash();
				// insert into session database
				$ipmask = $this->getipmask();
				$this->db->query("
					INSERT INTO {$this->prefix}session 
						(session_hash, session_userID, session_IP, session_lastvisited) 
					VALUES ('$sessionhash', $userid, '$ipmask', '$timenow')");
				$this->context->setcookie('s', $sessionhash, 0, $cookiepath);
			}
			else
			// update existing session data
			{
				$this->db->query('
					UPDATE ' . $this->prefix . "session 
					SET session_lastvisited='$timenow'
					WHERE session_hash = '" . $this->data['details']['session_hash'] . "'");
				if (!isset($_COOKIE['s']) || $_COOKIE['s'] != $this->data['details']['session_hash'])
					$this->context->setcookie('s', $this->data['details']['session_hash'], 0, $cookiepath);
			}
			// find out what groups the member belongs to
			$this->data['user_groups'] = array(-3);
			if ($this->data['details']['user_active'])
			{
				$this->data['user_groups'] = array(-2); // registered users
				$this->db->query('SELECT groupuser_groupID FROM ' . $this->prefix . "groupuser 
					WHERE groupuser_userID='$userid'");
				while ($row = $this->db->fetch_array())
					$this->data['user_groups'][] = $row['groupuser_groupID'];
				$this->db->free_result();
			}
			// see if we need to set a long term cookie
			if ($this->context->load_var('login-rememberme', 'POST', 'yesno'))
			{
				$hash = md5('f8b2dae3bed16ed0e268a93fe0a0b684'
					. $this->data['details']['user_seed']
					. $this->data['details']['user_password']
					. $this->getipmask());
				$userdata = $userid . '.' . $hash;	
				$this->context->setcookie('u', $userdata, TIMENOW + 31536000, $cookiepath);
			}
			
			$specialurl = &specialurl::getinstance();
			$specialurl->setsid($sessionhash, $rootcookie);
			
			// for helping prevent CSRF
			$this->context->setinitiatortoken(	
				md5('80c43d3001908cdd572d911d7ee1c61f'
				. $this->data['details']['user_seed']
				. $this->data['details']['user_password']
				. $this->getipmask()));
			
			$this->debug->notice('user', 'user logged in', $this->data['details']['user_name']);
		}
		else
		{
			$this->data['logged_in'] = false;
			unset($this->data['details']);
			$this->data['details']['user_ID'] = 0;
			$this->data['details']['user_name'] = 'Guest';
			$this->data['user_groups'] = array(-3);
			$this->context->setinitiatortoken( // helps prevent CSRF, not a session			
				md5('6300adff3f39c36caf13d0b992892b92' . $this->getipmask() .
				(isset($_SERVER['HTTP_USER_AGENT'])?preg_replace('/[\d\s]++/', '_', 
				$_SERVER['HTTP_USER_AGENT']):'')));
			$this->debug->notice('user', 'No user logged in (guest session)');
		}
	}