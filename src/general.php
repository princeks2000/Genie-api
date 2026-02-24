<?php

class general extends model
{
	public function home($args)
	{
		$data = $this->from('home')->where('id', '1')->fetch();
		$data['review'] = $this->from('review')->select('users.name as customer_name')->innerJoin('users ON users.id = review.user_id')->orderBy('star DESC')->orderBy('id DESC')->fetch();
		return $this->out(['status' => true, 'data' => $data], 200);
	}

	public function feature_request_save($args)
	{
		$id = $this->req['id'] ?? ($args['id'] ?? null);
		$title = $this->req['title'] ?? ($args['title'] ?? null);
		$request = $this->req['request'] ?? ($args['request'] ?? null);
		$date = $this->req['date'] ?? ($args['date'] ?? date('Y-m-d H:i:s'));

		if (!$title || !$request) {
			return $this->out(['status' => false, 'message' => 'Title and request are required'], 422);
		}

		$values = [
			'title' => $title,
			'request' => $request,
			'date' => $date
		];

		try {
			if ($id) {
				$this->update('customer_feature_request')->set($values)->where('id', $id)->execute();
				return $this->out(['status' => true, 'message' => 'Feature request updated', 'id' => $id], 200);
			} else {
				$new_id = $this->insertInto('customer_feature_request')->values($values)->execute();
				return $this->out(['status' => true, 'message' => 'Feature request created', 'id' => $new_id], 201);
			}
		} catch (\Exception $e) {
			return $this->out(['status' => false, 'message' => $e->getMessage()], 500);
		}
	}

	public function feature_request_list($args)
	{
		try {
			$data = $this->from('customer_feature_request')->orderBy('date DESC')->fetchAll();
			return $this->out(['status' => true, 'data' => $data], 200);
		} catch (\Exception $e) {
			return $this->out(['status' => false, 'message' => $e->getMessage()], 500);
		}
	}

	public function feature_request_retrieve($args)
	{
		$id = $this->req['id'] ?? ($args['id'] ?? null);
		if (!$id) {
			return $this->out(['status' => false, 'message' => 'ID is required'], 422);
		}

		try {
			$data = $this->from('customer_feature_request')->where('id', $id)->fetch();
			if (!$data) {
				return $this->out(['status' => false, 'message' => 'Feature request not found'], 404);
			}
			return $this->out(['status' => true, 'data' => $data], 200);
		} catch (\Exception $e) {
			return $this->out(['status' => false, 'message' => $e->getMessage()], 500);
		}
	}

	public function feature_request_delete($args)
	{
		$id = $this->req['id'] ?? ($args['id'] ?? null);
		if (!$id) {
			return $this->out(['status' => false, 'message' => 'ID is required'], 422);
		}

		try {
			$this->deleteFrom('customer_feature_request')->where('id', $id)->execute();
			return $this->out(['status' => true, 'message' => 'Feature request deleted'], 200);
		} catch (\Exception $e) {
			return $this->out(['status' => false, 'message' => $e->getMessage()], 500);
		}
	}

	public function fileupload($args)
	{
		try {
			$uploadDir = __DIR__ . '/../uploads/';
			if (!is_dir($uploadDir)) {
				mkdir($uploadDir, 0755, true);
			}

			$allowed = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'txt', 'csv', 'xlsx', 'doc', 'docx'];
			$results = [];

			$filesArray = [];
			foreach ($_FILES as $fileSpec) {
				if (is_array($fileSpec['name'])) {
					$count = count($fileSpec['name']);
					for ($i = 0; $i < $count; $i++) {
						$filesArray[] = [
							'name' => $fileSpec['name'][$i],
							'type' => $fileSpec['type'][$i] ?? null,
							'tmp_name' => $fileSpec['tmp_name'][$i],
							'error' => $fileSpec['error'][$i],
							'size' => $fileSpec['size'][$i]
						];
					}
				} else {
					$filesArray[] = $fileSpec;
				}
			}

			if (empty($filesArray)) {
				return $this->out(['status' => false, 'message' => 'No files received'], 422);
			}

			foreach ($filesArray as $f) {
				$err = $f['error'] ?? UPLOAD_ERR_OK;
				if ($err !== UPLOAD_ERR_OK) {
					$results[] = ['status' => false, 'message' => 'Upload error', 'code' => $err];
					continue;
				}
				$orig = $f['name'];
				$ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
				if (!in_array($ext, $allowed, true)) {
					$results[] = ['status' => false, 'message' => 'Extension not allowed', 'name' => $orig];
					continue;
				}
				$base = preg_replace('/[^A-Za-z0-9._-]/', '_', pathinfo($orig, PATHINFO_FILENAME));
				$saved = $base . '_' . uniqid('', true) . '.' . $ext;
				$path = $uploadDir . $saved;

				if (!is_uploaded_file($f['tmp_name'])) {
					$results[] = ['status' => false, 'message' => 'Invalid uploaded file', 'name' => $orig];
					continue;
				}

				if (!move_uploaded_file($f['tmp_name'], $path)) {
					$results[] = ['status' => false, 'message' => 'Failed to move file', 'name' => $orig];
					continue;
				}

				$results[] = [
					'status' => true,
					'original_name' => $orig,
					'saved_name' => $saved,
					'size' => $f['size'] ?? null,
					'mime' => $f['type'] ?? null,
					'url' => '/uploads/' . $saved
				];
			}

			$successCount = 0;
			foreach ($results as $r) {
				if (!empty($r['status'])) {
					$successCount++;
				}
			}

			return $this->out([
				'status' => true,
				'message' => $successCount . ' file(s) uploaded',
				'files' => $results
			], 200);
		} catch (\Throwable $e) {
			return $this->out(['status' => false, 'message' => $e->getMessage()], 500);
		}
	}

	public function list_files($args)
	{
		try {
			$uploadDir = __DIR__ . '/../uploads/';
			if (!is_dir($uploadDir)) {
				mkdir($uploadDir, 0755, true);
			}
			$files = [];
			$entries = @scandir($uploadDir) ?: [];
			foreach ($entries as $entry) {
				if ($entry === '.' || $entry === '..') {
					continue;
				}
				$path = $uploadDir . $entry;
				if (is_file($path)) {
					$files[] = [
						'name' => $entry,
						'size' => filesize($path),
						'modified' => date('Y-m-d H:i:s', filemtime($path)),
						'url' => '/uploads/' . $entry
					];
				}
			}
			return $this->out(['status' => true, 'count' => count($files), 'files' => $files], 200);
		} catch (\Throwable $e) {
			return $this->out(['status' => false, 'message' => $e->getMessage()], 500);
		}
	}

	public function delete_file($args)
	{
		try {
			$filename = $this->req['filename'] ?? ($args['filename'] ?? null);
			if (!$filename) {
				return $this->out(['status' => false, 'message' => 'filename is required'], 422);
			}
			$base = basename($filename);
			if ($base !== $filename) {
				return $this->out(['status' => false, 'message' => 'invalid filename'], 422);
			}
			$uploadDir = __DIR__ . '/../uploads/';
			$path = $uploadDir . $base;
			if (!file_exists($path) || !is_file($path)) {
				return $this->out(['status' => false, 'message' => 'file not found'], 404);
			}
			if (!unlink($path)) {
				return $this->out(['status' => false, 'message' => 'failed to delete'], 500);
			}
			return $this->out(['status' => true, 'message' => 'file deleted', 'filename' => $base], 200);
		} catch (\Throwable $e) {
			return $this->out(['status' => false, 'message' => $e->getMessage()], 500);
		}
	}
}
