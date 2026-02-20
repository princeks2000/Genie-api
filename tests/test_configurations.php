<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/index.php';
require_once __DIR__ . '/../define.php';
require_once __DIR__ . '/../handle.php';

$pdo = new PDO(
    "mysql:host=" . DBHOST . ";dbname=" . DBNAME . ';charset=UTF8',
    DBUSER,
    DBPASS
);

spl_autoload_register(function ($class) {
    $file = __DIR__ . '/../src/' . str_replace('\\', '/', $class) . '.php';
    if (file_exists($file))
        require $file;
});

$s = new settings($pdo);

function test($name, $callback)
{
    echo "Testing $name... ";
    try {
        $result = $callback();
        echo "PASSED\n";
        return $result;
    } catch (Exception $e) {
        echo "FAILED: " . $e->getMessage() . "\n";
        exit(1);
    }
}

function setReq($obj, $data)
{
    $ref = new ReflectionProperty($obj, 'req');
    $ref->setAccessible(true);
    $ref->setValue($obj, $data);
}

// 1. Setup
test("Setup configurations table", function () use ($s) {
    setReq($s, []);
    $res = $s->configurations_setup([]);
    $data = $res->getdata();
    if (!$data['status'])
        throw new Exception($data['message']);
});

// 2. Save a JSON object
test("Save configuration with JSON value", function () use ($s) {
    setReq($s, [
        'items' => [
            [
                '_key' => 'test_config',
                '_value' => ['theme' => 'dark', 'max_retry' => 3, 'features' => ['a', 'b']],
                'description' => 'A test configuration object',
            ]
        ]
    ]);
    $res = $s->configurations_save([]);
    $data = $res->getdata();
    if (!$data['status'])
        throw new Exception($data['message']);
    if ($data['count'] !== 1)
        throw new Exception('Expected count 1, got ' . $data['count']);
    $item = $data['items'][0];
    if (!is_array($item['_value']))
        throw new Exception('_value should be decoded as array');
    if ($item['_value']['theme'] !== 'dark')
        throw new Exception('theme value mismatch');
    if ($item['_value']['max_retry'] !== 3)
        throw new Exception('max_retry value mismatch');
});

// 3. Retrieve and verify JSON decoding
test("Retrieve configuration and verify JSON decode", function () use ($s) {
    setReq($s, ['keys' => ['test_config']]);
    $res = $s->configurations_retrieve([]);
    $data = $res->getdata();
    if (!$data['status'])
        throw new Exception($data['message']);
    if (count($data['items']) !== 1)
        throw new Exception('Expected 1 item');
    $val = $data['items'][0]['_value'];
    if (!is_array($val))
        throw new Exception('_value should be array');
    if ($val['theme'] !== 'dark')
        throw new Exception('theme mismatch after retrieve');
    if ($val['features'] !== ['a', 'b'])
        throw new Exception('features mismatch after retrieve');
});

// 4. Update (overwrite via save)
test("Update configuration value", function () use ($s) {
    setReq($s, [
        'items' => [
            [
                '_key' => 'test_config',
                '_value' => ['theme' => 'light', 'max_retry' => 5],
            ]
        ]
    ]);
    $res = $s->configurations_save([]);
    $data = $res->getdata();
    if (!$data['status'])
        throw new Exception($data['message']);
    if ($data['items'][0]['_value']['theme'] !== 'light')
        throw new Exception('Update failed: theme still dark');
});

// 5. Delete
test("Delete configuration by key", function () use ($s) {
    setReq($s, ['key' => 'test_config']);
    $res = $s->configurations_delete([]);
    $data = $res->getdata();
    if (!$data['status'])
        throw new Exception($data['message']);

    // Verify it is gone
    setReq($s, ['keys' => ['test_config']]);
    $res2 = $s->configurations_retrieve([]);
    $data2 = $res2->getdata();
    if (count($data2['items']) !== 0)
        throw new Exception('Delete failed, record still exists');
});

echo "\nAll configurations tests passed!\n";
