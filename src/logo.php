<?php
class logo extends model
{
    /* ==========================================
       LOGO ID GENERATION HELPERS
    ========================================== */

    /**
     * Convert number to alphabetic sequence (0=A, 1=B, ..., 25=Z, 26=AA, 27=AB, ...)
     */
    protected function numberToAlphabetic(int $num): string
    {
        $num = abs($num) + 1; // 0-based to 1-based for bijective numeration
        $result = '';
        while ($num > 0) {
            $num--;
            $result = chr(65 + ($num % 26)) . $result;
            $num = intdiv($num, 26);
        }
        return $result;
    }

    /**
     * Convert alphabetic string back to number (A=0, B=1, ..., Z=25, AA=26, AB=27, ...)
     */
    protected function alphabeticToNumber(string $str): int
    {
        $result = 0;
        for ($i = 0; $i < strlen($str); $i++) {
            $result = $result * 26 + (ord($str[$i]) - 64);
        }
        return $result - 1; // Adjust to 0-based
    }

    /**
     * Ensure logo_id_counters table exists for tracking suffixes per customer+logotype
     */


    /**
     * Get or create logo_id counter for a customer+logotype combination
     */
    protected function getLogoIdCounter(int $customerId, int $logotypeId): int
    {


        $row = $this->from('logo_id_counters')
            ->where('customer_id', $customerId)
            ->where('logotype_id', $logotypeId)
            ->fetch();

        if ($row) {
            return (int) $row['current_suffix_num'];
        }

        // Create new counter entry
        $this->insertInto('logo_id_counters')->values([
            'customer_id' => $customerId,
            'logotype_id' => $logotypeId,
            'current_suffix_num' => -1
        ])->execute();

        return -1;
    }

    /**
     * Increment and save logo_id counter for a customer+logotype combination
     */
    protected function incrementLogoIdCounter(int $customerId, int $logotypeId): int
    {


        $currentNum = $this->getLogoIdCounter($customerId, $logotypeId);
        $newNum = $currentNum + 1;

        $this->update('logo_id_counters')
            ->set(['current_suffix_num' => $newNum])
            ->where('customer_id', $customerId)
            ->where('logotype_id', $logotypeId)
            ->execute();

        return $newNum;
    }

    /**
     * Generate logo_id based on configured method
     * Method 1: Auto Increment - prefix + logotype_letter + global_counter
     * Method 2: Customer ID Increment - prefix + customer_field_value + alphabetic_suffix (per logotype)
     * 
     * Checks availability in logos table and increments until a unique ID is found.
     */
    protected function generateLogoId($customerId, int $logotypeId): string
    {
        $method = $this->getSettingValue('logo_id_method') ?? 'auto_increment';
        $prefix = $this->getSettingValue('logo_id_prefix') ?? 'CUST';

        // Fetch logotype reference letter
        $logotype = $this->from('logo_types')->where('id', $logotypeId)->fetch();
        if (!$logotype) {
            throw new \Exception("Logo type with id $logotypeId not found");
        }
        $logoTypeLetter = $logotype['reference'] ?? '';

        if ($method === 'customer_id_increment' || $method === 'customer_id_increment_prefix') {
            // Method 2/3: prefix + customer_field_value + alphabetic_suffix OR prefix + alphabetic_prefix + customer_field_value
            $fieldKey = $this->getSettingValue('logo_customer_key');
            if (!$fieldKey) {
                throw new \Exception("Logo customer key not set");
            }

            // Get customer's display field row
            $displayField = $this->from('customer_display_fields')
                ->where('id', $customerId)
                ->fetch();

            if (!$displayField) {
                throw new \Exception("No display field entry found for customer_id $customerId");
            }

            if (!isset($displayField[$fieldKey])) {
                throw new \Exception("Field '$fieldKey' not found in customer_display_fields for customer_id $customerId");
            }

            $customerFieldValue = $displayField[$fieldKey];
            if (empty($customerFieldValue)) {
                throw new \Exception("Field '$fieldKey' is empty for customer_id $customerId");
            }

            // Find next available suffix/prefix (increment until logo_id is unique)
            while (true) {
                $newNum = $this->incrementLogoIdCounter($customerId, $logotypeId);

                if ($method === 'customer_id_increment_prefix') {
                    // Method 3: prefix + alphabetic_prefix (starting at AA) + customerFieldValue
                    // AA is the 26th index (0=A, 25=Z, 26=AA)
                    $alphaPart = $this->numberToAlphabetic($newNum + 26);
                    $candidateLogoId = $prefix . $alphaPart . $customerFieldValue;
                } else {
                    // Method 2: prefix + customerFieldValue + alphabetic_suffix
                    $newSuffix = $this->numberToAlphabetic($newNum);
                    $candidateLogoId = $prefix . $customerFieldValue . $newSuffix;
                }

                // Check if this logo_id already exists
                $existing = $this->from('logos')->where('logo_id', $candidateLogoId)->fetch();
                if (!$existing) {
                    // Found a unique logo_id
                    return $candidateLogoId;
                }
                // Logo ID exists, loop will increment counter and try next
            }
        } else {
            // Method 1: Auto Increment - prefix + logotype_letter + global_counter
            $counterKey = 'logo_id_auto_increment_current';
            $currentCounter = $this->getSettingValue($counterKey);
            $counterVal = $currentCounter ? intval($currentCounter) : (intval($this->getSettingValue('logo_id_auto_increment_start') ?? 1));

            // Find next available counter (increment until logo_id is unique)
            while (true) {
                $newCounter = $counterVal + 1;
                $formattedCounter = str_pad($counterVal, 3, '0', STR_PAD_LEFT);
                $candidateLogoId = $prefix . $logoTypeLetter . $formattedCounter;

                // Check if this logo_id already exists
                $existing = $this->from('logos')->where('logo_id', $candidateLogoId)->fetch();
                if (!$existing) {
                    // Found a unique logo_id, save counter and return
                    $settingRow = $this->from('settings')->where('_key', $counterKey)->fetch();
                    if ($settingRow) {
                        $this->update('settings')->set(['_value' => (string) $newCounter])->where('_key', $counterKey)->execute();
                    } else {
                        $this->insertInto('settings')->values(['_key' => $counterKey, '_value' => (string) $newCounter, 'description' => 'Current auto-increment counter for logo IDs'])->execute();
                    }
                    return $candidateLogoId;
                }
                // Logo ID exists, try next counter
                $counterVal = $newCounter;
            }
        }
    }

    /**
     * Convert DST to PNG using PulseID API
     */
    protected function convertDstToPng_PulseID(string $logoid, string $uploadDir): bool
    {
        $dstFilePath = $uploadDir . '/' . $logoid . '.DST';
        $pngFilePath = $uploadDir . '/' . $logoid . '_DST.PNG';

        if (!file_exists($dstFilePath)) {
            throw new \Exception("DST file not found: {$dstFilePath}");
        }

        // Delegate to shared PulseID recolor helper using default threads
        try {
            return (bool) $this->pulseidRecolor($logoid, $uploadDir, null);
        } catch (\Throwable $e) {
            error_log("PulseID DST conversion failed for {$logoid}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Convert DST to PNG using Ambassador (Pulse Micro) API
     */
    protected function convertDstToPng_Ambassador(string $logoid, string $uploadDir): bool
    {
        $dstFilePath = $uploadDir . '/' . $logoid . '.DST';
        $pngFilePath = $uploadDir . '/' . $logoid . '_DST.PNG';

        if (!file_exists($dstFilePath)) {
            throw new \Exception("DST file not found: {$dstFilePath}");
        }

        // Delegate to shared Ambassador recolor helper using default threads
        try {
            return (bool) $this->ambassadorRecolor($logoid, $uploadDir, null);
        } catch (\Throwable $e) {
            error_log("Ambassador DST conversion failed for {$logoid}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Convert DST to PNG using Wilcom API
     */
    protected function convertDstToPng_Wilcom(string $logoid, string $uploadDir): bool
    {
        $dstFilePath = $uploadDir . '/' . $logoid . '.DST';
        $pngFilePath = $uploadDir . '/' . $logoid . '_DST.PNG';

        if (!file_exists($dstFilePath)) {
            throw new \Exception("DST file not found: {$dstFilePath}");
        }

        // Delegate to shared Wilcom recolor helper using default threads
        try {
            return (bool) $this->wilcomRecolor($logoid, $uploadDir, null);
        } catch (\Throwable $e) {
            error_log("Wilcom DST conversion failed for {$logoid}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Convert DST to PNG using Emboryx API
     */
    protected function convertDstToPng_Emboryx(string $logoid, string $uploadDir): bool
    {
        $dstFilePath = $uploadDir . '/' . $logoid . '.DST';
        $pngFilePath = $uploadDir . '/' . $logoid . '_DST.PNG';

        if (!file_exists($dstFilePath)) {
            throw new \Exception("DST file not found: {$dstFilePath}");
        }

        try {
            return (bool) $this->emboryxRecolor($logoid, $uploadDir, null);
        } catch (\Throwable $e) {
            error_log("Emboryx DST conversion failed for {$logoid}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Convert AI to PNG using Inkscape
     */
    protected function convertAiToPng_Inkscape(string $logoid, string $uploadDir): bool
    {
        $aiFilePath = $uploadDir . '/' . $logoid . '.AI';
        $svgFilePath = $uploadDir . '/' . $logoid . '.SVG';
        $pngFilePath = $uploadDir . '/' . $logoid . '_AI.PNG';

        if (!file_exists($aiFilePath)) {
            throw new \Exception("AI file not found: {$aiFilePath}");
        }

        try {
            // Step 1: Convert AI to SVG
            $cmd = sprintf(
                'inkscape --export-type=svg --export-plain-svg --export-area-drawing %%s --export-filename=%%s 2>&1',
                escapeshellarg($aiFilePath),
                escapeshellarg($svgFilePath)
            );

            exec($cmd, $output, $returnCode);
            if ($returnCode !== 0 || !file_exists($svgFilePath)) {
                throw new \Exception("Inkscape AI to SVG conversion failed. Output: " . implode("\n", $output));
            }

            // Rename to uppercase .SVG
            $svgFilePathUpper = str_replace('.SVG', '', $svgFilePath) . '.SVG';
            if ($svgFilePath !== $svgFilePathUpper) {
                rename($svgFilePath, $svgFilePathUpper);
                $svgFilePath = $svgFilePathUpper;
            }

            $cmd = sprintf(
                'INKSCAPE_HEADLESS=1 inkscape %%s --export-type=png --export-background-opacity=0 --export-filename=%%s 2>&1',
                escapeshellarg($svgFilePath),
                escapeshellarg($pngFilePath)
            );

            exec($cmd, $output, $returnCode);
            if ($returnCode !== 0 || !file_exists($pngFilePath)) {
                throw new \Exception("Inkscape SVG to PNG conversion failed. Output: " . implode("\n", $output));
            }

            // Rename PNG to uppercase
            $pngFilePathUpper = str_replace('.PNG', '', $pngFilePath) . '.PNG';
            if ($pngFilePath !== $pngFilePathUpper) {
                rename($pngFilePath, $pngFilePathUpper);
            }

            return true;
        } catch (\Throwable $e) {
            error_log("Inkscape AI conversion failed for {$logoid}: " . $e->getMessage());
            return false;
        }
    }


    /**
     * Save thread data from PulseID API
     */
    protected function saveLogoThreadData_PulseID(string $logoid, array $designInfo): void
    {
        try {
            // PulseID often wraps core design data inside an 'Info' key
            $info = $designInfo['Info'] ?? $designInfo;

            $threadData = array(
                'logo_id' => $logoid,
                'density' => $info['MasterDensity'] ?? null,
                'designWidth' => $info['Width'] ?? null,
                'designHeight' => $info['Height'] ?? null,
                'machineFormat' => $info['MachineFormat'] ?? null,
                'stitches' => $info['NumStitches'] ?? null,
                'numTrims' => $info['NumTrims'] ?? null,
                'colourChanges' => isset($designInfo['Stops']) ? (count($designInfo['Stops']) - 1) : (isset($info['Stops']) ? (count($info['Stops']) - 1) : 0)
            );

            // Extract thread information
            $palette = $designInfo['Palette'] ?? ($info['Palette'] ?? null);
            $stops = $designInfo['Stops'] ?? ($info['Stops'] ?? null);

            if ($palette && $stops) {
                // Calculate max thread count based on API response (colour changes from Stops)
                $colourChanges = count($stops) - 1;
                $maxThreadCount = $colourChanges + 1;

                $codelist = array_column($palette, 'Code', 'Name');
                $threadCount = 1;
                foreach ($stops as $color) {
                    if ($threadCount <= $maxThreadCount) {
                        $colorCode = $codelist[$color['ThreadName']] ?? '';
                        if ($colorCode) {
                            // Fetch m_code (manufacturer code) from color_list
                            $colorRow = $this->from('color_list')->where('code', $colorCode)->fetch();
                            $mCode = $colorRow ? ($colorRow['m_code'] ?? 'M') : 'M';
                            $threadData['thread_' . $threadCount] = $mCode . '-' . $colorCode;
                        }
                    } else {
                        $threadData['thread_' . $threadCount] = null;
                    }
                    $threadCount++;
                }
            }
            // Store in existing `logos` table (update only)
            $existing = $this->from('logos')->where('logo_id', $logoid)->fetch();
            if ($existing) {
                $this->update('logos')->set($threadData)->where('logo_id', $logoid)->execute();
            } else {
                error_log("No logo record found to save PulseID thread data for {$logoid}");
            }
        } catch (\Throwable $e) {
            error_log("Error saving PulseID thread data for {$logoid}: " . $e->getMessage());
        }
    }

    /**
     * Save thread data from Ambassador API
     */
    protected function saveLogoThreadData_Ambassador(string $logoid, array $designInfo): void
    {
        try {
            $threadData = array(
                'logo_id' => $logoid,
                'density' => $designInfo['Density'] ?? null,
                'designWidth' => $designInfo['Width'] ?? null,
                'designHeight' => $designInfo['Height'] ?? null,
                'machineFormat' => $designInfo['MachineFormat'] ?? null,
                'designNotes' => $designInfo['DesignNotes'] ?? null,
                'companyName' => $designInfo['CompanyName'] ?? null,
                'customerName' => $designInfo['CustomerName'] ?? null,
                'fabricThickness' => $designInfo['FabricThickness'] ?? null,
                'stitches' => $designInfo['Stitches'] ?? null,
                'filename' => $designInfo['Filename'] ?? null,
                'numTrims' => $designInfo['Trims'] ?? null,
                'originalFilename' => $designInfo['OriginalFilename'] ?? null,
                'costPer1000Stitches' => $designInfo['CostPer1000Stitches'] ?? null,
                'colourChanges' => $designInfo['ColourChanges'] ?? 0
            );

            // Extract thread colors if available in response
            if (isset($designInfo['Colors']) && is_array($designInfo['Colors'])) {
                $threadCount = 1;
                $colourChanges = intval($designInfo['ColourChanges'] ?? 0);
                $maxThreadCount = $colourChanges + 1;

                foreach ($designInfo['Colors'] as $color) {
                    if ($threadCount <= $maxThreadCount) {
                        // Check if color has IsUsed flag or if it should be included
                        if (isset($color['IsUsed']) && !$color['IsUsed']) {
                            continue; // Skip unused colors
                        }

                        $colorCode = $color['Code'] ?? null;
                        if ($colorCode) {
                            // Fetch m_code (manufacturer code) from color_list
                            $colorRow = $this->from('color_list')->where('code', $colorCode)->fetch();
                            $mCode = $colorRow ? ($colorRow['m_code'] ?? 'M') : 'M';
                            $threadData['thread_' . $threadCount] = $mCode . '-' . $colorCode;

                            // Store thread description if available
                            if (isset($color['Description'])) {
                                $threadData['thread_' . $threadCount . '_desc'] = $color['Description'];
                            }
                        }
                        $threadCount++;
                    }
                }
            }

            // Filter out null values
            $threadData = array_filter($threadData, function ($v) {
                return !is_null($v);
            });

            // Store in existing `logos` table (update only)
            $existing = $this->from('logos')->where('logo_id', $logoid)->fetch();
            if ($existing) {
                $threadData['last_update'] = date('Y-m-d H:i:s');
                $this->update('logos')->set($threadData)->where('logo_id', $logoid)->execute();
                // Update customer file_status if customer_id exists
                $customerId = $existing['customer_id'] ?? null;
                if ($customerId) {
                    $this->update('customers')->set(['file_status' => '1'])->where('id', $customerId)->execute();
                }
            } else {
                error_log("No logo record found to save Ambassador thread data for {$logoid}");
            }
        } catch (\Throwable $e) {
            error_log("Error saving Ambassador thread data for {$logoid}: " . $e->getMessage());
        }
    }

    /**
     * Save thread data from Wilcom API
     */
    protected function saveLogoThreadData_Wilcom(string $logoid, array $designInfo, array $fullResponse): void
    {
        try {
            $threadData = array(
                'logo_id' => $logoid,
                'designWidth' => isset($designInfo['attributes']['width']) ? ($designInfo['attributes']['width'] * 10) : null,
                'designHeight' => isset($designInfo['attributes']['height']) ? ($designInfo['attributes']['height'] * 10) : null,
                'machineFormat' => $designInfo['attributes']['machine_name'] ?? null,
                'stitches' => $designInfo['attributes']['num_stitches'] ?? null,
                'numTrims' => $designInfo['attributes']['num_trims'] ?? null,
                'colourChanges' => $designInfo['attributes']['num_colour_changes'] ?? 0
            );

            // Extract thread information from Wilcom response
            if (isset($fullResponse['design_info']['colorways']['colorway']['threads']['thread'])) {
                // Calculate max thread count based on API response (num_colour_changes from Wilcom)
                $colourChanges = $designInfo['attributes']['num_colour_changes'] ?? 0;
                $maxThreadCount = $colourChanges + 1;

                $threads = $fullResponse['design_info']['colorways']['colorway']['threads']['thread'];
                if (!isset($threads[0])) {
                    $threads = array($threads);
                }

                $threadCount = 1;
                foreach ($threads as $thread) {
                    if ($threadCount <= $maxThreadCount) {
                        // Find matching color code
                        $colorCode = $this->from('color_list')->where('wilcom_code', $thread['attributes']['color'])->fetch('code');
                        if ($colorCode) {
                            $manufacturer = $this->from('color_list')->where('code', $colorCode)->fetch('manufacturer');
                            $manuCode = $this->from('color_manufacturer')->where('name', $manufacturer)->fetch('code');
                            $threadData['thread_' . $threadCount] = ($manuCode ?? '') . '-' . $colorCode;
                        }
                        $threadData['thread_' . $threadCount . '_desc'] = $thread['attributes']['description'] ?? '';
                        $threadCount++;
                    }
                }
            }

            // Store in existing `logos` table (update only)
            $existing = $this->from('logos')->where('logo_id', $logoid)->fetch();
            if ($existing) {
                $this->update('logos')->set($threadData)->where('logo_id', $logoid)->execute();
            } else {
                error_log("No logo record found to save Wilcom thread data for {$logoid}");
            }
        } catch (\Throwable $e) {
            error_log("Error saving Wilcom thread data for {$logoid}: " . $e->getMessage());
        }
    }

    /**
     * Save thread data from Emboryx API
     */
    protected function saveLogoThreadData_Emboryx(string $logoid, array $threadDetails): void
    {
        try {
            $threadData = array(
                'logo_id' => $logoid,
                'designWidth' => isset($threadDetails['design_dimensions']['width']) ? ($threadDetails['design_dimensions']['width'] * 10) : null,
                'designHeight' => isset($threadDetails['design_dimensions']['height']) ? ($threadDetails['design_dimensions']['height'] * 10) : null,
                'stitches' => $threadDetails['total_stitch_count'] ?? null,
                'numTrims' => $threadDetails['trim_count'] ?? null,
                'colourChanges' => $threadDetails['color_change_count'] ?? 0
            );

            if (isset($threadDetails['threads']) && is_array($threadDetails['threads'])) {
                $threadCount = 1;
                foreach ($threadDetails['threads'] as $thread) {
                    $hex = strtolower(trim($thread['color'] ?? ''));
                    if ($hex) {
                        $hexCode = ltrim($hex, '#');
                        if (strlen($hexCode) == 6) {
                            $r = hexdec(substr($hexCode, 0, 2));
                            $g = hexdec(substr($hexCode, 2, 2));
                            $b = hexdec(substr($hexCode, 4, 2));

                            $colorRow = $this->from('color_list')
                                ->where('red', $r)
                                ->where('green', $g)
                                ->where('blue', $b)
                                ->fetch();

                            if ($colorRow) {
                                $manuCode = $this->from('color_manufacturer')->where('name', $colorRow['manufacturer'])->fetch('code');
                                $threadData['thread_' . $threadCount] = ($manuCode ?? '') . '-' . $colorRow['code'];
                                $threadData['thread_' . $threadCount . '_desc'] = $colorRow['name'];
                            } else {
                                $threadData['thread_' . $threadCount . '_desc'] = $hex;
                            }
                        }
                    }
                    $threadCount++;
                }
            }

            $existing = $this->from('logos')->where('logo_id', $logoid)->fetch();
            if ($existing) {
                $this->update('logos')->set($threadData)->where('logo_id', $logoid)->execute();
            } else {
                error_log("No logo record found to save Emboryx thread data for {$logoid}");
            }
        } catch (\Throwable $e) {
            error_log("Error saving Emboryx thread data for {$logoid}: " . $e->getMessage());
        }
    }

    /**
     * Upload logo files for a customer
     * POST /customer/upload_logo
     * Accepts base64-encoded files: JPEG, PNG, PDF, DST, AI
     * All files are optional.
     * Generates logo_id internally based on settings configuration.
     * Converts DST and AI files to PNG and extracts thread data.
     */
    public function upload_logo($args)
    {
        try {
            $customerId = intval($this->req['customer_id'] ?? ($args['customer_id'] ?? 0));
            if ($customerId <= 0) {
                return $this->out(['status' => false, 'message' => 'customer_id is required'], 422);
            }

            $logotypeId = intval($this->req['logotype_id'] ?? ($args['logotype_id'] ?? 0));
            if ($logotypeId <= 0) {
                return $this->out(['status' => false, 'message' => 'logotype_id is required'], 422);
            }

            // Verify customer exists
            $logo_customer_key = $this->getSettingValue('logo_customer_key');
            if (!$logo_customer_key) {
                return $this->out(['status' => false, 'message' => 'Customer logo key not found in settings'], 422);
            }
            $customer = $this->from('customer_display_fields')->where('id', $customerId)->fetch();
            if (!$customer) {
                return $this->out(['status' => false, 'message' => 'Customer not found'], 404);
            }

            // Determine logo_id: respect ext_vendor_id override if allowed, otherwise generate
            $extVendorId = trim($this->req['ext_vendor_id'] ?? ($args['ext_vendor_id'] ?? ''));
            $allowExtOverride = in_array(strtolower((string) ($this->req['logo_allow_ext_vendor_override'] ?? ($args['logo_allow_ext_vendor_override'] ?? '0'))), ['1', 'true', 'yes'], true);
            if ($allowExtOverride && $extVendorId !== '') {
                $generatedLogoId = $extVendorId;
                $usedExtVendorOverride = true;
            } else {
                $generatedLogoId = $this->generateLogoId($customerId, $logotypeId);
                $usedExtVendorOverride = false;
            }

            // Read optional metadata fields
            $description = $this->req['description'] ?? ($args['description'] ?? null);
            $specialInstruction = $this->req['special_instruction'] ?? ($args['special_instruction'] ?? null);
            $cusAppDateRaw = $this->req['cus_app_date'] ?? ($args['cus_app_date'] ?? null);
            $cusAppDate = null;
            if (!empty($cusAppDateRaw)) {
                $ts = strtotime($cusAppDateRaw);
                if ($ts !== false) {
                    $cusAppDate = date('Y-m-d', $ts);
                } else {
                    $cusAppDate = $cusAppDateRaw; // fallback to provided string
                }
            }

            // Allowed extensions (case-sensitive as capital letters)
            $allowedExtensions = ['JPEG', 'PNG', 'PDF', 'DST', 'AI'];

            // Track uploaded files
            $uploadedFiles = [];
            $errors = [];
            $hasFiles = false;

            // Process each possible file type
            foreach ($allowedExtensions as $ext) {
                $fileKey = 'file_' . strtolower($ext);
                if (!isset($this->req[$fileKey])) {
                    continue; // File is optional
                }

                $base64Data = $this->req[$fileKey];
                if (empty($base64Data)) {
                    continue; // Skip empty files
                }

                $hasFiles = true;

                // Decode base64
                $binaryData = base64_decode($base64Data, true);
                if ($binaryData === false) {
                    $errors[] = "$ext: Invalid base64 encoding";
                    continue;
                }

                try {
                    // Build upload directory path (inside 'logo_uploads' folder with customer_id)
                    $uploadDir = __DIR__ . '/../logo_uploads/';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0755, true);
                    }

                    // Save file as logo_id.EXTENSION (e.g., CUSTE001.JPEG)
                    $filename = $generatedLogoId . '.' . strtoupper($ext);
                    $filePath = $uploadDir . '/' . $filename;

                    // Write file
                    $bytesWritten = file_put_contents($filePath, $binaryData);
                    if ($bytesWritten === false) {
                        $errors[] = "$ext: Failed to write file";
                        continue;
                    }

                    $uploadedFiles[] = [
                        'extension' => strtoupper($ext),
                        'filename' => $filename,
                        'size' => $bytesWritten,
                        'path' => '/logo_uploads/' . $filename
                    ];

                } catch (\Throwable $e) {
                    $errors[] = "$ext: " . $e->getMessage();
                }
            }

            // Verify at least one file was provided
            if (!$hasFiles) {
                return $this->out([
                    'status' => false,
                    'message' => 'At least one file must be provided'
                ], 422);
            }

            // If no files were successfully uploaded, return error
            if (empty($uploadedFiles)) {
                return $this->out([
                    'status' => false,
                    'message' => 'No files were successfully uploaded',
                    'errors' => $errors
                ], 422);
            }

            // Insert preliminary logo record so conversion helpers can update thread/design fields
            $now = date('Y-m-d H:i:s');
            $initialExts = implode(',', array_map(function ($f) {
                return $f['extension'];
            }, $uploadedFiles));

            $initialLogoRecord = [
                'customer_id' => $customerId,
                'logotype' => $logotypeId,
                'logo_id' => $generatedLogoId,
                'availabe_extentions' => $initialExts,
                'description' => $description,
                'special_instruction' => $specialInstruction,
                'ext_vendor_id' => $extVendorId ?: null,
                'cus_app_date' => $cusAppDate,
                'create_time' => $now,
                'last_update' => $now
            ];

            // Include design dimensions only for non-embroidery logos (logotype != 1)
            if ($logotypeId !== 1) {
                $designWidth = $this->req['designWidth'] ?? ($args['designWidth'] ?? null);
                $designHeight = $this->req['designHeight'] ?? ($args['designHeight'] ?? null);
                if ($designWidth !== null) {
                    $initialLogoRecord['designWidth'] = $designWidth;
                }
                if ($designHeight !== null) {
                    $initialLogoRecord['designHeight'] = $designHeight;
                }
            }

            // If a logos row with this logo_id already exists (ext_vendor override or reuse), update it instead
            $existingLogo = $this->from('logos')->where('logo_id', $generatedLogoId)->fetch();
            if ($existingLogo) {
                $newLogoDbId = $existingLogo['id'];
                $this->update('logos')->set($initialLogoRecord)->where('id', $newLogoDbId)->execute();
            } else {
                $newLogoDbId = $this->insertInto('logos')->values($initialLogoRecord)->execute();
            }

            // Process DST and AI conversions
            $uploadDir = __DIR__ . '/../logo_uploads/';
            $conversionResults = [];
            $dstMethod = $this->getSettingValue('logo_dst_conversion_method') ?? 'pulseid'; // Default to wilcom
            $hasConversionErrors = false;

            // Check if DST file was uploaded
            $dstUploaded = in_array('DST', array_map(function ($f) {
                return $f['extension'];
            }, $uploadedFiles));
            if ($dstUploaded) {
                switch (strtolower($dstMethod)) {
                    case 'emboryx':
                        $success = $this->convertDstToPng_Emboryx($generatedLogoId, $uploadDir);
                        break;
                    case 'wilcom':
                        $success = $this->convertDstToPng_Wilcom($generatedLogoId, $uploadDir);
                        break;
                    case 'ambassador':
                        $success = $this->convertDstToPng_Ambassador($generatedLogoId, $uploadDir);
                        break;
                    case 'pulseid':
                    default:
                        $success = $this->convertDstToPng_PulseID($generatedLogoId, $uploadDir);
                        break;
                }
                if ($success) {
                    // Add PNG to uploaded files list
                    $pngPath = $uploadDir . '/' . $generatedLogoId . '_DST.PNG';
                    if (file_exists($pngPath)) {
                        $uploadedFiles[] = [
                            'extension' => '_DST.PNG',
                            'filename' => $generatedLogoId . '_DST.PNG',
                            'size' => filesize($pngPath),
                            'path' => '/logo_uploads/' . $generatedLogoId . '_DST.PNG'
                        ];
                        $conversionResults['dst'] = 'converted successfully';
                    }
                } else {
                    $hasConversionErrors = true;
                    $conversionResults['dst'] = 'conversion failed - check logs';
                }
            }

            // Check if AI file was uploaded
            $aiUploaded = in_array('AI', array_map(function ($f) {
                return $f['extension'];
            }, $uploadedFiles));
            if ($aiUploaded) {
                $success = $this->convertAiToPng_Inkscape($generatedLogoId, $uploadDir);
                if ($success) {
                    // Add PNG to uploaded files list
                    $pngPath = $uploadDir . '/' . $generatedLogoId . '_AI.PNG';
                    if (file_exists($pngPath)) {
                        $uploadedFiles[] = [
                            'extension' => '_AI.PNG',
                            'filename' => $generatedLogoId . '_AI.PNG',
                            'size' => filesize($pngPath),
                            'path' => '/logo_uploads/' . $generatedLogoId . '_AI.PNG'
                        ];
                        $conversionResults['ai'] = 'converted successfully';
                    }
                } else {
                    $hasConversionErrors = true;
                    $conversionResults['ai'] = 'conversion failed - check logs';
                }
            }

            // Update logos row with any conversion-added extensions and updated timestamp
            $uploadedExts = implode(',', array_map(function ($f) {
                return $f['extension'];
            }, $uploadedFiles));

            $this->update('logos')->set([
                'availabe_extentions' => $uploadedExts,
                'last_update' => $now
            ])->where('logo_id', $generatedLogoId)->execute();

            $response = [
                'status' => true,
                'message' => count($uploadedFiles) . ' file(s) uploaded successfully',
                'logo_id' => $generatedLogoId,
                'logo_db_id' => $newLogoDbId,
                'customer_id' => $customerId,
                'logotype_id' => $logotypeId,
                'uploaded_files' => $uploadedFiles
            ];

            if (!empty($conversionResults)) {
                $response['conversions'] = $conversionResults;
            }

            if (!empty($errors) || $hasConversionErrors) {
                $response['partial_errors'] = array_merge($errors, array_filter($conversionResults, function ($v) {
                    return strpos($v, 'failed') !== false;
                }));
            }

            return $this->out($response, 200);

        } catch (\Throwable $e) {
            return $this->out(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Update logo record and/or files by logo_id
     * POST /customer/update_logo
     * 
     * Updates metadata columns and/or replaces files. The logo_id itself remains unchanged.
     * If customer_id is provided, verifies it matches the logo's owner (optional safety check).
     * Files provided replace existing files with same extension.
     * DST and AI files trigger automatic conversion to PNG.
     */
    public function update_logo($args)
    {
        try {
            $logoId = trim($this->req['logo_id'] ?? ($args['logo_id'] ?? ''));
            if (empty($logoId)) {
                return $this->out(['status' => false, 'message' => 'logo_id is required'], 422);
            }

            // Lookup existing logo record
            $existingLogo = $this->from('logos')->where('logo_id', $logoId)->fetch();
            if (!$existingLogo) {
                return $this->out(['status' => false, 'message' => 'Logo not found with logo_id: ' . $logoId], 404);
            }

            // Optional: verify customer_id matches (ownership check)
            $providedCustomerId = intval($this->req['customer_id'] ?? ($args['customer_id'] ?? 0));
            if ($providedCustomerId > 0 && intval($existingLogo['customer_id']) !== $providedCustomerId) {
                return $this->out(['status' => false, 'message' => 'Customer ID does not match logo owner'], 403);
            }

            // Extract immutable fields from existing record
            $customerId = intval($existingLogo['customer_id']);
            $logotypeId = intval($existingLogo['logotype']);

            // Track what was updated
            $updatedFields = [];
            $uploadedFiles = [];
            $errors = [];

            // Allowed extensions (case-sensitive as capital letters)
            $allowedExtensions = ['JPEG', 'PNG', 'PDF', 'DST', 'AI'];
            $uploadDir = __DIR__ . '/../logo_uploads/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            // Process file updates
            $filesUpdated = [];
            foreach ($allowedExtensions as $ext) {
                $fileKey = 'file_' . strtolower($ext);
                if (!isset($this->req[$fileKey])) {
                    continue; // File not provided, skip
                }

                $base64Data = $this->req[$fileKey];
                if (empty($base64Data)) {
                    continue; // Skip empty files
                }

                // Decode base64
                $binaryData = base64_decode($base64Data, true);
                if ($binaryData === false) {
                    $errors[] = "$ext: Invalid base64 encoding";
                    continue;
                }

                try {
                    // Save file with same naming convention: {logo_id}.EXTENSION
                    $filename = $logoId . '.' . strtoupper($ext);
                    $filePath = $uploadDir . '/' . $filename;

                    // Write file (overwrites existing)
                    $bytesWritten = file_put_contents($filePath, $binaryData);
                    if ($bytesWritten === false) {
                        $errors[] = "$ext: Failed to write file";
                        continue;
                    }

                    $uploadedFiles[] = [
                        'extension' => strtoupper($ext),
                        'filename' => $filename,
                        'size' => $bytesWritten,
                        'path' => '/logo_uploads/' . $filename
                    ];

                    $filesUpdated[] = strtoupper($ext);

                } catch (\Throwable $e) {
                    $errors[] = "$ext: " . $e->getMessage();
                }
            }

            // Build metadata update set
            $metadataUpdates = [];

            // Update metadata fields if provided
            if (isset($this->req['description']) || isset($args['description'])) {
                $desc = $this->req['description'] ?? ($args['description'] ?? null);
                $metadataUpdates['description'] = $desc;
                $updatedFields[] = 'description';
            }

            if (isset($this->req['special_instruction']) || isset($args['special_instruction'])) {
                $instr = $this->req['special_instruction'] ?? ($args['special_instruction'] ?? null);
                $metadataUpdates['special_instruction'] = $instr;
                $updatedFields[] = 'special_instruction';
            }

            if (isset($this->req['cus_app_date']) || isset($args['cus_app_date'])) {
                $cusAppDateRaw = $this->req['cus_app_date'] ?? ($args['cus_app_date'] ?? null);
                $cusAppDate = null;
                if (!empty($cusAppDateRaw)) {
                    $ts = strtotime($cusAppDateRaw);
                    if ($ts !== false) {
                        $cusAppDate = date('Y-m-d', $ts);
                    } else {
                        $cusAppDate = $cusAppDateRaw; // fallback to provided string
                    }
                }
                $metadataUpdates['cus_app_date'] = $cusAppDate;
                $updatedFields[] = 'cus_app_date';
            }

            // Update design dimensions only if logotype != 1 and dimensions provided
            if ($logotypeId !== 1) {
                if (isset($this->req['designWidth']) || isset($args['designWidth'])) {
                    $designWidth = $this->req['designWidth'] ?? ($args['designWidth'] ?? null);
                    if ($designWidth !== null) {
                        $metadataUpdates['designWidth'] = $designWidth;
                        $updatedFields[] = 'designWidth';
                    }
                }

                if (isset($this->req['designHeight']) || isset($args['designHeight'])) {
                    $designHeight = $this->req['designHeight'] ?? ($args['designHeight'] ?? null);
                    if ($designHeight !== null) {
                        $metadataUpdates['designHeight'] = $designHeight;
                        $updatedFields[] = 'designHeight';
                    }
                }
            }

            // Process DST and AI conversions if files were replaced
            $conversionResults = [];
            $hasConversionErrors = false;
            $dstMethod = $this->getSettingValue('logo_dst_conversion_method') ?? 'pulseid';

            if (in_array('DST', $filesUpdated)) {
                try {
                    switch (strtolower($dstMethod)) {
                        case 'emboryx':
                            $success = $this->convertDstToPng_Emboryx($logoId, $uploadDir);
                            break;
                        case 'wilcom':
                            $success = $this->convertDstToPng_Wilcom($logoId, $uploadDir);
                            break;
                        case 'ambassador':
                            $success = $this->convertDstToPng_Ambassador($logoId, $uploadDir);
                            break;
                        case 'pulseid':
                        default:
                            $success = $this->convertDstToPng_PulseID($logoId, $uploadDir);
                            break;
                    }
                    if ($success) {
                        $pngPath = $uploadDir . '/' . $logoId . '_DST.PNG';
                        if (file_exists($pngPath)) {
                            $uploadedFiles[] = [
                                'extension' => 'PNG',
                                'filename' => $logoId . '_DST.PNG',
                                'size' => filesize($pngPath),
                                'path' => '/logo_uploads/' . $logoId . '_DST.PNG',
                                'conversion' => 'dst'
                            ];
                            $conversionResults['dst'] = 'converted successfully';
                        }
                    } else {
                        $hasConversionErrors = true;
                        $conversionResults['dst'] = 'conversion failed - check logs';
                    }
                } catch (\Throwable $e) {
                    error_log("DST conversion failed during update for {$logoId}: " . $e->getMessage());
                    $hasConversionErrors = true;
                    $conversionResults['dst'] = 'conversion error: ' . $e->getMessage();
                }
            }

            if (in_array('AI', $filesUpdated)) {
                try {
                    $success = $this->convertAiToPng_Inkscape($logoId, $uploadDir);
                    if ($success) {
                        $pngPath = $uploadDir . '/' . $logoId . '_AI.PNG';
                        if (file_exists($pngPath)) {
                            $uploadedFiles[] = [
                                'extension' => 'PNG',
                                'filename' => $logoId . '_AI.PNG',
                                'size' => filesize($pngPath),
                                'path' => '/logo_uploads/' . $logoId . '_AI.PNG',
                                'conversion' => 'ai'
                            ];
                            $conversionResults['ai'] = 'converted successfully';
                        }
                    } else {
                        $hasConversionErrors = true;
                        $conversionResults['ai'] = 'conversion failed - check logs';
                    }
                } catch (\Throwable $e) {
                    error_log("AI conversion failed during update for {$logoId}: " . $e->getMessage());
                    $hasConversionErrors = true;
                    $conversionResults['ai'] = 'conversion error: ' . $e->getMessage();
                }
            }

            // Recalculate extension list based on files in uploadDir
            $currentExtensions = [];
            foreach ($allowedExtensions as $ext) {
                $filePath = $uploadDir . '/' . $logoId . '.' . strtoupper($ext);
                if (file_exists($filePath)) {
                    $currentExtensions[] = strtoupper($ext);
                }
            }
            // Also check for conversion-generated PNGs
            if (file_exists($uploadDir . '/' . $logoId . '_DST.PNG')) {
                $currentExtensions[] = '_DST.PNG';
            }
            if (file_exists($uploadDir . '/' . $logoId . '_AI.PNG')) {
                $currentExtensions[] = '_AI.PNG';
            }

            // Update logos record
            $now = date('Y-m-d H:i:s');
            $metadataUpdates['availabe_extentions'] = implode(',', array_unique($currentExtensions));
            $metadataUpdates['last_update'] = $now;

            $this->update('logos')->set($metadataUpdates)->where('logo_id', $logoId)->execute();

            // Fetch updated record
            $updatedLogo = $this->from('logos')->where('logo_id', $logoId)->fetch();

            // Build response
            $response = [
                'status' => true,
                'message' => 'Logo updated successfully',
                'logo_id' => $logoId,
                'logo_db_id' => $updatedLogo['id'],
                'customer_id' => $customerId,
                'logotype_id' => $logotypeId,
                'updated_fields' => $updatedFields
            ];

            if (!empty($uploadedFiles)) {
                $response['uploaded_files'] = $uploadedFiles;
            }

            if (!empty($conversionResults)) {
                $response['conversions'] = $conversionResults;
            }

            if (!empty($errors) || $hasConversionErrors) {
                $response['partial_errors'] = array_merge($errors, array_filter($conversionResults, function ($v) {
                    return strpos($v, 'failed') !== false || strpos($v, 'error') !== false;
                }));
            }

            // Include updated record if changes were made
            if (!empty($updatedFields) || !empty($uploadedFiles)) {
                $response['item'] = $updatedLogo;
            }

            return $this->out($response, 200);

        } catch (\Throwable $e) {
            return $this->out(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }


    /**
     * Regenerate DST PNG with specific threads using PulseID
     */
    protected function regenerateDstPngWithThreads_PulseID(string $logoid, string $uploadDir, ?array $threads): bool
    {
        // For PulseID, we just call pulseidRecolor with the optional threads
        // Output path defaults to logoid_DST.PNG if not specified
        try {
            return (bool) $this->pulseidRecolor($logoid, $uploadDir, $threads);
        } catch (\Throwable $e) {
            error_log("PulseID PNG regeneration failed for $logoid: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Regenerate DST PNG with specific threads using Ambassador
     */
    protected function regenerateDstPngWithThreads_Ambassador(string $logoid, string $uploadDir, ?array $threads): bool
    {
        try {
            return (bool) $this->ambassadorRecolor($logoid, $uploadDir, $threads);
        } catch (\Throwable $e) {
            error_log("Ambassador PNG regeneration failed for $logoid: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Regenerate DST PNG with specific threads using Wilcom
     */
    protected function regenerateDstPngWithThreads_Wilcom(string $logoid, string $uploadDir, ?array $threads): bool
    {
        try {
            return (bool) $this->wilcomRecolor($logoid, $uploadDir, $threads);
        } catch (\Throwable $e) {
            error_log("Wilcom PNG regeneration failed for $logoid: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Regenerate DST PNG with specific threads using Emboryx
     */
    protected function regenerateDstPngWithThreads_Emboryx(string $logoid, string $uploadDir, ?array $threads): bool
    {
        try {
            return (bool) $this->emboryxRecolor($logoid, $uploadDir, $threads);
        } catch (\Throwable $e) {
            error_log("Emboryx PNG regeneration failed for $logoid: " . $e->getMessage());
            return false;
        }
    }

    /**
     * PulseID Recolor Logic
     */
    /**
     * PulseID Recolor Logic (Legacy Implementation)
     */
    protected function pulseidRecolor(string $logoid, string $uploadDir, ?array $providedThreads = null, ?string $outputFilePath = null, bool $skipDbSave = false): bool
    {
        $dstFilePath = $uploadDir . '/' . $logoid . '.DST';
        if (!file_exists($dstFilePath)) {
            throw new \Exception("DST file not found");
        }

        // PulseID URL from credentials table
        $baseUrl = $this->getCredentialValue('pulseid_url'); // typically https://webapi.pulseidconnect.com/1/
        if (!$baseUrl) {
            throw new \Exception("PulseID URL (pulseid_url) not configured in credentials table");
        }

        // Sanitize Logo ID for PulseID API (alphanumeric only)
        $logoid_view = preg_replace('/[^A-Za-z0-9]/', '', $logoid);

        // 1. Upload Design to PulseID
        $uploadUrl = rtrim($baseUrl, '/') . "/Upload/Designs/" . $logoid_view . ".DST?format=json";
        $cFile = new \CURLFile($dstFilePath, 'application/octet-stream', $logoid . '.DST');

        $ch = curl_init($uploadUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, ['' => $cFile]); // Note the empty key for legacy PulseID upload
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $uploadResponse = curl_exec($ch);
        $uploadHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);

        $this->logServiceCall('logs_pulseid', $logoid, $uploadUrl, 'POST', '[FILE DATA]', $uploadResponse, $uploadHttpCode);

        if ($uploadHttpCode !== 200) {
            throw new \Exception("PulseID upload failed with code $uploadHttpCode: $uploadResponse");
        }

        // 2. Prepare Render Parameters (Palette)
        $request = [
            'Background' => '00000000',
            'ImageHeight' => 600
        ];

        if ($providedThreads) {
            foreach ($providedThreads as $index => $threadCode) {
                // Fetch color details (hex) from color_list
                // Note: getcolorDetails is expected to be in the model/logo class
                $colorDetails = $this->getcolorDetails($threadCode, ''); // manufacture optional in legacy
                if ($colorDetails) {
                    $hex = sprintf("#%02x%02x%02x", intval($colorDetails['red']), intval($colorDetails['green']), intval($colorDetails['blue']));
                    $request['Palette'][$index] = $hex;
                }
            }
        }

        // 3. Render Design
        $renderUrl = rtrim($baseUrl, '/') . "/Render/Designs/" . $logoid_view . ".DST?" . http_build_query($request);

        $ch = curl_init($renderUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $pngData = curl_exec($ch);
        $renderHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);

        $this->logServiceCall('logs_pulseid', $logoid, $renderUrl, 'GET', '', '[BINARY DATA]', $renderHttpCode);

        if ($renderHttpCode !== 200 || !$pngData) {
            throw new \Exception("PulseID render failed with code $renderHttpCode");
        }

        // Save PNG
        $savePath = $outputFilePath ?? ($uploadDir . '/' . $logoid . '_DST.PNG');
        file_put_contents($savePath, $pngData);

        // 4. Save extracted design info (optional, if we want to store stitches etc.)
        // Legacy code calls GetInfo/Designs/
        if (!$skipDbSave) {
            $infoUrl = rtrim($baseUrl, '/') . "/GetInfo/Designs/" . $logoid_view . ".DST?format=json";
            $ch = curl_init($infoUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $infoResponse = curl_exec($ch);
            $infoHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            unset($ch);

            if ($infoHttpCode === 200) {
                $designInfo = json_decode($infoResponse, true);
                if (isset($designInfo['Info'])) {
                    $this->saveLogoThreadData_PulseID($logoid, $designInfo);
                }
            }
        }

        return true;
    }


    /**
     * Ambassador (Pulse Micro) Recolor Logic
     */
    protected function ambassadorRecolor(string $logoid, string $uploadDir, ?array $providedThreads = null, ?string $outputFilePath = null, bool $skipDbSave = false): bool
    {
        $dstFilePath = $uploadDir . '/' . $logoid . '.DST';
        if (!file_exists($dstFilePath)) {
            throw new \Exception("DST file not found");
        }

        // Credentials
        // Let's assume ambassador_api_key in credentials is the password and ambassador_url is the base url
        // Alternatively, use a hypothetical username if not present, but usually we need an explicit username
        // Based on old code, username=nixonxavier, password=1@Katalyst
        // We will try to fetch from credentials if available, otherwise fallback to old hardcoded for now,
        // but prefer credentials. Let's look for ambassador_username and ambassador_password
        $username = $this->getCredentialValue('ambassador_username') ?: 'nixonxavier';
        $password = $this->getCredentialValue('ambassador_password') ?: '1@Katalyst';
        $baseUrl = $this->getCredentialValue('ambassador_url') ?: 'https://api.ambassador.pulsemicro.com';

        // 1. Authenticate to get token
        $authUrl = rtrim($baseUrl, '/') . "/api/auth/gettoken";
        $chAuth = curl_init($authUrl);
        curl_setopt($chAuth, CURLOPT_POST, true);
        curl_setopt($chAuth, CURLOPT_POSTFIELDS, "Password=" . urlencode($password) . "&Username=" . urlencode($username));
        curl_setopt($chAuth, CURLOPT_HTTPHEADER, ["Content-Type: application/x-www-form-urlencoded"]);
        curl_setopt($chAuth, CURLOPT_RETURNTRANSFER, true);
        $authResponseRaw = curl_exec($chAuth);
        $authHttpCode = curl_getinfo($chAuth, CURLINFO_HTTP_CODE);
        unset($chAuth);

        if ($authHttpCode !== 200 || !$authResponseRaw) {
            throw new \Exception("Ambassador auth failed with code $authHttpCode: $authResponseRaw");
        }

        $authResponse = json_decode($authResponseRaw, true);
        $token = $authResponse['Data']['Token']['Token'] ?? null;
        if (!$token) {
            throw new \Exception("Ambassador token missing in response");
        }

        $authHeader = ["Authorization: Bearer $token"];

        // 2. Upload File
        $uploadUrl = rtrim($baseUrl, '/') . "/api/files/upload/";
        $cFile = new \CURLFile($dstFilePath, 'application/octet-stream', $logoid . '.DST');

        $chUpload = curl_init($uploadUrl);
        curl_setopt($chUpload, CURLOPT_POST, true);
        curl_setopt($chUpload, CURLOPT_POSTFIELDS, ['file' => $cFile]);
        curl_setopt($chUpload, CURLOPT_HTTPHEADER, $authHeader);
        curl_setopt($chUpload, CURLOPT_RETURNTRANSFER, true);
        $uploadResponse = curl_exec($chUpload);
        $uploadHttpCode = curl_getinfo($chUpload, CURLINFO_HTTP_CODE);
        unset($chUpload);

        if ($uploadHttpCode !== 200 || !$uploadResponse) {
            throw new \Exception("Ambassador upload failed with code $uploadHttpCode");
        }
        $filename = trim($uploadResponse, '"');

        // 3. Export / Render
        if ($providedThreads) {
            // Need to recolor via Export API
            $Colors = [];
            $Index = 0;
            // manufacture is typically passed, assuming empty string fallback if not strictly required
            // or we might need to adjust the function signature. The old code used `$params['manufacture']`.
            // We use '' since `ambassadorRecolor` signature doesn't pass manufacturer.
            foreach ($providedThreads as $threadCode) {
                // If providedThread is like "Gunold-1001", try to split it, or just pass to getcolorDetails
                $colorDetails = $this->getcolorDetails($threadCode, '');
                if ($colorDetails) {
                    $Colors[] = [
                        'Index' => $Index,
                        'Red' => intval($colorDetails['red']),
                        'Green' => intval($colorDetails['green']),
                        'Blue' => intval($colorDetails['blue'])
                    ];
                }
                $Index++;
            }

            $convertRequest = [
                'Id' => $filename,
                'Colors' => $Colors,
                'OutputFormat' => 'png'
            ];

            $exportUrl = rtrim($baseUrl, '/') . "/api/export";
            $headers = array_merge($authHeader, ['Content-Type: application/json']);

            $chExport = curl_init($exportUrl);
            curl_setopt($chExport, CURLOPT_POST, true);
            curl_setopt($chExport, CURLOPT_POSTFIELDS, json_encode($convertRequest));
            curl_setopt($chExport, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($chExport, CURLOPT_RETURNTRANSFER, true);
            $renderResponseRaw = curl_exec($chExport);
            $renderHttpCode = curl_getinfo($chExport, CURLINFO_HTTP_CODE);
            unset($chExport);
            $this->logServiceCall('logs_ambassador', $logoid, $exportUrl, 'POST', substr(json_encode($convertRequest), 0, 1000), substr($renderResponseRaw, 0, 1000) . '...', $renderHttpCode);

            if ($renderHttpCode !== 200 || !$renderResponseRaw) {
                throw new \Exception("Ambassador recolor export failed with code $renderHttpCode");
            }

            $renderResponse = json_decode($renderResponseRaw, true);

        } else {
            // No custom threads, just get the default image
            $openUrl = rtrim($baseUrl, '/') . "/api/files/open?filename=$filename";
            $chOpen = curl_init($openUrl);
            curl_setopt($chOpen, CURLOPT_HTTPHEADER, $authHeader);
            curl_setopt($chOpen, CURLOPT_RETURNTRANSFER, true);
            $renderResponseRaw = curl_exec($chOpen);
            $renderHttpCode = curl_getinfo($chOpen, CURLINFO_HTTP_CODE);
            unset($chOpen);

            $this->logServiceCall('logs_ambassador', $logoid, $openUrl, 'GET', '', substr($renderResponseRaw, 0, 1000) . '...', $renderHttpCode);

            if ($renderHttpCode !== 200 || !$renderResponseRaw) {
                throw new \Exception("Ambassador files open failed with code $renderHttpCode");
            }
            $renderResponse = json_decode($renderResponseRaw, true);
        }

        if (isset($renderResponse['Base64Image'])) {
            $pngData = base64_decode($renderResponse['Base64Image']);
            $savePath = $outputFilePath ?? ($uploadDir . '/' . $logoid . '_DST.PNG');
            file_put_contents($savePath, $pngData);

            if (!$skipDbSave && isset($renderResponse['DesignInfo'])) {
                // In old code this was: $this->model->insertcustomeremblogos($LogoId,$DesignInfo);
                // The Ambassador save function name was assumed to be saveLogoThreadData_Ambassador:
                // $this->saveLogoThreadData_Ambassador($logoid, $renderResponse['DesignInfo']);
                // Let's use the actual method used for pulseid/generic insertions if saveLogoThreadData_Ambassador isn't defined or update logos directly. 
                // We'll leave it as saveLogoThreadData_Ambassador assuming it exists or similar fallback.
                if (method_exists($this, 'saveLogoThreadData_Ambassador')) {
                    $this->saveLogoThreadData_Ambassador($logoid, $renderResponse['DesignInfo']);
                }
            }

            // Also log the DST response for legacy tracking
            if (!$skipDbSave && method_exists($this, 'insertdstlogo')) {
                $userId = $this->req['userid'] ?? ''; // Try to extract userid if available
                $this->insertdstlogo($renderResponseRaw, $logoid, $userId);
            }

            return true;
        } else {
            throw new \Exception("Ambassador response missing Base64Image data");
        }
    }

    /**
     * Wilcom Recolor Logic
     */
    protected function wilcomRecolor(string $logoid, string $uploadDir, ?array $providedThreads = null, ?string $outputFilePath = null, bool $skipDbSave = false): bool
    {
        $dstFilePath = $uploadDir . '/' . $logoid . '.DST';
        if (!file_exists($dstFilePath)) {
            throw new \Exception("DST file not found at " . $dstFilePath);
        }

        // Credentials
        $appId = $this->getCredentialValue('wilcom_application_id');
        $appKey = $this->getCredentialValue('wilcom_application_key');
        $baseUrl = $this->getCredentialValue('wilcom_url');

        if (!$appId || !$appKey || !$baseUrl) {
            throw new \Exception("Wilcom credentials not configured in credentials table");
        }

        // 1. Map threads to Wilcom XML format
        $threadXml = '';
        if ($providedThreads) {
            foreach ($providedThreads as $threadCode) {
                $color = $this->getcolorDetails($threadCode, '');
                if ($color) {
                    $threadXml .= sprintf(
                        '<thread color="%s" code="%s" brand="%s" description="%s" />',
                        htmlspecialchars($color['wilcom_code'] ?? ''),
                        htmlspecialchars($color['code'] ?? ''),
                        htmlspecialchars($color['manufacturer'] ?? ''),
                        htmlspecialchars($color['name'] ?? '')
                    );
                }
            }
        }

        // 2. Prepare file contents
        $contents = file_get_contents($dstFilePath);
        $filecontents = base64_encode($contents);
        $resolution = $this->getSettingValue('wilcomimageresolution') ?? '96';

        // 3. Construct Request XML
        $requestXml = '<xml>';
        $requestXml .= '<design file="' . htmlspecialchars($logoid) . '.DST">';
        $requestXml .= '<recolor><colorway><threads>' . $threadXml . '</threads></colorway></recolor>';
        $requestXml .= '</design>';
        $requestXml .= '<trueview_options dpi="' . htmlspecialchars($resolution) . '"/>';
        $requestXml .= '<files><file filename="' . htmlspecialchars($logoid) . '.DST" filecontents="' . $filecontents . '"/></files>';
        $requestXml .= '</xml>';

        // 4. Send Request
        $url = rtrim($baseUrl, '/') . '/api/designTrueview';
        $postData = [
            'appId' => $appId,
            'appKey' => $appKey,
            'requestXml' => $requestXml
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);

        $this->logServiceCall('logs_wilcom', $logoid, $url, 'POST', substr(json_encode($postData), 0, 1000) . '...', substr($response, 0, 1000) . '...', $httpCode);

        if ($httpCode !== 200 || !$response) {
            throw new \Exception("Wilcom API failed with code $httpCode: " . substr($response, 0, 500));
        }

        // 5. Parse Response
        try {
            $xmldata = simplexml_load_string($response);
            if (!$xmldata || !isset($xmldata->files->file['filecontents'])) {
                throw new \Exception("Wilcom response parsing failed or missing image data");
            }

            $pngDataBase64 = (string) $xmldata->files->file['filecontents'];
            $pngData = base64_decode($pngDataBase64);

            if (!$pngData) {
                throw new \Exception("Failed to decode image from Wilcom response");
            }

            $savePath = $outputFilePath ?? ($uploadDir . '/' . $logoid . '_DST.PNG');
            file_put_contents($savePath, $pngData);

            // Optional: extract design_info if not skipping DB save
            if (!$skipDbSave && isset($xmldata->design_info)) {
                // Convert SimpleXMLElement to array and map @attributes to attributes for compatibility
                $json = json_encode($xmldata);
                $fullResponseArray = json_decode(str_replace('"@attributes":', '"attributes":', $json), true);
                if (isset($fullResponseArray['design_info'])) {
                    $this->saveLogoThreadData_Wilcom($logoid, $fullResponseArray['design_info'], $fullResponseArray);
                }
            }

            return true;
        } catch (\Exception $e) {
            throw new \Exception("Wilcom response error: " . $e->getMessage());
        }
    }


    /**
     * Emboryx Recolor Logic
     */
    protected function emboryxRecolor(string $logoid, string $uploadDir, ?array $providedThreads = null, ?string $outputFilePath = null, bool $skipDbSave = false): bool
    {
        $dstFilePath = $uploadDir . '/' . $logoid . '.DST';
        if (!file_exists($dstFilePath)) {
            throw new \Exception("DST file not found");
        }

        $defaultColors = [];
        if ($providedThreads) {
            foreach ($providedThreads as $threadCode) {
                $parts = explode('-', $threadCode, 2);
                $code = count($parts) > 1 ? $parts[1] : $threadCode;

                $hex = '';
                $colorRow = $this->from('color_list')->where('code', $code)->fetch();
                if ($colorRow) {
                    $r = str_pad(dechex((int) $colorRow['red']), 2, '0', STR_PAD_LEFT);
                    $g = str_pad(dechex((int) $colorRow['green']), 2, '0', STR_PAD_LEFT);
                    $b = str_pad(dechex((int) $colorRow['blue']), 2, '0', STR_PAD_LEFT);
                    $hex = '#' . $r . $g . $b;
                } else {
                    $hex = '#000000';
                }
                $defaultColors[] = $hex;
            }
        } else {
            $existing = $this->from('logos')->where('logo_id', $logoid)->fetch();
            if ($existing && $existing['colourChanges'] > 0) {
                for ($i = 1; $i <= $existing['colourChanges'] + 1; $i++) {
                    $threadCode = $existing['thread_' . $i];
                    if ($threadCode) {
                        $parts = explode('-', $threadCode, 2);
                        $code = count($parts) > 1 ? $parts[1] : $threadCode;
                        $colorRow = $this->from('color_list')->where('code', $code)->fetch();
                        if ($colorRow) {
                            $r = str_pad(dechex((int) $colorRow['red']), 2, '0', STR_PAD_LEFT);
                            $g = str_pad(dechex((int) $colorRow['green']), 2, '0', STR_PAD_LEFT);
                            $b = str_pad(dechex((int) $colorRow['blue']), 2, '0', STR_PAD_LEFT);
                            $defaultColors[] = '#' . $r . $g . $b;
                        }
                    }
                }
            }
        }

        $apiUrl = $this->getCredentialValue('emboryx_url');

        $postFields = [
            'width' => $this->getSettingValue('logo_emboryx_width') ?? '400',
            'file' => new \CURLFile($dstFilePath)
        ];

        if (!empty($defaultColors)) {
            $postFields['default_colors'] = json_encode($defaultColors);
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiUrl);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        unset($ch);

        $logPostFields = $postFields;
        if (isset($logPostFields['file'])) {
            $logPostFields['file'] = '[File Object]';
        }
        $this->logServiceCall('logs_emboryx', $logoid, $apiUrl, 'POST', substr(json_encode($logPostFields), 0, 1000) . '...', substr((string) $response, 0, 1000) . '...', $httpCode);

        if ($error) {
            throw new \Exception("Emboryx curl error: " . $error);
        }

        if ($httpCode !== 200) {
            throw new \Exception("Emboryx error HTTP $httpCode: $response");
        }

        $data = json_decode($response, true);
        if (!$data || !isset($data['image'])) {
            throw new \Exception("Invalid response from Emboryx API");
        }

        $pngData = base64_decode($data['image']);
        $savePath = $outputFilePath ?? ($uploadDir . '/' . $logoid . '_DST.PNG');
        file_put_contents($savePath, $pngData);

        if (!$skipDbSave && isset($data['thread_details'])) {
            $this->saveLogoThreadData_Emboryx($logoid, $data['thread_details']);
        }

        return true;
    }


    /**
     * Preview a logo recolor without saving changes
     * POST /customer/preview_recolor
     */
    public function preview_recolor($args)
    {
        try {
            $logoId = trim($this->req['logo_id'] ?? ($args['logo_id'] ?? ''));
            if (empty($logoId)) {
                return $this->out(['status' => false, 'message' => 'logo_id is required'], 422);
            }

            // Gather threads from request
            $threads = [];
            for ($i = 1; $i <= 22; $i++) {
                $key = 'thread_' . $i;
                if (isset($this->req[$key]) || isset($args[$key])) {
                    $val = $this->req[$key] ?? $args[$key];
                    if ($val) {
                        // Parse "M-CODE" to just CODE usually, or keep as is depending on API needs.
                        // Usually APIs need the hex or the manufacturer code.
                        // Let's assume we pass what we have and the helper handles parsing.
                        $threads[] = $val;
                    }
                }
            }

            if (empty($threads)) {
                return $this->out(['status' => false, 'message' => 'No threads provided'], 422);
            }

            $uploadDir = __DIR__ . '/../logo_uploads/';

            // Generate temp filename
            $tempFilename = $logoId . '_PREVIEW_' . time() . '.PNG';
            $tempPath = $uploadDir . '/' . $tempFilename;

            // Determine method
            $dstMethod = $this->getSettingValue('logo_dst_conversion_method') ?? 'pulseid';
            $success = false;

            switch (strtolower($dstMethod)) {
                case 'emboryx':
                    $success = $this->emboryxRecolor($logoId, $uploadDir, $threads, $tempPath, true);
                    break;
                case 'wilcom':
                    $success = $this->wilcomRecolor($logoId, $uploadDir, $threads, $tempPath, true);
                    break;
                case 'ambassador':
                    $success = $this->ambassadorRecolor($logoId, $uploadDir, $threads, $tempPath, true);
                    break;
                case 'pulseid':
                default:
                    $success = $this->pulseidRecolor($logoId, $uploadDir, $threads, $tempPath, true);
                    break;
            }

            if ($success && file_exists($tempPath)) {
                $imageData = file_get_contents($tempPath);
                $base64 = base64_encode($imageData);

                // Cleanup temp file
                unlink($tempPath);

                return $this->out([
                    'status' => true,
                    'message' => 'Preview generated successfully',
                    'image_base64' => $base64
                ], 200);
            } else {
                return $this->out(['status' => false, 'message' => 'Failed to generate preview'], 500);
            }

        } catch (\Throwable $e) {
            return $this->out(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }


    /**
     * Save default thread selection for a logo
     * POST /customer/save_default_threads
     */
    public function save_default_threads($args)
    {
        try {
            $logoId = trim($this->req['logo_id'] ?? ($args['logo_id'] ?? ''));
            if (empty($logoId)) {
                return $this->out(['status' => false, 'message' => 'logo_id is required'], 422);
            }

            // Verify logo exists
            $logo = $this->from('logos')->where('logo_id', $logoId)->fetch();
            if (!$logo) {
                return $this->out(['status' => false, 'message' => 'Logo not found'], 404);
            }

            // Build update array
            $updateSet = ['last_update' => date('Y-m-d H:i:s')];
            $threadsToRegenerate = [];

            for ($i = 1; $i <= 22; $i++) {
                $threadKey = 'thread_' . $i;
                $descKey = 'thread_' . $i . '_desc';

                if (isset($this->req[$threadKey]) || isset($args[$threadKey])) {
                    $val = $this->req[$threadKey] ?? $args[$threadKey];
                    $updateSet[$threadKey] = $val;
                    if ($val) {
                        $threadsToRegenerate[] = $val;
                    } else {
                        // If checking specifically for null or empty string to unset?
                        // Assuming basic replacement
                    }
                }
                if (isset($this->req[$descKey]) || isset($args[$descKey])) {
                    $updateSet[$descKey] = $this->req[$descKey] ?? $args[$descKey];
                }
            }

            if (empty($updateSet) || count($updateSet) === 1) { // 1 because of last_update
                return $this->out(['status' => false, 'message' => 'No threads provided to save'], 422);
            }

            $this->update('logos')->set($updateSet)->where('id', $logo['id'])->execute();

            // Regenerate DST PNG if it's an embroidery logo (logotype=1) and we have threads
            // Or if we want to ensure visual consistency
            if (!empty($threadsToRegenerate)) {
                $uploadDir = __DIR__ . '/../logo_uploads/';
                $dstMethod = $this->getSettingValue('logo_dst_conversion_method') ?? 'pulseid';

                // Only regenerate if DST exists
                if (file_exists($uploadDir . '/' . $logoId . '.DST')) {
                    switch (strtolower($dstMethod)) {
                        case 'emboryx':
                            $this->regenerateDstPngWithThreads_Emboryx($logoId, $uploadDir, $threadsToRegenerate);
                            break;
                        case 'wilcom':
                            $this->regenerateDstPngWithThreads_Wilcom($logoId, $uploadDir, $threadsToRegenerate);
                            break;
                        case 'ambassador':
                            $this->regenerateDstPngWithThreads_Ambassador($logoId, $uploadDir, $threadsToRegenerate);
                            break;
                        case 'pulseid':
                        default:
                            $this->regenerateDstPngWithThreads_PulseID($logoId, $uploadDir, $threadsToRegenerate);
                            break;
                    }
                }
            }

            return $this->out([
                'status' => true,
                'message' => 'Default threads saved successfully'
            ], 200);

        } catch (\Throwable $e) {
            return $this->out(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Get a single logo details
     * GET /customer/get_logo
     */
    public function get_logo($args)
    {
        try {
            $logoId = trim($_GET['logo_id'] ?? ($args['logo_id'] ?? ''));
            if (empty($logoId)) {
                return $this->out(['status' => false, 'message' => 'logo_id is required'], 422);
            }

            $logo = $this->from('logos')->where('logo_id', $logoId)->fetch();
            if (!$logo) {
                return $this->out(['status' => false, 'message' => 'Logo not found'], 404);
            }

            // Decorate file URLs
            if (!empty($logo['availabe_extentions'])) {
                $exts = explode(',', $logo['availabe_extentions']);
                $files = [];
                foreach ($exts as $ext) {
                    $files[$ext] = '/logo_uploads/' . $logoId . (strpos($ext, '_') === 0 ? '' : '.') . $ext;
                }
                $logo['files'] = $files;
            }

            return $this->out([
                'status' => true,
                'message' => 'Logo retrieved successfully',
                'data' => $logo
            ], 200);

        } catch (\Throwable $e) {
            return $this->out(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Get all logos for a customer
     * GET /customer/get_logos
     */
    public function get_logos($args)
    {
        try {
            $customerId = intval($_GET['customer_id'] ?? ($args['customer_id'] ?? 0));
            if ($customerId <= 0) {
                return $this->out(['status' => false, 'message' => 'customer_id is required'], 422);
            }

            $logos = $this->from('logos')
                ->where('customer_id', $customerId)
                ->where('is_trashed', 0) // Exclude trashed logos by default
                ->fetchAll();

            foreach ($logos as &$logo) {
                if (!empty($logo['availabe_extentions'])) {
                    $exts = explode(',', $logo['availabe_extentions']);
                    $files = [];
                    foreach ($exts as $ext) {
                        $files[$ext] = '/logo_uploads/' . $logo['logo_id'] . (strpos($ext, '_') === 0 ? '' : '.') . $ext;
                    }
                    $logo['files'] = $files;
                }
            }

            return $this->out([
                'status' => true,
                'message' => 'Logos retrieved successfully',
                'data' => $logos
            ], 200);

        } catch (\Throwable $e) {
            return $this->out(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Apply default threads from one logo to another
     * POST /customer/apply_default_threads
     */
    public function apply_default_threads($args)
    {
        try {
            $sourceLogoId = trim($this->req['source_logo_id'] ?? ($args['source_logo_id'] ?? ''));
            $targetLogoId = trim($this->req['target_logo_id'] ?? ($args['target_logo_id'] ?? ''));

            if (empty($sourceLogoId) || empty($targetLogoId)) {
                return $this->out(['status' => false, 'message' => 'source_logo_id and target_logo_id are required'], 422);
            }

            $sourceLogo = $this->from('logos')->where('logo_id', $sourceLogoId)->fetch();
            $targetLogo = $this->from('logos')->where('logo_id', $targetLogoId)->fetch();

            if (!$sourceLogo || !$targetLogo) {
                return $this->out(['status' => false, 'message' => 'One or both logos not found'], 404);
            }

            // Copy threads 1-15 (or up to 22) and descriptions
            $updateSet = ['last_update' => date('Y-m-d H:i:s')];
            $threadsToRegenerate = [];

            for ($i = 1; $i <= 22; $i++) {
                $threadKey = 'thread_' . $i;
                $descKey = 'thread_' . $i . '_desc';

                if (isset($sourceLogo[$threadKey])) {
                    $updateSet[$threadKey] = $sourceLogo[$threadKey];
                    if ($sourceLogo[$threadKey]) {
                        $threadsToRegenerate[] = $sourceLogo[$threadKey];
                    }
                } else {
                    $updateSet[$threadKey] = null;
                }

                if (isset($sourceLogo[$descKey])) {
                    $updateSet[$descKey] = $sourceLogo[$descKey];
                } else {
                    $updateSet[$descKey] = null;
                }
            }

            $this->update('logos')->set($updateSet)->where('id', $targetLogo['id'])->execute();

            // Regenerate target PNG if needed
            if (!empty($threadsToRegenerate)) {
                $uploadDir = __DIR__ . '/../logo_uploads/';
                $dstMethod = $this->getSettingValue('logo_dst_conversion_method') ?? 'pulseid';

                if (file_exists($uploadDir . '/' . $targetLogoId . '.DST')) {
                    switch (strtolower($dstMethod)) {
                        case 'emboryx':
                            $this->regenerateDstPngWithThreads_Emboryx($targetLogoId, $uploadDir, $threadsToRegenerate);
                            break;
                        case 'wilcom':
                            $this->regenerateDstPngWithThreads_Wilcom($targetLogoId, $uploadDir, $threadsToRegenerate);
                            break;
                        case 'ambassador':
                            $this->regenerateDstPngWithThreads_Ambassador($targetLogoId, $uploadDir, $threadsToRegenerate);
                            break;
                        case 'pulseid':
                        default:
                            $this->regenerateDstPngWithThreads_PulseID($targetLogoId, $uploadDir, $threadsToRegenerate);
                            break;
                    }
                }
            }

            return $this->out([
                'status' => true,
                'message' => 'Threads applied successfully'
            ], 200);

        } catch (\Throwable $e) {
            return $this->out(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Create a new color palette for a logo
     * POST /customer/color_palette/create
     */
    public function color_palette_create($args)
    {
        try {
            $logoId = trim($this->req['logo_id'] ?? ($args['logo_id'] ?? ''));
            if (empty($logoId)) {
                return $this->out(['status' => false, 'message' => 'logo_id is required'], 422);
            }

            $name = trim($this->req['name'] ?? ($args['name'] ?? 'My Palette'));

            $values = [
                'logo_id' => $logoId,
                'name' => $name,
                'description' => trim($this->req['description'] ?? ($args['description'] ?? '')),
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ];

            // Collect threads
            for ($i = 1; $i <= 22; $i++) {
                $threadKey = 'thread_' . $i;
                if (isset($this->req[$threadKey]) || isset($args[$threadKey])) {
                    $values[$threadKey] = $this->req[$threadKey] ?? $args[$threadKey];
                }
            }

            $paletteId = $this->insertInto('logo_color_palettes')->values($values)->execute();

            return $this->out([
                'status' => true,
                'message' => 'Color palette created successfully',
                'id' => $paletteId
            ], 200);

        } catch (\Throwable $e) {
            return $this->out(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Update an existing color palette
     * POST /customer/color_palette/update
     */
    public function color_palette_update($args)
    {
        try {
            $id = intval($this->req['id'] ?? ($args['id'] ?? 0));
            if ($id <= 0) {
                return $this->out(['status' => false, 'message' => 'id is required'], 422);
            }

            $palette = $this->from('logo_color_palettes')->where('id', $id)->fetch();
            if (!$palette) {
                return $this->out(['status' => false, 'message' => 'Color palette not found'], 404);
            }

            $updateData = ['updated_at' => date('Y-m-d H:i:s')];

            if (isset($this->req['name']) || isset($args['name'])) {
                $updateData['name'] = trim($this->req['name'] ?? $args['name']);
            }
            if (isset($this->req['description']) || isset($args['description'])) {
                $updateData['description'] = trim($this->req['description'] ?? $args['description']);
            }

            for ($i = 1; $i <= 22; $i++) {
                $threadKey = 'thread_' . $i;
                if (isset($this->req[$threadKey]) || isset($args[$threadKey])) {
                    $updateData[$threadKey] = $this->req[$threadKey] ?? $args[$threadKey];
                }
            }

            $this->update('logo_color_palettes')->set($updateData)->where('id', $id)->execute();

            return $this->out([
                'status' => true,
                'message' => 'Color palette updated successfully'
            ], 200);

        } catch (\Throwable $e) {
            return $this->out(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Delete a color palette
     * POST /customer/color_palette/delete
     */
    public function color_palette_delete($args)
    {
        try {
            $id = intval($this->req['id'] ?? ($args['id'] ?? 0));
            if ($id <= 0) {
                return $this->out(['status' => false, 'message' => 'id is required'], 422);
            }

            $this->deleteFrom('logo_color_palettes')->where('id', $id)->execute();

            return $this->out([
                'status' => true,
                'message' => 'Color palette deleted successfully'
            ], 200);

        } catch (\Throwable $e) {
            return $this->out(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * List color palettes for a logo
     * GET /customer/color_palette/list
     */
    public function color_palette_list($args)
    {
        try {
            $logoId = trim($_GET['logo_id'] ?? ($args['logo_id'] ?? ''));

            if (empty($logoId)) {
                return $this->out(['status' => false, 'message' => 'logo_id is required'], 422);
            }

            $palettes = $this->from('logo_color_palettes')->where('logo_id', $logoId)->fetchAll();

            return $this->out([
                'status' => true,
                'message' => 'Color palettes retrieved successfully',
                'data' => $palettes
            ], 200);

        } catch (\Throwable $e) {
            return $this->out(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Retrieve thread grouping for a logo
     * GET /customer/thread_grouping/get
     */
    public function get_thread_grouping($args)
    {
        try {
            $logoId = trim($_GET['logo_id'] ?? ($args['logo_id'] ?? ''));

            if (empty($logoId)) {
                return $this->out(['status' => false, 'message' => 'logo_id is required'], 422);
            }

            $logo = $this->from('logos')->select('thread_grouping')->where('logo_id', $logoId)->fetch();

            if (!$logo) {
                return $this->out(['status' => false, 'message' => 'Logo not found'], 404);
            }

            $grouping = $logo['thread_grouping'];
            if ($grouping && is_string($grouping)) {
                $grouping = json_decode($grouping, true);
            }

            return $this->out([
                'status' => true,
                'message' => 'Thread grouping retrieved successfully',
                'data' => $grouping
            ], 200);

        } catch (\Throwable $e) {
            return $this->out(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Update thread grouping for a logo
     * POST /customer/thread_grouping/update
     */
    public function update_thread_grouping($args)
    {
        try {
            $logoId = trim($this->req['logo_id'] ?? ($args['logo_id'] ?? ''));
            $grouping = $this->req['thread_grouping'] ?? ($args['thread_grouping'] ?? null);

            if (empty($logoId)) {
                return $this->out(['status' => false, 'message' => 'logo_id is required'], 422);
            }
            if ($grouping === null) {
                return $this->out(['status' => false, 'message' => 'thread_grouping is required'], 422);
            }

            // Verify logo exists
            $logo = $this->from('logos')->where('logo_id', $logoId)->fetch();
            if (!$logo) {
                return $this->out(['status' => false, 'message' => 'Logo not found'], 404);
            }

            // Ensure grouping is stored as JSON string
            if (!is_string($grouping)) {
                $grouping = json_encode($grouping);
            } else {
                // Validate if it's already a valid JSON string
                json_decode($grouping);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    return $this->out(['status' => false, 'message' => 'Invalid JSON for thread_grouping'], 422);
                }
            }

            $this->update('logos')->set(['thread_grouping' => $grouping])->where('logo_id', $logoId)->execute();

            return $this->out([
                'status' => true,
                'message' => 'Thread grouping updated successfully'
            ], 200);

        } catch (\Throwable $e) {
            return $this->out(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Move a logo to the trash
     * POST /customer/logo/trash
     */
    public function trash_logo($args)
    {
        try {
            $logoId = trim($this->req['logo_id'] ?? ($args['logo_id'] ?? ''));
            if (empty($logoId)) {
                return $this->out(['status' => false, 'message' => 'logo_id is required'], 422);
            }

            $this->update('logos')->set(['is_trashed' => 1])->where('logo_id', $logoId)->execute();

            return $this->out([
                'status' => true,
                'message' => 'Logo moved to trash successfully'
            ], 200);

        } catch (\Throwable $e) {
            return $this->out(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Retrieve trashed logos for a customer
     * GET /customer/trash_logos
     */
    public function get_trashed_logos($args)
    {
        try {
            $customerId = trim($_GET['customer_id'] ?? ($args['customer_id'] ?? ''));
            if (empty($customerId)) {
                return $this->out(['status' => false, 'message' => 'customer_id is required'], 422);
            }

            $logos = $this->from('logos')
                ->where('customer_id', $customerId)
                ->where('is_trashed', 1)
                ->fetchAll();

            foreach ($logos as &$logo) {
                if (!empty($logo['availabe_extentions'])) {
                    $exts = explode(',', $logo['availabe_extentions']);
                    $files = [];
                    foreach ($exts as $ext) {
                        $files[$ext] = '/logo_uploads/' . $logo['logo_id'] . (strpos($ext, '_') === 0 ? '' : '.') . $ext;
                    }
                    $logo['files'] = $files;
                }
            }

            return $this->out([
                'status' => true,
                'message' => 'Trashed logos retrieved successfully',
                'data' => $logos
            ], 200);

        } catch (\Throwable $e) {
            return $this->out(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Permanently delete a logo including its physical files
     * POST /customer/logo/permanently_delete
     */
    public function permanently_delete_logo($args)
    {
        try {
            $logoId = trim($this->req['logo_id'] ?? ($args['logo_id'] ?? ''));
            if (empty($logoId)) {
                return $this->out(['status' => false, 'message' => 'logo_id is required'], 422);
            }

            // 1. Fetch logo to confirm existence and get files info if needed
            $logo = $this->from('logos')->where('logo_id', $logoId)->fetch();
            if (!$logo) {
                return $this->out(['status' => false, 'message' => 'Logo not found'], 404);
            }

            // 2. Delete physical files from logo_uploads directory
            $uploadDir = __DIR__ . '/../logo_uploads/';
            if (is_dir($uploadDir)) {
                $files = glob($uploadDir . $logoId . '.*');
                foreach ($files as $file) {
                    if (is_file($file)) {
                        unlink($file);
                    }
                }

                // Also clean up derivative PNGs (e.g., LOGO123_DST.PNG, LOGO123_AI.PNG)
                $derivatives = glob($uploadDir . $logoId . '_*.*');
                foreach ($derivatives as $file) {
                    if (is_file($file)) {
                        unlink($file);
                    }
                }

                // And SVG (if any)
                if (file_exists($uploadDir . $logoId . '.SVG')) {
                    unlink($uploadDir . $logoId . '.SVG');
                }
            }

            // 3. Delete from database
            $this->deleteFrom('logo_color_palettes')->where('logo_id', $logoId)->execute();
            $this->deleteFrom('logos')->where('logo_id', $logoId)->execute();

            return $this->out([
                'status' => true,
                'message' => 'Logo permanently deleted successfully'
            ], 200);

        } catch (\Throwable $e) {
            return $this->out(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }


    /**
     * Download Color Card PDF
     * GET /customer/logo/downloadcolorcard?logo_id=xxx
     */
    public function downloadcolorcard($args)
    {
        try {
            $logoId = trim($_GET['logo_id'] ?? ($args['logo_id'] ?? ''));
            if (empty($logoId)) {
                return $this->out(['status' => false, 'message' => 'logo_id is required'], 422);
            }

            $pdfData = $this->generateColorCardPdfContent($logoId);

            // Output
            //ob_clean();
            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename="' . $logoId . '.pdf"');
            echo $pdfData;
            exit;

        } catch (\Throwable $e) {
            return $this->out(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Helper to generate Color Card PDF content
     * Returns raw PDF binary string
     */
    protected function generateColorCardPdfContent(string $logoId): string
    {
        // Get logo info
        $logo = $this->from('logos')->where('logo_id', $logoId)->fetch();

        if (!$logo) {
            throw new \Exception('Logo not found');
        }

        $address1 = $this->getSettingValue('address1') ?? '';
        $wide_logo = $this->getSettingValue('wide_logo') ?? '';

        // Initialize TCPDF
        $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('Genie API');
        $pdf->SetAuthor($logoId);
        $pdf->SetTitle($logoId . ' Color Card');
        $pdf->SetSubject($logoId);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);

        $bordercolor = [36, 137, 205];
        $lineStyle = ['width' => 0.5, 'cap' => 'butt', 'join' => 'miter', 'dash' => 0, 'color' => $bordercolor];
        $selectedfont = 'helvetica';

        $pdf->AddPage();
        $pdf->SetAutoPageBreak(TRUE, 0);
        $pdf->SetFooterMargin(0);

        // 1. Header - Wide Logo & Title
        if ($wide_logo) {
            $pdf->Image($wide_logo, 10, 12.5, 0, 12, '', '', 'C', true, 100, 'C', false, false, 0, false, false, false);
        }
        $pdf->SetFont($selectedfont, '', 18);
        $pdf->SetXY(0, 30);
        $pdf->Cell(210, 5, 'COLOR CARD', 0, 1, 'C', 0, '', 0);

        $pdf->SetFont($selectedfont, '', 13);
        $pdf->SetXY(0, 40);
        $pdf->Cell(210, 5, $address1, 0, 1, 'C', 0, '', 0);

        $pdf->SetXY(0, 47);
        $pdf->Cell(210, 5, '(' . $logo['customerName'] . ')', 0, 1, 'C', 0, '', 0);

        // Blue separator bar
        $pdf->Rect(0, 55, 277, 5, 'DF', ['all' => $lineStyle], $bordercolor);

        // 2. Thread Information Box
        $one = 9;
        $boxheight = (7 * ($logo['colourChanges'] ?? 0)) + (8 * $one) + 15;
        $boxwidth = 100.3;
        $pdf->SetFillColor(245, 245, 245);
        $pdf->SetLineStyle($lineStyle);
        $pdf->RoundedRect(10, 69, $boxwidth, $boxheight, 3.50, '1111', 'DF');

        $pdf->SetFont($selectedfont, '', 9);
        $hstart1 = 10.3;
        $hstart2 = 50;
        $hstart3 = 55;
        $start = 75;

        $rowsData = [
            ['Primary Thread Card', $logo['machineFormat'] ?? ''],
            ['Account No', $logo['account_number'] ?? ''],
            ['Company', $logo['customerName'] ?? ''],
            ['Design', $logoId . '.DST'],
        ];

        foreach ($rowsData as $index => $rowData) {
            $bg = ($index % 2 == 0) ? [232, 232, 232] : [245, 245, 245];
            $pdf->SetFillColor($bg[0], $bg[1], $bg[2]);
            $rowY = $start + ($index * $one);

            $pdf->SetXY($hstart1, $rowY);
            $pdf->Cell(40, $one, $rowData[0], 0, 1, 'L', 1, '', 0);
            $pdf->SetXY($hstart2, $rowY);
            $pdf->Cell(5, $one, ':', 0, 1, 'C', 1, '', 0);
            $pdf->SetXY($hstart3, $rowY);
            $pdf->Cell(55, $one, $rowData[1], 0, 1, 'L', 1, '', 0);
        }

        // Stitches, Stops, Trims Row
        $pdf->SetFillColor(232, 232, 232);
        $rowY = $start + (4 * $one);
        $pdf->SetXY($hstart1, $rowY);
        $pdf->Cell(15, $one, 'Stitches', 0, 1, 'L', 1, '', 0);
        $pdf->SetXY(25, $rowY);
        $pdf->Cell(5, $one, ':', 0, 1, 'L', 1, '', 0);
        $pdf->SetXY(30, $rowY);
        $pdf->Cell(15, $one, $logo['stitches'] ?? '', 0, 1, 'L', 1, '', 0);

        $pdf->SetXY(45, $rowY);
        $pdf->Cell(15, $one, 'Stops', 0, 1, 'L', 1, '', 0);
        $pdf->SetXY(60, $rowY);
        $pdf->Cell(5, $one, ':', 0, 1, 'L', 1, '', 0);
        $pdf->SetXY(65, $rowY);
        $pdf->Cell(15, $one, $logo['colourChanges'] ?? '', 0, 1, 'L', 1, '', 0);

        $pdf->SetXY(80, $rowY);
        $pdf->Cell(13, $one, 'Trims', 0, 1, 'L', 1, '', 0);
        $pdf->SetXY(93, $rowY);
        $pdf->Cell(5, $one, ':', 0, 1, 'L', 1, '', 0);
        $pdf->SetXY(98, $rowY);
        $pdf->Cell(12, $one, $logo['numTrims'] ?? '', 0, 1, 'L', 1, '', 0);

        // Height and Width
        $designWidth = number_format(($logo['designWidth'] ?? 0) / 254, 2) . ' inches';
        $designHeight = number_format(($logo['designHeight'] ?? 0) / 254, 2) . ' inches';

        $pdf->SetFillColor(245, 245, 245);
        $rowY = $start + (5 * $one);
        $pdf->SetXY($hstart1, $rowY);
        $pdf->Cell(15, $one, 'Height', 0, 1, 'L', 1, '', 0);
        $pdf->SetXY(25, $rowY);
        $pdf->Cell(5, $one, ':', 0, 1, 'L', 1, '', 0);
        $pdf->SetXY(30, $rowY);
        $pdf->Cell(15, $one, $designHeight, 0, 1, 'L', 1, '', 0);

        $pdf->SetXY(45, $rowY);
        $pdf->Cell(15, $one, 'Width', 0, 1, 'L', 1, '', 0);
        $pdf->SetXY(60, $rowY);
        $pdf->Cell(5, $one, ':', 0, 1, 'L', 1, '', 0);
        $pdf->SetXY(65, $rowY);
        $pdf->Cell(45, $one, $designWidth, 0, 1, 'L', 1, '', 0);

        // 3. Thread List
        $pdf->SetFont($selectedfont, 'B', 11);
        $pdf->SetFillColor(180, 196, 209);
        $rowY = $start + (6 * $one) + 5;
        $pdf->SetXY($hstart1, $rowY);
        $pdf->Cell(10, $one, '#', 0, 1, 'L', 1, '', 0);
        $pdf->SetXY(20, $rowY);
        $pdf->Cell(30, $one, 'Description', 0, 1, 'L', 1, '', 0);
        $pdf->SetXY(50, $rowY);
        $pdf->Cell(20, $one, 'Color', 0, 1, 'L', 1, '', 0);
        $pdf->SetXY(70, $rowY);
        $pdf->Cell(40, $one, 'Color Description', 0, 1, 'L', 1, '', 0);

        $pdf->SetFont($selectedfont, '', 9);
        $even = true;
        $listStart = $rowY + $one + 3;
        $itemHeight = 7;

        for ($i = 1; $i <= ($logo['colourChanges'] ?? 0) + 1; $i++) {
            $threadCode = $logo['thread_' . $i] ?? '';
            $colorDetails = $threadCode ? $this->getcolorDetails($threadCode) : null;

            $pdf->SetFillColor($even ? 245 : 232, $even ? 245 : 232, $even ? 245 : 232);
            $rowY = $listStart + (($i - 1) * $itemHeight);

            $pdf->SetXY($hstart1, $rowY);
            $pdf->Cell(10, $itemHeight, str_pad($i, 2, "0", STR_PAD_LEFT), 0, 1, 'L', 1, '', 0);
            $pdf->SetXY(20, $rowY);
            $pdf->Cell(30, $itemHeight, $colorDetails['name'] ?? '', 0, 1, 'L', 1, '', 0);
            $pdf->SetXY(50, $rowY);
            $pdf->Cell(20, $itemHeight, $threadCode, 0, 1, 'L', 1, '', 0);
            $pdf->SetXY(70, $rowY);
            $pdf->Cell(40, $itemHeight, '', 0, 1, 'L', 1, '', 0);

            $even = !$even;
        }

        // 4. Notes
        $noteY = 169;
        $noteHeight = 60;
        $pdf->SetFillColor(245, 245, 245);

        if (($logo['colourChanges'] ?? 0) + 1 > 10) {
            $pdf->RoundedRect(115, $noteY - 5, 85, $noteHeight, 3.50, '1111', 'DF');
            $pdf->SetFont($selectedfont, 'B', 11);
            $pdf->SetXY(120, $noteY);
            $pdf->Cell(60, $one, 'Notes :', 0, 1, 'L', 1, '', 0);
            $pdf->SetXY(120, $noteY + 7);
            $pdf->SetFont($selectedfont, '', 9);
            $pdf->MultiCell(80, $one, $logo['description'] ?? '', 0, 'L', 1, 2, null, null, true);
            $pdf->SetX(120);
            $pdf->MultiCell(80, $one, $logo['special_instruction'] ?? '', 0, 'L', 1, 2, null, null, true);
        } else {
            $noteY = 69 + $boxheight + 6;
            $pdf->RoundedRect(10, $noteY, $boxwidth, 50, 3.50, '1111', 'DF');
            $pdf->SetFont($selectedfont, 'B', 11);
            $pdf->SetXY(14, $noteY + 5);
            $pdf->Cell(80, $one, 'Notes :', 0, 1, 'L', 1, '', 0);
            $pdf->SetXY(14, $noteY + 12);
            $pdf->SetFont($selectedfont, '', 9);
            $pdf->MultiCell(80, $one, $logo['description'] ?? '', 0, 'L', 1, 2, null, null, true);
            $pdf->SetX(14);
            $pdf->MultiCell(80, $one, $logo['special_instruction'] ?? '', 0, 'L', 1, 2, null, null, true);
        }

        // 5. Logo Image
        $scale = $this->calculateDimensions($logo['designWidth'] ?? 1, $logo['designHeight'] ?? 1, 70, 70);
        $pdf->SetFillColor(255, 255, 255);
        $pdf->RoundedRect(115, 69, 85, 90, 3.50, '1111', 'DF');

        $logoImage = __DIR__ . '/../logo_uploads/' . $logoId . '_DST.PNG';
        if (file_exists($logoImage)) {
            $pdf->Image($logoImage, 118, 75, $scale['width'], $scale['height'], '', '', '', true, 150, '', false, false, 0, false, false, false);
        }

        // 6. Footer
        $printedDateTime = date('d/m/Y H:i:s');
        $footerY = $pdf->getPageHeight() - 15;
        $pdf->SetFont($selectedfont, '', 8);
        $pdf->SetXY(10, $footerY);
        $pdf->Cell(100, 5, "Print DateTime: $printedDateTime", 0, 0);
        $pdf->SetXY($pdf->getPageWidth() - 110, $footerY);
        $pdf->Cell(100, 5, "", 0, 0, 'R');

        return $pdf->Output($logoId . ".pdf", 'S');
    }

    /**
     * Download Logo ZIP
     * GET /customer/logo/download_zip?logo_id=xxx
     */
    public function download_logo_zip($args)
    {
        try {
            $logoId = trim($_GET['logo_id'] ?? ($args['logo_id'] ?? ''));
            if (empty($logoId)) {
                return $this->out(['status' => false, 'message' => 'logo_id is required'], 422);
            }

            // Get logo info
            $logo = $this->from('logos')->where('logo_id', $logoId)->fetch();

            if (!$logo) {
                return $this->out(['status' => false, 'message' => 'Logo not found'], 404);
            }

            $uploadDir = __DIR__ . '/../logo_uploads/';

            // Create a temporary ZIP file
            $tmpZipFile = tempnam(sys_get_temp_dir(), 'logo_zip_') . '.zip';
            $zip = new \ZipArchive();

            if ($zip->open($tmpZipFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                return $this->out(['status' => false, 'message' => 'Failed to create ZIP file'], 500);
            }

            // 1. Add Color Card PDF to ZIP
            try {
                $pdfData = $this->generateColorCardPdfContent($logoId);
                $zip->addFromString($logoId . '_color_card.pdf', $pdfData);
            } catch (\Exception $e) {
                // Ignore PDF generation error but maybe log it. We still want to return other files if possible.
                error_log("Failed to add PDF to logo zip for $logoId: " . $e->getMessage());
            }

            // 2. Add logo files
            if (!empty($logo['availabe_extentions'])) {
                $exts = explode(',', $logo['availabe_extentions']);
                foreach ($exts as $ext) {
                    $ext = trim($ext);
                    if (empty($ext))
                        continue;

                    // Depending on how extensions are saved, it might have an underscore like `_DST.PNG` or just `DST`
                    $fileName = $logoId . (strpos($ext, '_') === 0 ? '' : '.') . $ext;
                    $filePath = $uploadDir . $fileName;

                    if (file_exists($filePath)) {
                        $zip->addFile($filePath, $fileName);
                    }
                }
            }

            $zip->close();

            // 3. Check if file is valid
            if (!file_exists($tmpZipFile) || filesize($tmpZipFile) === 0) {
                return $this->out(['status' => false, 'message' => 'ZIP file is empty or missing'], 500);
            }

            // 4. Output the ZIP
            //ob_clean();
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $logoId . '_archive.zip"');
            header('Content-Length: ' . filesize($tmpZipFile));
            header('Pragma: no-cache');
            header('Expires: 0');
            readfile($tmpZipFile);

            // Cleanup
            unlink($tmpZipFile);
            exit;

        } catch (\Throwable $e) {
            return $this->out(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }
}
