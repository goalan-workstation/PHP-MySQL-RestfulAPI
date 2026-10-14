<?php
declare(strict_types=1);

session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/theme.php';

$unreadContactCount = (int) $pdo
    ->query('SELECT COUNT(*) FROM contact_us WHERE `CHK` = 0')
    ->fetchColumn();
$cartStatusCounts = $pdo
    ->query(
        'SELECT COALESCE(SUM(CASE WHEN `view` = 0 THEN 1 ELSE 0 END), 0) AS unread_count,
                COALESCE(SUM(CASE WHEN `CHK` = 0 THEN 1 ELSE 0 END), 0) AS unfinished_count
         FROM shopping_cart'
    )
    ->fetch(PDO::FETCH_ASSOC);
$unreadCartCount = (int) $cartStatusCounts['unread_count'];
$unfinishedCartCount = (int) $cartStatusCounts['unfinished_count'];

// Login records (visible only to root admin id=1)
$loginLogs = [];
$showLoginLogs = (int)$_SESSION['admin_id'] === 1;
if ($showLoginLogs) {
    $loginLogs = $pdo
        ->query('SELECT username, login_time, ip_address, user_agent, status FROM admin_login_log ORDER BY login_time DESC LIMIT 50')
        ->fetchAll(PDO::FETCH_ASSOC);
}

if (!isset($_SESSION['admin_power'])) {
    $adminStatement = $pdo->prepare('SELECT admin_power FROM admin WHERE id = :id');
    $adminStatement->execute(['id' => $_SESSION['admin_id']]);
    $storedPower = $adminStatement->fetchColumn();
    $powerOptions = require __DIR__ . '/admin_permissions.php';
    $decodedPower = $storedPower === false ? null : json_decode((string) $storedPower, true);
    $_SESSION['admin_power'] = is_array($decodedPower)
        ? array_values(array_intersect(
            array_keys($powerOptions),
            array_filter($decodedPower, 'is_string')
        ))
        : [];
}
$adminPower = $_SESSION['admin_power'];
$adminPower = is_array($adminPower)
    ? array_values(array_filter($adminPower, 'is_string'))
    : [];

$modules = [
    '網站基本設定' => 'web_info/edit.php',
    '網站色系' => 'web_style/edit.php',
    '關於我們管理' => 'about_us/edit.php',
    '網站管理員' => 'admin/list.php',
    '最新消息' => 'news/list.php',
    '最新活動' => 'activity/list.php',
    '相簿管理' => 'album/list.php',
    '產品管理' => 'product/list.php',
    '購物車管理' => 'shopping_cart/list.php',
    '會員管理' => 'member/list.php',
    '聯絡我們管理' => 'contact/list.php',
];
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>管理功能列表</title>
    <link rel="stylesheet" href="assets/css/admin.css">
</head>
<body class="dashboard-page" data-theme="<?= $styleOption ?>">
    <main class="dashboard">
        <nav class="breadcrumb" aria-label="麵包屑">
            <a href="dashboard.php">首頁</a>
            <span aria-hidden="true">›</span>
            <span aria-current="page">管理功能列表</span>
        </nav>
        <header class="dashboard-header">
            <div>
                <p class="eyebrow">ADMINISTRATION</p>
                <h1>管理功能列表</h1>
                <p class="page-description">請選擇要管理的項目</p>
            </div>
            <div class="dashboard-actions">
                <div class="account-actions">
                    <?= adminIdentityMarkup() ?>
                    <a class="button button-logout" href="logout.php">登出</a>
                </div>
            </div>
        </header>

        <nav class="module-list" aria-label="管理功能">
            <?php foreach ($modules as $module => $url): ?>
                <?php if ($url !== null && in_array($url, $adminPower, true)): ?>
                    <a class="button button-module" href="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>">
                        <span>
                            <?= htmlspecialchars($module, ENT_QUOTES, 'UTF-8') ?>
                            <?php if ($module === '購物車管理'): ?>
                                <span class="unread-contact-count">（未讀取共 <?= $unreadCartCount ?> 筆，未完成共 <?= $unfinishedCartCount ?> 筆）</span>
                            <?php endif; ?>
                            <?php if ($module === '聯絡我們管理'): ?>
                                <span class="unread-contact-count">（目前有 <?= $unreadContactCount ?> 筆未讀取）</span>
                            <?php endif; ?>
                        </span>
                        <span class="button-arrow" aria-hidden="true">›</span>
                    </a>
                <?php endif; ?>
            <?php endforeach; ?>
        </nav>

        <?php if ($showLoginLogs): ?>
        <section class="login-logs-section" style="margin-top:32px;">
            <h2 style="font-size:18px;margin-bottom:12px;cursor:pointer;display:inline-flex;align-items:center;gap:8px;" onclick="toggleLoginLogs(this)">
                <span class="log-toggle-icon" style="display:inline-block;transition:transform 0.2s;">▸</span>
                後台登入紀錄
            </h2>
            <div id="login-logs-body" style="display:none;margin-top:12px;">
            <?php if (empty($loginLogs)): ?>
                <p style="color:#888;font-size:14px;">尚無登入紀錄。</p>
            <?php else: ?>
            <div style="overflow-x:auto;">
                <table class="data-table" style="width:100%;border-collapse:collapse;font-size:13px;">
                    <thead>
                        <tr style="background:#f5f5f5;text-align:left;">
                            <th style="padding:8px 10px;border-bottom:1px solid #ddd;">#</th>
                            <th style="padding:8px 10px;border-bottom:1px solid #ddd;">帳號</th>
                            <th style="padding:8px 10px;border-bottom:1px solid #ddd;">時間</th>
                            <th style="padding:8px 10px;border-bottom:1px solid #ddd;">IP 地址</th>
                            <th style="padding:8px 10px;border-bottom:1px solid #ddd;">裝置</th>
                            <th style="padding:8px 10px;border-bottom:1px solid #ddd;">結果</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($loginLogs as $i => $log): ?>
                        <tr style="border-bottom:1px solid #eee;">
                            <td style="padding:8px 10px;color:#888;"><?= $i + 1 ?></td>
                            <td style="padding:8px 10px;font-weight:600;"><?= htmlspecialchars($log['username']) ?></td>
                            <td style="padding:8px 10px;"><?= htmlspecialchars($log['login_time']) ?></td>
                            <td style="padding:8px 10px;"><?= htmlspecialchars($log['ip_address']) ?></td>
                            <td style="padding:8px 10px;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= htmlspecialchars($log['user_agent']) ?>"><?= htmlspecialchars($log['user_agent']) ?></td>
                            <td style="padding:8px 10px;">
                                <?php if ($log['status'] === 'success'): ?>
                                    <span style="color:#27ae60;font-weight:600;">成功</span>
                                <?php elseif ($log['status'] === 'reset'): ?>
                                    <span style="color:#e67e22;font-weight:600;">重置</span>
                                <?php else: ?>
                                    <span style="color:#c0392b;font-weight:600;">失敗</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p style="color:#999;font-size:12px;margin-top:8px;">顯示最近 50 筆記錄。</p>
            <?php endif; ?>
            </div>
        </section>
        <script>
        function toggleLoginLogs(el) {
            var body = document.getElementById('login-logs-body');
            var icon = el.querySelector('.log-toggle-icon');
            if (body.style.display === 'none') {
                body.style.display = 'block';
                icon.style.transform = 'rotate(90deg)';
            } else {
                body.style.display = 'none';
                icon.style.transform = 'rotate(0deg)';
            }
        }
        </script>
        <?php endif; ?>
    </main>
</body>
</html>
