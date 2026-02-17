<?php
class handler
{
	private $userid;
	private $usertype;
	private $link;
	private $db;
	private $headers;
	private $data;
	private $status;
	private $pdo;
	function __construct($db, $pdo)
	{
		$this->userid = 0;
		$this->usertype = 0;
		$this->db = $db;
		$this->pdo = $pdo;
		$this->headers = getallheaders();
	}
	private function serve()
	{
		if ($this->link) {
			$this->data = $this->link->getdata();
			$this->status = $this->link->getstatus();
		}
	}

	public function auth($type)
	{
		if (array_key_exists('Authtoken', $this->headers)) {
			if ($type == 0) {
				$user = $this->db->from('login_tokens')->innerJoin('users ON users.id = login_tokens.user_id')->select(null)->select('user_id')->where('token', $this->headers['Authtoken'])->fetch();
			} else {
				$user = $this->db->from('login_tokens')->innerJoin('users ON users.id = login_tokens.user_id')->select(null)->select('user_id')->where('token', $this->headers['Authtoken'])->fetch();
			}

			if ($user) {
				return $user;
			} else {
				$this->data = ['status' => false, 'message' => 'Authtoken Expired'];
				$this->status = 401;
				return false;
			}
		} else {
			$this->data = ['status' => false, 'message' => 'Authtoken Missings'];
			$this->status = 401;
			return false;
		}
	}
	public function noauth()
	{
		if (array_key_exists('Authtoken', $this->headers)) {
			$user = $this->db->from('login_tokens')->select(null)->select('user_id')->where('token', $this->headers['Authtoken'])->fetch();
			if ($user) {
				return $user;
			} else {
				return ['user_id' => 0];
			}
		} else {
			return ['user_id' => 0];
		}
	}
	public function notfount()
	{
		$this->data = ['status' => false, 'message' => 'Handle not defined'];
		$this->status = 501;
		return false;
	}
	public function route404()
	{
		$this->data = ['status' => false, 'message' => 'Route not defined'];
		$this->status = 501;
		return $this;
	}
	public function route404post()
	{
		$this->data = ['status' => false, 'message' => 'Route not defined post'];
		$this->status = 501;
		return $this;
	}
	public function setdata($link, $args, $auth = '')
	{
		$src = explode('___', $link);
		$auths = explode('___', $auth);
		$class = $src[0];
		$method = $src[1];
		$rank = new $class($this->pdo);
		if ($auth != '') {
			$action = str_replace('___', '/', $link);
			$authmethod = 'auth';
			$user = $this->$authmethod($auths[1]);
			if ($user) {
				$this->userid = $user['user_id'];
				$args['auth_user_id'] = $user['user_id'];
				// permissions check for authenticated users
				$level = $this->db->from('users')->where('id', $user['user_id'])->fetch('level');
				$permPath = __DIR__ . '/permissions.json';
				$allowed = true;
				if (file_exists($permPath)) {
					$perms = json_decode(file_get_contents($permPath), true) ?: [];
					$allowedEntries = $perms['levels'][$level] ?? null;
					if (is_array($allowedEntries)) {
						$allowed = false;
						list($class, $method) = explode('/', $action);
						foreach ($allowedEntries as $entry) {
							if (is_array($entry) && isset($entry['class'], $entry['methods']) && is_array($entry['methods'])) {
								if ($entry['class'] === $class && in_array($method, $entry['methods'], true)) {
									$allowed = true;
									break;
								}
							}
						}
					}
				}
				if (!$allowed) {
					$this->data = ['status' => false, 'message' => 'Forbidden'];
					$this->status = 403;
					return $this;
				}
				$this->link = $rank->$method($args);
				return $this;
			} else {
				return $this;
			}
		} else {
			$user = $this->noauth();
			$args['auth_user_id'] = $user['user_id'];
			$this->link = $rank->$method($args);
			return $this;
		}
	}
	public function getdata()
	{
		return json_encode($this->data);
	}
	public function getstatus()
	{
		return $this->status ?? 200;
	}
	/*public function __call($method, $args,$public)
	{
		$parts = explode('___', $method);
		$action = $parts[0] . '/' . ($parts[1] ?? '');
		$permPath = __DIR__ . '/permissions.json';
		if (file_exists($permPath)) {
			$perms = json_decode(file_get_contents($permPath), true) ?: [];
			$public = $perms['public'] ?? [];
		}
		if (defined('PUBLIC_ROUTES') && is_array(PUBLIC_ROUTES)) {
			$public = array_unique(array_merge($public, PUBLIC_ROUTES));
		}
		if (in_array($action, $public, true)) {
			$class = $this->setdata($method, $args[0], '');
		} else {
			$class = $this->setdata($method, $args[0], 'auth___user');
		}
		$this->serve();
		return $class;
	}*/
	public function __call($method, $args)
	{
		if($args[0]['public']){
			$class = $this->setdata($method, $args[0], '');
		} else {
			$class = $this->setdata($method, $args[0], 'auth___user');
		}
		$this->serve();
		return $class;
	}
	public function getuserid()
	{
		return $this->userid;
	}

}


