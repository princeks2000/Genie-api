<?php
class settings extends model
{
	protected array $typeMap = [
		'string' => 'VARCHAR(1000)',
		'int' => 'INT',
		'bool' => 'TINYINT(1)',
		'date' => 'DATE',
		'float' => 'DECIMAL(10,2)',
	];

	protected function columnExists(string $table, string $column): bool
	{
		$sql = "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?";
		$stmt = $this->pdo->prepare($sql);
		$stmt->execute([$table, $column]);
		$count = $stmt->fetchColumn();
		return (bool) $count;
	}

	protected function addColumn(string $table, string $column, string $type, $length = null): void
	{
		if (!isset($this->typeMap[$type])) {
			throw new InvalidArgumentException("Invalid data type: {$type}");
		}
		if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column)) {
			throw new InvalidArgumentException("Invalid column name: {$column}");
		}
		if ($this->columnExists($table, $column)) {
			return;
		}

		$sqlType = $this->typeMap[$type];
		if ($type === 'string' && $length) {
			$sqlType = "VARCHAR(" . intval($length) . ")";
		}

		$sql = "ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$sqlType} NULL, ADD INDEX idx_{$column} (`{$column}`)";
		$this->pdo->exec($sql);
	}

	protected function dropColumn(string $table, string $column): void
	{
		if (!$this->columnExists($table, $column)) {
			return;
		}
		$sql = "ALTER TABLE `{$table}` DROP COLUMN `{$column}`";
		$this->pdo->exec($sql);
	}

	public function settings_retrieve($args)
	{
		$keys = $this->req['keys'] ?? null;
		$data = $this->from('settings')->select(null)->select('_key,_value,description');
		if ($keys) {
			$data = $data->where('_key', $keys);
		}
		$data = $data->fetchAll();
		return $this->out(['status' => true, 'keys' => $keys, 'items' => $data], 200);
	}
	public function settings_save($args)
	{

		$items = $this->req['items'];
		if (!is_array($items)) {
			return $this->out(['status' => false, 'message' => 'items must be an array'], 422);
		}
		$this->pdo->beginTransaction();
		try {
			$count = 0;
			$affectedKeys = [];
			foreach ($items as $it) {
				$key = $it['_key'];
				$val = $it['_value'];
				$desc = $it['description'];
				if ($key === null || $val === null) {
					continue;
				}
				$affectedKeys[$key] = true;
				$sql = "INSERT INTO settings (_key,_value,description) VALUES (?,?,?) ON DUPLICATE KEY UPDATE _value=VALUES(_value), description=VALUES(description)";
				$stmt = $this->pdo->prepare($sql);
				$stmt->execute([$key, $val, $desc]);
				$count++;
			}
			$this->pdo->commit();
			$keys = array_keys($affectedKeys);
			if (empty($keys)) {
				return $this->out(['status' => true, 'message' => 'Stored', 'count' => $count, 'items' => []], 200);
			}
			$data = $this->from('settings')->select(null)->select('_key,_value,description')->where('_key', $keys)->fetchAll();
			return $this->out(['status' => true, 'message' => 'Stored', 'count' => $count, 'items' => $data], 200);
		} catch (\Throwable $e) {

			return $this->out(['status' => false, 'message' => 'Failed to store', 'error' => $e->getMessage()], 500);
		}
	}
	public function settings_delete($args)
	{
		$key = $this->req['key'] ?? ($args['key'] ?? null);
		$keys = $this->req['keys'] ?? ($args['keys'] ?? null);
		if ($keys && is_array($keys)) {
			$this->deleteFrom('settings')->where('_key', $keys)->execute();
			return $this->out(['status' => true, 'message' => 'Deleted', 'keys' => $keys], 200);
		}
		if (!$key) {
			return $this->out(['status' => false, 'message' => 'key or keys is required'], 422);
		}
	}
	public function credentials_retrieve($args)
	{
		$keys = $this->req['keys'] ?? null;
		$data = $this->from('credentials')->select(null)->select('_key,_value,description');
		if ($keys) {
			$data = $data->where('_key', $keys);
		}
		$data = $data->fetchAll();
		return $this->out(['status' => true, 'keys' => $keys, 'items' => $data], 200);
	}
	public function credentials_save($args)
	{

		$items = $this->req['items'];
		if (!is_array($items)) {
			return $this->out(['status' => false, 'message' => 'items must be an array'], 422);
		}
		$this->pdo->beginTransaction();
		try {
			$count = 0;
			$affectedKeys = [];
			foreach ($items as $it) {
				$key = $it['_key'];
				$val = $it['_value'];
				$desc = $it['description'];
				if ($key === null || $val === null) {
					continue;
				}
				$affectedKeys[$key] = true;
				$sql = "INSERT INTO credentials (_key,_value,description) VALUES (?,?,?) ON DUPLICATE KEY UPDATE _value=VALUES(_value), description=VALUES(description)";
				$stmt = $this->pdo->prepare($sql);
				$stmt->execute([$key, $val, $desc]);
				$count++;
			}
			$this->pdo->commit();
			$keys = array_keys($affectedKeys);
			if (empty($keys)) {
				return $this->out(['status' => true, 'message' => 'Stored', 'count' => $count, 'items' => []], 200);
			}
			$data = $this->from('credentials')->select(null)->select('_key,_value,description')->where('_key', $keys)->fetchAll();
			return $this->out(['status' => true, 'message' => 'Stored', 'count' => $count, 'items' => $data], 200);
		} catch (\Throwable $e) {

			return $this->out(['status' => false, 'message' => 'Failed to store', 'error' => $e->getMessage()], 500);
		}
	}
	public function credentials_delete($args)
	{
		$keys = $this->req['keys'] ?? ($args['keys'] ?? null);
		if ($keys && is_array($keys)) {
			$this->deleteFrom('credentials')->where('_key', $keys)->execute();
			return $this->out(['status' => true, 'message' => 'Deleted', 'keys' => $keys], 200);
		}
		return $this->out(['status' => false, 'message' => 'keys is required'], 422);

	}

	public function list_schedulers($args)
	{
		$schedulers = $this->from('cron_scheduler')->fetchAll();
		$base = defined('BASEPATH') ? BASEPATH : '';

		foreach ($schedulers as &$s) {
			$s['url'] = $base . 'cron/' . $s['task_name'] . '/' . $s['cron_key'];
		}

		return $this->out(['status' => true, 'schedulers' => $schedulers], 200);
	}

	public function update_scheduler($args)
	{
		$taskId = $this->req['task_id'] ?? 0;
		$interval = $this->req['interval_minutes'] ?? null;
		$status = $this->req['status'] ?? null;

		if (!$taskId) {
			return $this->out(['status' => false, 'message' => 'task_id is required'], 422);
		}

		$updateData = [];
		if ($interval !== null)
			$updateData['interval_minutes'] = intval($interval);
		if ($status !== null)
			$updateData['status'] = intval($status) ? 1 : 0;

		if (empty($updateData)) {
			return $this->out(['status' => false, 'message' => 'Nothing to update'], 422);
		}

		$this->update('cron_scheduler')
			->set($updateData)
			->where('id', $taskId)
			->execute();

		$updated = $this->from('cron_scheduler')->where('id', $taskId)->fetch();

		return $this->out([
			'status' => true,
			'message' => 'Scheduler updated',
			'data' => $updated
		], 200);
	}

	public function save_display_fields($args)
	{
		$items = $this->req['items'] ?? [];
		if (!is_array($items) || empty($items)) {
			return $this->out(['status' => false, 'message' => 'items must be a non-empty array'], 422);
		}

		$results = [];
		$cachedSamples = [];
		foreach ($items as $it) {
			$id = $it['id'] ?? null;
			$tableType = $it['table_type'] ?? 'customer'; // Default to customer for backward compatibility
			$display_name = $it['display_name'] ?? null;
			$columnName = $display_name ? $this->sanitizeColumnName($display_name) : null;
			$dataType = $it['data_type'] ?? 'string';
			$dataLength = $it['data_length'] ?? null;

			if (!$columnName) {
				$results[] = ['error' => 'column_name is required', 'item' => $it];
				continue;
			}
			if (!$display_name) {
				$results[] = ['error' => 'display_name is required', 'item' => $it];
				continue;
			}

			// Cleanup old column if updating and renaming or changing table type
			if ($id) {
				$existing = $this->from('display_field_definitions')->where('id', $id)->fetch();
				if ($existing && ($existing['column_name'] !== $columnName || $existing['table_type'] !== $tableType)) {
					try {
						$this->dropColumn($existing['table_type'] . '_display_fields', $existing['column_name']);
					} catch (\Throwable $e) {
						error_log("Failed to cleanup old column {$existing['column_name']}: " . $e->getMessage());
					}
				}
			}

			// Add column to target table
			try {
				$this->addColumn($tableType . '_display_fields', $columnName, $dataType, $dataLength);
			} catch (\Throwable $e) {
				$results[] = ['error' => 'Failed to create column: ' . $e->getMessage(), 'item' => $it];
				continue;
			}

			$platformId = $it['platform_id'] ?? null;
			$jsonPath = $it['json_path'] ?? null;

			$sample_value = '';
			if ($platformId && $jsonPath) {
				$cacheKey = $platformId . '-' . $tableType;
				if (!isset($cachedSamples[$cacheKey])) {
					$cachedSamples[$cacheKey] = $this->getSampleData($platformId, $tableType);
				}
				if ($cachedSamples[$cacheKey]) {
					$extracted = $this->extractJsonValue($cachedSamples[$cacheKey], $jsonPath);
					if (is_array($extracted)) {
						$sample_value = json_encode($extracted);
					} elseif ($extracted !== null) {
						$sample_value = (string) $extracted;
					}
				}
			}

			$values = [
				'column_name' => $columnName,
				'display_name' => $display_name,
				'table_type' => $tableType,
				'sample_value' => $sample_value,
				'platform_id' => $platformId,
				'json_path' => $jsonPath,
				'data_type' => $dataType,
				'data_length' => $dataLength,
				'category' => $it['category'] ?? null,
				'is_active' => $it['is_active'] ?? 1,
				'sort_order' => $it['sort_order'] ?? 0
			];

			if ($id) {
				$this->update('display_field_definitions')->set($values)->where('id', $id)->execute();
				$results[] = ['status' => 'updated', 'id' => $id];
			} else {
				// Check for existing field by column_name if no ID provided
				$existing = $this->from('display_field_definitions')->where('column_name', $columnName)->fetch();
				if ($existing) {
					$this->update('display_field_definitions')->set($values)->where('id', $existing['id'])->execute();
					$results[] = ['status' => 'updated_existing', 'id' => $existing['id']];
				} else {
					$newId = $this->insertInto('display_field_definitions')->values($values)->execute();
					$results[] = ['status' => 'created', 'id' => $newId];
				}
			}
		}

		return $this->out(['status' => true, 'message' => 'Processing complete', 'results' => $results], 200);
	}

	public function retrieve_display_fields($args)
	{
		$tableType = $this->req['table_type'] ?? ($args['table_type'] ?? null);

		if (!$tableType) {
			return $this->out(['status' => false, 'message' => 'table_type is required'], 422);
		}

		$data = $this->from('display_field_definitions')
			->where('table_type', $tableType)
			->orderBy('sort_order ASC')
			->fetchAll();

		return $this->out(['status' => true, 'table_type' => $tableType, 'items' => $data], 200);
	}

	public function delete_display_fields($args)
	{
		$ids = $this->req['ids'] ?? [];
		if (!is_array($ids) || empty($ids)) {
			return $this->out(['status' => false, 'message' => 'ids must be a non-empty array'], 422);
		}

		// Fetch definitions to know which columns to drop
		$definitions = $this->from('display_field_definitions')->where('id', $ids)->fetchAll();

		foreach ($definitions as $def) {
			$tableType = $def['table_type'] ?? 'customer';
			$columnName = $def['column_name'];
			if ($columnName) {
				try {
					$this->dropColumn($tableType . '_display_fields', $columnName);
				} catch (\Throwable $e) {
					// Log error but continue
					error_log("Failed to drop column {$columnName} from {$tableType}_display_fields: " . $e->getMessage());
				}
			}
		}

		$this->deleteFrom('display_field_definitions')->where('id', $ids)->execute();

		return $this->out(['status' => true, 'message' => count($ids) . ' fields deleted and columns dropped'], 200);
	}

	public function explore_api_keys($args)
	{
		$platformId = $this->req['platform_id'] ?? null;
		$tableType = $this->req['table_type'] ?? 'customer';

		if (!$platformId) {
			return $this->out(['status' => false, 'message' => 'platform_id is required'], 422);
		}

		if ($platformId === 'portal') {
			$tableName = ($tableType === 'customer') ? 'customers' : $tableType . 's';
			$sql = "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?";
			$stmt = $this->pdo->prepare($sql);
			$stmt->execute([$tableName]);
			$paths = $stmt->fetchAll(PDO::FETCH_COLUMN);

			return $this->out([
				'status' => true,
				'platform' => 'portal',
				'sample_paths' => $paths
			], 200);
		}

		$platform = $this->from('platforms')->where('id', $platformId)->fetch();
		if (!$platform) {
			return $this->out(['status' => false, 'message' => 'Platform not found'], 404);
		}

		$sampleData = null;
		try {
			if ($platform['code'] == 'shopify') {
				$api = new shopify($this->pdo);
				if ($tableType === 'product') {
					$response = $api->get_products(['limit' => 1]);
					$sampleData = $response['products'][0] ?? null;
				} else {
					$response = $api->get_customers(['limit' => 1]);
					$sampleData = $response['customers'][0] ?? null;
				}
			} elseif ($platform['code'] == 'apparelmagic') {
				$api = new am($this->pdo);
				$path = ($tableType === 'product') ? 'products' : 'customers';
				$response = $api->paginate($path, [], ['limit' => 1], 'explore keys');
				$sampleData = $response[0] ?? null;
			}
		} catch (\Throwable $e) {
			return $this->out(['status' => false, 'message' => 'API Echo Error: ' . $e->getMessage()], 500);
		}

		if (!$sampleData) {
			return $this->out(['status' => false, 'message' => 'No sample data found from API'], 404);
		}

		$paths = $this->exploreJsonKeys($sampleData);

		return $this->out([
			'status' => true,
			'platform' => $platform['code'],
			'sample_paths' => $paths
		], 200);
	}


	protected function getSampleData($platformId, $tableType)
	{
		if ($platformId === 'portal') {
			return null;
		}

		$platform = $this->from('platforms')->where('id', $platformId)->fetch();
		if (!$platform) {
			return null;
		}

		try {
			if ($platform['code'] == 'shopify') {
				$api = new shopify($this->pdo);
				if ($tableType === 'product') {
					$response = $api->get_products(['limit' => 1]);
					return $response['products'][0] ?? null;
				} else {
					$response = $api->get_customers(['limit' => 1]);
					return $response['customers'][0] ?? null;
				}
			} elseif ($platform['code'] == 'apparelmagic') {
				$api = new am($this->pdo);
				$path = ($tableType === 'product') ? 'products' : 'customers';
				$response = $api->paginate($path, [], ['limit' => 1], 'explore keys');
				return $response[0] ?? null;
			}
		} catch (\Throwable $e) {
			error_log("Failed to fetch sample data for platform {$platformId}: " . $e->getMessage());
		}

		return null;
	}

	protected function sanitizeColumnName(string $name): string
	{
		$name = strtolower($name);
		$name = preg_replace('/[^a-z0-9_]/', '_', $name);
		$name = preg_replace('/_+/', '_', $name);
		return trim($name, '_');
	}

	public function list_platforms($args)
	{
		try {
			$platforms = $this->from('platforms')->orderBy('id ASC')->fetchAll();
			return $this->out(['status' => true, 'items' => $platforms], 200);
		} catch (\Throwable $e) {
			return $this->out(['status' => false, 'message' => $e->getMessage()], 500);
		}
	}

	public function logo_types_list($args)
	{
		try {
			$items = $this->from('logo_types')->orderBy('id ASC')->fetchAll();
			return $this->out(['status' => true, 'items' => $items], 200);
		} catch (\Throwable $e) {
			return $this->out(['status' => false, 'message' => $e->getMessage()], 500);
		}
	}

	public function logo_types_toggle($args)
	{
		$id = intval($this->req['id'] ?? ($args['id'] ?? 0));
		if ($id <= 0) {
			return $this->out(['status' => false, 'message' => 'id is required'], 422);
		}

		$existing = $this->from('logo_types')->where('id', $id)->fetch();
		if (!$existing) {
			return $this->out(['status' => false, 'message' => 'Logo type not found'], 404);
		}

		// If explicit active value provided, use it; otherwise toggle
		if (isset($this->req['active'])) {
			$newActive = intval($this->req['active']) ? 1 : 0;
		} else {
			$newActive = $existing['active'] ? 0 : 1;
		}

		$now = date('Y-m-d H:i:s');
		$this->update('logo_types')->set(['active' => $newActive, 'last_update' => $now])->where('id', $id)->execute();

		$updated = $this->from('logo_types')->where('id', $id)->fetch();
		return $this->out(['status' => true, 'message' => 'Updated', 'item' => $updated], 200);
	}

	public function logo_conversion_methods_list($args)
	{
		try {
			$items = $this->from('logo_conversion_methods')->orderBy('id ASC')->fetchAll();
			return $this->out(['status' => true, 'items' => $items], 200);
		} catch (\Throwable $e) {
			return $this->out(['status' => false, 'message' => $e->getMessage()], 500);
		}
	}

	public function logo_conversion_methods_toggle($args)
	{
		try {
			$id = intval($this->req['id'] ?? ($args['id'] ?? 0));
			if ($id <= 0) {
				return $this->out(['status' => false, 'message' => 'id is required'], 422);
			}

			$existing = $this->from('logo_conversion_methods')->where('id', $id)->fetch();
			if (!$existing) {
				return $this->out(['status' => false, 'message' => 'Method not found'], 404);
			}

			if (isset($this->req['status'])) {
				$newStatus = intval($this->req['status']) ? 1 : 0;
			} else {
				$newStatus = $existing['status'] ? 0 : 1;
			}

			$this->update('logo_conversion_methods')->set(['status' => $newStatus])->where('id', $id)->execute();
			$updated = $this->from('logo_conversion_methods')->where('id', $id)->fetch();
			return $this->out(['status' => true, 'message' => 'Updated', 'item' => $updated], 200);
		} catch (\Throwable $e) {
			return $this->out(['status' => false, 'message' => $e->getMessage()], 500);
		}
	}

	public function update_platform($args)
	{
		try {
			$id = intval($this->req['id'] ?? ($args['id'] ?? 0));
			if ($id <= 0) {
				return $this->out(['status' => false, 'message' => 'id is required'], 422);
			}

			$values = [
				'is_active' => intval($this->req['is_active'] ?? 1),
				'product_mapping_key' => $this->req['product_mapping_key'] ?? '',
				'customer_mapping_key' => $this->req['customer_mapping_key'] ?? '',
				'order_mapping_key' => $this->req['order_mapping_key'] ?? '',
				'order_snyc_key' => $this->req['order_snyc_key'] ?? '',
				'customer_sync_key' => $this->req['customer_sync_key'] ?? '',
				'product_sync_key' => $this->req['product_sync_key'] ?? ''
			];

			$this->update('platforms')->set($values)->where('id', $id)->execute();
			$updated = $this->from('platforms')->where('id', $id)->fetch();

			return $this->out([
				'status' => true,
				'message' => 'Platform updated',
				'data' => $updated
			], 200);
		} catch (\Throwable $e) {
			return $this->out(['status' => false, 'message' => $e->getMessage()], 500);
		}
	}

	/**
	 * List colors with pagination and search
	 * GET /settings/color_list
	 */
	public function color_list($args)
	{
		try {
			$limit = isset($_GET['limit']) ? intval($_GET['limit']) : null;
			$offset = intval($_GET['offset'] ?? 0);
			$search = $_GET['search'] ?? null;

			$query = $this->from('color_list');

			if ($search) {
				$query->where('name LIKE ?', "%$search%")
					->whereOr('manufacturer LIKE ?', "%$search%")
					->whereOr('code LIKE ?', "%$search%");
			}

			$total = $query->count();

			if ($limit !== null) {
				$query->limit($limit)->offset($offset);
			}

			$items = $query->orderBy('id ASC')->fetchAll();

			return $this->out([
				'status' => true,
				'total' => $total,
				'limit' => $limit,
				'offset' => $offset,
				'items' => $items
			], 200);
		} catch (\Throwable $e) {
			return $this->out(['status' => false, 'message' => $e->getMessage()], 500);
		}
	}
	/**
	 * List manufacturers from the dedicated table
	 * GET /settings/color_manufacturer
	 */
	public function color_manufacturer($args)
	{
		try {
			$items = $this->from('color_manufacturer')
				->where('status', 1)
				->orderBy('name ASC')
				->fetchAll();

			return $this->out([
				'status' => true,
				'count' => count($items),
				'items' => $items
			], 200);
		} catch (\Throwable $e) {
			return $this->out(['status' => false, 'message' => $e->getMessage()], 500);
		}
	}

	/**
	 * Save color manufacturer (Create/Update)
	 * POST /settings/color_manufacturer/save
	 */
	public function color_manufacturer_save($args)
	{
		try {
			$id = intval($this->req['id'] ?? 0);
			$data = [
				'name' => $this->req['name'] ?? '',
				'code' => $this->req['code'] ?? null,
				'status' => intval($this->req['status'] ?? 1)
			];

			if (empty($data['name'])) {
				return $this->out(['status' => false, 'message' => 'Name is required'], 422);
			}

			if ($id > 0) {
				$this->update('color_manufacturer')->set($data)->where('id', $id)->execute();
				$message = 'Manufacturer updated successfully';
			} else {
				$id = $this->insertInto('color_manufacturer')->values($data)->execute();
				$message = 'Manufacturer created successfully';
			}

			return $this->out(['status' => true, 'message' => $message, 'id' => $id], 200);
		} catch (\Throwable $e) {
			return $this->out(['status' => false, 'message' => $e->getMessage()], 500);
		}
	}

	/**
	 * Delete color manufacturer
	 * POST /settings/color_manufacturer/delete
	 */
	public function color_manufacturer_delete($args)
	{
		try {
			$id = intval($this->req['id'] ?? 0);
			if ($id <= 0) {
				return $this->out(['status' => false, 'message' => 'ID is required'], 422);
			}

			$this->deleteFrom('color_manufacturer')->where('id', $id)->execute();

			return $this->out(['status' => true, 'message' => 'Manufacturer deleted successfully'], 200);
		} catch (\Throwable $e) {
			return $this->out(['status' => false, 'message' => $e->getMessage()], 500);
		}
	}

	/**
	 * Retrieve single color
	 * POST /settings/color_list/retrieve
	 */
	public function color_list_retrieve($args)
	{
		try {
			$id = intval($this->req['id'] ?? 0);
			if ($id <= 0) {
				return $this->out(['status' => false, 'message' => 'ID is required'], 422);
			}

			$item = $this->from('color_list')->where('id', $id)->fetch();
			if (!$item) {
				return $this->out(['status' => false, 'message' => 'Color not found'], 404);
			}

			return $this->out(['status' => true, 'item' => $item], 200);
		} catch (\Throwable $e) {
			return $this->out(['status' => false, 'message' => $e->getMessage()], 500);
		}
	}

	/**
	 * Save color (Create/Update)
	 * POST /settings/color_list/save
	 */
	public function color_list_save($args)
	{
		try {
			$items = $this->req['items'] ?? null;

			if (is_array($items)) {
				$this->pdo->beginTransaction();
				try {
					$count = 0;
					$resultItems = [];

					foreach ($items as $it) {
						$id = intval($it['id'] ?? 0);
						$data = [
							'name' => $it['name'] ?? '',
							'manufacturer' => $it['manufacturer'] ?? '',
							'code' => $it['code'] ?? '',
							'm_code' => $it['m_code'] ?? '',
							'red' => $it['red'] ?? null,
							'green' => $it['green'] ?? null,
							'blue' => $it['blue'] ?? null,
							'wilcom_code' => $it['wilcom_code'] ?? '',
							'status' => intval($it['status'] ?? 1)
						];

						if (empty($data['name']) || empty($data['manufacturer']) || empty($data['code'])) {
							continue;
						}

						if ($id > 0) {
							$this->update('color_list')->set($data)->where('id', $id)->execute();
						} else {
							$existing = $this->from('color_list')
								->where('code', $data['code'])
								->where('manufacturer', $data['manufacturer'])
								->fetch();
							if ($existing) {
								$id = intval($existing['id']);
								$this->update('color_list')->set($data)->where('id', $id)->execute();
							} else {
								$id = $this->insertInto('color_list')->values($data)->execute();
							}
						}

						$count++;
						$resultItems[] = $this->from('color_list')->where('id', $id)->fetch();
					}

					$this->pdo->commit();
					return $this->out([
						'status' => true,
						'message' => 'Colors saved successfully',
						'count' => $count,
						'items' => $resultItems
					], 200);
				} catch (\Throwable $e) {
					$this->pdo->rollBack();
					throw $e;
				}
			}

			$id = intval($this->req['id'] ?? 0);
			$data = [
				'name' => $this->req['name'] ?? '',
				'manufacturer' => $this->req['manufacturer'] ?? '',
				'code' => $this->req['code'] ?? '',
				'm_code' => $this->req['m_code'] ?? '',
				'red' => $this->req['red'] ?? null,
				'green' => $this->req['green'] ?? null,
				'blue' => $this->req['blue'] ?? null,
				'wilcom_code' => $this->req['wilcom_code'] ?? '',
				'status' => intval($this->req['status'] ?? 1)
			];

			if (empty($data['name']) || empty($data['manufacturer']) || empty($data['code'])) {
				return $this->out(['status' => false, 'message' => 'Name, manufacturer, and code are required'], 422);
			}

			if ($id > 0) {
				$this->update('color_list')->set($data)->where('id', $id)->execute();
				$message = 'Color updated successfully';
			} else {
				$existing = $this->from('color_list')
					->where('code', $data['code'])
					->where('manufacturer', $data['manufacturer'])
					->fetch();
				if ($existing) {
					$id = intval($existing['id']);
					$this->update('color_list')->set($data)->where('id', $id)->execute();
					$message = 'Color updated successfully';
				} else {
					$id = $this->insertInto('color_list')->values($data)->execute();
					$message = 'Color created successfully';
				}
			}

			return $this->out(['status' => true, 'message' => $message, 'id' => $id], 200);
		} catch (\Throwable $e) {
			return $this->out(['status' => false, 'message' => $e->getMessage()], 500);
		}
	}

	/**
	 * Delete color
	 * POST /settings/color_list/delete
	 */
	public function color_list_delete($args)
	{
		try {
			$id = intval($this->req['id'] ?? 0);
			if ($id <= 0) {
				return $this->out(['status' => false, 'message' => 'ID is required'], 422);
			}

			$this->deleteFrom('color_list')->where('id', $id)->execute();

			return $this->out(['status' => true, 'message' => 'Color deleted successfully'], 200);
		} catch (\Throwable $e) {
			return $this->out(['status' => false, 'message' => $e->getMessage()], 500);
		}
	}

	// ==================== DICT COLOR CRUD ====================

	/**
	 * List dict_color with optional pagination and search
	 * GET /settings/dist_color
	 */
	public function dict_color_list($args)
	{
		try {
			$limit = isset($_GET['limit']) ? intval($_GET['limit']) : null;
			$offset = intval($_GET['offset'] ?? 0);
			$search = $_GET['search'] ?? null;

			$query = $this->from('dict_color');

			if ($search) {
				$query->where('code LIKE ?', "%$search%")
					->whereOr('value LIKE ?', "%$search%");
			}

			$total = $query->count();

			if ($limit !== null) {
				$query->limit($limit)->offset($offset);
			}

			$items = $query->orderBy('id ASC')->fetchAll();

			return $this->out([
				'status' => true,
				'total' => $total,
				'limit' => $limit,
				'offset' => $offset,
				'items' => $items
			], 200);
		} catch (\Throwable $e) {
			return $this->out(['status' => false, 'message' => $e->getMessage()], 500);
		}
	}

	/**
	 * Retrieve single dict_color
	 * POST /settings/dist_color/retrieve
	 */
	public function dict_color_retrieve($args)
	{
		try {
			$id = intval($this->req['id'] ?? 0);
			if ($id <= 0) {
				return $this->out(['status' => false, 'message' => 'ID is required'], 422);
			}

			$item = $this->from('dict_color')->where('id', $id)->fetch();
			if (!$item) {
				return $this->out(['status' => false, 'message' => 'Color not found'], 404);
			}

			return $this->out(['status' => true, 'item' => $item], 200);
		} catch (\Throwable $e) {
			return $this->out(['status' => false, 'message' => $e->getMessage()], 500);
		}
	}

	/**
	 * Save dict_color (Create/Update)
	 * POST /settings/dist_color/save
	 */
	public function dict_color_save($args)
	{
		try {
			$id = intval($this->req['id'] ?? 0);
			$data = [
				'code' => $this->req['code'] ?? '',
				'value' => $this->req['value'] ?? '',
				'last_update' => date('Y-m-d H:i:s')
			];

			if (empty($data['code']) || empty($data['value'])) {
				return $this->out(['status' => false, 'message' => 'Code and value are required'], 422);
			}

			if ($id > 0) {
				$this->update('dict_color')->set($data)->where('id', $id)->execute();
				$message = 'Color updated successfully';
			} else {
				$id = $this->insertInto('dict_color')->values($data)->execute();
				$message = 'Color created successfully';
			}

			return $this->out(['status' => true, 'message' => $message, 'id' => $id], 200);
		} catch (\Throwable $e) {
			return $this->out(['status' => false, 'message' => $e->getMessage()], 500);
		}
	}

	/**
	 * Delete dict_color
	 * POST /settings/dist_color/delete
	 */
	public function dict_color_delete($args)
	{
		try {
			$id = intval($this->req['id'] ?? 0);
			if ($id <= 0) {
				return $this->out(['status' => false, 'message' => 'ID is required'], 422);
			}

			$this->deleteFrom('dict_color')->where('id', $id)->execute();

			return $this->out(['status' => true, 'message' => 'Color deleted successfully'], 200);
		} catch (\Throwable $e) {
			return $this->out(['status' => false, 'message' => $e->getMessage()], 500);
		}
	}

	// ==================== DICT PLACEMENT CRUD ====================

	/**
	 * List dict_placement with optional pagination and search
	 * GET /settings/dist_placement
	 */
	public function dict_placement_list($args)
	{
		try {
			$limit = isset($_GET['limit']) ? intval($_GET['limit']) : null;
			$offset = intval($_GET['offset'] ?? 0);
			$search = $_GET['search'] ?? null;

			$query = $this->from('dict_placement');

			if ($search) {
				$query->where('code LIKE ?', "%$search%")
					->whereOr('value LIKE ?', "%$search%")
					->whereOr('category LIKE ?', "%$search%");
			}

			$total = $query->count();

			if ($limit !== null) {
				$query->limit($limit)->offset($offset);
			}

			$items = $query->orderBy('id ASC')->fetchAll();

			return $this->out([
				'status' => true,
				'total' => $total,
				'limit' => $limit,
				'offset' => $offset,
				'items' => $items
			], 200);
		} catch (\Throwable $e) {
			return $this->out(['status' => false, 'message' => $e->getMessage()], 500);
		}
	}

	/**
	 * Retrieve single dict_placement
	 * POST /settings/dist_placement/retrieve
	 */
	public function dict_placement_retrieve($args)
	{
		try {
			$id = intval($this->req['id'] ?? 0);
			if ($id <= 0) {
				return $this->out(['status' => false, 'message' => 'ID is required'], 422);
			}

			$item = $this->from('dict_placement')->where('id', $id)->fetch();
			if (!$item) {
				return $this->out(['status' => false, 'message' => 'Placement not found'], 404);
			}

			return $this->out(['status' => true, 'item' => $item], 200);
		} catch (\Throwable $e) {
			return $this->out(['status' => false, 'message' => $e->getMessage()], 500);
		}
	}

	/**
	 * Save dict_placement (Create/Update)
	 * POST /settings/dist_placement/save
	 */
	public function dict_placement_save($args)
	{
		try {
			$id = intval($this->req['id'] ?? 0);
			$data = [
				'code' => $this->req['code'] ?? '',
				'value' => $this->req['value'] ?? '',
				'category' => $this->req['category'] ?? ''
			];

			if (empty($data['code']) || empty($data['value'])) {
				return $this->out(['status' => false, 'message' => 'Code and value are required'], 422);
			}

			if ($id > 0) {
				$this->update('dict_placement')->set($data)->where('id', $id)->execute();
				$message = 'Placement updated successfully';
			} else {
				$id = $this->insertInto('dict_placement')->values($data)->execute();
				$message = 'Placement created successfully';
			}

			return $this->out(['status' => true, 'message' => $message, 'id' => $id], 200);
		} catch (\Throwable $e) {
			return $this->out(['status' => false, 'message' => $e->getMessage()], 500);
		}
	}

	/**
	 * Delete dict_placement
	 * POST /settings/dist_placement/delete
	 */
	public function dict_placement_delete($args)
	{
		try {
			$id = intval($this->req['id'] ?? 0);
			if ($id <= 0) {
				return $this->out(['status' => false, 'message' => 'ID is required'], 422);
			}

			$this->deleteFrom('dict_placement')->where('id', $id)->execute();

			return $this->out(['status' => true, 'message' => 'Placement deleted successfully'], 200);
		} catch (\Throwable $e) {
			return $this->out(['status' => false, 'message' => $e->getMessage()], 500);
		}
	}

	// ==================== CONFIGURATIONS (JSON) ====================

	/**
	 * Setup configurations table (creates it if not exists)
	 * POST /configurations/setup
	 */
	public function configurations_setup($args)
	{
		try {
			$sql = "CREATE TABLE IF NOT EXISTS `configurations` (
				`_key`        VARCHAR(255)  NOT NULL,
				`_value`      LONGTEXT      NULL,
				`description` TEXT          NULL,
				PRIMARY KEY (`_key`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
			$this->pdo->exec($sql);
			return $this->out(['status' => true, 'message' => 'configurations table ready'], 200);
		} catch (\Throwable $e) {
			return $this->out(['status' => false, 'message' => $e->getMessage()], 500);
		}
	}

	/**
	 * Retrieve configurations (all or by keys)
	 * POST /configurations/retrieve
	 */
	public function configurations_retrieve($args)
	{
		try {
			$keys = $this->req['keys'] ?? null;
			$data = $this->from('configurations')->select(null)->select('_key,_value,description');
			if ($keys) {
				$data = $data->where('_key', $keys);
			}
			$data = $data->fetchAll();

			// Decode _value from JSON for each item
			foreach ($data as &$item) {
				if ($item['_value'] !== null) {
					$decoded = json_decode($item['_value'], true);
					if (json_last_error() === JSON_ERROR_NONE) {
						$item['_value'] = $decoded;
					}
				}
			}
			unset($item);

			return $this->out(['status' => true, 'keys' => $keys, 'items' => $data], 200);
		} catch (\Throwable $e) {
			return $this->out(['status' => false, 'message' => $e->getMessage()], 500);
		}
	}

	/**
	 * Save configurations (insert or update by _key)
	 * POST /configurations/save
	 * Expects: { "items": [{ "_key": "...", "_value": {...}, "description": "..." }] }
	 * NOTE: _value MUST be a JSON object (array or associative object). Primitives are rejected.
	 */
	public function configurations_save($args)
	{
		$items = $this->req['items'] ?? null;
		if (!is_array($items)) {
			return $this->out(['status' => false, 'message' => 'items must be an array'], 422);
		}
		$this->pdo->beginTransaction();
		try {
			$count = 0;
			$affectedKeys = [];
			foreach ($items as $it) {
				$key = $it['_key'] ?? null;
				$val = $it['_value'] ?? null;
				$desc = $it['description'] ?? null;

				if ($key === null || $val === null) {
					continue;
				}

				// If _value is already a PHP array/object, encode directly
				if (is_array($val) || is_object($val)) {
					$val = json_encode($val, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
				} elseif (is_string($val)) {
					// Accept a JSON string, but validate it decodes to an object/array
					$decoded = json_decode($val, true);
					if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
						$this->pdo->rollBack();
						return $this->out([
							'status' => false,
							'message' => "_value for key '{$key}' must be a JSON object, got a plain string or invalid JSON",
						], 422);
					}
					// Re-encode to normalise formatting
					$val = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
				} else {
					// Primitives (int, float, bool) are not allowed
					$this->pdo->rollBack();
					return $this->out([
						'status' => false,
						'message' => "_value for key '{$key}' must be a JSON object, got a primitive value",
					], 422);
				}

				$affectedKeys[$key] = true;
				$sql = "INSERT INTO configurations (_key,_value,description) VALUES (?,?,?) ON DUPLICATE KEY UPDATE _value=VALUES(_value), description=VALUES(description)";
				$stmt = $this->pdo->prepare($sql);
				$stmt->execute([$key, $val, $desc]);
				$count++;
			}
			$this->pdo->commit();

			$keys = array_keys($affectedKeys);
			if (empty($keys)) {
				return $this->out(['status' => true, 'message' => 'Stored', 'count' => $count, 'items' => []], 200);
			}

			// Return stored items with _value decoded back to a JSON object
			$data = $this->from('configurations')->select(null)->select('_key,_value,description')->where('_key', $keys)->fetchAll();
			foreach ($data as &$item) {
				if ($item['_value'] !== null) {
					$decoded = json_decode($item['_value'], true);
					if (json_last_error() === JSON_ERROR_NONE) {
						$item['_value'] = $decoded;
					}
				}
			}
			unset($item);

			return $this->out(['status' => true, 'message' => 'Stored', 'count' => $count, 'items' => $data], 200);
		} catch (\Throwable $e) {
			$this->pdo->rollBack();
			return $this->out(['status' => false, 'message' => 'Failed to store', 'error' => $e->getMessage()], 500);
		}
	}

	/**
	 * Delete configurations by key or keys
	 * POST /configurations/delete
	 */
	public function configurations_delete($args)
	{
		try {
			$key = $this->req['key'] ?? ($args['key'] ?? null);
			$keys = $this->req['keys'] ?? ($args['keys'] ?? null);

			if ($keys && is_array($keys)) {
				$this->deleteFrom('configurations')->where('_key', $keys)->execute();
				return $this->out(['status' => true, 'message' => 'Deleted', 'keys' => $keys], 200);
			}
			if ($key) {
				$this->deleteFrom('configurations')->where('_key', $key)->execute();
				return $this->out(['status' => true, 'message' => 'Deleted', 'key' => $key], 200);
			}
			return $this->out(['status' => false, 'message' => 'key or keys is required'], 422);
		} catch (\Throwable $e) {
			return $this->out(['status' => false, 'message' => $e->getMessage()], 500);
		}
	}

	public function verify_platform_credentials($args)
	{
		$platformId = $this->req['platform_id'] ?? null;
		if (!$platformId) {
			return $this->out(['status' => false, 'message' => 'platform_id is required'], 422);
		}

		$platform = $this->from('platforms')->where('id', $platformId)->fetch();
		if (!$platform) {
			return $this->out(['status' => false, 'message' => 'Platform not found'], 404);
		}

		$code = $platform['code'];
		$isValid = false;
		$message = '';

		try {
			if ($code == 'shopify') {
				$token = $this->req['shopify_token'] ?? null;
				$url = $this->req['shopify_url'] ?? null;
				if (!$token || !$url) {
					return $this->out(['status' => false, 'message' => 'shopify_token and shopify_url are required'], 422);
				}

				if (substr($url, -1) !== '/')
					$url .= '/';

				$curl = curl_init();
				curl_setopt_array($curl, array(
					CURLOPT_URL => $url . 'customers.json?limit=1',
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_TIMEOUT => 30,
					CURLOPT_HTTPHEADER => array('X-Shopify-Access-Token:' . $token),
				));
				$response = curl_exec($curl);
				$status_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
				unset($curl);

				if ($status_code == 200) {
					$isValid = true;
					$message = 'Connection successful';
				} else {
					$isValid = false;
					$res = json_decode($response, true);
					$message = $res['errors'] ?? 'Invalid credentials or URL';
					if (is_array($message))
						$message = json_encode($message);
				}
			} elseif ($code == 'apparelmagic') {
				$token = $this->req['am_token'] ?? null;
				$url = $this->req['am_url'] ?? null;
				if (!$token || !$url) {
					return $this->out(['status' => false, 'message' => 'am_token and am_url are required'], 422);
				}

				if (substr($url, -1) !== '/')
					$url .= '/';

				$am_url = $url . 'api/json/customers/?token=' . $token . '&time=' . time() . '&pagination[limit]=1';
				$ch = curl_init();
				curl_setopt_array($ch, array(
					CURLOPT_URL => $am_url,
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_TIMEOUT => 30,
					CURLOPT_HTTPHEADER => array("cache-control: no-cache"),
				));
				$result = curl_exec($ch);
				$http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
				unset($ch);

				if ($http_status == 200) {
					$response = json_decode($result, true);
					if (isset($response['response'])) {
						$isValid = true;
						$message = 'Connection successful';
					} else {
						$isValid = false;
						$message = $response['meta']['errors'][0] ?? 'Invalid credentials';
					}
				} else {
					$isValid = false;
					$message = 'HTTP error: ' . $http_status;
				}
			} else {
				return $this->out(['status' => false, 'message' => 'Verification not implemented for this platform'], 501);
			}
		} catch (\Throwable $e) {
			return $this->out(['status' => false, 'message' => 'Internal error: ' . $e->getMessage()], 500);
		}

		return $this->out([
			'status' => true,
			'valid' => $isValid,
			'platform' => $code,
			'message' => $message
		], 200);
	}
}
