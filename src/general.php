<?php

class general extends model
{
	public function home($args)
	{
		$data = $this->from('home')->where('id', '1')->fetch();
		$data['review'] = $this->from('review')->select('users.name as customer_name')->innerJoin('users ON users.id = review.user_id')->orderBy('star DESC')->orderBy('id DESC')->fetch();
		return $this->out(['status' => true, 'data' => $data], 200);
	}
}
