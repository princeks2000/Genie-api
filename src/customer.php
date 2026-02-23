<?php
class customer extends model
{
    protected array $typeMap = [
        'string' => 'VARCHAR(1000)',
        'int' => 'INT',
        'bool' => 'TINYINT(1)',
        'date' => 'DATE',
        'float' => 'DECIMAL(10,2)',
    ];

    /* ==========================================
       JSON PATH EXTRACTION HELPERS
    ========================================== */

    /**
     * Extract value from JSON using dot notation path
     * Example: extractJsonValue($json, "addresses.0.city")
     */


    /* ==========================================
       COLUMN MANAGEMENT
    ========================================== */

    protected function columnExists(string $param1, ?string $param2 = null): bool
    {
        if ($param2 === null) {
            // Old signature: columnExists(string $column) checking customer_display_fields
            $table = 'customer_display_fields';
            $column = $param1;
        } else {
            // New signature: columnExists(string $table, string $column)
            $table = $param1;
            $column = $param2;
        }

        $sql = "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$table, $column]);
        $count = $stmt->fetchColumn();
        return (bool) $count;
    }

    protected function addColumn(string $column, string $type): void
    {
        if (!isset($this->typeMap[$type])) {
            throw new InvalidArgumentException("Invalid data type: {$type}");
        }
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column)) {
            throw new InvalidArgumentException("Invalid column name: {$column}");
        }
        if ($this->columnExists($column)) {
            return;
        }
        $sql = "ALTER TABLE customer_display_fields ADD COLUMN `{$column}` {$this->typeMap[$type]} NULL, ADD INDEX idx_{$column} (`{$column}`)";
        $this->pdo->exec($sql);
    }

    protected function ensureMappingKeyColumn(): void
    {
        // 1. Drop foreign key if it exists
        try {
            $this->pdo->exec("ALTER TABLE customer_display_fields DROP FOREIGN KEY fk_display_customer");
        } catch (\Throwable $e) {
        }

        // 2. Drop primary key if customer_id is the primary key
        try {
            $sql = "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_display_fields' 
                    AND CONSTRAINT_NAME = 'PRIMARY'";
            $pk = $this->pdo->query($sql)->fetchColumn();
            if ($pk === 'customer_id') {
                $this->pdo->exec("ALTER TABLE customer_display_fields DROP PRIMARY KEY");
                $this->pdo->exec("ALTER TABLE customer_display_fields MODIFY customer_id BIGINT(20) UNSIGNED NULL");
            }
        } catch (\Throwable $e) {
        }

        // 3. Add new auto-increment ID if it doesn't exist
        if (!$this->columnExists('id')) {
            $this->pdo->exec("ALTER TABLE customer_display_fields ADD COLUMN id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST");
        }

        // 4. Ensure unique_mapping_key exists
        if (!$this->columnExists('unique_mapping_key')) {
            $this->pdo->exec("ALTER TABLE customer_display_fields ADD COLUMN unique_mapping_key VARCHAR(255) NULL");
            $this->pdo->exec("CREATE UNIQUE INDEX uk_mapping_key ON customer_display_fields (unique_mapping_key)");
        }

        // 5. Ensure sources column exists
        if (!$this->columnExists('sources')) {
            $this->pdo->exec("ALTER TABLE customer_display_fields ADD COLUMN sources TEXT NULL");
        }
    }
    /**
     * Validate that customer_key setting points to a valid column
     */
    protected function validateCustomerKey(): string
    {
        $customerKey = $this->getSettingValue('customer_key') ?: 'email';

        if (!$this->columnExists('customers', $customerKey)) {
            throw new \RuntimeException("Invalid customer_key setting: column '$customerKey' does not exist in customers table");
        }

        return $customerKey;
    }

    /* ==========================================
       PLATFORM RESPONSE STORAGE
    ========================================== */

    /**
     * Extract sync key value from JSON using platform's customer_sync_key path.
     * Stored in customer_platform_responses.external_id.
     */
    protected function extractSyncKeyValue(array $json, string $syncKeyPath): ?string
    {
        if (empty($syncKeyPath)) {
            return null;
        }
        $value = $this->extractJsonValue($json, $syncKeyPath);
        return $value !== null ? (string) $value : null;
    }

    /**
     * Find or create customer by mapping key value
     */
    // protected function findOrCreateCustomerByMappingKey(string $mappingKeyValue): int
    // {
    //     // Check if customer exists with this mapping key value
    //     $customerKey = $this->getSettingValue('customer_key') ?: 'email';
    //     $existing = $this->from('customers')
    //         ->where($customerKey, $mappingKeyValue)
    //         ->fetch();

    //     if ($existing) {
    //         return (int) $existing['id'];
    //     }

    //     // Create new customer
    //     $accountNumber = 'CUST-' . strtoupper(substr(md5($mappingKeyValue . time()), 0, 8));
    //     $customerId = $this->insertInto('customers')
    //         ->values([
    //             'account_number' => $accountNumber,
    //             'created_at' => date('Y-m-d H:i:s')
    //         ])
    //         ->execute();

    //     return $customerId;
    // }

    /**
     * Save platform response
     * POST /customer/save_platform_response
     */
    public function save_platform_response($args)
    {
        try {
            $platformCode = $this->req['platform_code'] ?? ($args['platform_code'] ?? null);
            $rawResponse = $this->req['raw_response'] ?? ($args['raw_response'] ?? null);

            if (!$platformCode || !$rawResponse) {
                return $this->out([
                    'status' => false,
                    'message' => 'platform_code and raw_response are required'
                ], 422);
            }

            // Validate JSON
            if (is_string($rawResponse)) {
                $rawResponse = json_decode($rawResponse, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    return $this->out([
                        'status' => false,
                        'message' => 'Invalid JSON in raw_response: ' . json_last_error_msg()
                    ], 422);
                }
            }

            if (!is_array($rawResponse)) {
                return $this->out([
                    'status' => false,
                    'message' => 'raw_response must be a JSON object'
                ], 422);
            }

            // Get platform config
            $platform = $this->from('platforms')
                ->where('code', $platformCode)
                ->fetch();

            if (!$platform) {
                return $this->out([
                    'status' => false,
                    'message' => "Platform not found: {$platformCode}"
                ], 404);
            }

            // Extract sync key value using platforms.customer_sync_key; store in external_id
            $syncKeyPath = $platform['customer_sync_key'] ?? '';
            $externalId = $this->extractSyncKeyValue($rawResponse, $syncKeyPath);

            // Extract mapping key value (always required for now based on legacy logic, or at least nice to have)
            $mappingPath = $platform['customer_mapping_key'] ?? '';
            $mappingKeyValue = null;
            if ($mappingPath) {
                $val = $this->extractJsonValue($rawResponse, $mappingPath);
                $mappingKeyValue = (is_string($val) || is_numeric($val)) ? (string) $val : null;
            }

            if (!$externalId && !$mappingKeyValue) {
                return $this->out([
                    'status' => false,
                    'message' => "Could not extract neither external_id (path: $syncKeyPath) nor mapping_key_value (path: $mappingPath)"
                ], 422);
            }

            // Store or update platform response (use external_id priority)
            $existing = null;
            if ($externalId) {
                // Priority 1: Lookup by external_id
                $existing = $this->from('customer_platform_responses')
                    ->where('platform_id', $platform['id'])
                    ->where('external_id', $externalId)
                    ->fetch();
            }

            if (!$existing && $mappingKeyValue) {
                // Priority 2: Lookup by mapping_key_value
                $existing = $this->from('customer_platform_responses')
                    ->where('platform_id', $platform['id'])
                    ->where('mapping_key_value', $mappingKeyValue)
                    ->fetch();
            }

            if ($existing) {
                $updateData = [
                    'raw_response' => json_encode($rawResponse),
                    'synced_at' => date('Y-m-d H:i:s')
                ];
                if ($externalId) {
                    $updateData['external_id'] = $externalId;
                }
                if ($mappingKeyValue) {
                    $updateData['mapping_key_value'] = $mappingKeyValue;
                }

                $this->update('customer_platform_responses')
                    ->set($updateData)
                    ->where('id', $existing['id'])
                    ->execute();
                $responseId = $existing['id'];
            } else {
                $insertData = [
                    'platform_id' => $platform['id'],
                    'raw_response' => json_encode($rawResponse),
                    'synced_at' => date('Y-m-d H:i:s'),
                    'created_at' => date('Y-m-d H:i:s')
                ];
                if ($externalId) {
                    $insertData['external_id'] = $externalId;
                } else {
                    $insertData['external_id'] = '';
                }
                if ($mappingKeyValue) {
                    $insertData['mapping_key_value'] = $mappingKeyValue;
                } else {

                    $insertData['mapping_key_value'] = '';
                }

                $responseId = $this->insertInto('customer_platform_responses')
                    ->values($insertData)
                    ->execute();
            }

            // Trigger materialization. Use mapping key if available, else external_id
            $syncKey = $mappingKeyValue ?: $externalId;
            if ($syncKey) {
                $this->syncCustomerByMappingKey($syncKey);
            }

            return $this->out([
                'status' => true,
                'message' => 'Platform response saved and synced',
                'response_id' => $responseId,
                'mapping_key_value' => $mappingKeyValue,
                'external_id' => $externalId
            ], 200);

        } catch (\Throwable $e) {
            return $this->out([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update platform response from API and synchronize
     * POST /customer/update_platform_response
     */
    public function update_platform_response($args)
    {
        try {
            $displayId = $this->req['display_id'] ?? ($args['display_id'] ?? null);
            $platformId = $this->req['platform_id'] ?? ($args['platform_id'] ?? null);

            if (!$displayId || !$platformId) {
                return $this->out([
                    'status' => false,
                    'message' => 'display_id and platform_id are required'
                ], 422);
            }

            // 1. Fetch display row
            $displayRow = $this->from('customer_display_fields')
                ->where('id', $displayId)
                ->fetch();

            if (!$displayRow) {
                return $this->out(['status' => false, 'message' => 'Display record not found'], 404);
            }

            // 2. Extract external ID for this platform
            $sources = explode(',', (string) $displayRow['sources']);
            $externalIds = explode(',', (string) $displayRow['source_external_ids']);
            $sourceIndex = array_search((string) $platformId, $sources);

            if ($sourceIndex === false || !isset($externalIds[$sourceIndex])) {
                return $this->out(['status' => false, 'message' => 'External ID not found for this platform in display record'], 404);
            }

            $externalId = $externalIds[$sourceIndex];

            // 3. Get platform config
            $platform = $this->from('platforms')->where('id', $platformId)->fetch();
            if (!$platform) {
                return $this->out(['status' => false, 'message' => 'Platform not found'], 404);
            }

            // 4. Fetch latest data from API
            $rawResponseData = null;
            if ($platform['code'] === 'shopify') {
                $api = new shopify($this->pdo);
                $rawResponseData = $api->get_customer($externalId);
            } else if ($platform['code'] === 'apparelmagic') {
                $api = new am($this->pdo);
                $rawResponseData = $api->get_customers($externalId);
            }

            if (!$rawResponseData) {
                return $this->out(['status' => false, 'message' => 'Failed to fetch data from platform API'], 502);
            }

            // 5. Update or insert platform response
            $existingResponse = $this->from('customer_platform_responses')
                ->where('platform_id', $platformId)
                ->where('external_id', $externalId)
                ->fetch();

            $updateData = [
                'raw_response' => json_encode($rawResponseData),
                'synced_at' => date('Y-m-d H:i:s')
            ];

            if ($existingResponse) {
                $this->update('customer_platform_responses')
                    ->set($updateData)
                    ->where('id', $existingResponse['id'])
                    ->execute();
            } else {
                $updateData['platform_id'] = $platformId;
                $updateData['external_id'] = $externalId;
                $updateData['mapping_key_value'] = $displayRow['unique_mapping_key'];
                $updateData['created_at'] = date('Y-m-d H:i:s');
                $this->insertInto('customer_platform_responses')
                    ->values($updateData)
                    ->execute();
            }

            // 6. Synchronize display fields
            $this->syncCustomerByDisplayId($displayId);

            return $this->out([
                'status' => true,
                'message' => 'Platform response fetched and synced successfully',
                'external_id' => $externalId
            ], 200);

        } catch (\Throwable $e) {
            return $this->out([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }




    /* ==========================================
       DISPLAY FIELD DEFINITIONS
    ========================================== */

    public function getActiveFields(): array
    {
        return $this->from('display_field_definitions dfd')
            ->leftJoin('platforms p ON p.id = dfd.platform_id')
            ->select('dfd.*, p.code as platform_code')
            ->where('dfd.is_active', 1)
            ->where('dfd.table_type', 'customer')
            ->orderBy('dfd.sort_order ASC')
            ->fetchAll();
    }

    /* ==========================================
       DISPLAY TABLE INIT
    ========================================== */

    protected function ensureCustomerRow(string $mappingKey, ?int $customerId = null): void
    {
        $this->ensureMappingKeyColumn();
        $sql = "INSERT INTO customer_display_fields (unique_mapping_key, customer_id) VALUES (?, ?) 
                ON DUPLICATE KEY UPDATE customer_id = IFNULL(customer_id, VALUES(customer_id))";
        $this->pdo->prepare($sql)->execute([$mappingKey, $customerId]);
    }
    /* ==========================================
       VALUE MATERIALIZATION (JSON → DISPLAY)
    ========================================== */

    /**
     * Fetch value from JSON response using json_path
     */
    protected function fetchJsonValue(
        int $customerId,
        ?string $platformCode,
        string $jsonPath
    ): mixed {
        // Get customer mapping key
        $customerKey = $this->getSettingValue('customer_key') ?: 'email';
        $customer = $this->from('customers')->where('id', $customerId)->fetch();

        if (!$customer || !isset($customer[$customerKey])) {
            return null;
        }

        $query = $this->from('customer_platform_responses cpr')
            ->select('cpr.raw_response')
            ->innerJoin('platforms p ON p.id = cpr.platform_id')
            ->where('cpr.mapping_key_value', $customer[$customerKey]);

        if ($platformCode) {
            $query->where('p.code', $platformCode);
        }

        $row = $query->fetch();

        if (!$row) {
            return null;
        }

        $json = json_decode($row['raw_response'], true);
        if (!$json) {
            return null;
        }

        return $this->extractJsonValue($json, $jsonPath);
    }

    protected function syncCustomerByMappingKey(string $mappingValue): void
    {
        $fields = $this->getActiveFields();
        if (empty($fields)) {
            return;
        }
        // 1. Get customer record (if it exists)
        $customerKey = $this->validateCustomerKey();
        $customer = $this->from('customers')->where($customerKey, $mappingValue)->fetch();
        $customerId = $customer ? (int) $customer['id'] : null;

        $this->ensureCustomerRow($mappingValue, $customerId);

        // Track sources and their external IDs
        $sourceMap = []; // platform_id/portal => external_id
        if ($customer) {
            $sourceMap['portal'] = (string) $customer['id'];
        }

        // 2. Fetch platform responses for this mapping value (external_id)
        $platformResponses = [];
        $responses = $this->from('customer_platform_responses cpr')
            ->leftJoin('platforms p ON p.id = cpr.platform_id')
            ->select('cpr.raw_response, p.code as platform_code, cpr.platform_id, cpr.external_id, cpr.id as customer_platform_response_id')
            ->where('cpr.mapping_key_value', $mappingValue)
            ->fetchAll();

        foreach ($responses as $resp) {
            if ($resp['platform_code']) {
                $platformResponses[$resp['platform_code']] = json_decode($resp['raw_response'], true);
                $sourceMap[$resp['platform_id']] = $resp['external_id'];
            }
        }

        // 3. Build update data
        $reservedColumns = ['id', 'unique_mapping_key', 'sources', 'source_external_ids', 'customer_id', 'created_at', 'updated_at'];
        $updateData = [];
        foreach ($fields as $field) {
            $columnName = $field['column_name'];

            // Skip reserved/system columns to prevent integrity constraint violations
            if (in_array($columnName, $reservedColumns)) {
                continue;
            }

            $jsonPath = $field['json_path'];
            $platformCode = $field['platform_code'];
            $value = null;

            if (!$platformCode || $platformCode === 'portal') {
                // Internal field from customers table (set to null if no portal entry)
                $value = $customer ? ($customer[$jsonPath] ?? null) : null;
            } else if (isset($platformResponses[$platformCode])) {
                // External field from platform JSON
                $value = $this->extractJsonValue($platformResponses[$platformCode], $jsonPath);
            }

            $castValue = $this->castValue($value, $field['data_type']);
            $updateData[$columnName] = $castValue;

        }

        if (!empty($updateData)) {
            // Add unique sources and external IDs strings
            $updateData['sources'] = implode(',', array_keys($sourceMap));
            $updateData['source_external_ids'] = implode(',', array_values($sourceMap));
            $updateData['customer_id'] = $customerId;
            $this->update('customer_display_fields')
                ->set($updateData)
                ->where('unique_mapping_key', $mappingValue)
                ->execute();
        }
    }

    protected function syncCustomerByDisplayId(int $displayId): void
    {

        $fields = $this->getActiveFields();
        if (empty($fields)) {
            return;
        }

        // Get the specific display row
        $displayRow = $this->from('customer_display_fields')
            ->where('id', $displayId)
            ->fetch();

        if (!$displayRow) {
            return;
        }

        $mappingValue = $displayRow['unique_mapping_key'];
        if (!$mappingValue) {
            return;
        }

        // 1. Get customer record (if it exists)
        $customerKey = $this->validateCustomerKey();
        $customer = $this->from('customers')->where($customerKey, $mappingValue)->fetch();
        $customerId = $customer ? (int) $customer['id'] : null;

        // Track sources and their external IDs
        $sourceMap = [];
        if ($customer) {
            $sourceMap['portal'] = (string) $customer['id'];
        }

        // 2. Fetch platform responses
        $platformResponses = [];
        $responses = $this->from('customer_platform_responses cpr')
            ->leftJoin('platforms p ON p.id = cpr.platform_id')
            ->select('cpr.raw_response, p.code as platform_code, cpr.platform_id, cpr.external_id')
            ->where('cpr.mapping_key_value', $mappingValue)
            ->fetchAll();

        foreach ($responses as $resp) {
            if ($resp['platform_code']) {
                $platformResponses[$resp['platform_code']] = json_decode($resp['raw_response'], true);
                $sourceMap[$resp['platform_id']] = $resp['external_id'];
            }
        }

        // 3. Build update data
        $reservedColumns = ['id', 'unique_mapping_key', 'sources', 'source_external_ids', 'customer_id', 'created_at', 'updated_at'];
        $updateData = [];
        foreach ($fields as $field) {
            $columnName = $field['column_name'];

            // Skip reserved/system columns to prevent integrity constraint violations
            if (in_array($columnName, $reservedColumns)) {
                continue;
            }

            $jsonPath = $field['json_path'];
            $platformCode = $field['platform_code'];
            $value = null;

            if (!$platformCode || $platformCode === 'portal') {
                $value = $customer ? ($customer[$jsonPath] ?? null) : null;
            } else if (isset($platformResponses[$platformCode])) {
                $value = $this->extractJsonValue($platformResponses[$platformCode], $jsonPath);
            }

            $updateData[$columnName] = $this->castValue($value, $field['data_type']);
        }

        if (!empty($updateData)) {
            $updateData['sources'] = implode(',', array_keys($sourceMap));
            $updateData['source_external_ids'] = implode(',', array_values($sourceMap));
            $updateData['customer_id'] = $customerId;
            $this->update('customer_display_fields')
                ->set($updateData)
                ->where('id', $displayId)
                ->execute();
        }
    }

    /**
     * Sync customer - materialize display columns from JSON
     */
    protected function syncCustomer(int $customerId): void
    {
        $customerKey = $this->validateCustomerKey();
        $customer = $this->from('customers')->where('id', $customerId)->fetch();
        if ($customer && isset($customer[$customerKey])) {
            $this->syncCustomerByMappingKey($customer[$customerKey]);
        }
    }



    public function sync_customer($args)
    {
        $customerId = intval($this->req['customer_id'] ?? ($args['customer_id'] ?? 0));
        if ($customerId <= 0) {
            return $this->out(['status' => false, 'message' => 'customer_id is required'], 422);
        }
        try {
            $this->syncCustomer($customerId);
            return $this->out(['status' => true, 'message' => 'Customer synced'], 200);
        } catch (\Throwable $e) {
            return $this->out(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function materialize_all_display_fields($args)
    {
        try {
            $customerKey = $this->validateCustomerKey();

            // Discover all unique mapping keys from both tables
            $sql = "(SELECT `$customerKey` as mapping_key FROM customers WHERE `$customerKey` IS NOT NULL)
                    UNION
                    (SELECT mapping_key_value as mapping_key FROM customer_platform_responses WHERE mapping_key_value IS NOT NULL AND mapping_key_value != '')";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();
            $mappingKeys = $stmt->fetchAll(PDO::FETCH_COLUMN);

            $count = 0;
            foreach ($mappingKeys as $key) {
                $this->syncCustomerByMappingKey($key);
                $count++;
            }

            return $this->out([
                'status' => true,
                'message' => "Bulk materialization completed: $count entities processed",
                'processed' => $count
            ], 200);
        } catch (\Throwable $e) {
            return $this->out(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /* ==========================================
       BULK SYNC (CRON / QUEUE)
    ========================================== */

    protected function syncAllCustomers(int $limit = 500): void
    {
        $customers = $this->from('customers')
            ->select('id')
            ->limit($limit)
            ->fetchAll();

        foreach ($customers as $customer) {
            try {
                $this->syncCustomer((int) $customer['id']);
            } catch (\Throwable $e) {
                // Log error but continue with other customers
                error_log("Error syncing customer {$customer['id']}: " . $e->getMessage());
            }
        }
    }

    public function import_shopify_customers($args)
    {
        try {
            $interval = $args['interval_minutes'] ?? 60;
            $since = date('c', strtotime("-$interval minutes"));
            $platformId = $args['platform_id'] ?? null;

            // 1. Get platform sync key (customer_sync_key)
            if ($platformId) {
                $platform = $this->from('platforms')->where('id', $platformId)->fetch();
            } else {
                $platform = $this->from('platforms')->where('code', 'shopify')->fetch();
            }

            if (!$platform) {
                return ['status' => false, 'message' => 'Shopify platform not found'];
            }

            $platformId = $platform['id'];
            $mappingKey = $platform['customer_mapping_key']; // existing logic
            $syncKeyPath = $platform['customer_sync_key'];
            if (!$mappingKey) {
                die('no mapping key found');
            }
            if (!$syncKeyPath) {
                die('no sync Key found');
            }

            $shopify = new shopify($this->pdo);
            $count = 0;
            $nextPage = null;
            $items = [];

            do {
                //$params = ['limit' => 250, 'updated_at_min' => $since];
                $params = ['limit' => 250];
                if ($nextPage) {
                    $params = ['page_info' => $nextPage, 'limit' => 250];
                }

                $response = $shopify->get_customers($params);

                if (!$response || !isset($response['customers'])) {
                    break;
                }

                foreach ($response['customers'] as $customerData) {
                    $mappingKeyValue = $this->extractJsonValue($customerData, $mappingKey);
                    $externalId = $this->extractSyncKeyValue($customerData, $syncKeyPath);

                    if (!$externalId) {
                        $items[] = ['id' => $customerData['id'] ?? 'unknown', 'status' => 'failure', 'message' => 'Missing sync key'];
                        continue;
                    }

                    $existing = null;
                    if ($externalId) {
                        $existing = $this->from('customer_platform_responses')
                            ->where('platform_id', $platformId)
                            ->where('external_id', $externalId)
                            ->fetch();
                    }
                    $values = [
                        'platform_id' => $platformId,
                        'raw_response' => json_encode($customerData),
                        'synced_at' => date('Y-m-d H:i:s')
                    ];
                    if ($externalId)
                        $values['external_id'] = $externalId;
                    if ($mappingKeyValue)
                        $values['mapping_key_value'] = $mappingKeyValue;
                    else
                        $values['mapping_key_value'] = ''; // Fallback
                    try {
                        if ($existing) {
                            $this->update('customer_platform_responses')->set($values)->where('id', $existing['id'])->execute();
                        } else {
                            $values['created_at'] = date('Y-m-d H:i:s');
                            $this->insertInto('customer_platform_responses')->values($values)->execute();
                        }

                        $syncKey = $mappingKeyValue;
                        $items[] = ['id' => $syncKey, 'status' => 'success'];
                        $count++;

                        // Sync
                        if ($syncKey)
                            $this->syncCustomerByMappingKey($syncKey);

                    } catch (\Exception $e) {
                        $syncKey = $mappingKeyValue ?: $externalId;
                        $items[] = ['id' => $syncKey, 'status' => 'failure', 'message' => $e->getMessage()];
                    }
                }

                $link = $shopify->header['Link'] ?? $shopify->header['link'] ?? null;
                $nextPage = null;
                if ($link && preg_match('/<[^>]*page_info=([^>]+)>;\s*rel="next"/', $link, $matches)) {
                    $nextPage = $matches[1];
                }
            } while ($nextPage);

            return [
                'status' => true,
                'message' => "Imported $count customers",
                'processed' => $count,
                'items' => $items
            ];

        } catch (\Throwable $e) {
            return ['status' => false, 'message' => $e->getMessage()];
        }
    }

    public function import_am_customers($args)
    {
        try {
            $interval = $args['interval_minutes'] ?? 60;
            $since = date('Y-m-d H:i:s', strtotime("-$interval minutes"));
            $platformId = $args['platform_id'] ?? null;

            // 1. Get platform sync key (customer_sync_key)
            if ($platformId) {
                $platform = $this->from('platforms')->where('id', $platformId)->fetch();
            } else {
                $platform = $this->from('platforms')->where('code', 'apparelmagic')->fetch();
            }

            if (!$platform) {
                return [
                    'status' => false,
                    'message' => 'Apparel Magic platform not found in platforms table'
                ];
            }

            $platformId = $platform['id'];
            $mappingKey = $platform['customer_mapping_key'] ?? 'customer_id'; // default fallback logic
            $syncKeyPath = $platform['customer_sync_key'] ?? '';


            $am = new am($this->pdo);

            $count = 0;
            $items = [];
            $pagination = [
                'limit' => 100
            ];

            // Filter by last_modified_time
            $parameters = [
                // ['field' => 'last_modified_time', 'operator' => '>=', 'include_type' => 'AND', 'value' => $since]
            ];

            do {
                $response = $am->paginate('customers', $parameters, $pagination, 'import am customers');
                if (!$response || !is_array($response)) {
                    break;
                }
                foreach ($response as $customerData) {
                    $mappingKeyValue = $this->extractJsonValue($customerData, $mappingKey);
                    $externalId = $this->extractSyncKeyValue($customerData, $syncKeyPath);

                    if (!$mappingKeyValue && !$externalId) {
                        $items[] = ['id' => $customerData['customer_id'] ?? 'unknown', 'status' => 'failure', 'message' => 'Missing both mapping key and sync key'];
                        continue;
                    }

                    $existing = null;
                    if ($externalId) {
                        $existing = $this->from('customer_platform_responses')
                            ->where('platform_id', $platformId)
                            ->where('external_id', $externalId)
                            ->fetch();
                    }
                    if (!$existing && $mappingKeyValue) {
                        $existing = $this->from('customer_platform_responses')
                            ->where('platform_id', $platformId)
                            ->where('mapping_key_value', $mappingKeyValue)
                            ->fetch();
                    }

                    $values = [
                        'platform_id' => $platformId,
                        'raw_response' => json_encode($customerData),
                        'synced_at' => date('Y-m-d H:i:s')
                    ];
                    if ($externalId)
                        $values['external_id'] = $externalId;
                    if ($mappingKeyValue)
                        $values['mapping_key_value'] = $mappingKeyValue;
                    elseif ($externalId)
                        $values['mapping_key_value'] = '';
                    try {
                        if ($existing) {
                            $this->update('customer_platform_responses')->set($values)->where('id', $existing['id'])->execute();
                        } else {
                            $values['created_at'] = date('Y-m-d H:i:s');
                            $this->insertInto('customer_platform_responses')->values($values)->execute();
                        }

                        $syncKey = $mappingKeyValue;
                        $items[] = ['id' => $syncKey, 'status' => 'success'];
                        $count++;

                        // Sync
                        if ($syncKey)
                            $this->syncCustomerByMappingKey($syncKey);

                    } catch (\Exception $e) {
                        $syncKey = $mappingKeyValue;
                        $items[] = ['id' => $syncKey, 'status' => 'failure', 'message' => $e->getMessage()];
                    }
                }
                $pagination["last_id"] = $am->pagination['last_id'] ?? null;
            }
            while (!empty($pagination["last_id"]));

            return [
                'status' => true,
                'message' => "Successfully imported $count customers from Apparel Magic",
                'processed' => $count,
                'items' => $items
            ];

        } catch (\Throwable $e) {
            return [
                'status' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    public function sync_all_customers($args)
    {
        $limit = intval($this->req['limit'] ?? ($args['limit'] ?? 500));
        if ($limit <= 0) {
            $limit = 500;
        }
        try {
            $this->syncAllCustomers($limit);
            return $this->out(['status' => true, 'message' => 'Bulk sync completed', 'limit' => $limit], 200);
        } catch (\Throwable $e) {
            return $this->out(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Fix corrupted customer_id values in customer_display_fields
     * POST /customer/fix_corrupted_customer_ids
     */
    /*public function fix_corrupted_customer_ids($args)
    {
        try {
            $customerKey = $this->validateCustomerKey();

            // Get all display field records
            $displayFields = $this->from('customer_display_fields')
                ->select('id, unique_mapping_key, customer_id')
                ->fetchAll();

            $fixed = 0;
            $skipped = 0;

            foreach ($displayFields as $record) {
                // Find correct customer ID using mapping key
                $customer = $this->from('customers')
                    ->where($customerKey, $record['unique_mapping_key'])
                    ->fetch();

                if ($customer) {
                    $correctId = $customer['id'];
                    $currentId = $record['customer_id'];

                    // Only update if different
                    if ($currentId != $correctId) {
                        $this->update('customer_display_fields')
                            ->set(['customer_id' => $correctId])
                            ->where('id', $record['id'])
                            ->execute();
                        $fixed++;
                    } else {
                        $skipped++;
                    }
                } else {
                    // No matching customer - platform-only record, set to NULL
                    if ($record['customer_id'] !== null) {
                        $this->update('customer_display_fields')
                            ->set(['customer_id' => null])
                            ->where('id', $record['id'])
                            ->execute();
                        $fixed++;
                    } else {
                        $skipped++;
                    }
                }
            }

            return $this->out([
                'status' => true,
                'message' => "Migration completed",
                'fixed' => $fixed,
                'skipped' => $skipped,
                'total' => count($displayFields)
            ], 200);

        } catch (\Throwable $e) {
            return $this->out(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }/*

    /**
     * Get next available customer ID
     */
    public function get_next_customer_id($args)
    {
        try {
            $nextId = $this->getSettingValue('next_customer_id');
            if (!$nextId) {
                $nextId = 1001;
            }

            return $this->out([
                'status' => true,
                'next_id' => (int) $nextId
            ], 200);

        } catch (\Throwable $e) {
            return $this->out(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /* ==========================================
       CUSTOMER CRUD OPERATIONS
    ========================================== */

    /**
     * List customers with pagination and search
     */
    public function customer_list($args)
    {
        try {
            $limit = intval($this->req['limit'] ?? 100);
            $offset = intval($this->req['offset'] ?? 0);
            $search = $this->req['search'] ?? null;

            $query = $this->from('customer_display_fields');

            // 1. Dynamic search across all active fields
            if ($search) {
                $searchCond = [];
                $searchParams = [];

                // Default base columns
                $searchCols = [];

                // Get dynamic columns from definitions
                $fields = $this->getActiveFields();
                foreach ($fields as $field) {
                    $searchCols[] = $field['column_name'];
                }

                $searchCols = array_unique($searchCols);
                foreach ($searchCols as $col) {
                    $searchCond[] = "`$col` LIKE ?";
                    $searchParams[] = "%$search%";
                }
                // var_dump($searchCond);
                //var_dump($searchParams);
                if (!empty($searchCond)) {
                    $query->where('(' . implode(' OR ', $searchCond) . ')', $searchParams);
                }
            }

            // 2. Individual column filters
            $reservedParams = ['limit', 'offset', 'search', 'sort_by', 'sort_order'];
            foreach ($this->req as $key => $value) {
                if (!in_array($key, $reservedParams) && !empty($value)) {
                    // Simple hygiene: only allow filtering by keys that don't look like SQL commands
                    // and are potentially valid columns (approximate check)
                    if (preg_match('/^[a-zA-Z0-9_]+$/', $key)) {
                        $query->where("`$key`", $value);
                    }
                }
            }

            // 3. Dynamic sorting
            $sortBy = $this->req['sort_by'] ?? 'id';
            $sortOrder = strtoupper($this->req['sort_order'] ?? 'DESC');
            if (!in_array($sortOrder, ['ASC', 'DESC'])) {
                $sortOrder = 'DESC';
            }

            // Validate sortBy against available columns to prevent SQL injection
            $allowedSortCols = ['id', 'unique_mapping_key', 'created_at', 'updated_at', 'customer_id', 'sources', 'source_external_ids'];
            $fields = $this->getActiveFields();
            foreach ($fields as $field) {
                $allowedSortCols[] = $field['column_name'];
            }

            if (!in_array($sortBy, $allowedSortCols)) {
                $sortBy = 'id';
            }

            $query->orderBy("`$sortBy` $sortOrder");

            $total = $query->count();
            $items = $query->limit($limit)->offset($offset)->fetchAll();
            //echo $items = $query->limit($limit)->offset($offset);

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
     * Retrieve a single customer
     */
    public function customer_retrieve($args)
    {
        try {
            $id = intval($this->req['id'] ?? ($args['id'] ?? 0));
            if ($id <= 0) {
                return $this->out(['status' => false, 'message' => 'Valid id is required'], 422);
            }

            $customer = $this->from('customers')->where('id', $id)->fetch();

            if (!$customer) {
                return $this->out(['status' => false, 'message' => 'Customer not found'], 404);
            }

            // Also fetch display fields if they exist
            $displayFields = $this->from('customers')->where('id', $id)->fetch();
            return $this->out(['status' => true, 'item' => $customer], 200);
        } catch (\Throwable $e) {
            return $this->out(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Create or Update a customer
     */
    public function customer_save($args)
    {
        try {
            $id = intval($this->req['id'] ?? ($args['id'] ?? 0));

            // Check uniqueness based on customer_key setting
            $customerKey = $this->getSettingValue('customer_key');
            if ($customerKey && isset($this->req[$customerKey]) && !empty($this->req[$customerKey])) {
                $checkValue = $this->req[$customerKey];
                $query = $this->from('customers')->where($customerKey, $checkValue);
                if ($id > 0) {
                    $query->where('id != ?', $id);
                }
                $existing = $query->fetch();
                if ($existing) {
                    return $this->out(['status' => false, 'message' => "Customer already exists with this $customerKey: $checkValue"], 422);
                }
            }

            $values = [
                'name' => $this->req['name'] ?? null,
                'email' => $this->req['email'] ?? null,
                'phone' => $this->req['phone'] ?? null,
                'address1' => $this->req['address1'] ?? '',
                'address2' => $this->req['address2'] ?? '',
                'city' => $this->req['city'] ?? '',
                'state' => $this->req['state'] ?? '',
                'postal_code' => $this->req['postal_code'] ?? '',
                'country' => $this->req['country'] ?? '',
                'fax' => $this->req['fax'] ?? '',
                'updated_at' => date('Y-m-d H:i:s')
            ];

            // Validation (minimal)
            if (!$values['name'] && !$values['email']) {
                return $this->out(['status' => false, 'message' => 'Name or Email is required'], 422);
            }

            if ($id > 0) {
                // Update
                $this->update('customers')->set($values)->where('id', $id)->execute();
                $message = 'Customer updated';

                // Trigger sync (materialize display fields)
                $this->syncCustomer($id);
            } else {
                // Create
                $values['created_at'] = date('Y-m-d H:i:s');

                // Auto-increment customer_id if not provided
                if (empty($values['customer_id'])) {
                    $nextId = $this->getSettingValue('next_customer_id');
                    if (!$nextId) {
                        $nextId = 1001;
                    }
                    $values['customer_id'] = (int) $nextId;

                    // Increment setting
                    $this->pdo->exec("UPDATE settings SET _value = " . ($values['customer_id'] + 1) . " WHERE _key = 'next_customer_id'");
                }

                $id = $this->insertInto('customers')->values($values)->execute();
                $message = 'Customer created';

                // Initialize display fields row
                $customerKey = $this->validateCustomerKey();
                if (isset($values[$customerKey])) {
                    $this->ensureCustomerRow($values[$customerKey], $id);
                }

                // Trigger sync (materialize display fields)
                $this->syncCustomer($id);
            }

            $updated = $this->from('customers')->where('id', $id)->fetch();

            return $this->out([
                'status' => true,
                'message' => $message,
                'id' => $id,
                'item' => $updated
            ], 200);
        } catch (\Throwable $e) {
            return $this->out(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Delete customer(s)
     */
    public function customer_delete($args)
    {
        try {
            $ids = $this->req['ids'] ?? null;
            $id = $this->req['id'] ?? ($args['id'] ?? null);

            if (!$ids && $id) {
                $ids = [$id];
            }

            if (!is_array($ids) || empty($ids)) {
                return $this->out(['status' => false, 'message' => 'id or ids array is required'], 422);
            }

            // Get customer_key setting to find mapping keys
            $customerKey = $this->getSettingValue('customer_key') ?: 'email';

            // Get mapping key values for customers being deleted
            $customers = $this->from('customers')
                ->select("id, $customerKey as mapping_key")
                ->where('id', $ids)
                ->fetchAll();

            // Delete from customers table
            $this->deleteFrom('customers')->where('id', $ids)->execute();

            // Delete corresponding customer_display_fields records using unique_mapping_key
            foreach ($customers as $customer) {
                if (!empty($customer['mapping_key'])) {
                    $this->deleteFrom('customer_display_fields')
                        ->where('unique_mapping_key', $customer['mapping_key'])
                        ->execute();
                }
            }

            return $this->out(['status' => true, 'message' => count($ids) . ' Customer(s) deleted'], 200);
        } catch (\Throwable $e) {
            return $this->out(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }

}
