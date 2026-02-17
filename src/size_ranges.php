<?php
class size_ranges extends model
{
    public function list_size_ranges($args)
    {
        try {
            $data = $this->from('size_ranges')->orderBy('id ASC')->fetchAll();
            return $this->out(['status' => true, 'items' => $data], 200);
        } catch (\Throwable $e) {
            return $this->out(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function get_size_range($args)
    {
        $id = $this->req['id'] ?? ($_GET['id'] ?? null);
        if (!$id) {
            return $this->out(['status' => false, 'message' => 'ID is required'], 422);
        }

        try {
            $data = $this->from('size_ranges')->where('id', $id)->fetch();
            if (!$data) {
                return $this->out(['status' => false, 'message' => 'Size range not found'], 404);
            }
            return $this->out(['status' => true, 'item' => $data], 200);
        } catch (\Throwable $e) {
            return $this->out(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function create_size_range($args)
    {
        $data = $this->req;
        
        // Basic validation
        if (!isset($data['name'])) {
            return $this->out(['status' => false, 'message' => 'Name is required'], 422);
        }

        // prepare data for insertion
        $insertData = [
            'name' => $data['name']
        ];

        // Handle size columns (01 to 16)
        for ($i = 1; $i <= 16; $i++) {
            $num = sprintf("%02d", $i);
            $sizeKey = "size_{$num}";
            $nameKey = "size_{$num}_name";
            
            $insertData[$sizeKey] = $data[$sizeKey] ?? '';
            $insertData[$nameKey] = $data[$nameKey] ?? '';
        }

        try {
            $id = $this->insertInto('size_ranges')->values($insertData)->execute();
            return $this->out(['status' => true, 'message' => 'Size range created', 'id' => $id], 201);
        } catch (\Throwable $e) {
            return $this->out(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function update_size_range($args)
    {
        $data = $this->req;
        $id = $data['id'] ?? null;

        if (!$id) {
            return $this->out(['status' => false, 'message' => 'ID is required'], 422);
        }

        // prepare data for update
        $updateData = [];
        if (isset($data['name'])) {
            $updateData['name'] = $data['name'];
        }

        // Handle size columns (01 to 16)
        for ($i = 1; $i <= 16; $i++) {
            $num = sprintf("%02d", $i);
            $sizeKey = "size_{$num}";
            $nameKey = "size_{$num}_name";
            
            if (isset($data[$sizeKey])) {
                $updateData[$sizeKey] = $data[$sizeKey];
            }
            if (isset($data[$nameKey])) {
                $updateData[$nameKey] = $data[$nameKey];
            }
        }

        if (empty($updateData)) {
            return $this->out(['status' => false, 'message' => 'No data to update'], 422);
        }

        try {
            $this->update('size_ranges')->set($updateData)->where('id', $id)->execute();
            return $this->out(['status' => true, 'message' => 'Size range updated'], 200);
        } catch (\Throwable $e) {
            return $this->out(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function delete_size_range($args)
    {
        $id = $this->req['id'] ?? ($args['id'] ?? null);

        if (!$id) {
            return $this->out(['status' => false, 'message' => 'ID is required'], 422);
        }

        try {
            $this->deleteFrom('size_ranges')->where('id', $id)->execute();
            return $this->out(['status' => true, 'message' => 'Size range deleted'], 200);
        } catch (\Throwable $e) {
            return $this->out(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
