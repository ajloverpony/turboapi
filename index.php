<?php
// ==========================================
// PORTABLE VARIABLE STORAGE - Web GUI
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

// Determine base API URL for the Copy Link feature
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
$domainName = $_SERVER['HTTP_HOST'];
$api_url_base = rtrim($protocol . $domainName . '/' . ltrim(dirname($_SERVER['SCRIPT_NAME']), '/'), '/') . '/api.php';

/**
 * Format bytes into human readable size
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
 * Notifies the dashboard of an update via Redis flag
 */
function triggerUpdate($redis) {
    if ($redis) {
        $redis->set('tw_last_update_time', microtime(true));
    }
}

// Connect to MariaDB
$mariadb_status = 'Disconnected';
$pdo = null;
try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $mariadb_status = 'Connected';
} catch (PDOException $e) {
    if ($db_name == 'turboapi_placeholder') { // Default string
        $mariadb_status = 'Pending Setup';
    } else {
        $mariadb_status = 'Error: ' . $e->getMessage();
    }
}

// Connect to Redis
$redis = null;
$redis_status = 'Unavailable';
try {
    if (class_exists('Redis')) {
        $redis = new Redis();
        if ($redis->connect($redis_host, $redis_port)) {
            $redis_status = 'Connected';
        } else {
            $redis_status = 'Connection Failed';
            $redis = null;
        }
    } else {
        $redis_status = 'Redis extension not loaded';
    }
} catch (Exception $e) {
    $redis_status = 'Error: ' . $e->getMessage();
    $redis = null;
}

// Handle AJAX Table Refresh
$isAjax = isset($_GET['ajax']) && $_GET['ajax'] === '1';

// Handle form submissions
$message = '';
$messageType = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$isAjax) {
    $action = $_POST['action'] ?? '';
    $key = $_POST['var_key'] ?? '';
    
    if ($action === 'save') {
        $value = $_POST['var_value'] ?? '';
        if (trim($key) !== '') {
            try {
                if ($pdo) {
                    $stmt = $pdo->prepare("INSERT INTO variables (var_key, var_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE var_value = ?");
                    $stmt->execute([$key, $value, $value]);
                    
                    if ($redis) {
                        $redis->set($redis_prefix . $key, $value);
                        triggerUpdate($redis);
                    }
                    $message = "Variable '$key' saved successfully.";
                    $messageType = 'success';
                }
            } catch (Exception $e) {
                $message = 'Error: ' . $e->getMessage();
                $messageType = 'error';
            }
        }
    } elseif ($action === 'delete' && trim($key) !== '') {
        try {
            if ($pdo) {
                $stmt = $pdo->prepare("DELETE FROM variables WHERE var_key = ?");
                $stmt->execute([$key]);
            }
            if ($redis) {
                $redis->del($redis_prefix . $key);
                triggerUpdate($redis);
            }
            $message = "Variable '$key' deleted.";
            $messageType = 'success';
        } catch (Exception $e) {
            $message = 'Error: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}

// Fetch variables
$variables = [];
if ($pdo && $mariadb_status === 'Connected') {
    try {
        $stmt = $pdo->query("SELECT var_key, var_value, updated_at FROM variables ORDER BY var_key ASC");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $in_redis = false;
            if ($redis && $redis->exists($redis_prefix . $row['var_key'])) {
                $in_redis = true;
            }
            $variables[] = [
                'key' => $row['var_key'],
                'value' => $row['var_value'],
                'updated_at' => $row['updated_at'],
                'in_redis' => $in_redis,
                'size' => getFormattedSize($row['var_value'])
            ];
        }
    } catch (PDOException $e) {}
}

// Render Table Rows (for initial load and AJAX refresh)
function renderTableRows($variables, $api_url_base) {
    if (empty($variables)) {
        return '<tr><td colspan="4" class="px-6 py-16 text-center text-slate-500 italic">No variables stored.</td></tr>';
    }
    $html = '';
    foreach ($variables as $v) {
        $apiPullUrl = $api_url_base . '?action=pull&name=' . urlencode($v['key']);
        $html .= '<tr class="hover:bg-slate-800/50 transition-colors group">';
        $html .= '<td class="px-6 py-4">';
        $html .= '<div class="font-mono text-sm text-blue-300 font-semibold mb-1">' . htmlspecialchars($v['key']) . '</div>';
        $html .= '<div class="text-[10px] text-slate-500 font-medium uppercase tracking-wider">Upd: ' . $v['updated_at'] . '</div>';
        $html .= '</td>';
        $html .= '<td class="px-6 py-4">';
        $html .= '<div class="flex items-center gap-2">';
        $html .= '<div class="max-w-[200px] bg-slate-900/50 p-2.5 rounded-lg border border-slate-700/50 font-mono text-sm text-slate-300 overflow-hidden text-ellipsis whitespace-nowrap" title="' . htmlspecialchars($v['value']) . '">' . htmlspecialchars($v['value']) . '</div>';
        $html .= '<span class="text-[10px] bg-indigo-500/20 text-indigo-300 px-1.5 py-0.5 rounded font-bold">' . $v['size'] . '</span>';
        $html .= '</div>';
        $html .= '</td>';
        $html .= '<td class="px-6 py-4 text-center">';
        $html .= '<div class="flex flex-col gap-1 items-center">';
        $html .= '<span class="inline-flex items-center gap-1.5 px-2 py-1 rounded-md bg-blue-500/10 text-blue-400 border border-blue-500/20 text-[10px] font-bold uppercase tracking-wider">SSD</span>';
        if ($v['in_redis']) {
            $html .= '<span class="inline-flex items-center gap-1.5 px-2 py-1 rounded-md bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 text-[10px] font-bold uppercase tracking-wider">Memory</span>';
        }
        $html .= '</div>';
        $html .= '</td>';
        $html .= '<td class="px-6 py-4 text-right">';
        $html .= '<div class="flex items-center justify-end gap-2 opacity-60 group-hover:opacity-100 transition-opacity">';
        $html .= '<button type="button" onclick="copyApiUrl(\'' . $apiPullUrl . '\', this)" class="p-2 text-emerald-400 bg-emerald-400/5 hover:bg-emerald-400/20 border border-transparent hover:border-emerald-400/30 rounded-xl transition-all" title="Copy Pull Link"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"></path></svg></button>';
        $html .= '<button type="button" onclick="editVar(\'' . addslashes($v['key']) . '\', \'' . addslashes($v['value']) . '\')" class="p-2 text-blue-400 bg-blue-400/5 hover:bg-blue-400/20 border border-transparent hover:border-blue-400/30 rounded-xl transition-all" title="Edit"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg></button>';
        $html .= '<form method="POST" class="inline" onsubmit="return confirm(\'Delete variable?\');"><input type="hidden" name="action" value="delete"><input type="hidden" name="var_key" value="' . htmlspecialchars($v['key']) . '"><button type="submit" class="p-2 text-red-400 bg-red-400/5 hover:bg-red-400/20 border border-transparent hover:border-red-400/30 rounded-xl transition-all"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg></button></form>';
        $html .= '</div>';
        $html .= '</td></tr>';
    }
    return $html;
}

if ($isAjax) {
    echo renderTableRows($variables, $api_url_base);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dual-Layer Variable Storage</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { background: #0f172a; color: #f8fafc; font-family: 'Inter', sans-serif; }
        .glass { background: rgba(30, 41, 59, 0.7); backdrop-filter: blur(10px); border: 1px solid rgba(255, 255, 255, 0.1); }
        .update-flash { animation: flash-green 1s ease-out; }
        @keyframes flash-green { 0% { background-color: rgba(16, 185, 129, 0.2); } 100% { background-color: transparent; } }
    </style>
</head>
<body class="p-4 md:p-8">
    <div class="max-w-6xl mx-auto">
        <header class="flex flex-col md:flex-row justify-between items-center mb-10 pb-6 border-b border-slate-700">
            <div>
                <h1 class="text-3xl font-extrabold bg-clip-text text-transparent bg-gradient-to-r from-blue-400 to-indigo-500 mb-2">Dual-Layer Variable Storage</h1>
                <div class="flex items-center gap-4">
                    <p class="text-slate-400 font-medium tracking-wide">TurboWarp SSD/Memory Sync Service</p>
                    <a href="docs.php" class="px-3 py-1 bg-slate-800 hover:bg-slate-700 border border-slate-600 rounded-md text-xs text-slate-300 font-bold tracking-wider transition-colors inline-flex items-center gap-1.5">
                        <svg class="w-3.5 h-3.5 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path></svg>
                        API DOCS
                    </a>
                </div>
            </div>
            
            <div class="flex flex-col gap-2 mt-4 md:mt-0 text-sm">
                <div class="flex items-center gap-3 px-4 py-2 rounded-full bg-slate-800/80 border <?= $mariadb_status === 'Connected' ? 'border-green-500/50' : 'border-red-500/50' ?> shadow-lg">
                    <div class="w-3 h-3 rounded-full <?= $mariadb_status === 'Connected' ? 'bg-green-500 animate-pulse shadow-[0_0_8px_rgba(34,197,94,0.8)]' : 'bg-red-500 shadow-[0_0_8px_rgba(239,68,68,0.8)]' ?>"></div>
                    <span class="text-slate-300 font-medium tracking-wider text-xs uppercase">SSD: <span class="<?= $mariadb_status === 'Connected' ? 'text-green-400' : 'text-red-400' ?>"><?= htmlspecialchars($mariadb_status) ?></span></span>
                </div>
                <div class="flex items-center gap-3 px-4 py-2 rounded-full bg-slate-800/80 border <?= $redis_status === 'Connected' ? 'border-green-500/50' : 'border-yellow-500/50' ?> shadow-lg">
                    <div id="redis-pulse" class="w-3 h-3 rounded-full <?= $redis_status === 'Connected' ? 'bg-green-500 shadow-[0_0_8px_rgba(34,197,94,0.8)]' : 'bg-yellow-500 shadow-[0_0_8px_rgba(234,179,8,0.8)]' ?>"></div>
                    <span class="text-slate-300 font-medium tracking-wider text-xs uppercase">Memory: <span class="<?= $redis_status === 'Connected' ? 'text-green-400' : 'text-yellow-400' ?>"><?= htmlspecialchars($redis_status) ?></span></span>
                </div>
            </div>
        </header>

        <?php if ($message): ?>
            <div class="mb-8 p-4 rounded-xl <?= $messageType === 'success' ? 'bg-green-900/30 border border-green-500/50 text-green-300' : 'bg-red-900/30 border border-red-500/50 text-red-300' ?> shadow-lg flex items-center gap-3 animate-fade-in">
                <span class="font-medium"><?= htmlspecialchars($message) ?></span>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <div class="lg:col-span-1">
                <div class="glass p-6 rounded-2xl sticky top-8">
                    <h2 class="text-xl font-bold mb-6 flex items-center gap-2">Save Variable</h2>
                    <form method="POST">
                        <input type="hidden" name="action" value="save">
                        <div class="mb-5">
                            <label class="block text-sm font-semibold text-slate-300 mb-2 uppercase tracking-wide">Key</label>
                            <input type="text" id="var_key" name="var_key" required class="w-full bg-slate-900/50 border border-slate-700 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div class="mb-6">
                            <label class="block text-sm font-semibold text-slate-300 mb-2 uppercase tracking-wide">Value</label>
                            <textarea id="var_value" name="var_value" rows="5" class="w-full bg-slate-900/50 border border-slate-700 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"></textarea>
                        </div>
                        <button type="submit" class="w-full py-3 bg-gradient-to-r from-blue-600 to-indigo-600 rounded-xl font-bold shadow-lg hover:from-blue-500 hover:to-indigo-500 transition-all">Save SSD & Memory</button>
                    </form>
                </div>
            </div>

            <div class="lg:col-span-2">
                <div class="glass rounded-2xl overflow-hidden shadow-2xl">
                    <div class="p-6 border-b border-slate-700/80 bg-slate-800/40 flex justify-between items-center">
                        <h2 class="text-xl font-bold">Stored Variables</h2>
                        <span id="live-badge" class="px-2 py-0.5 bg-emerald-500/20 text-emerald-400 text-[10px] font-bold rounded border border-emerald-500/30 uppercase hidden">Live Sync Active</span>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left">
                            <thead class="bg-slate-900/60 text-slate-400 text-xs uppercase tracking-wider">
                                <tr>
                                    <th class="px-6 py-4">Key</th>
                                    <th class="px-6 py-4">Value / Size</th>
                                    <th class="px-6 py-4 text-center">Status</th>
                                    <th class="px-6 py-4 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="variables-table-body">
                                <?= renderTableRows($variables, $api_url_base) ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Live Updates System (SSE)
        const eventSource = new EventSource('events.php');
        const badge = document.getElementById('live-badge');
        const redisPulse = document.getElementById('redis-pulse');

        eventSource.onopen = () => {
            badge.classList.remove('hidden');
            if (redisPulse) redisPulse.classList.add('animate-pulse');
        };

        eventSource.onmessage = (event) => {
            const data = JSON.parse(event.data);
            if (data.update) {
                refreshTable();
            }
        };

        eventSource.onerror = () => {
            badge.classList.add('hidden');
            if (redisPulse) redisPulse.classList.remove('animate-pulse');
        };

        function refreshTable() {
            fetch('index.php?ajax=1')
                .then(res => res.text())
                .then(html => {
                    const tbody = document.getElementById('variables-table-body');
                    tbody.innerHTML = html;
                    tbody.classList.add('update-flash');
                    setTimeout(() => tbody.classList.remove('update-flash'), 1000);
                });
        }

        function editVar(key, val) {
            document.getElementById('var_key').value = key;
            document.getElementById('var_value').value = val;
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        function copyApiUrl(url, btn) {
            navigator.clipboard.writeText(url).then(() => {
                const original = btn.innerHTML;
                btn.innerHTML = '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>';
                btn.classList.add('text-emerald-400');
                setTimeout(() => { btn.innerHTML = original; btn.classList.remove('text-emerald-400'); }, 2000);
            });
        }
    </script>
</body>
</html>
