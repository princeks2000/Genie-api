<?php
use Respect\Validation\Validator as v;
class user extends model
{
	private function get_ip()
	{
		if (isset($_SERVER['HTTP_CLIENT_IP']))
			return $_SERVER['HTTP_CLIENT_IP'];
		if (isset($_SERVER['HTTP_X_FORWARDED_FOR']))
			return $_SERVER['HTTP_X_FORWARDED_FOR'];
		return $_SERVER['REMOTE_ADDR'];
	}

	public function _getuser($user_id)
	{
		$user = $this->from('users')->where('id', $user_id)->fetch();
		if ($user) {
			unset($user['password']);
			return $user;
		}

	}
	public function login()
	{
		$user = $this->from('users')->where('username', $this->req['username'])->where('password', md5($this->req['password']))->fetch();
		if ($user) {
			if (isset($user['two_factor_enabled']) && intval($user['two_factor_enabled']) === 1) {
				return $this->out(['status' => false, 'message' => '2FA required', 'totp_required' => true], 200);
			}
			$current_ip = $this->get_ip();
			$otp_required = false;

			// Check 1: IP Change
			if ($user['skip_ip_check'] == 0 && !empty($user['last_ip']) && $user['last_ip'] != $current_ip) {
				$otp_required = true;
			}

			// Check 2: Multiple Login
			if (!$otp_required && $user['allow_multiple_login'] == 0) {
				$active_session = $this->from('login_tokens')->where('user_id', $user['id'])->fetch();
				if ($active_session) {
					$otp_required = true;
				}
			}

			if ($otp_required) {
				$otp = rand(100000, 999999);
				$otp_expiry = date('Y-m-d H:i:s', strtotime('+15 minutes'));
				$this->insertInto('user_otps')->values([
					'user_id' => $user['id'],
					'otp' => $otp,
					'created_at' => date('Y-m-d H:i:s'),
					'expires_at' => $otp_expiry,
					'is_used' => 0
				])->execute();

				$email_to = isset($user['email']) ? $user['email'] : $user['username'];
				$message = $this->render_template('otp_email', ['otp' => $otp]);
				$this->_email('no-reply@genie.com', $email_to, $message, "Login OTP");
				return $this->out(['status' => false, 'message' => 'OTP sent to email', 'otp_required' => true], 200);
			}

			$token = bin2hex(random_bytes(32));
			$values = [];
			$values['user_id'] = $user['id'];
			$values['token'] = $token;
			$values['createtime'] = date('Y-m-d H:i:s');
			$id = $this->insertInto('login_tokens')->values($values)->execute();
			if ($id) {
				$this->update('users')->set(['last_ip' => $current_ip])->where('id', $user['id'])->execute();
				$values = [];
				$user = $this->_getuser($user['id']);
				$user['token'] = $token;
				return $this->out(['status' => true, 'message' => '', 'user' => $user], 200);
			} else {
				return $this->out(['status' => false], 200);
			}
		} else {
			return $this->out(['status' => false], 200);
		}
	}

	private function base32decode($secret)
	{
		$alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
		$secret = strtoupper($secret);
		$secret = preg_replace('/[^A-Z2-7]/', '', $secret);
		$buffer = 0;
		$bitsLeft = 0;
		$result = '';
		for ($i = 0; $i < strlen($secret); $i++) {
			$val = strpos($alphabet, $secret[$i]);
			if ($val === false)
				continue;
			$buffer = ($buffer << 5) | $val;
			$bitsLeft += 5;
			if ($bitsLeft >= 8) {
				$bitsLeft -= 8;
				$result .= chr(($buffer >> $bitsLeft) & 0xFF);
			}
		}
		return $result;
	}

	private function generate_totp_secret($length = 32)
	{
		$alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
		$out = '';
		$bytes = random_bytes($length);
		for ($i = 0; $i < strlen($bytes); $i++) {
			$out .= $alphabet[ord($bytes[$i]) & 31];
		}
		return $out;
	}

	private function verify_totp($secret, $code, $window = 1)
	{
		$timeSlice = floor(time() / 30);
		$secretKey = $this->base32decode($secret);
		for ($i = -$window; $i <= $window; $i++) {
			$counter = pack('N*', 0) . pack('N*', $timeSlice + $i);
			$hash = hash_hmac('sha1', $counter, $secretKey, true);
			$offset = ord($hash[19]) & 0x0F;
			$binary = ((ord($hash[$offset]) & 0x7F) << 24) |
				((ord($hash[$offset + 1]) & 0xFF) << 16) |
				((ord($hash[$offset + 2]) & 0xFF) << 8) |
				(ord($hash[$offset + 3]) & 0xFF);
			$otp = $binary % 1000000;
			$otpStr = str_pad((string) $otp, 6, '0', STR_PAD_LEFT);
			if (hash_equals($otpStr, str_pad($code, 6, '0', STR_PAD_LEFT))) {
				return true;
			}
		}
		return false;
	}

	public function enable_2fa_init($args)
	{
		$secret = $this->generate_totp_secret(20);
		$this->update('users')->set(['totp_secret' => $secret, 'two_factor_enabled' => 0])->where('id', $args['auth_user_id'])->execute();
		$username = $this->from('users')->where('id', $args['auth_user_id'])->fetch('username');
		$issuer = 'Genie';
		$otpauth = 'otpauth://totp/' . $issuer . ':' . $username . '?secret=' . $secret . '&issuer=' . $issuer . '&digits=6&period=30';
		return $this->out(['status' => true, 'secret' => $secret, 'otpauth_url' => $otpauth], 200);
	}

	public function enable_2fa_verify($args)
	{
		$code = $this->req['code'];
		$user = $this->from('users')->where('id', $args['auth_user_id'])->fetch();
		if ($user && !empty($user['totp_secret'])) {
			if ($this->verify_totp($user['totp_secret'], $code)) {
				$this->update('users')->set(['two_factor_enabled' => 1])->where('id', $args['auth_user_id'])->execute();
				return $this->out(['status' => true, 'message' => '2FA enabled'], 200);
			}
			return $this->out(['status' => false, 'message' => 'Invalid code'], 200);
		}
		return $this->out(['status' => false, 'message' => 'User not found or secret missing'], 200);
	}
	public function disable_2fa($args)
	{
		$this->update('users')->set(['two_factor_enabled' => 0, 'totp_secret' => null])->where('id', $args['auth_user_id'])->execute();
		return $this->out(['status' => true, 'message' => '2FA disabled'], 200);
	}

	public function verify_login_totp()
	{
		$username = $this->req['username'];
		$code = $this->req['code'];
		$user = $this->from('users')->where('username', $username)->fetch();
		if ($user && intval($user['two_factor_enabled']) === 1 && !empty($user['totp_secret'])) {
			if ($this->verify_totp($user['totp_secret'], $code)) {
				$token = bin2hex(random_bytes(32));
				$values = [];
				$values['user_id'] = $user['id'];
				$values['token'] = $token;
				$values['createtime'] = date('Y-m-d H:i:s');
				$this->insertInto('login_tokens')->values($values)->execute();
				$this->update('users')->set(['last_ip' => $this->get_ip()])->where('id', $user['id'])->execute();
				$u = $this->_getuser($user['id']);
				$u['token'] = $token;
				return $this->out(['status' => true, 'message' => 'Login successful', 'user' => $u], 200);
			}
			return $this->out(['status' => false, 'message' => 'Invalid code'], 200);
		}
		return $this->out(['status' => false, 'message' => 'Invalid user or 2FA not enabled'], 200);
	}

	public function verify_login_otp()
	{
		$username = $this->req['username'];
		$otp = $this->req['otp'];

		$user = $this->from('users')->where('username', $username)->fetch();

		if ($user) {
			$otp_record = $this->from('user_otps')
				->where('user_id', $user['id'])
				->where('otp', $otp)
				->where('is_used', 0)
				->where('expires_at >?', date('Y-m-d H:i:s'))
				->where('purpose', 'login')
				->fetch();

			if ($otp_record) {
				$this->update('user_otps')->set(['is_used' => 1])->where('id', $otp_record['id'])->execute();
				$this->update('users')->set(['last_ip' => $this->get_ip()])->where('id', $user['id'])->execute();

				$token = bin2hex(random_bytes(32));
				$values = [];
				$values['user_id'] = $user['id'];
				$values['token'] = $token;
				$values['createtime'] = date('Y-m-d H:i:s');
				$this->insertInto('login_tokens')->values($values)->execute();

				$user = $this->_getuser($user['id']);
				$user['token'] = $token;
				return $this->out(['status' => true, 'message' => 'Login successful', 'user' => $user], 200);
			} else {
				return $this->out(['status' => false, 'message' => 'Invalid or expired OTP'], 200);
			}
		} else {
			return $this->out(['status' => false, 'message' => 'Invalid User'], 200);
		}
	}
	public function forgot_password_request()
	{
		$username = $this->req['username'];
		$user = $this->from('users')->where('username', $username)->fetch();
		if ($user) {
			$otp = rand(100000, 999999);
			$otp_expiry = date('Y-m-d H:i:s', strtotime('+15 minutes'));
			$this->insertInto('user_otps')->values([
				'user_id' => $user['id'],
				'otp' => $otp,
				'created_at' => date('Y-m-d H:i:s'),
				'expires_at' => $otp_expiry,
				'is_used' => 0,
				'purpose' => 'password_reset'
			])->execute();
			$email_to = $user['username'];
			$message = $this->render_template('forgot_password_email', ['otp' => $otp]);
			$this->_email('no-reply@genie.com', $email_to, $message, "Password Reset OTP");
			return $this->out(['status' => true, 'message' => 'OTP sent'], 200);
		}
		return $this->out(['status' => false, 'message' => 'User not found'], 200);
	}
	public function reset_password_with_otp()
	{
		$username = $this->req['username'];
		$otp = $this->req['otp'];
		$new_password = $this->req['new_password'];
		$user = $this->from('users')->where('username', $username)->fetch();
		if ($user) {
			$otp_record = $this->from('user_otps')
				->where('user_id', $user['id'])
				->where('otp', $otp)
				->where('is_used', 0)
				->where('expires_at >?', date('Y-m-d H:i:s'))
				->where('purpose', 'password_reset')
				->fetch();
			if ($otp_record) {
				$this->update('users')->set(['password' => md5($new_password)])->where('id', $user['id'])->execute();
				$this->update('user_otps')->set(['is_used' => 1])->where('id', $otp_record['id'])->execute();
				return $this->out(['status' => true, 'message' => 'Password reset successful'], 200);
			}
			return $this->out(['status' => false, 'message' => 'Invalid or expired OTP'], 200);
		}
		return $this->out(['status' => false, 'message' => 'User not found'], 200);
	}
	public function update_profile($args)
	{
		$data = $this->req;
		$data = ['name' => $this->req['name'], 'phonenumber' => $this->req['phonenumber'], 'address' => $this->req['address'], 'position' => $this->req['position']];
		if (isset($this->req['password']) && $this->req['password'] !== '') {
			$this->req['password'] = md5($this->req['password']);
			$data['password'] = $this->req['password'];

		} else {
			unset($this->req['password']);
		}
		$this->update('users')->set($data)->where('id', $args['auth_user_id'])->execute();
		return $this->out(['status' => true, 'message' => "Profile saved successfully"], 200);
	}
	public function impersonate_user($args)
	{
		$target_id = intval($this->req['user_id'] ?? 0);
		if ($target_id <= 0) {
			return $this->out(['status' => false, 'message' => 'user_id is required'], 422);
		}
		$u = $this->from('users')->where('id', $target_id)->fetch();
		if (!$u) {
			return $this->out(['status' => false, 'message' => 'User not found'], 404);
		}
		$token = bin2hex(random_bytes(32));
		$values = ['user_id' => $target_id, 'token' => $token, 'createtime' => date('Y-m-d H:i:s')];
		$id = $this->insertInto('login_tokens')->values($values)->execute();
		if (!$id) {
			return $this->out(['status' => false, 'message' => 'Failed to generate token'], 500);
		}
		return $this->out(['status' => true, 'token' => $token, 'user' => ['id' => $u['id'], 'username' => $u['username'], 'level' => $u['level'] ?? null]], 200);
	}
	public function switchuserlist($args)
	{
		$users = $this->from('users')->select(null)->select('id,name,position')->where('switchuser', 1)->fetchAll();
		return $this->out(['status' => true, 'users' => $users], 200);
	}
	public function getprofile($args)
	{
		$data = $this->_getuser($args['auth_user_id']);
		return $this->out(['status' => true, 'message' => '', 'user' => $data], 200);
	}
	public function create_order($args)
	{

	}
	public function order_list($args)
	{

	}

}
