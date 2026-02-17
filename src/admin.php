<?php
class admin extends model
{
	public function create_user($args)
	{
		$data = $this->req;
		if (empty($data['username']) || empty($data['password']) || empty($data['level'])) {
			return $this->out(['status' => false, 'message' => 'username, password, and level are required'], 422);
		}
		$data['password'] = md5($data['password']);
		$data['createdate'] = date('Y-m-d H:i:s');
		$id = $this->insertInto('users')->values($data)->execute();
		if ($id) {
			$user = $this->_getuser($id);
			return $this->out(['status' => true, 'message' => 'User created', 'user' => $user], 201);
		}
		return $this->out(['status' => false, 'message' => 'Failed to create user'], 500);
	}

	public function update_user($args)
	{
		$data = $this->req;
		if (empty($data['id'])) {
			return $this->out(['status' => false, 'message' => 'id is required'], 422);
		}
		if (isset($data['password']) && $data['password'] !== '') {
			$data['password'] = md5($data['password']);
		} else {
			unset($data['password']);
		}
		$id = $data['id'];
		unset($data['id']);
		$this->update('users')->set($data)->where('id', $id)->execute();
		$user = $this->_getuser($id);
		return $this->out(['status' => true, 'message' => 'User updated', 'user' => $user], 200);
	}

	public function delete_user($args)
	{
		$id = $this->req['id'] ?? null;
		if (!$id) {
			return $this->out(['status' => false, 'message' => 'id is required'], 422);
		}
		$this->deleteFrom('login_tokens')->where('user_id', $id)->execute();
		$this->deleteFrom('user_otps')->where('user_id', $id)->execute();
		$this->deleteFrom('users')->where('id', $id)->execute();
		return $this->out(['status' => true, 'message' => 'User deleted'], 200);
	}

	public function user_levels($args)
	{
		$data = $this->from('user_level')->fetchAll();
		return $this->out(['status' => true, 'levels' => $data], 200);
	}
	public function create_user_level($args)
	{
		$data = $this->req;
		$title = trim($data['title'] ?? '');
		$description = $data['description'] ?? '';
		if ($title === '') {
			return $this->out(['status' => false, 'message' => 'title is required'], 422);
		}
		$id = $this->insertInto('user_level')->values(['title' => $title, 'description' => $description])->execute();
		if ($id) {
			$row = $this->from('user_level')->where('id', $id)->fetch();
			return $this->out(['status' => true, 'message' => 'Level created', 'level' => $row], 201);
		}
		return $this->out(['status' => false, 'message' => 'Failed to create level'], 500);
	}
	public function update_user_level($args)
	{
		$data = $this->req;
		$id = intval($data['id'] ?? 0);
		if ($id <= 0) {
			return $this->out(['status' => false, 'message' => 'id is required'], 422);
		}
		$payload = [];
		if (isset($data['title'])) {
			$payload['title'] = $data['title'];
		}
		if (isset($data['description'])) {
			$payload['description'] = $data['description'];
		}
		if (empty($payload)) {
			return $this->out(['status' => false, 'message' => 'No fields to update'], 422);
		}
		$this->update('user_level')->set($payload)->where('id', $id)->execute();
		$row = $this->from('user_level')->where('id', $id)->fetch();
		return $this->out(['status' => true, 'message' => 'Level updated', 'level' => $row], 200);
	}
	public function delete_user_level($args)
	{
		$id = intval($this->req['id'] ?? 0);
		if ($id <= 0) {
			return $this->out(['status' => false, 'message' => 'id is required'], 422);
		}
		$in_use = intval($this->from('users')->select(null)->select('COUNT(*) AS c')->where('level', $id)->fetch('c'));
		if ($in_use > 0) {
			return $this->out(['status' => false, 'message' => 'Level in use'], 409);
		}
		$this->deleteFrom('user_level')->where('id', $id)->execute();
		return $this->out(['status' => true, 'message' => 'Level deleted'], 200);
	}

	public function list_users($args)
	{
		$page = intval($this->req['page'] ?? 1);
		$limit = intval($this->req['limit'] ?? 50);
		if ($limit <= 0 || $limit > 200) { $limit = 50; }
		if ($page <= 0) { $page = 1; }
		$offset = ($page - 1) * $limit;
		$query = $this->from('users')->select(null)->select('id, username, level, switchuser, createdate')->orderBy('id DESC');
		if (method_exists($query, 'limit')) { $query = $query->limit($limit)->offset($offset); }
		$users = $query->fetchAll();
		foreach ($users as &$u) { unset($u['password']); }
		$total = $this->from('users')->select(null)->select('COUNT(*) AS c')->fetch('c');
		return $this->out(['status' => true, 'users' => $users, 'page' => $page, 'limit' => $limit, 'total' => intval($total)], 200);
	}

	public function list_switch_users($args)
	{
		$users = $this->from('users')->select(null)->select('id, username')->where('switchuser', 1)->orderBy('id DESC')->fetchAll();
		return $this->out(['status' => true, 'users' => $users], 200);
	}
	public function admin_list_routes($args)
	{
		$path = __DIR__ . '/../routes.php';
		$get = [];
		$post = [];
		$map = [];
		if (file_exists($path)) {
			include $path;
			foreach ($post as $r) {
				$action = $r[1] ?? '';
				if (is_string($action) && strpos($action, '/') !== false) {
					list($class, $method) = explode('/', $action, 2);
					if (!isset($map[$class])) $map[$class] = [];
					if (!in_array($method, $map[$class], true)) $map[$class][] = $method;
				}
			}
			foreach ($get as $r) {
				$action = $r[1] ?? '';
				if (is_string($action) && strpos($action, '/') !== false) {
					list($class, $method) = explode('/', $action, 2);
					if (!isset($map[$class])) $map[$class] = [];
					if (!in_array($method, $map[$class], true)) $map[$class][] = $method;
				}
			}
			$out = [];
			foreach ($map as $cls => $methods) {
				$out[] = ['class' => $cls, 'methods' => array_values($methods)];
			}
			return $this->out(['status' => true, 'routes' => $out], 200);
		}
		return $this->out(['status' => false, 'message' => 'routes.php not found'], 500);
	}

	public function admin_set_level_permissions($args)
	{
		$level_id = intval($this->req['level_id'] ?? 0);
		$permissions = $this->req['permissions'] ?? [];
		if ($level_id <= 0 || !is_array($permissions)) {
			return $this->out(['status' => false, 'message' => 'level_id and permissions are required'], 422);
		}
		$normalized = [];
		foreach ($permissions as $p) {
			if (is_array($p) && isset($p['class'], $p['methods']) && is_array($p['methods'])) {
				$normalized[] = ['class' => $p['class'], 'methods' => array_values(array_unique($p['methods']))];
			}
		}
		$permPath = __DIR__ . '/../permissions.json';
		$existing = [];
		if (file_exists($permPath)) {
			$existing = json_decode(file_get_contents($permPath), true) ?: [];
		}
		$existing['levels'][$level_id] = $normalized;
		file_put_contents($permPath, json_encode($existing));
		return $this->out(['status' => true, 'message' => 'Permissions updated'], 200);
	}

	public function admin_get_level_permissions($args)
	{
		$level_id = intval($this->req['level_id'] ?? 0);
		if ($level_id <= 0) {
			return $this->out(['status' => false, 'message' => 'level_id is required'], 422);
		}
		$permPath = __DIR__ . '/../permissions.json';
		$existing = [];
		if (file_exists($permPath)) {
			$existing = json_decode(file_get_contents($permPath), true) ?: [];
		}
		$permissions = $existing['levels'][$level_id] ?? [];
		return $this->out(['status' => true, 'permissions' => $permissions], 200);
	}

	public function admin_set_all_level_permissions($args)
	{
		$path = __DIR__ . '/../routes.php';
		$get = [];
		$post = [];
		$map = [];
		if (file_exists($path)) {
			include $path;
			foreach ($post as $r) {
				list($name, $action) = $r;
				if (is_string($action) && strpos($action, '/') !== false) {
					list($class, $method) = explode('/', $action, 2);
					if (!isset($map[$class])) $map[$class] = [];
					if (!in_array($method, $map[$class], true)) $map[$class][] = $method;
				}
			}
			foreach ($get as $r) {
				list($name, $action) = $r;
				if (is_string($action) && strpos($action, '/') !== false) {
					list($class, $method) = explode('/', $action, 2);
					if (!isset($map[$class])) $map[$class] = [];
					if (!in_array($method, $map[$class], true)) $map[$class][] = $method;
				}
			}
		} else {
			return $this->out(['status' => false, 'message' => 'routes.php not found'], 500);
		}
		$out = [];
		foreach ($map as $cls => $methods) { $out[] = ['class' => $cls, 'methods' => array_values($methods)]; }
		$levels = $this->from('user_level')->select(null)->select('id')->fetchAll();
		$permPath = __DIR__ . '/../permissions.json';
		$existing = [];
		if (file_exists($permPath)) { $existing = json_decode(file_get_contents($permPath), true) ?: []; }
		foreach ($levels as $lvl) {
			$level_id = is_array($lvl) ? ($lvl['id'] ?? $lvl[0] ?? null) : $lvl;
			if ($level_id) { $existing['levels'][$level_id] = $out; }
		}
		file_put_contents($permPath, json_encode($existing));
		return $this->out(['status' => true, 'message' => 'All levels granted all actions', 'count' => count($levels)], 200);
	}
}
