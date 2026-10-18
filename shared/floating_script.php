<?php
require_once __DIR__ . '/../config/pusher.php';
session_start();
if (!isset($_SESSION['user_id'])) { exit; }

$type = isset($_GET['type']) && $_GET['type'] === 'closer' ? 'closer' : 'agent';

function getFloatingScriptContent($type) {
    $scriptFile = __DIR__ . '/../scripts/' . ($type === 'closer' ? 'closer' : 'agent') . '_script.txt';
    $rawContent = file_exists($scriptFile) ? file_get_contents($scriptFile) : 'No ' . ucfirst($type) . ' script available.';
    $safeContent = htmlspecialchars($rawContent, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    return preg_replace(
        '/(https?:\/\/[^\s]+)/',
        '<a href="$1" target="_blank" style="color: #60a5fa; text-decoration: underline;">$1</a>',
        $safeContent
    );
}

if (isset($_GET['json']) && $_GET['json'] === '1') {
    header('Content-Type: application/json');
    echo json_encode([
        'type' => $type,
        'content' => getFloatingScriptContent($type)
    ], JSON_UNESCAPED_SLASHES);
    exit();
}

$agent_script = getFloatingScriptContent('agent');
$closer_script = getFloatingScriptContent('closer');

// Convert basic URLs to clickable links
// already applied in helper function
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Reference Script</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body { margin: 0; overflow: hidden; background: transparent; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        .window-container { display: flex; flex-direction: column; height: 100vh; background: rgba(15, 23, 42, 0.95); border-radius: 12px; border: 1px solid #3b82f6; box-shadow: 0 10px 30px rgba(0,0,0,0.5); overflow: hidden; box-sizing: border-box; }
        .title-bar { -webkit-app-region: drag; background: #0A2540; padding: 12px 15px; display: flex; justify-content: space-between; align-items: center; color: white; user-select: none; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .title-bar-text { font-size: 14px; font-weight: bold; display: flex; align-items: center; gap: 8px; }
        .close-btn { -webkit-app-region: no-drag; background: none; border: none; color: #94a3b8; cursor: pointer; font-size: 20px; transition: color 0.2s; line-height: 1; padding: 0 5px; }
        .close-btn:hover { color: #ef4444; }
        .content { flex: 1; padding: 20px; overflow-y: auto; color: #f8fafc; font-size: 14px; line-height: 1.6; white-space: pre-wrap; }
        ::-webkit-scrollbar { width: 8px; }
        ::-webkit-scrollbar-track { background: rgba(255,255,255,0.05); }
        ::-webkit-scrollbar-thumb { background: #3b82f6; border-radius: 4px; }
        
        .script-section-title { color: #38bdf8; border-bottom: 1px solid rgba(255,255,255,0.1); padding-bottom: 5px; margin-bottom: 15px; margin-top: 20px; font-size: 16px; font-weight: bold; text-transform: uppercase; letter-spacing: 1px; }
        .script-section-title:first-child { margin-top: 0; }
    </style>
</head>
<body>
    <div class="window-container">
        <div class="title-bar">
            <div class="title-bar-text"><i class="fas fa-file-alt" style="color: #38bdf8;"></i> CHC Script</div>
            <button class="close-btn" onclick="if(window.electronAPI) window.electronAPI.closeCurrentWindow()">&times;</button>
        </div>
        <div class="content" id="script-content">
            <div style="opacity:0.7;">Loading script content…</div>
        </div>
    </div>
    <script src="https://js.pusher.com/8.0/pusher.min.js"></script>
    <script>
        const SCRIPT_TYPE = <?php echo json_encode($type, JSON_UNESCAPED_SLASHES); ?>;
        const CACHE_KEY = `floating-script:${SCRIPT_TYPE}`;
        const contentElement = document.getElementById('script-content');

        function renderScript(html) {
            contentElement.innerHTML = html;
        }

        function fetchScriptContent() {
            fetch(`floating_script.php?json=1&type=${encodeURIComponent(SCRIPT_TYPE)}`)
                .then(response => response.json())
                .then(data => {
                    if (data && data.content) {
                        const current = localStorage.getItem(CACHE_KEY);
                        if (current !== data.content) {
                            localStorage.setItem(CACHE_KEY, data.content);
                            renderScript(data.content);
                        } else if (!current) {
                            localStorage.setItem(CACHE_KEY, data.content);
                        }
                    }
                })
                .catch(() => {});
        }

        function invalidateFloatingScriptCache() {
            localStorage.removeItem(CACHE_KEY);
            fetchScriptContent();
        }

        window.invalidateFloatingScriptCache = invalidateFloatingScriptCache;

        const cached = localStorage.getItem(CACHE_KEY);
        if (cached) {
            renderScript(cached);
            fetchScriptContent();
        } else {
            fetchScriptContent();
        }

        if (typeof Pusher !== 'undefined' && typeof WORKSPACE_PUSHER_KEY !== 'undefined') {
            try {
                const pusher = new Pusher(WORKSPACE_PUSHER_KEY, { cluster: WORKSPACE_PUSHER_CLUSTER, forceTLS: true });
                const channel = pusher.subscribe('global-updates');
                channel.bind('cache-invalidate', function(payload) {
                    if (payload && payload.section === 'scripts') {
                        invalidateFloatingScriptCache();
                    }
                });
            } catch (e) {
                console.error('Pusher error on floating script cache', e);
            }
        }

    </script>
</body>
</html>