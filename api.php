<?php
// ==========================================
// PORTABLE VARIABLE STORAGE - API
// ==========================================
// Enter your CloudPanel database credentials here:
$db_host = '127.0.0.1';
$db_name = 'turboapi';
$db_user = 'turboapi';
$db_pass = 'hzO4AX2Z2qXrE2JqcTOA';

// Redis configuration (Fast Memory Storage)
$redis_host = '127.0.0.1';
$redis_port = 6379;
$redis_prefix = 'tw_var_'; // Prefix to avoid key collisions

// Enable CORS for TurboWarp
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Ensure JSON response
header('Content-Type: application/json');

// Connect to MariaDB using PDO
try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

// Connect to Redis (wrapped in try-catch to prevent crashing if not enabled)
$redis = null;
try {
    if (class_exists('Redis')) {
        $redis = new Redis();
        if (!$redis->connect($redis_host, $redis_port)) {
            $redis = null;
        }
    }
} catch (Exception $e) {
    // Redis not available, stay with $redis = null
    $redis = null;
}

function sendResponse($success, $data = [], $message = '') {
    echo json_encode([
        'success' => $success,
        'data' => $data,
        'message' => $message
    ]);
    exit;
}

$action = $_GET['action'] ?? '';
$name = $_REQUEST['name'] ?? '';

if (!$action || !$name) {
    sendResponse(false, [], 'Missing action or name parameter. Use ?action=get&name=myvar or action=set');
}

if ($action === 'get') {
    // 1. Try Redis first (fast)
    if ($redis) {
        $val = $redis->get($redis_prefix . $name);
        if ($val !== false) {
            sendResponse(true, ['name' => $name, 'value' => $val, 'source' => 'redis']);
        }
    }
    
    // 2. Fallback to MariaDB (persistent)
    $stmt = $pdo->prepare("SELECT value FROM variables WHERE name = ?");
    $stmt->execute([$name]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($row) {
        sendResponse(true, ['name' => $name, 'value' => $row['value'], 'source' => 'mariadb']);
    } else {
        // Variable not found, sending empty value rather than failing allows client to act on defaults
        sendResponse(true, ['name' => $name, 'value' => null, 'source' => 'none'], 'Variable not found');
    }
} 
elseif ($action === 'set') {
    // Read value from request (either standard POST var or raw body)
    $value = isset($_REQUEST['value']) ? $_REQUEST['value'] : file_get_contents('php://input');
    
    // Determine target storage. Client can define it via &storage=redis/mariadb. Default to MariaDB.
    $storage = strtolower($_REQUEST['storage'] ?? 'mariadb');
    
    if ($storage === 'redis') {
        if ($redis) {
            $redis->set($redis_prefix . $name, $value);
            // Optionally remove from MariaDB so there are no overlapping shadows
            $stmt = $pdo->prepare("DELETE FROM variables WHERE name = ?");
            $stmt->execute([$name]);
            sendResponse(true, ['name' => $name, 'storage' => 'redis']);
        } else {
            sendResponse(false, [], 'Redis is not connected on this server, could not save.');
        }
    } else {
        // Fallback or explicit 'mariadb'
        $stmt = $pdo->prepare("INSERT INTO variables (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = ?");
        $stmt->execute([$name, $value, $value]);
        
        // Remove from redis if exists to prevent stale cached data
        if ($redis) {
            $redis->del($redis_prefix . $name);
        }
        sendResponse(true, ['name' => $name, 'storage' => 'mariadb']);
    }
} else {
    sendResponse(false, [], 'Unknown action. Supported actions: get, set.');
}
