<?php
// ==========================================
// PORTABLE VARIABLE STORAGE - Web GUI
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

// Connect to MariaDB using PDO
$mariadb_status = 'Disconnected';
$pdo = null;
try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $mariadb_status = 'Connected';
} catch (PDOException $e) {
    if ($db_name == 'your_database_name') {
        $mariadb_status = 'Pending Setup (Check connection details)';
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

$message = '';
$messageType = ''; // 'success' or 'error'

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $name = $_POST['name'] ?? '';
    
    if ($action === 'add' || $action === 'edit') {
        $value = $_POST['value'] ?? '';
        $storage = $_POST['storage'] ?? 'mariadb';
        
        if (trim($name) === '') {
            $message = 'Variable name cannot be empty.';
            $messageType = 'error';
        } else {
            try {
                if ($storage === 'redis') {
                    if ($redis) {
                        $redis->set($redis_prefix . $name, $value);
                        // cleanup db just in case
                        if ($pdo) {
                            $stmt = $pdo->prepare("DELETE FROM variables WHERE name = ?");
                            $stmt->execute([$name]);
                        }
                        $message = "Variable '$name' saved to Redis successfully.";
                        $messageType = 'success';
                    } else {
                        $message = 'Cannot save to Redis: Redis is not connected.';
                        $messageType = 'error';
                    }
                } else {
                    if ($pdo) {
                        $stmt = $pdo->prepare("INSERT INTO variables (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = ?");
                        $stmt->execute([$name, $value, $value]);
                        // cleanup redis just in case
                        if ($redis) {
                            $redis->del($redis_prefix . $name);
                        }
                        $message = "Variable '$name' saved to MariaDB successfully.";
                        $messageType = 'success';
                    } else {
                        $message = 'Cannot save to DB: MariaDB is not connected.';
                        $messageType = 'error';
                    }
                }
            } catch (Exception $e) {
                $message = 'Error saving variable: ' . $e->getMessage();
                $messageType = 'error';
            }
        }
    } elseif ($action === 'delete') {
        $storage = $_POST['storage'] ?? '';
        try {
            if ($storage === 'redis' && $redis) {
                $redis->del($redis_prefix . $name);
                $message = "Redis variable '$name' deleted.";
                $messageType = 'success';
            } elseif ($storage === 'mariadb' && $pdo) {
                $stmt = $pdo->prepare("DELETE FROM variables WHERE name = ?");
                $stmt->execute([$name]);
                $message = "MariaDB variable '$name' deleted.";
                $messageType = 'success';
            }
        } catch (Exception $e) {
            $message = 'Error deleting variable: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}

// Fetch all variables to display
$variables = [];

// Fetch MariaDB variables
if ($pdo && $mariadb_status === 'Connected') {
    try {
        $stmt = $pdo->query("SELECT name, value, updated_at FROM variables ORDER BY name ASC");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $variables[$row['name']] = [
                'name' => $row['name'],
                'value' => $row['value'],
                'storage' => 'mariadb',
                'updated_at' => $row['updated_at']
            ];
        }
    } catch (PDOException $e) {
        // Table might not exist yet
    }
}

// Fetch Redis variables
if ($redis && $redis_status === 'Connected') {
    try {
        $keys = $redis->keys($redis_prefix . '*');
        if ($keys) {
            foreach ($keys as $key) {
                $name = substr($key, strlen($redis_prefix));
                $value = $redis->get($key);
                // Display as Redis since api prioritizes it
                $variables[$name] = [
                    'name' => $name,
                    'value' => $value,
                    'storage' => 'redis',
                    'updated_at' => 'N/A (Memory)'
                ];
            }
        }
    } catch (Exception $e) {
        // Handle redis errors quietly
    }
}

// Sort compiled variables by name
ksort($variables);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Portable Variable Storage Service</title>
    <!-- Use Tailwind CSS for a highly premium, dark modern feel -->
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { 
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            color: #f8fafc;
            min-height: 100vh;
        }
        .glass-panel {
            background: rgba(30, 41, 59, 0.7);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
            transition: box-shadow 0.3s ease;
        }
        /* Fix input backgrounds */
        input[type="text"], textarea {
            background: rgba(15, 23, 42, 0.6);
            color: white;
            border: 1px solid rgba(148, 163, 184, 0.3);
            transition: all 0.2s ease;
        }
        input[type="text"]:focus, textarea:focus {
            border-color: #3b82f6;
            outline: none;
            box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.5);
        }
        /* Custom scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        ::-webkit-scrollbar-track {
            background: rgba(15, 23, 42, 0.5); 
        }
        ::-webkit-scrollbar-thumb {
            background: rgba(148, 163, 184, 0.3); 
            border-radius: 4px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: rgba(148, 163, 184, 0.6); 
        }
    </style>
</head>
<body class="p-4 md:p-8 font-sans antialiased">
    <div class="max-w-6xl mx-auto">
        <header class="flex flex-col md:flex-row justify-between items-center mb-10 pb-6 border-b border-slate-700">
            <div>
                <h1 class="text-3xl font-extrabold bg-clip-text text-transparent bg-gradient-to-r from-blue-400 to-indigo-500 mb-2">Variable Storage Service</h1>
                <p class="text-slate-400 font-medium tracking-wide">Manage variables for TurboWarp / API</p>
            </div>
            
            <div class="flex flex-col gap-2 mt-4 md:mt-0 text-sm">
                <div class="flex items-center gap-3 px-4 py-2 rounded-full bg-slate-800/80 border <?= $mariadb_status === 'Connected' ? 'border-green-500/50' : 'border-red-500/50' ?> shadow-lg">
                    <div class="w-3 h-3 rounded-full <?= $mariadb_status === 'Connected' ? 'bg-green-500 shadow-[0_0_8px_rgba(34,197,94,0.8)]' : 'bg-red-500 animate-pulse shadow-[0_0_8px_rgba(239,68,68,0.8)]' ?>"></div>
                    <span class="text-slate-300 font-medium tracking-wider text-xs uppercase">MariaDB: <span class="<?= $mariadb_status === 'Connected' ? 'text-green-400' : 'text-red-400' ?>"><?= htmlspecialchars($mariadb_status) ?></span></span>
                </div>
                <div class="flex items-center gap-3 px-4 py-2 rounded-full bg-slate-800/80 border <?= $redis_status === 'Connected' ? 'border-green-500/50' : 'border-red-500/50' ?> shadow-lg">
                    <div class="w-3 h-3 rounded-full <?= $redis_status === 'Connected' ? 'bg-green-500 shadow-[0_0_8px_rgba(34,197,94,0.8)]' : 'bg-yellow-500 animate-pulse shadow-[0_0_8px_rgba(234,179,8,0.8)]' ?>"></div>
                    <span class="text-slate-300 font-medium tracking-wider text-xs uppercase">Redis: <span class="<?= $redis_status === 'Connected' ? 'text-green-400' : 'text-yellow-400' ?>"><?= htmlspecialchars($redis_status) ?></span></span>
                </div>
            </div>
        </header>

        <?php if ($message): ?>
            <div class="mb-8 p-4 rounded-xl <?= $messageType === 'success' ? 'bg-green-900/30 border border-green-500/50 text-green-300' : 'bg-red-900/30 border border-red-500/50 text-red-300' ?> shadow-lg flex items-center gap-3 animate-fade-in">
                <?php if ($messageType === 'success'): ?>
                    <svg class="w-6 h-6 text-green-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                <?php else: ?>
                    <svg class="w-6 h-6 text-red-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                <?php endif; ?>
                <span class="font-medium"><?= htmlspecialchars($message) ?></span>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <!-- Add/Edit Form -->
            <div class="lg:col-span-1">
                <div id="form-panel" class="glass-panel p-6 rounded-2xl w-full sticky top-8">
                    <h2 class="text-xl font-bold mb-6 flex items-center gap-2">
                        <svg class="w-5 h-5 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                        <span id="form-title">Add / Edit Variable</span>
                    </h2>
                    
                    <form method="POST" action="">
                        <input type="hidden" name="action" id="form-action" value="add">
                        
                        <div class="mb-5">
                            <label class="block text-sm font-semibold text-slate-300 mb-2 uppercase tracking-wide" for="name">Variable Name</label>
                            <input type="text" id="name" name="name" required placeholder="e.g. global_score" class="w-full px-4 py-3 rounded-xl placeholder-slate-500 font-mono text-sm" />
                        </div>
                        
                        <div class="mb-5">
                            <label class="block text-sm font-semibold text-slate-300 mb-2 uppercase tracking-wide" for="value">Value <span class="text-slate-500 text-xs normal-case font-normal">(String / JSON)</span></label>
                            <textarea id="value" name="value" rows="5" class="w-full px-4 py-3 rounded-xl placeholder-slate-500 font-mono text-sm" placeholder="Enter data..."></textarea>
                        </div>
                        
                        <div class="mb-6">
                            <label class="block text-sm font-semibold text-slate-300 mb-3 uppercase tracking-wide">Storage Target</label>
                            <div class="flex gap-4">
                                <label class="flex-1 cursor-pointer group relative">
                                    <input type="radio" name="storage" value="mariadb" class="peer sr-only" checked>
                                    <div class="p-3 text-center rounded-xl border border-slate-600 bg-slate-800/80 text-slate-400 peer-checked:border-blue-500 peer-checked:bg-blue-900/40 peer-checked:text-blue-300 hover:bg-slate-700/80 transition-all duration-200">
                                        <div class="font-bold mb-1 flex items-center justify-center gap-1.5">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"></path></svg>
                                            MariaDB
                                        </div>
                                        <span class="text-[10px] uppercase tracking-wider opacity-75 block">Persistent</span>
                                    </div>
                                    <!-- glow effect on checked -->
                                    <div class="absolute inset-0 rounded-xl bg-blue-500/20 blur-md -z-10 opacity-0 peer-checked:opacity-100 transition-opacity"></div>
                                </label>
                                <label class="flex-1 cursor-pointer group relative">
                                    <input type="radio" name="storage" value="redis" class="peer sr-only">
                                    <div class="p-3 text-center rounded-xl border border-slate-600 bg-slate-800/80 text-slate-400 peer-checked:border-indigo-500 peer-checked:bg-indigo-900/40 peer-checked:text-indigo-300 hover:bg-slate-700/80 transition-all duration-200">
                                        <div class="font-bold mb-1 flex items-center justify-center gap-1.5">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                                            Redis
                                        </div>
                                        <span class="text-[10px] uppercase tracking-wider opacity-75 block">Fast Memory</span>
                                    </div>
                                    <div class="absolute inset-0 rounded-xl bg-indigo-500/20 blur-md -z-10 opacity-0 peer-checked:opacity-100 transition-opacity"></div>
                                </label>
                            </div>
                        </div>
                        
                        <div class="flex gap-3">
                            <button type="button" id="btn-cancel" onclick="resetForm()" class="hidden flex-1 py-3 px-4 bg-slate-800 hover:bg-slate-700 border border-slate-600 text-white rounded-xl font-bold transition-all transform active:scale-95">
                                Cancel
                            </button>
                            <button type="submit" id="btn-submit" class="flex-[2] w-full py-3 px-4 bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-500 hover:to-indigo-500 text-white rounded-xl font-bold shadow-lg shadow-blue-500/25 transition-all transform active:scale-95 flex justify-center items-center gap-2">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4"></path></svg>
                                Save Variable
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- List of Variables -->
            <div class="lg:col-span-2">
                <div class="glass-panel text-white rounded-2xl overflow-hidden shadow-2xl">
                    <div class="p-6 border-b border-slate-700/80 flex justify-between items-center bg-slate-800/40">
                        <h2 class="text-xl font-bold flex items-center gap-2">
                            <svg class="w-5 h-5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"></path></svg>
                            Stored Variables
                        </h2>
                        <span class="bg-indigo-500/20 border border-indigo-500/30 px-3 py-1 rounded-full text-xs font-bold text-indigo-300 tracking-wider">
                            <?= count($variables) ?> ITEM<?= count($variables) !== 1 ? 'S' : '' ?>
                        </span>
                    </div>
                    
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse">
                            <thead class="bg-slate-900/60 text-slate-400 text-xs uppercase tracking-wider">
                                <tr>
                                    <th class="px-6 py-4 font-bold border-b border-slate-700/50">Name</th>
                                    <th class="px-6 py-4 font-bold border-b border-slate-700/50 w-2/5">Value</th>
                                    <th class="px-6 py-4 font-bold border-b border-slate-700/50 text-center">Storage Target</th>
                                    <th class="px-6 py-4 font-bold border-b border-slate-700/50 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-700/40">
                                <?php if (empty($variables)): ?>
                                    <tr>
                                        <td colspan="4" class="px-6 py-16 text-center text-slate-500 flex flex-col items-center justify-center gap-4">
                                            <svg class="w-16 h-16 opacity-30" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"></path></svg>
                                            <p class="text-lg">No variables stored yet.</p>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($variables as $v): ?>
                                        <tr class="hover:bg-slate-800/50 transition-colors group">
                                            <td class="px-6 py-4">
                                                <div class="font-mono text-sm text-blue-300 font-semibold mb-1"><?= htmlspecialchars($v['name']) ?></div>
                                                <div class="text-[10px] text-slate-500 font-medium uppercase tracking-wider">Updated: <?= $v['updated_at'] ?></div>
                                            </td>
                                            <td class="px-6 py-4">
                                                <div class="max-w-[250px] md:max-w-[300px] bg-slate-900/50 p-2.5 rounded-lg border border-slate-700/50 font-mono text-sm text-slate-300 overflow-hidden text-ellipsis whitespace-nowrap group-hover:bg-slate-900/80 transition-colors" title="<?= htmlspecialchars($v['value']) ?>">
                                                    <?= htmlspecialchars($v['value']) ?>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4 text-center">
                                                <?php if ($v['storage'] === 'redis'): ?>
                                                    <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-indigo-500/10 text-indigo-400 border border-indigo-500/20 text-xs font-bold uppercase tracking-wider">
                                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                                                        Redis
                                                    </span>
                                                <?php else: ?>
                                                    <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-blue-500/10 text-blue-400 border border-blue-500/20 text-xs font-bold uppercase tracking-wider">
                                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"></path></svg>
                                                        MariaDB
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-6 py-4 text-right">
                                                <div class="flex items-center justify-end gap-2 opacity-60 group-hover:opacity-100 transition-opacity">
                                                    <button type="button" onclick="editVar('<?= htmlspecialchars(addslashes($v['name'])) ?>', '<?= htmlspecialchars(addslashes($v['value'])) ?>', '<?= $v['storage'] ?>')" class="p-2.5 text-blue-400 bg-blue-400/5 hover:bg-blue-400/20 border border-transparent hover:border-blue-400/30 rounded-xl transition-all" title="Edit Variable">
                                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>
                                                    </button>
                                                    <form method="POST" action="" class="inline-block m-0" onsubmit="return confirm('Are you sure you want to delete \'<?= htmlspecialchars(addslashes($v['name'])) ?>\'?');">
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="name" value="<?= htmlspecialchars($v['name']) ?>">
                                                        <input type="hidden" name="storage" value="<?= $v['storage'] ?>">
                                                        <button type="submit" class="p-2.5 text-red-400 bg-red-400/5 hover:bg-red-400/20 border border-transparent hover:border-red-400/30 rounded-xl transition-all shadow-[0_4px_10px_rgba(0,0,0,0.1)] hover:shadow-red-500/20" title="Delete Variable">
                                                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function editVar(name, value, storage) {
            document.getElementById('name').value = name;
            document.getElementById('value').value = value;
            document.getElementById('form-action').value = 'edit';
            document.getElementById('form-title').innerHTML = `Edit <span class="text-white bg-slate-700 px-2 py-0.5 rounded text-sm ml-1">${name}</span>`;
            document.querySelector(`input[name="storage"][value="${storage}"]`).checked = true;
            
            // Show cancel button
            document.getElementById('btn-cancel').classList.remove('hidden');
            document.getElementById('btn-submit').innerHTML = '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg> Update Variable';
            
            window.scrollTo({ top: 0, behavior: 'smooth' });
            
            // Highlight form
            const formContainer = document.getElementById('form-panel');
            formContainer.style.boxShadow = '0 0 0 3px rgba(59, 130, 246, 0.6), 0 25px 50px -12px rgba(0, 0, 0, 0.5)';
            setTimeout(() => {
                formContainer.style.boxShadow = '0 25px 50px -12px rgba(0, 0, 0, 0.5)';
            }, 1500);
        }

        function resetForm() {
            document.getElementById('name').value = '';
            document.getElementById('value').value = '';
            document.getElementById('form-action').value = 'add';
            document.getElementById('form-title').innerText = 'Add / Edit Variable';
            document.querySelector(`input[name="storage"][value="mariadb"]`).checked = true;
            
            // Hide cancel button
            document.getElementById('btn-cancel').classList.add('hidden');
            document.getElementById('btn-submit').innerHTML = '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4"></path></svg> Save Variable';
        }
    </script>
</body>
</html>
