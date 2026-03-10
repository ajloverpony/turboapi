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

// Turn off all output buffering
while (ob_get_level() > 0) {
    ob_end_flush();
}
ob_implicit_flush(true);

// Set memory limit and execution time
ini_set('memory_limit', '128M');
set_time_limit(60); // Let it run for 1 minute max then exit to recycle

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
// We initialize it to 0 so the first run ALWAYS sends the current state
$lastReportedTime = 0; 

$startTime = time();
// Run for about 50 seconds then exit (browser will reconnect)
while(time() - $startTime < 50) {
    // Check if the browser requested a disconnect
    if (connection_aborted()) break;

    $currentTime = $redis->get('tw_last_update_time');

    if ($currentTime) {
        // Compare as strings to be safe with float precision if needed
        if ((string)$currentTime !== (string)$lastReportedTime) {
            $lastReportedTime = $currentTime;
            echo "event: update\n";
            echo "data: " . json_encode(['update' => true, 'timestamp' => $currentTime]) . "\n\n";
            ob_flush();
            flush();
        }
    }

    // Small keep-alive ping (comment line)
    echo ": ping\n\n";
    ob_flush();
    flush();

    // Poll every 500ms (balanced for speed vs server load)
    usleep(500000); 
}
