<?php
// ==========================================
// DUAL-LAYER VARIABLE STORAGE - SSE Stream
// ==========================================
// Redis configuration
$redis_host = '127.0.0.1';
$redis_port = 6379;

// Nginx buffering bypass headers
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no'); 

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

if (!$redis) {
    echo "event: error\ndata: Redis unavailable\n\n";
    flush();
    exit;
}

// Track the last known update time sent to browser
$lastReportedTime = $redis->get('tw_last_update_time') ?: 0;

// SSE Loop
while(true) {
    if (connection_aborted()) break;

    $currentTime = $redis->get('tw_last_update_time');

    if ($currentTime && $currentTime > $lastReportedTime) {
        $lastReportedTime = $currentTime;
        echo "data: " . json_encode(['update' => true, 'timestamp' => $currentTime]) . "\n\n";
        ob_flush();
        flush();
    }

    // Small keep-alive ping
    echo ": ping\n\n";
    ob_flush();
    flush();

    // Poll every 0.25s
    usleep(250000); 
}
