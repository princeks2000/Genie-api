<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/index.php';
require_once __DIR__ . '/../define.php';
require_once __DIR__ . '/../handle.php';

// Mock PDO and DB
$host = DBHOST;
$dbname = DBNAME;
$user = DBUSER;
$pass = DBPASS;
$pdo = new PDO("mysql:host=" . $host . ";dbname=" . $dbname . ';charset=UTF8', $user, $pass);

// Autoload src classes
spl_autoload_register(function ($src) {
    if (file_exists(__DIR__ . '/../src/' . str_replace('\\', '/', $src) . '.php')) {
        require __DIR__ . '/../src/' . str_replace('\\', '/', $src) . '.php';
    }
});

$general = new general($pdo);

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

// 1. Create
$newId = test("Create Feature Request", function () use ($general) {
    // Mock request data using reflection to bypass protected property
    $ref = new ReflectionProperty($general, 'req');
    $ref->setAccessible(true);
    $ref->setValue($general, [
        'title' => 'Test Feature',
        'request' => 'This is a test request'
    ]);

    $res = $general->feature_request_save([]);
    $data = $res->getdata();
    if (!$data['status'])
        throw new Exception($data['message']);
    return $data['id'];
});

// 2. List
test("List Feature Requests", function () use ($general, $newId) {
    $res = $general->feature_request_list([]);
    $data = $res->getdata();
    if (!$data['status'])
        throw new Exception($data['message']);

    $found = false;
    foreach ($data['data'] as $item) {
        if ($item['id'] == $newId) {
            $found = true;
            break;
        }
    }
    if (!$found)
        throw new Exception("New feature request not found in list");
});

// 3. Retrieve
test("Retrieve Feature Request", function () use ($general, $newId) {
    $ref = new ReflectionProperty($general, 'req');
    $ref->setAccessible(true);
    $ref->setValue($general, ['id' => $newId]);

    $res = $general->feature_request_retrieve([]);
    $data = $res->getdata();
    if (!$data['status'])
        throw new Exception($data['message']);
    if ($data['data']['title'] !== 'Test Feature')
        throw new Exception("Title mismatch");
});

// 4. Update
test("Update Feature Request", function () use ($general, $newId) {
    $ref = new ReflectionProperty($general, 'req');
    $ref->setAccessible(true);
    $ref->setValue($general, [
        'id' => $newId,
        'title' => 'Updated Test Feature',
        'request' => 'Updated request'
    ]);

    $res = $general->feature_request_save([]);
    $data = $res->getdata();
    if (!$data['status'])
        throw new Exception($data['message']);

    // Verify update
    $ref->setValue($general, ['id' => $newId]);
    $res = $general->feature_request_retrieve([]);
    $data = $res->getdata();
    if ($data['data']['title'] !== 'Updated Test Feature')
        throw new Exception("Update failed");
});

// 5. Delete
test("Delete Feature Request", function () use ($general, $newId) {
    $ref = new ReflectionProperty($general, 'req');
    $ref->setAccessible(true);
    $ref->setValue($general, ['id' => $newId]);

    $res = $general->feature_request_delete([]);
    $data = $res->getdata();
    if (!$data['status'])
        throw new Exception($data['message']);

    // Verify delete
    $res = $general->feature_request_retrieve([]);
    if ($res->getstatus() !== 404)
        throw new Exception("Delete failed, still exists");
});

echo "\nAll CRUD tests passed!\n";
