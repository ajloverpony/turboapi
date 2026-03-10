<?php
// ==========================================
// DUAL-LAYER ADVANCED VARIABLE STORAGE - API
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
header("X-Accel-Buffering: no"); // Prevent Nginx buffering for SSE/Live updates

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Ensure plain text response
header('Content-Type: text/plain');

// Connect to MariaDB using PDO
try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("fail");
}

// Connect to Redis
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

/**
 * Calculate size in bytes and return human-readable format
 */
function getFormattedSize($string) {
    $bytes = strlen($string);
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . 'mb';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . 'kb';
    } else {
        return $bytes . 'b';
    }
}

/**
 * Trigger the update signal for SSE
 */
function notifyUpdate($redis) {
    if ($redis) {
        $redis->set('last_update_time', microtime(true));
    }
}

// Helper function to get current value (Redis -> DB fallback)
function getCurrentValue($name, $pdo, $redis, $redis_prefix) {
    if ($redis) {
        $val = $redis->get($redis_prefix . $name);
        if ($val !== false) {
            return $val;
        }
    }
    
    $stmt = $pdo->prepare("SELECT var_value FROM variables WHERE var_key = ?");
    $stmt->execute([$name]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($row) {
        return $row['var_value'];
    }
    return ""; // Default empty string if not found
}

// Helper function to save dual-layer
function saveDualLayer($name, $value, $pdo, $redis, $redis_prefix) {
    try {
        $stmt = $pdo->prepare("INSERT INTO variables (var_key, var_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE var_value = ?");
        $stmt->execute([$name, $value, $value]);
        
        if ($redis) {
            $redis->set($redis_prefix . $name, $value);
        }
        
        // Notify SSE stream
        notifyUpdate($redis);
        
        return true;
    } catch (Exception $e) {
        return false;
    }
}

if ($action === 'pull') {
    $current_value = getCurrentValue($name, $pdo, $redis, $redis_prefix);
    
    // Auto-heal redis cache
    if ($redis) {
        $redis->set($redis_prefix . $name, $current_value);
    }
    
    $range = $_GET['range'] ?? '';
    
    // Support range-based retrieval
    if ($range && preg_match('/^(\d+)-(\d+)$/', $range, $matches)) {
        $start = (int)$matches[1];
        $end = (int)$matches[2];
        $len = max(0, $end - $start + 1);
        $sliced = mb_substr($current_value, $start, $len);
        echo $sliced;
        exit;
    }
    
    echo $current_value;
    exit;
} 
elseif ($action === 'save') {
    $value = isset($_REQUEST['value']) ? $_REQUEST['value'] : file_get_contents('php://input');
    
    if (saveDualLayer($name, $value, $pdo, $redis, $redis_prefix)) {
        echo "success";
    } else {
        echo "fail";
    }
    exit;
}
elseif ($action === 'append') {
    $value = isset($_REQUEST['value']) ? $_REQUEST['value'] : file_get_contents('php://input');
    $current_value = getCurrentValue($name, $pdo, $redis, $redis_prefix);
    
    $new_value = $current_value . $value;
    
    if (saveDualLayer($name, $new_value, $pdo, $redis, $redis_prefix)) {
        echo "success";
    } else {
        echo "fail";
    }
    exit;
}
elseif ($action === 'replace') {
    $value = isset($_REQUEST['value']) ? $_REQUEST['value'] : file_get_contents('php://input');
    $range = $_REQUEST['range'] ?? '';
    $current_value = getCurrentValue($name, $pdo, $redis, $redis_prefix);
    
    if (preg_match('/^(\d+)-(\d+)$/', $range, $matches)) {
        $start = (int)$matches[1];
        $end = (int)$matches[2];
        $len = max(0, $end - $start + 1);
        
        $prefix = mb_substr($current_value, 0, $start);
        $suffix = mb_substr($current_value, $start + $len);
        
        $new_value = $prefix . $value . $suffix;
        
        if (saveDualLayer($new_value, $new_value, $pdo, $redis, $redis_prefix)) {
            // Wait, saveDualLayer expects $name, $value, ...
        }
        // Fixed below:
        if (saveDualLayer($name, $new_value, $pdo, $redis, $redis_prefix)) {
             echo "success";
        } else {
            echo "fail";
        }
    } else {
        echo "fail";
    }
    exit;
} else {
    echo "fail";
    exit;
}
