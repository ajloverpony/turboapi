<?php
// ==========================================
// PORTABLE VARIABLE STORAGE - Web GUI (Upgrade)
// ==========================================
// Enter your CloudPanel database credentials here:
$db_host = '127.0.0.1';
$db_name = 'turboapi';
$db_user = 'turboapi';
$db_pass = 'hzO4AX2Z2qXrE2JqcTOA';

// Redis configuration
$redis_host = '127.0.0.1';
$redis_port = 6379;
$redis_prefix = 'tw_var_';

// Determine base API URL
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
$domainName = $_SERVER['HTTP_HOST'];
$api_url_base = rtrim($protocol . $domainName . '/' . ltrim(dirname($_SERVER['SCRIPT_NAME']), '/'), '/') . '/api.php';

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
 * Trigger update for SSE
 */
function triggerUpdate($pdo, $redis) {
    if ($redis) {
        $redis->set('last_update_time', microtime(true));
    }
}

// Connect to MariaDB
$pdo = null;
try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $mariadb_status = 'Connected';
} catch (PDOException $e) {
    $mariadb_status = 'Error';
}

// Connect to Redis
$redis = null;
try {
    if (class_exists('Redis')) {
        $redis = new Redis();
        if ($redis->connect($redis_host, $redis_port)) {
            $redis_status = 'Connected';
        } else {
            $redis_status = 'Error';
            $redis = null;
        }
    } else {
        $redis_status = 'Missing Extension';
    }
} catch (Exception $e) {
    $redis_status = 'Error';
}

// Handle AJAX refresh request
if (isset($_GET['refresh'])) {
    renderTable($pdo, $redis, $redis_prefix, $api_url_base);
    exit;
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $key = $_POST['var_key'] ?? '';
    $value = $_POST['var_value'] ?? '';

    if ($action === 'save' && trim($key) !== '') {
        $stmt = $pdo->prepare("INSERT INTO variables (var_key, var_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE var_value = ?");
        $stmt->execute([$key, $value, $value]);
        if ($redis) $redis->set($redis_prefix . $key, $value);
        triggerUpdate($pdo, $redis);
    } elseif ($action === 'delete') {
        $stmt = $pdo->prepare("DELETE FROM variables WHERE var_key = ?");
        $stmt->execute([$key]);
        if ($redis) $redis->del($redis_prefix . $key);
        triggerUpdate($pdo, $redis);
    }
}

/**
 * Render the variables table content
 */
function renderTable($pdo, $redis, $redis_prefix, $api_url_base) {
    if (!$pdo) return;
    $stmt = $pdo->query("SELECT var_key, var_value, updated_at FROM variables ORDER BY var_key ASC");
    $variables = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($variables)) {
        echo '<tr><td colspan="4" class="px-6 py-12 text-center text-slate-500 italic">No variables found.</td></tr>';
        return;
    }

    foreach ($variables as $v) {
        $in_redis = $redis && $redis->exists($redis_prefix . $v['var_key']);
        $size = getFormattedSize($v['var_value']);
        $pull_url = $api_url_base . "?action=pull&name=" . urlencode($v['key'] ?? $v['var_key']);
        ?>
        <tr class="hover:bg-slate-800/40 transition-colors border-b border-slate-700/50">
            <td class="px-6 py-4 font-mono text-sm text-blue-400 font-bold"><?= htmlspecialchars($v['var_key']) ?></td>
            <td class="px-6 py-4">
                <div class="flex items-center gap-2">
                    <span class="max-w-[200px] truncate block opacity-70"><?= htmlspecialchars($v['var_value']) ?></span>
                    <span class="text-[10px] bg-indigo-500/20 text-indigo-300 px-1.5 py-0.5 rounded font-bold uppercase"><?= $size ?></span>
                </div>
            </td>
            <td class="px-6 py-4">
                <div class="flex flex-col gap-1">
                    <span class="text-[10px] text-slate-500 uppercase">Upd: <?= date('H:i:s', strtotime($v['updated_at'])) ?></span>
                    <div class="flex gap-1">
                        <span class="w-2 h-2 rounded-full bg-blue-500" title="SSD"></span>
                        <?php if ($in_redis): ?><span class="w-2 h-2 rounded-full bg-emerald-500" title="Memory"></span><?php endif; ?>
                    </div>
                </div>
            </td>
            <td class="px-6 py-4 text-right">
                <div class="flex justify-end gap-2">
                    <button onclick="copyToClipboard('<?= $pull_url ?>', this)" class="p-2 bg-slate-700 hover:bg-emerald-600 rounded text-slate-300 transition-all" title="Copy Pull URL">
                       <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"></path></svg>
                    </button>
                    <button onclick="editVar('<?= addslashes($v['var_key']) ?>', '<?= addslashes($v['var_value']) ?>')" class="p-2 bg-slate-700 hover:bg-blue-600 rounded text-slate-300 transition-all">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>
                    </button>
                    <form method="POST" style="display:inline">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="var_key" value="<?= htmlspecialchars($v['var_key']) ?>">
                        <button type="submit" class="p-2 bg-slate-700 hover:bg-red-600 rounded text-slate-300 transition-all">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                        </button>
                    </form>
                </div>
            </td>
        </tr>
        <?php
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Live Variable Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&family=JetBrains+Mono&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: #0b0f1a; color: #e2e8f0; }
        .glass { background: rgba(30, 41, 59, 0.7); backdrop-filter: blur(10px); border: 1px solid rgba(255, 255, 255, 0.05); }
        .mono { font-family: 'JetBrains Mono', monospace; }
        .btn-glow { transition: all 0.3s; }
        .btn-glow:hover { box-shadow: 0 0 15px rgba(59, 130, 246, 0.5); transform: translateY(-1px); }
    </style>
</head>
<body class="p-4 md:p-10">
    <div class="max-w-6xl mx-auto">
        <!-- Header -->
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-6 mb-12">
            <div>
                <h1 class="text-4xl font-extrabold tracking-tight bg-clip-text text-transparent bg-gradient-to-r from-blue-400 to-emerald-400 mb-2">Dual-Layer Storage</h1>
                <p class="text-slate-400 font-medium">Real-time variables with SSD/Memory persistence.</p>
            </div>
            
            <div class="flex gap-4">
                <div class="glass px-4 py-2 rounded-xl flex items-center gap-3">
                    <div id="sse-status" class="w-2.5 h-2.5 rounded-full bg-slate-600 animate-pulse"></div>
                    <span class="text-xs font-bold tracking-widest uppercase opacity-60">Live Stream</span>
                </div>
                <div class="glass px-4 py-2 rounded-xl flex items-center gap-2">
                    <span class="text-[10px] uppercase font-bold text-slate-500">Node:</span>
                    <span class="text-xs font-mono text-emerald-400">CloudPanel-PRO</span>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-4 gap-8">
            <!-- Sidebar: Editor -->
            <div class="lg:col-span-1 space-y-6">
                <div class="glass p-6 rounded-2xl">
                    <h2 class="text-lg font-bold mb-6 flex items-center gap-2">
                        <svg class="w-5 h-5 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v3m0 0v3m0-3h3m-3 0H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                        Quick Edit
                    </h2>
                    <form method="POST" id="editForm" class="space-y-4">
                        <input type="hidden" name="action" value="save">
                        <div>
                            <label class="block text-[10px] font-bold uppercase text-slate-500 mb-1 ml-1">Key Name</label>
                            <input type="text" name="var_key" id="var_key" required class="w-full bg-slate-900/50 border border-slate-700 rounded-lg px-3 py-2 text-sm mono focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="player_score">
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold uppercase text-slate-500 mb-1 ml-1">Value</label>
                            <textarea name="var_value" id="var_value" rows="4" class="w-full bg-slate-900/50 border border-slate-700 rounded-lg px-3 py-2 text-sm mono focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="1000"></textarea>
                        </div>
                        <button type="submit" class="w-full bg-blue-600 hover:bg-blue-500 text-white font-bold py-3 rounded-xl btn-glow flex justify-center items-center gap-2">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4"></path></svg>
                            Commit Sync
                        </button>
                    </form>
                </div>
            </div>

            <!-- Main: Variables Table -->
            <div class="lg:col-span-3">
                <div class="glass rounded-3xl overflow-hidden shadow-2xl">
                    <div class="px-8 py-6 bg-slate-800/20 flex justify-between items-center border-b border-slate-700/50">
                        <h3 class="font-bold flex items-center gap-3">
                            <span class="w-2 h-2 rounded-full bg-emerald-500 shadow-[0_0_10px_#10b981]"></span>
                            Live Registry
                        </h3>
                        <a href="docs.php" class="text-xs font-bold text-blue-400 hover:underline">View API Syntax</a>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead>
                                <tr class="text-left text-[10px] uppercase font-bold text-slate-500 bg-slate-900/30">
                                    <th class="px-6 py-4">Variable Key</th>
                                    <th class="px-6 py-4">Live Content / Metrics</th>
                                    <th class="px-6 py-4">Storage</th>
                                    <th class="px-6 py-4 text-right">Utility</th>
                                </tr>
                            </thead>
                            <tbody id="variables-table">
                                <?= renderTable($pdo, $redis, $redis_prefix, $api_url_base) ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Copy to clipboard helper
        function copyToClipboard(text, btn) {
            navigator.clipboard.writeText(text);
            const original = btn.innerHTML;
            btn.innerHTML = '<svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>';
            setTimeout(() => btn.innerHTML = original, 2000);
        }

        // Edit helper
        function editVar(key, val) {
            document.getElementById('var_key').value = key;
            document.getElementById('var_value').value = val;
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        // Live Dynamic Refresh
        function refreshTable() {
            const table = document.getElementById('variables-table');
            fetch('?refresh=1')
                .then(res => res.text())
                .then(html => {
                    table.innerHTML = html;
                    console.log("Table Sync Completed.");
                });
        }

        // Initialize SSE
        const evtSource = new EventSource("events.php");
        const dot = document.getElementById('sse-status');

        evtSource.onmessage = (event) => {
            const data = JSON.parse(event.data);
            if (data.updated) {
                refreshTable();
                dot.classList.replace('bg-slate-600', 'bg-emerald-500');
                setTimeout(() => dot.classList.replace('bg-emerald-500', 'bg-slate-600'), 1000);
            }
        };

        evtSource.onerror = (err) => {
            dot.classList.replace('bg-slate-600', 'bg-red-500');
        };
    </script>
</body>
</html>
