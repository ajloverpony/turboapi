<?php
// ==========================================
// DUAL-LAYER VARIABLE STORAGE - Docs
// ==========================================
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>API Documentation - Variable Storage</title>
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
        }
        .code-block {
            background: rgba(15, 23, 42, 0.8);
            border: 1px solid rgba(148, 163, 184, 0.2);
        }
    </style>
</head>
<body class="p-4 md:p-8 font-sans antialiased">
    <div class="max-w-4xl mx-auto">
        <header class="flex justify-between items-center mb-10 pb-6 border-b border-slate-700">
            <div>
                <h1 class="text-3xl font-extrabold bg-clip-text text-transparent bg-gradient-to-r from-emerald-400 to-teal-500 mb-2">API Documentation</h1>
                <p class="text-slate-400 font-medium tracking-wide">TurboWarp Integration Guide</p>
            </div>
            <a href="index.php" class="px-5 py-2.5 rounded-xl border border-slate-600 hover:bg-slate-800 transition-colors font-semibold text-sm">Return to Dashboard</a>
        </header>

        <div class="space-y-8">
            <section class="glass-panel p-8 rounded-2xl">
                <h2 class="text-xl font-bold mb-4 text-white border-b border-slate-700 pb-2">Overview</h2>
                <p class="text-slate-300 leading-relaxed">
                    This Dual-Layer API allows TurboWarp to natively read and store persistent data (SSD + Memory). 
                    All data interactions happen via the <strong>HTTP Extension</strong> natively without any JSON parsing required. All responses return <strong>Raw Text</strong>.
                </p>
            </section>

            <section class="glass-panel p-8 rounded-2xl">
                <h2 class="text-xl font-bold mb-4 text-emerald-400 border-b border-slate-700 pb-2">Reading Data (Pull)</h2>
                
                <div class="mb-6">
                    <h3 class="text-lg font-semibold mb-2 text-white">Full Value Pull</h3>
                    <p class="text-sm text-slate-400 mb-2">Fetch the entire contents of a variable.</p>
                    <div class="code-block p-4 rounded-lg font-mono text-sm text-blue-300 overflow-x-auto">
                        GET /api.php?action=pull&name=my_var
                    </div>
                </div>

                <div>
                    <h3 class="text-lg font-semibold mb-2 text-white">Range Pull (Substring)</h3>
                    <p class="text-sm text-slate-400 mb-2">Fetch only specific characters by defining a 0-indexed range. Awesome for decoding chunked data!</p>
                    <div class="code-block p-4 rounded-lg font-mono text-sm text-purple-300 overflow-x-auto">
                        GET /api.php?action=pull&name=my_var&range=0-5
                    </div>
                    <div class="mt-3 bg-blue-900/20 border border-blue-500/20 p-3 rounded text-sm text-blue-200">
                        <strong>Cheat Sheet:</strong> If the string is <code>Hello World</code>: <br>
                        <code>range=0-4</code> returns <code>Hello</code> <br>
                        <code>range=6-10</code> returns <code>World</code>
                    </div>
                </div>
            </section>

            <section class="glass-panel p-8 rounded-2xl">
                <h2 class="text-xl font-bold mb-4 text-indigo-400 border-b border-slate-700 pb-2">Writing Data (Save/Append/Replace)</h2>
                
                <div class="mb-6">
                    <h3 class="text-lg font-semibold mb-2 text-white">Save (Overwrite)</h3>
                    <p class="text-sm text-slate-400 mb-2">Replaces everything in the variable. If the variable doesn't exist, it creates it.</p>
                    <div class="code-block p-4 rounded-lg font-mono text-sm text-orange-300 overflow-x-auto">
                        POST /api.php?action=save&name=my_var&value=12345
                    </div>
                </div>

                <div class="mb-6">
                    <h3 class="text-lg font-semibold mb-2 text-white">Append</h3>
                    <p class="text-sm text-slate-400 mb-2">Stuffs data onto the end of an existing variable. Extremely fast for logs!</p>
                    <div class="code-block p-4 rounded-lg font-mono text-sm text-orange-300 overflow-x-auto">
                        POST /api.php?action=append&name=my_var&value=_new_data
                    </div>
                </div>

                <div>
                    <h3 class="text-lg font-semibold mb-2 text-white">Replace Range</h3>
                    <p class="text-sm text-slate-400 mb-2">Replaces characters exclusively in the targeted index range without downloading the whole string.</p>
                    <div class="code-block p-4 rounded-lg font-mono text-sm text-orange-300 overflow-x-auto">
                        POST /api.php?action=replace&name=my_var&range=0-4&value=Howdy
                    </div>
                    <p class="text-sm text-slate-400 mt-2">If <code>my_var</code> was <code>Hello World</code>, it becomes <code>Howdy World</code>.</p>
                </div>
            </section>
        </div>
    </div>
</body>
</html>
