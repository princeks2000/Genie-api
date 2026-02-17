<?php
class cron extends model
{
	public function task($args)
	{
		$task = $args['task'];
		$key = $args['key'];
		set_time_limit(0);
		// Verify key and get scheduler config
		$scheduler = $this->from('cron_scheduler')
			->where('task_name', $task)
			->where('cron_key', $key)
			->where('status', 1)
			->fetch();

		if (!$scheduler) {
			return $this->out(['status' => false, 'message' => 'Invalid task, key, or task disabled'], 403);
		}

		// Update start tracking
		$this->update('cron_scheduler')
			->set([
				'last_start_at' => date('Y-m-d H:i:s'),
				'start_count' => $scheduler['start_count'] + 1
			])
			->where('id', $scheduler['id'])
			->execute();

		$args['scheduler'] = $scheduler;

		// Execute task
		if (method_exists($this, $task)) {
			$this->$task($args);
		}

		// Update end tracking
		$this->update('cron_scheduler')
			->set([
				'last_end_at' => date('Y-m-d H:i:s'),
				'end_count' => $scheduler['end_count'] + 1
			])
			->where('id', $scheduler['id'])
			->execute();

		return $this->out(['status' => true, 'message' => 'Task executed successfully'], 200);
	}
	public function import_customers($args)
	{
		$scheduler = $args['scheduler'];
		$platforms = $this->from('platforms')->where('is_active', 1)->fetchAll();
		$cust = new customer($this->pdo);
		$results = [];

		foreach ($platforms as $platform) {
			// Start platform log
			$log_id = $this->insertInto('logs_cron')->values([
				'scheduler_id' => $scheduler['id'],
				'platform_id' => $platform['id'],
				'start_time' => date('Y-m-d H:i:s'),
				'status' => 'running'
			])->execute();

			$import_results = [];
			if ($platform['code'] == 'shopify') {
				$import_results = $cust->import_shopify_customers([
					'interval_minutes' => $scheduler['interval_minutes'],
					'platform_id' => $platform['id']
				]);
				$results[] = 'Shopify processed';
			} elseif ($platform['code'] == 'apparelmagic') {
				$import_results = $cust->import_am_customers([
					'interval_minutes' => $scheduler['interval_minutes'],
					'platform_id' => $platform['id']
				]);
				$results[] = 'Apparel Magic processed';
			}

			// Log items
			if (isset($import_results['items']) && is_array($import_results['items'])) {
				foreach ($import_results['items'] as $item) {
					$this->insertInto('logs_cron_items')->values([
						'log_cron_id' => $log_id,
						'item_unique_id' => $item['id'],
						'status' => $item['status'],
						'message' => $item['message'] ?? null
					])->execute();
				}
			}

			// End platform log
			$this->update('logs_cron')
				->set([
					'end_time' => date('Y-m-d H:i:s'),
					'status' => 'completed'
				])
				->where('id', $log_id)
				->execute();
		}

		return $results;
	}
	public function customer_materialize_all($args)
	{
		$scheduler = $args['scheduler'];
		$log_id = $this->insertInto('logs_cron')->values([
			'scheduler_id' => $scheduler['id'],
			'platform_id' => '0',
			'start_time' => date('Y-m-d H:i:s'),
			'status' => 'running'
		])->execute();
		$cust = new customer($this->pdo);
		$cust->materialize_all_display_fields($args);
		$this->update('logs_cron')->set(['end_time' => date('Y-m-d H:i:s'), 'status' => 'completed'])->where('id', $log_id)->execute();
	}
	public function import_products($args)
	{
		$scheduler = $args['scheduler'];
		$platforms = $this->from('platforms')->where('is_active', 1)->fetchAll();
		$prod = new product($this->pdo);
		$results = [];

		foreach ($platforms as $platform) {
			// Start platform log
			$log_id = $this->insertInto('logs_cron')->values([
				'scheduler_id' => $scheduler['id'],
				'platform_id' => $platform['id'],
				'start_time' => date('Y-m-d H:i:s'),
				'status' => 'running'
			])->execute();

			$import_results = [];
			if ($platform['code'] == 'shopify') {
				$import_results = $prod->import_shopify_products([
					'interval_minutes' => $scheduler['interval_minutes'],
					'platform_id' => $platform['id']
				]);
				$results[] = 'Shopify processed';
			} elseif ($platform['code'] == 'apparelmagic') {
				$import_results = $prod->import_am_products([
					'interval_minutes' => $scheduler['interval_minutes'],
					'platform_id' => $platform['id']
				]);
				$results[] = 'Apparel Magic processed';
			}

			// Log items
			if (isset($import_results['items']) && is_array($import_results['items'])) {
				foreach ($import_results['items'] as $item) {
					$this->insertInto('logs_cron_items')->values([
						'log_cron_id' => $log_id,
						'item_unique_id' => $item['id'],
						'status' => $item['status'],
						'message' => $item['message'] ?? null
					])->execute();
				}
			}

			// End platform log
			$this->update('logs_cron')
				->set([
					'end_time' => date('Y-m-d H:i:s'),
					'status' => 'completed'
				])
				->where('id', $log_id)
				->execute();
		}

		return $results;
	}

	public function product_materialize_all($args)
	{
		$scheduler = $args['scheduler'];
		$log_id = $this->insertInto('logs_cron')->values([
			'scheduler_id' => $scheduler['id'],
			'platform_id' => '0',
			'start_time' => date('Y-m-d H:i:s'),
			'status' => 'running'
		])->execute();
		$prod = new product($this->pdo);
		$prod->materialize_all_display_fields($args);
		$this->update('logs_cron')->set(['end_time' => date('Y-m-d H:i:s'), 'status' => 'completed'])->where('id', $log_id)->execute();
	}

}
