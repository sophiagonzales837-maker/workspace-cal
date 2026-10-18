<?php
require_once '../config/db.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Block public access; only internal CRM users allowed
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

$json_file = '../downloads/app_version.json';
$app_data = file_exists($json_file) ? json_decode(file_get_contents($json_file), true) : null;

// --- SMART DIRECT DOWNLOAD CONVERTER ---
function getDirectDownloadUrl($url)
{
    if (empty($url)) return '#';

    // 1. Convert Google Drive Links
    // Matches standard: https://drive.google.com/file/d/FILE_ID/view
    if (preg_match('/drive\.google\.com\/file\/d\/([a-zA-Z0-9_-]+)/', $url, $matches)) {
        return "https://drive.google.com/uc?export=download&id=" . $matches[1];
    }

    // 2. Convert Dropbox Links
    if (strpos($url, 'dropbox.com') !== false) {
        return str_replace(['?dl=0', '&dl=0'], ['?dl=1', '&dl=1'], $url);
    }

    return $url;
}

$direct_url = $app_data ? getDirectDownloadUrl($app_data['url']) : '#';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Download Desktop App | CHC Workspace</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-navy: #142030;
            --electric-blue: #3563A9;
            --brand-blue-hover: #264A82;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #030712;
            margin: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            color: #E2E8F0;
            background-image: radial-gradient(ellipse at 12% 0%, rgba(8, 145, 178, 0.16), transparent 38%), radial-gradient(rgba(148, 163, 184, 0.13) 0.7px, transparent 0.7px);
            background-size: auto, 22px 22px;
        }

        .download-card {
            background: rgba(15, 23, 42, 0.88);
            width: 100%;
            max-width: 500px;
            padding: 40px;
            border-radius: 16px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.05);
            text-align: center;
            border-top: 5px solid var(--electric-blue);
            border: 1px solid rgba(56, 189, 248, 0.2);
            backdrop-filter: blur(16px);
        }

        .logo-icon {
            font-size: 48px;
            color: var(--electric-blue);
            margin-bottom: 15px;
        }

        .app-title {
            margin: 0 0 5px 0;
            color: #F8FAFC;
            font-size: 24px;
            font-weight: 800;
        }

        .version-badge {
            display: inline-block;
            background: rgba(16, 185, 129, 0.12);
            color: #6EE7B7;
            font-weight: bold;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 14px;
            margin-bottom: 20px;
            border: 1px solid rgba(52, 211, 153, 0.3);
        }

        .notes-box {
            background: rgba(11, 18, 32, 0.85);
            border: 1px solid rgba(56, 189, 248, 0.18);
            border-radius: 8px;
            padding: 15px;
            text-align: left;
            font-size: 14px;
            color: #CBD5E1;
            margin-bottom: 25px;
            line-height: 1.5;
        }

        .btn-download {
            display: block;
            width: 100%;
            box-sizing: border-box;
            background: var(--electric-blue);
            color: white;
            text-decoration: none;
            padding: 15px;
            border-radius: 8px;
            font-size: 16px;
            font-weight: bold;
            transition: background 0.2s, transform 0.2s;
            box-shadow: 0 4px 6px rgba(0, 123, 255, 0.2);
        }

        .btn-download:hover {
            background: #0056b3;
            transform: translateY(-2px);
        }

        .back-link {
            display: inline-block;
            margin-top: 20px;
            color: #64748B;
            text-decoration: none;
            font-size: 14px;
            transition: color 0.2s;
        }

        .back-link:hover {
            color: var(--primary-navy);
            text-decoration: underline;
        }
    </style>
</head>

<body>

    <div class="download-card">
        <div class="logo-icon"><i class="fas fa-desktop"></i></div>
        <h1 class="app-title">CHC Desktop App</h1>

        <?php if ($app_data && !empty($app_data['url'])): ?>
            <div class="version-badge">Version <?php echo htmlspecialchars($app_data['version']); ?></div>

            <div class="notes-box">
                <strong style="color: var(--primary-navy); display:block; margin-bottom: 8px;">What's New in this Update:</strong>
                <?php echo nl2br($app_data['notes']) ?: '<i>Routine performance improvements and bug fixes.</i>'; ?>
                <div style="margin-top: 15px; font-size: 11px; color: #94A3B8;">Published: <?php echo date('M d, Y', strtotime($app_data['date'])); ?></div>
            </div>

            <a href="<?php echo htmlspecialchars($direct_url); ?>" target="_blank" class="btn-download">
                <i class="fas fa-cloud-download-alt"></i> Download Latest Setup
            </a>
            <p style="font-size: 11px; color: #64748B; margin-top: 10px;">
                <em>Note: Because the file is large, Google Drive may ask you to click "Download anyway" in the new tab.</em>
            </p>
        <?php else: ?>
            <p style="color: #64748B; margin: 20px 0;">The system administrator has not published a desktop app version yet.</p>
        <?php endif; ?>

        <a href="javascript:history.back()" class="back-link">&larr; Return to Dashboard</a>
    </div>

</body>

</html>