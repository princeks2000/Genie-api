<?php

class product extends model
{
    protected array $typeMap = [
        'string' => 'VARCHAR(1000)',
        'int' => 'INT',
        'bool' => 'TINYINT(1)',
        'date' => 'DATE',
        'float' => 'DECIMAL(10,2)',
    ];

    /* ==========================================
       UTILITIES
    ========================================== */

    protected function exploreJsonKeys(array $array, string $prefix = ''): array
    {
        $keys = [];
        foreach ($array as $key => $value) {
            $fullKey = $prefix === '' ? $key : "{$prefix}.{$key}";
            $keys[] = $fullKey;
            if (is_array($value) && !empty($value)) {
                // If it's a Sequential array, explore ONLY the first element
                if (array_keys($value) === range(0, count($value) - 1)) {
                    $item = $value[0];
                    if (is_array($item)) {
                        $keys = array_merge($keys, $this->exploreJsonKeys($item, $fullKey . '.0'));
                    } else {
                        $keys[] = $fullKey . '.0';
                    }
                } else {
                    $keys = array_merge($keys, $this->exploreJsonKeys($value, $fullKey));
                }
            }
        }
        return array_unique($keys);
    }

    protected function extractJsonValue(array $json, string $path): mixed
    {
        $keys = explode('.', $path);
        $current = $json;
        foreach ($keys as $key) {
            if (!is_array($current) || !isset($current[$key])) {
                return null;
            }
            $current = $current[$key];
        }
        return $current;
    }

    protected function extractSyncKeyValue(array $json, string $path): mixed
    {
        return $this->extractJsonValue($json, $path);
    }

    public function import_shopify_products($args)
    {
        try {
            $interval = $args['interval_minutes'] ?? 60;
            $platformId = $args['platform_id'] ?? null;

            if ($platformId) {
                $platform = $this->from('platforms')->where('id', $platformId)->fetch();
            } else {
                $platform = $this->from('platforms')->where('code', 'shopify')->fetch();
            }

            if (!$platform) {
                return ['status' => false, 'message' => 'Shopify platform not found'];
            }

            $platformId = $platform['id'];
            $mappingKey = $platform['product_mapping_key'];
            $syncKeyPath = $platform['product_sync_key'];

            $shopify = new shopify($this->pdo);
            $count = 0;
            $nextPage = null;
            $items = [];

            do {
                $params = ['limit' => 250];
                if ($nextPage) {
                    $params = ['page_info' => $nextPage, 'limit' => 250];
                }

                $response = $shopify->get_products($params);

                if (!$response || !isset($response['products'])) {
                    break;
                }

                foreach ($response['products'] as $productData) {
                    $mappingKeyValue = $this->extractJsonValue($productData, $mappingKey);
                    $externalId = $this->extractSyncKeyValue($productData, $syncKeyPath);

                    if (!$externalId) {
                        $items[] = ['id' => $productData['id'] ?? 'unknown', 'status' => 'failure', 'message' => 'Missing sync key'];
                        continue;
                    }

                    $existing = $this->from('products_platform_responses')
                        ->where('platform_id', $platformId)
                        ->where('external_id', $externalId)
                        ->fetch();

                    $values = [
                        'platform_id' => $platformId,
                        'raw_response' => json_encode($productData),
                        'synced_at' => date('Y-m-d H:i:s')
                    ];
                    $values['external_id'] = (string) $externalId;
                    $values['mapping_key_value'] = (string) ($mappingKeyValue ?: '');

                    try {
                        if ($existing) {
                            $this->update('products_platform_responses')->set($values)->where('id', $existing['id'])->execute();
                        } else {
                            $values['created_at'] = date('Y-m-d H:i:s');
                            $this->insertInto('products_platform_responses')->values($values)->execute();
                        }

                        $syncKey = $mappingKeyValue ?: $externalId;
                        $items[] = ['id' => $syncKey, 'status' => 'success'];
                        $count++;

                        if ($syncKey) {
                            $this->syncProductByMappingKey($syncKey);
                        }

                    } catch (\Exception $e) {
                        $items[] = ['id' => $mappingKeyValue ?: $externalId, 'status' => 'failure', 'message' => $e->getMessage()];
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
                'message' => "Imported $count products",
                'processed' => $count,
                'items' => $items
            ];

        } catch (\Throwable $e) {
            return ['status' => false, 'message' => $e->getMessage()];
        }
    }

    public function import_am_products($args)
    {
        try {
            $interval = $args['interval_minutes'] ?? 60;
            $platformId = $args['platform_id'] ?? null;

            if ($platformId) {
                $platform = $this->from('platforms')->where('id', $platformId)->fetch();
            } else {
                $platform = $this->from('platforms')->where('code', 'apparelmagic')->fetch();
            }

            if (!$platform) {
                return ['status' => false, 'message' => 'Apparel Magic platform not found'];
            }

            $platformId = $platform['id'];
            $mappingKey = $platform['product_mapping_key'] ?? 'style_number';
            $syncKeyPath = $platform['product_sync_key'] ?? 'id';

            $am = new am($this->pdo);
            $count = 0;
            $items = [];
            $pagination = ['limit' => 100];

            do {
                $response = $am->paginate('products', [], $pagination, 'import am products');
                if (!$response || !is_array($response)) {
                    break;
                }
                foreach ($response as $productData) {
                    $mappingKeyValue = $this->extractJsonValue($productData, $mappingKey);
                    $externalId = $this->extractSyncKeyValue($productData, $syncKeyPath);

                    if (!$mappingKeyValue && !$externalId) {
                        $items[] = ['id' => $productData['id'] ?? 'unknown', 'status' => 'failure', 'message' => 'Missing keys'];
                        continue;
                    }

                    $existing = null;
                    if ($externalId) {
                        $existing = $this->from('products_platform_responses')
                            ->where('platform_id', $platformId)
                            ->where('external_id', $externalId)
                            ->fetch();
                    }
                    if (!$existing && $mappingKeyValue) {
                        $existing = $this->from('products_platform_responses')
                            ->where('platform_id', $platformId)
                            ->where('mapping_key_value', $mappingKeyValue)
                            ->fetch();
                    }

                    $values = [
                        'platform_id' => $platformId,
                        'raw_response' => json_encode($productData),
                        'synced_at' => date('Y-m-d H:i:s')
                    ];
                    if ($externalId)
                        $values['external_id'] = (string) $externalId;
                    if ($mappingKeyValue)
                        $values['mapping_key_value'] = (string) $mappingKeyValue;

                    try {
                        if ($existing) {
                            $this->update('products_platform_responses')->set($values)->where('id', $existing['id'])->execute();
                        } else {
                            $values['created_at'] = date('Y-m-d H:i:s');
                            $this->insertInto('products_platform_responses')->values($values)->execute();
                        }

                        $syncKey = $mappingKeyValue ?: $externalId;
                        $items[] = ['id' => $syncKey, 'status' => 'success'];
                        $count++;

                        if ($syncKey) {
                            $this->syncProductByMappingKey($syncKey);
                        }

                    } catch (\Exception $e) {
                        $items[] = ['id' => $mappingKeyValue ?: $externalId, 'status' => 'failure', 'message' => $e->getMessage()];
                    }
                }
                $pagination["last_id"] = $am->pagination['last_id'] ?? null;
            } while (!empty($pagination["last_id"]));

            return [
                'status' => true,
                'message' => "Imported $count products from Apparel Magic",
                'processed' => $count,
                'items' => $items
            ];

        } catch (\Throwable $e) {
            return ['status' => false, 'message' => $e->getMessage()];
        }
    }

    protected function castValue(mixed $value, string $dataType): mixed
    {
        if ($value === null) {
            return null;
        }

        switch ($dataType) {
            case 'int':
                return (int) $value;
            case 'bool':
                return $value ? 1 : 0;
            case 'float':
                return (float) $value;
            case 'date':
                if (is_string($value)) {
                    $timestamp = strtotime($value);
                    return $timestamp ? date('Y-m-d', $timestamp) : null;
                }
                return null;
            case 'string':
            default:
                return (string) $value;
        }
    }

    /* ==========================================
       COLUMN MANAGEMENT
    ========================================== */

    protected function columnExists(string $table, string $column): bool
    {
        $sql = "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$table, $column]);
        $count = $stmt->fetchColumn();
        return (bool) $count;
    }

    protected function ensureMappingKeyColumn(): void
    {
        if (!$this->columnExists('products_display_fields', 'id')) {
            $this->pdo->exec("ALTER TABLE products_display_fields ADD COLUMN id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST");
        }

        if (!$this->columnExists('products_display_fields', 'unique_mapping_key')) {
            $this->pdo->exec("ALTER TABLE products_display_fields ADD COLUMN unique_mapping_key VARCHAR(150) NOT NULL");
            $this->pdo->exec("CREATE UNIQUE INDEX uk_mapping_key ON products_display_fields (unique_mapping_key)");
        }

        if (!$this->columnExists('products_display_fields', 'sources')) {
            $this->pdo->exec("ALTER TABLE products_display_fields ADD COLUMN sources TEXT NULL");
        }

        if (!$this->columnExists('products_display_fields', 'source_external_ids')) {
            $this->pdo->exec("ALTER TABLE products_display_fields ADD COLUMN source_external_ids TEXT NULL");
        }
    }

    protected function validateProductKey(): string
    {
        $productKey = $this->getSettingValue('product_key') ?: 'style_number';

        if (!$this->columnExists('products', $productKey)) {
            // If it's the default and doesn't exist, we might need to be careful, but products table should have it.
            // For now, assume products table is set up with style_number.
        }

        return $productKey;
    }

    /* ==========================================
       PLATFORM RESPONSE STORAGE
    ========================================== */

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

            if (is_string($rawResponse)) {
                $rawResponse = json_decode($rawResponse, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    return $this->out([
                        'status' => false,
                        'message' => 'Invalid JSON in raw_response'
                    ], 422);
                }
            }

            $platform = $this->from('platforms')->where('code', $platformCode)->fetch();
            if (!$platform) {
                return $this->out(['status' => false, 'message' => "Platform not found: {$platformCode}"], 404);
            }

            $syncKeyPath = $platform['product_sync_key'] ?? '';
            $externalId = $syncKeyPath ? (string) $this->extractJsonValue($rawResponse, $syncKeyPath) : null;

            $mappingPath = $platform['product_mapping_key'] ?? '';
            $mappingKeyValue = $mappingPath ? (string) $this->extractJsonValue($rawResponse, $mappingPath) : null;

            if (!$externalId && !$mappingKeyValue) {
                return $this->out(['status' => false, 'message' => "Could not extract external_id or mapping_key"], 422);
            }

            $existing = null;
            if ($externalId) {
                $existing = $this->from('products_platform_responses')
                    ->where('platform_id', $platform['id'])
                    ->where('external_id', $externalId)
                    ->fetch();
            }

            if (!$existing && $mappingKeyValue) {
                $existing = $this->from('products_platform_responses')
                    ->where('platform_id', $platform['id'])
                    ->where('mapping_key_value', $mappingKeyValue)
                    ->fetch();
            }

            $values = [
                'platform_id' => $platform['id'],
                'external_id' => $externalId ?: '',
                'mapping_key_value' => $mappingKeyValue ?: '',
                'raw_response' => json_encode($rawResponse),
                'synced_at' => date('Y-m-d H:i:s')
            ];

            if ($existing) {
                $this->update('products_platform_responses')->set($values)->where('id', $existing['id'])->execute();
                $responseId = $existing['id'];
            } else {
                $values['created_at'] = date('Y-m-d H:i:s');
                $responseId = $this->insertInto('products_platform_responses')->values($values)->execute();
            }

            // Sync
            $syncKey = $mappingKeyValue ?: $externalId;
            if ($syncKey) {
                $this->syncProductByMappingKey($syncKey);
            }

            return $this->out([
                'status' => true,
                'message' => 'Platform response saved and synced',
                'response_id' => $responseId
            ], 200);

        } catch (\Throwable $e) {
            return $this->out(['status' => false, 'message' => $e->getMessage()], 500);
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
            ->where('dfd.table_type', 'products')
            ->orderBy('dfd.sort_order ASC')
            ->fetchAll();
    }

    /* ==========================================
       VALUE MATERIALIZATION
    ========================================== */

    protected function ensureProductRow(string $mappingKey, ?int $productId = null): void
    {
        $this->ensureMappingKeyColumn();
        $sql = "INSERT INTO products_display_fields (unique_mapping_key, product_id) VALUES (?, ?) 
                ON DUPLICATE KEY UPDATE product_id = IFNULL(product_id, VALUES(product_id))";
        $this->pdo->prepare($sql)->execute([$mappingKey, $productId]);
    }

    public function syncProductByMappingKey(string $mappingValue): void
    {
        $fields = $this->getActiveFields();
        if (empty($fields)) {
            // Even if no fields, we should ensure the row exists if there's a response
            $productKey = $this->validateProductKey();
            $product = $this->from('products')->where($productKey, $mappingValue)->fetch();
            $productId = $product ? (int) $product['id'] : null;
            $this->ensureProductRow($mappingValue, $productId);
            return;
        }

        $productKey = $this->validateProductKey();
        $product = $this->from('products')->where($productKey, $mappingValue)->fetch();
        $productId = $product ? (int) $product['id'] : null;

        $this->ensureProductRow($mappingValue, $productId);

        $sourceMap = [];
        if ($product) {
            $sourceMap['portal'] = (string) $product['id'];
        }

        $responses = $this->from('products_platform_responses ppr')
            ->leftJoin('platforms p ON p.id = ppr.platform_id')
            ->select('ppr.raw_response, p.code as platform_code, ppr.platform_id, ppr.external_id')
            ->where('ppr.mapping_key_value', $mappingValue)
            ->fetchAll();

        $platformResponses = [];
        foreach ($responses as $resp) {
            if ($resp['platform_code']) {
                $platformResponses[$resp['platform_code']] = json_decode($resp['raw_response'], true);
                $sourceMap[$resp['platform_id']] = $resp['external_id'];
            }
        }

        $updateData = [];
        foreach ($fields as $field) {
            $value = null;
            $platformCode = $field['platform_code'];
            $jsonPath = $field['json_path'];

            if (!$platformCode || $platformCode === 'portal') {
                $value = $product ? ($product[$jsonPath] ?? null) : null;
            } else if (isset($platformResponses[$platformCode])) {
                $value = $this->extractJsonValue($platformResponses[$platformCode], $jsonPath);
            }

            $updateData[$field['column_name']] = $this->castValue($value, $field['data_type']);
        }

        if (!empty($updateData)) {
            $updateData['sources'] = implode(',', array_keys($sourceMap));
            $updateData['source_external_ids'] = implode(',', array_values($sourceMap));
            $updateData['product_id'] = $productId;
            $this->update('products_display_fields')
                ->set($updateData)
                ->where('unique_mapping_key', $mappingValue)
                ->execute();
        }
    }

    public function materialize_all_display_fields($args)
    {
        try {
            $productKey = $this->validateProductKey();
            $sql = "(SELECT `$productKey` as mapping_key FROM products WHERE `$productKey` IS NOT NULL)
                    UNION
                    (SELECT mapping_key_value as mapping_key FROM products_platform_responses WHERE mapping_key_value IS NOT NULL AND mapping_key_value != '')";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();
            $mappingKeys = $stmt->fetchAll(PDO::FETCH_COLUMN);

            $count = 0;
            foreach ($mappingKeys as $key) {
                $this->syncProductByMappingKey($key);
                $count++;
            }

            return $this->out(['status' => true, 'message' => "Bulk materialization completed: $count processed"], 200);
        } catch (\Throwable $e) {
            return $this->out(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /* ==========================================
       CRUD
    ========================================== */

    public function product_save($args)
    {
        try {
            $id = intval($this->req['id'] ?? 0);
            $values = $this->req;
            unset($values['id']);

            if ($id > 0) {
                $this->update('products')->set($values)->where('id', $id)->execute();
            } else {
                $id = $this->insertInto('products')->values($values)->execute();
            }

            // Sync after save
            $product = $this->from('products')->where('id', $id)->fetch();
            $productKey = $this->validateProductKey();
            if ($product && isset($product[$productKey])) {
                $this->syncProductByMappingKey($product[$productKey]);
            }

            return $this->out(['status' => true, 'message' => 'Product saved', 'id' => $id], 200);
        } catch (\Throwable $e) {
            return $this->out(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function product_retrieve($args)
    {
        $id = intval($this->req['id'] ?? ($args['id'] ?? 0));
        if ($id <= 0)
            return $this->out(['status' => false, 'message' => 'id required'], 422);

        $product = $this->from('products')->where('id', $id)->fetch();
        if (!$product)
            return $this->out(['status' => false, 'message' => 'Product not found'], 404);

        $displayFields = $this->from('products_display_fields')->where('product_id', $id)->fetch();
        $product['display_fields'] = $displayFields;

        return $this->out(['status' => true, 'item' => $product], 200);
    }

    public function product_list($args)
    {
        $limit = intval($this->req['limit'] ?? 50);
        $offset = intval($this->req['offset'] ?? 0);

        $items = $this->from('products')
            ->limit($limit)
            ->offset($offset)
            ->fetchAll();

        return $this->out(['status' => true, 'items' => $items], 200);
    }

    public function product_delete($args)
    {
        $id = intval($this->req['id'] ?? ($args['id'] ?? 0));
        if ($id <= 0)
            return $this->out(['status' => false, 'message' => 'id required'], 422);

        // Delete from display fields first
        $this->deleteFrom('products_display_fields')->where('product_id', $id)->execute();
        $this->deleteFrom('products')->where('id', $id)->execute();

        return $this->out(['status' => true, 'message' => 'Product deleted'], 200);
    }
}
