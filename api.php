<?php
// ==========================================
// DUAL-LAYER VARIABLE STORAGE - API
// ==========================================
// Enter your CloudPanel database credentials here:
$db_host = '127.0.0.1';
$db_name = 'your_database_name';
$db_user = 'your_database_user';
$db_pass = 'your_database_password';

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

// Connect to MariaDB using PDO
try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("fail");
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
    $redis = null;
}

$action = $_GET['action'] ?? '';
$name = $_REQUEST['name'] ?? '';

if (!$action || !$name) {
    die("fail");
}

if ($action === 'pull') {
    // Ensure plain text response
    header('Content-Type: text/plain');
    
    // 1. Try Redis first (fast Memory)
    if ($redis) {
        $val = $redis->get($redis_prefix . $name);
        if ($val !== false) {
            echo $val;
            exit;
        }
    }
    
    // 2. Fallback to MariaDB (SSD)
    $stmt = $pdo->prepare("SELECT var_value FROM variables WHERE var_key = ?");
    $stmt->execute([$name]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($row) {
        $value = $row['var_value'];
        
        // Update Redis for next time
        if ($redis) {
            $redis->set($redis_prefix . $name, $value);
        }
        
        echo $value;
        exit;
    } else {
        // Variable not found, sending empty text
        echo "";
        exit;
    }
} 
elseif ($action === 'save') {
    // Ensure plain text response
    header('Content-Type: text/plain');
    
    // Read value from request (either standard POST value or raw body)
    $value = isset($_REQUEST['value']) ? $_REQUEST['value'] : file_get_contents('php://input');
    
    try {
        // Dual-write: MariaDB first
        $stmt = $pdo->prepare("INSERT INTO variables (var_key, var_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE var_value = ?");
        $stmt->execute([$name, $value, $value]);
        
        // Dual-write: Redis second
        if ($redis) {
            $redis->set($redis_prefix . $name, $value);
        }
        
        echo "success";
        exit;
    } catch (Exception $e) {
        echo "fail";
        exit;
    }
} else {
    echo "fail";
    exit;
}
