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
}
