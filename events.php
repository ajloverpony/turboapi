<?php
/**
 * events.php - Server-Sent Events (SSE) Stream
 * Watches Redis for the 'last_update_time' key to notify dashboard clients.
 */

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no'); // Crucial for Nginx/CloudPanel real-time streaming

// Redis connection
$redis_host = '127.0.0.1';
$redis_port = 6379;

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
    die("data: {\"error\": \"Redis unavailable\"}\n\n");
}

$last_seen = 0;

// Poll Redis in a loop
while (true) {
    if (connection_aborted()) break;

    $update_time = $redis->get('last_update_time');

    if ($update_time && $update_time > $last_seen) {
        $last_seen = $update_time;
        echo "data: " . json_encode(['updated' => true, 'timestamp' => $update_time]) . "\n\n";
    }

    // Small sleep to prevent CPU spikes, but fast enough for "real-time" feel
    flush(); 
    usleep(250000); // 0.25 seconds
}
