<?php

/*
 * ---------------------------------------------------------------
 * User functions
 * ---------------------------------------------------------------
 *
 * This page Is all the user-based actions such as login, logout,
 * session verification, etc. This needs to be included on any page.
 * 
 * TODO:
 *
 *     -Clean up functions with too many nested if statements
 *     -General code clean up
 * 	   -Maybe make exception codes mean something.
 *
 */

/**
 * Class user
 */
class user extends database
{

	/* 	Login Functions	 */

	// Check if the cookie is valid. If valid, set as session. This prevents deleted users with a cookie from being able to log in the site.
	/**
	 * @param $email
	 * @return bool
	 * @throws Exception
	 */
	public function session_check($email)
	{
		if (empty($_SESSION['authenticated']) && $_COOKIE['rememberMe'] === true && !empty($email)) {
			$_SESSION['authenticated'] = $email;
		}
		$params = [$email];
		$sql = "SELECT * FROM $this->user_table WHERE email = $1";
		$result = pg_query_params($this->link, $sql, $params);
		if ($result === false) {
			$message = 'Error in: class:user | function:session_check';
			$code = 1;
			throw new Exception($message, $code);
		}
		$row_cnt = pg_num_rows($result);
		return $row_cnt < 1 ? false : true;
	}

	// Do stuff, I guess? right now only checks if logout was used. This needs to be expanded
	/**
	 * @param $email
	 * @param null $action
	 * @return bool
	 */
	public function session_authenticate($email, $action = null)
	{
		if ($action == 'logout') {
			session_destroy();
			setcookie('rememberMe', null, -1, '/');
			setcookie('email', null, -1, '/');
			return false;
		}
		return true;
	}

	// Process the login, set the session and cookies (if desired)
	/**
	 * @param $email
	 * @param $password
	 * @return bool|string
	 */
	public function login($email, $password)
	{
		$params = [$email];
		$sql = "SELECT * FROM $this->user_table WHERE email = $1";
		$result = pg_fetch_assoc(pg_query_params($this->link, $sql, $params));
		if ($result === false) {
			return 'Account does not exist. Please register first.';
		}
		$hash = $result['password'];
		$verified = $result['verified'];
		$apikey = $result['api_key'];
		if ($verified === '1') {
			if (password_verify($password, $hash)) {
				$_SESSION['authenticated'] = $email;
				$_SESSION['api_key'] = $apikey;
				if ($_POST['rememberMe'] === 'true') {
					setcookie('rememberMe', true, time() + 31000000);
					setcookie('email', $email, time() + 31000000);
					setcookie('api_key', $apikey, time() + 31000000);
				}
				return true;
			} else {
				$status = 'Login failed.';
			}
		} else {
			$status = 'Account not verified. Please check your email.';
		}
		return $status;
	}

	// grab the whole table for the admin display. TODO - make this check a bit more secure that you're an admin.
	/**
	 * @param $email
	 * @return array|bool
	 */
	public function admindata($email)
	{
		if ($email === $GLOBALS['admin_account']) {
			$sql = "SELECT email, verified, stream_key, api_key, channel_name, display_name FROM $this->user_table";
			$result = pg_query($this->link, $sql);
			$array = [];
			while ($row = pg_fetch_assoc($result)) {
				$array[] = $row;
			}
			return $array;
		} else {
			return false;
		}
	}

	// create a new account and send registration verification email
	/**
	 * @param $email
	 * @param $password
	 * @param $displayname
	 * @param $furl
	 * @return bool|string
	 * @throws Exception
	 */
	public function register($email, $password, $displayname, $furl)
	{
		$emailcheck = $this->emailcheck($email);
		if ($emailcheck === true) {
			return 'Account already exists!';
		}

		$dncheck = $this->dncheck($email, $displayname);
		if ($dncheck === true) {
			return 'Display name already exists!';
		}

		$authcode = bin2hex(random_bytes(32));
		$hash = password_hash($password, PASSWORD_DEFAULT);
		$sql = "INSERT INTO $this->user_table (email, password, auth_code, verified, channel_name, channel_title, display_name, profile_img) VALUES ($1, $2, $7, 0, $3, $4, $5, $6)";
		$params = [$email, $hash, "$displayname's channel", "Welcome to $displayname's stream!", $displayname, '/profiles/default/profile_default.png', $authcode];
		$result = pg_query_params($this->link, $sql, $params);
		if ($result === false) {
			$message = 'Error in: class:user | function:register';
			$code = 1;
			throw new Exception($message, $code);
		}
		// edit emailtemplate.php as needed to change desired emails sent out
		$subject = $GLOBALS['sitetitle'] . ' Account Verification';
		ob_start();
		include '/inc/emailtemplate.php';
		$message = ob_get_contents();
		ob_end_clean();
		$headers = [];
		$headers[] = "MIME-Version: 1.0";
		$headers[] = "Content-Type: text/html; charset=UTF-8";
		$headers[] = "From: " . $GLOBALS['from_email'];
		$headers[] = "Bcc: " . $GLOBALS['bcc_email'];
		$headers[] = "Reply-To: " . $GLOBALS['reply_email'];
		$headers[] = 'X-Mailer: PHP/' . phpversion();
		mail($email, $subject, $message, implode("\r\n", $headers));
		return true;
	}

	// verify account from verification link in registration email

	/**
	 * @param $email
	 * @return bool
	 * @throws Exception
	 */
	public function emailcheck($email)
	{
		$params = [$email];
		$sql = "SELECT * FROM $this->user_table WHERE email = $1";
		$result = pg_query_params($this->link, $sql, $params);
		if ($result === false) {
			$message = 'Error in: class:user | function:emailcheck';
			$code = 1;
			throw new Exception($message, $code);
		}
		$row_cnt = pg_num_rows($result);
		return $row_cnt >= 1 ? true : false;
	}

	/**
	 * @param $email
	 * @param $displayname
	 * @return bool
	 * @throws Exception
	 */
	public function dncheck($email, $displayname)
	{
		$params = [$displayname, $email];
		$sql = "SELECT * FROM $this->user_table WHERE display_name = $1 AND email != $2";
		$result = pg_query_params($this->link, $sql, $params);
		if ($result === false) {
			$message = 'Error in: class:user | function:dncheck';
			$code = 1;
			throw new Exception($message, $code);
		}
		$row_cnt = pg_num_rows($result);
		return $row_cnt >= 1 ? true : false;
	}

	/**
	 * @param $email
	 * @param $code
	 * @return string
	 * @throws Exception
	 */
	public function verify($email, $code)
	{
		$params = [$email, $code];
		$sql = "SELECT * FROM $this->user_table WHERE email = $1 AND auth_code = $2";
		$result = pg_query_params($this->link, $sql, $params);
		$row_cnt = pg_num_rows($result);
		if ($row_cnt >= 1) {

			// simple function to generate private keys (stream and api). Doesn't need to be overly complex as this is private, and not much you can do if you can predict the string.
			/**
			 * @param int $length
			 * @return string
			 */
			function generateRandomString($length = 10)
			{
				$characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
				$charactersLength = strlen($characters);
				$randomString = '';
				for ($i = 0; $i < $length; $i++) {
					$randomString .= $characters[rand(0, $charactersLength - 1)];
				}
				return $randomString;
			}

			$streamkey = generateRandomString();
			$apikey = generateRandomString();

			$params = [$streamkey, $email, $code, $apikey];
			$sql = "UPDATE $this->user_table SET auth_code = null, verified = 1, stream_key = $1, api_key = $4 WHERE email = $2 AND auth_code = $3";
			$check = pg_query_params($this->link, $sql, $params);
			if ($check === false) {
				$message = 'Error in: class:user | function:verify';
				$code = 5;
				throw new Exception($message, $code);
			} else {
				$status = 'true';
			}
		} else {
			$status = 'Invalid email/authentication code!';
		}
		return $status;
	}

	// Check if email exists

	/**
	 * @param $email
	 * @param $furl
	 * @return bool
	 * @throws Exception
	 */
	public function resetCode($email, $furl)
	{
		$authcode = bin2hex(random_bytes(32));
		$sql = "UPDATE $this->user_table SET auth_code = $1 WHERE email = $2";
		$params = [$authcode, $email];
		$result = pg_query_params($this->link, $sql, $params);
		if ($result === false) {
			$message = 'Error in: class:user | function:resetCode';
			$code = 1;
			throw new Exception($message, $code);
		}
		$subject = $GLOBALS ["sitetitle"] . ' Password Reset';
		$message = "Password reset auth code for $email:<br /><br /><b>$authcode</b><br /><br />Reset form: <a href='$furl/lostpass'>Click here</a>";
		$headers = [];
		$headers[] = "MIME-Version: 1.0";
		$headers[] = "Content-Type: text/html; charset=UTF-8";
		$headers[] = "From: " . $GLOBALS['from_email'];
		$headers[] = "Reply-To: issues@rirnef.net";
		$headers[] = 'X-Mailer: PHP/' . phpversion();
		mail($email, $subject, $message, implode("\r\n", $headers));
		return true;
	}

	// Check if display name exists

	/**
	 * @param $email
	 * @param $code
	 * @param $password
	 * @return bool|string
	 * @throws Exception
	 */
	public function passwordReset($email, $code, $password)
	{
		$params = [$email, $code];
		$sql = "SELECT * FROM $this->user_table WHERE email = $1 AND auth_code = $2";
		$result = pg_query_params($this->link, $sql, $params);
		$row_cnt = pg_num_rows($result);
		if ($row_cnt >= 1) {
			$hash = password_hash($password, PASSWORD_DEFAULT);
			$params = [$hash, $email, $code];
			$sql = "UPDATE $this->user_table SET auth_code = null, password = $1 WHERE email = $2 AND auth_code = $3";
			$check = pg_query_params($this->link, $sql, $params);
			if ($check === false) {
				$message = 'Error in: class:user | function:verify';
				$code = 2;
				throw new Exception($message, $code);
			} else {
				$status = true;
			}
		} else {
			$status = 'Invalid email/authentication code!';
		}
		return $status;
	}

	// Grab account info

	/**
	 * @param $value
	 * @param string $field
	 * @return array
	 * @throws Exception
	 */
	public function info($value, $field = 'email')
	{
		$sql = "SELECT email, stream_key, channel_name, channel_title, display_name, profile_img, api_key, chat_jp_setting FROM $this->user_table WHERE $field = $1";
		$params = [$value];
		$info = pg_fetch_assoc(pg_query_params($this->link, $sql, $params));
		if ($info === null) {
			$message = 'Error in: class:user | function:info';
			$code = 1;
			throw new Exception($message, $code);
		}
		return $info;
	}

	// updates channel_name, channel_title, and display_name.
	/**
	 * @param $email
	 * @param $channelname
	 * @param $channeltitle
	 * @param $displayname
	 * @param $chatjp
	 * @return string
	 * @throws Exception
	 */
	public function update($email, $channelname, $channeltitle, $displayname, $chatjp)
	{
		$dncheck = $this->dncheck($email, $displayname);
		if ($dncheck === true) {
			$status = 'Display name already exists!';
		} else {
			$sql = "UPDATE $this->user_table SET channel_name = $1, channel_title = $2, display_name = $3, chat_jp_setting = '$chatjp' WHERE email = $4";
			$params = [$channelname, $channeltitle, $displayname, $email];
			$result = pg_query_params($this->link, $sql, $params);
			if ($result === false) {
				$message = 'Error in: class:user | function:update';
				$code = 1;
				throw new Exception($message, $code);
			} else {
				$status = 'Updated!';
			}
		}
		return $status;
	}

	/**
	 * @param $input
	 * @param $function
	 * @return string
	 */
	public function updateStreamkey($input, $function)
	{
		if ($function === 'channel') {
			$params = [$input];
			$sql = "SELECT channel_name FROM $this->user_table WHERE display_name = $1";
			$query = pg_fetch_assoc(pg_query_params($this->link, $sql, $params));
			$channelname = $query['channel_name'];
			return $channelname;
		} elseif ($function === 'title') {
			$params = [$input];
			$sql = "SELECT channel_title FROM $this->user_table WHERE display_name = $1";
			$query = pg_fetch_assoc(pg_query_params($this->link, $sql, $params));
			$channeltitle = $query['channel_title'];
			return $channeltitle;
		} elseif ($function === 'email') {
			$params = [$input];
			$sql = "SELECT email FROM $this->user_table WHERE display_name = $1";
			$query = pg_fetch_assoc(pg_query_params($this->link, $sql, $params));
			$email = $query['email'];
			return $email;
		} else {
			return 'Error in updateStreamkey()!';
		}
	}

	/**
	 * @param $key
	 * @return bool
	 * @throws Exception
	 */
	public function verifyAPIkey($key)
	{
		$params = [$key];
		$sql = "SELECT display_name FROM $this->user_table WHERE api_key = $1 AND api_key IS NOT null";
		$query = pg_query_params($this->link, $sql, $params);
		if ($query === false) {
			$message = 'Error in: class:user | function:verifyAPIkey()';
			$code = 1;
			throw new Exception($message, $code);
		}
		$row_cnt = pg_num_rows($query);
		return $row_cnt >= 1 ? true : false;
	}

}
