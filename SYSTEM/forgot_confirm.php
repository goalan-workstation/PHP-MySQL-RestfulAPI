<?php
declare(strict_types=1);

$token = $_GET['token'] ?? '';
$message = null;
$isError = false;
$newPassword = '';
$adminName = '';
$success = false;

if (strlen($token) === 64 && preg_match('/^[a-f0-9]{64}$/', $token)) {
    require_once __DIR__ . '/../conn/conn.inc.php';

    $stmt = $pdo->prepare('SELECT id, admin_name, new_password, expires_at, used FROM password_resets WHERE token = :token LIMIT 1');
    $stmt->execute(['token' => $token]);
    $row = $stmt->fetch();

    if ($row === false) {
        $message = '無效的重置連結。請重新請求密碼重置。';
        $isError = true;
    } elseif ((int)$row['used'] === 1) {
        $message = '此重置連結已使用過，無法再次確認。請重新請求密碼重置。';
        $isError = true;
    } elseif (strtotime($row['expires_at']) < time()) {
        $message = '此重置連結已過期（有效期 30 分鐘）。請重新請求密碼重置。';
        $isError = true;
    } else {
        // Valid reset - show the new password and confirmation button
        $newPassword = $row['new_password'];
        $adminName = $row['admin_name'];
    }
} else {
    $message = '無效的重置連結參數。';
    $isError = true;
}

// Handle confirmation POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_reset'])) {
    $confirmToken = $_POST['token'] ?? '';
    if (strlen($confirmToken) === 64 && preg_match('/^[a-f0-9]{64}$/', $confirmToken)) {
        require_once __DIR__ . '/../conn/conn.inc.php';
        $stmt = $pdo->prepare('SELECT id, admin_name, new_password, expires_at, used FROM password_resets WHERE token = :token LIMIT 1');
        $stmt->execute(['token' => $confirmToken]);
        $row = $stmt->fetch();

        if ($row !== false && (int)$row['used'] === 0 && strtotime($row['expires_at']) >= time()) {
            $newPwd = $row['new_password'];
            $targetAdmin = $row['admin_name'];

            $updatePwd = $pdo->prepare('UPDATE admin SET admin_pwd = :pwd, updata_time = CURRENT_TIMESTAMP WHERE admin_name = :name');
            $updatePwd->execute(['pwd' => $newPwd, 'name' => $targetAdmin]);

            $markUsed = $pdo->prepare('UPDATE password_resets SET used = 1 WHERE id = :id');
            $markUsed->execute(['id' => $row['id']]);

            // Audit log
            $logReset = $pdo->prepare("INSERT INTO admin_login_log (admin_id, username, ip_address, user_agent, status) VALUES (:aid, :username, :ip, :ua, 'reset')");
            $logReset->execute([
                'aid' => null,
                'username' => $targetAdmin,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                'ua' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ]);

            $message = '密碼已成功更新！請使用新密碼登入系統。';
            $success = true;
            $isError = false;
            $newPassword = '';
        } else {
            $message = '無效或已過期的重置連結，請重新請求。';
            $isError = true;
        }
    } else {
        $message = '無效的確認請求。';
        $isError = true;
    }
}

require_once __DIR__ . '/theme.php';
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>密碼重置確認 - 管理系統</title>
    <link rel="stylesheet" href="assets/css/admin.css">
    <style>
        .reset-card { max-width: 480px; margin: 60px auto; padding: 32px; }
        .pwd-display {
            background: #f5f5f5; padding: 14px 20px; border-radius: 6px;
            font-size: 22px; letter-spacing: 3px; font-weight: bold;
            color: #c0392b; text-align: center; margin: 16px 0;
        }
        .reset-msg { font-size: 15px; line-height: 1.6; }
        .reset-msg.error { color: #c0392b; }
        .reset-msg.success { color: #27ae60; font-weight: bold; }
        .confirm-btn {
            display: inline-block; background: #2980b9; color: #fff;
            padding: 12px 28px; border-radius: 4px; text-decoration: none;
            font-size: 16px; border: none; cursor: pointer; margin-top: 16px;
        }
        .confirm-btn:hover { background: #1f6d92; }
        .note-box {
            background: #fff8e1; border: 1px solid #f0d77b; border-radius: 4px;
            padding: 12px 16px; margin-top: 20px; font-size: 13px; color: #5d4a00;
            line-height: 1.6;
        }
    </style>
</head>
<body class="login-page" data-theme="<?= $styleOption ?>">
    <main class="login-card reset-card">
        <div class="brand-mark" aria-hidden="true">A</div>
        <h1 style="font-size:20px; margin-bottom:8px;">密碼重置確認</h1>

        <?php if ($message !== null && !$success): ?>
            <p class="reset-msg <?= $isError ? 'error' : '' ?>" role="alert"><?= $message ?></p>
            <p style="text-align:center; margin-top:16px;"><a href="login.php" style="color:#5d7fc4; font-size:14px;">&larr; 返回登入</a></p>
        <?php elseif ($success): ?>
            <p class="reset-msg success" role="alert"><?= $message ?></p>
            <p style="text-align:center; margin-top:16px;"><a href="login.php" style="color:#5d7fc4; font-size:14px;">前往登入 &rarr;</a></p>
        <?php elseif ($newPassword !== ''): ?>
            <p class="reset-msg">管理員：<strong><?= htmlspecialchars($adminName) ?></strong></p>
            <p class="reset-msg">您的新密碼為：</p>
            <div class="pwd-display"><?= htmlspecialchars($newPassword) ?></div>
            <p class="reset-msg">請點擊下方按鈕確認使用此新密碼：</p>
            <form method="post" action="">
                <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                <input type="hidden" name="confirm_reset" value="1">
                <button type="submit" class="confirm-btn">確認使用新密碼</button>
            </form>
            <div class="note-box">
                <strong>說明：</strong><br>
                &bull; 點擊「確認使用新密碼」後，舊密碼將被替換為新密碼。<br>
                &bull; 如果未點擊按鈕，密碼將維持不變，此連結 30 分鐘後自動失效。<br>
                &bull; 此連結僅可使用一次。
            </div>
        <?php endif; ?>
    </main>
</body>
</html>
