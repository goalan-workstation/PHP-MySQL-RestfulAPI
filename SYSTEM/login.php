<?php
declare(strict_types=1);
session_start();
if (isset($_SESSION['admin_id'])) {
    header('Location: dashboard.php');
    exit;
}
const LOGIN_MAX_ATTEMPTS = 5;
const LOGIN_LOCKOUT_MINUTES = 15;
$loginError = false;
$loginLockout = 0;
$recentAttempts = 0;
$forgotMode = isset($_GET['forgot']) && $_GET['forgot'] === '1';
$forgotMessage = null;
$forgotIsError = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['forgot_submit']) && $_POST['forgot_submit'] === '1') {
        require_once __DIR__ . '/../conn/conn.inc.php';
        $forgotEmail = trim($_POST['forgot_email'] ?? '');
        if (filter_var($forgotEmail, FILTER_VALIDATE_EMAIL) !== false) {
            $stmt = $pdo->prepare('SELECT admin_name FROM admin WHERE admin_email = :email LIMIT 1');
            $stmt->execute(['email' => $forgotEmail]);
            $adminName = $stmt->fetchColumn();
            if ($adminName !== false) {
                $newPassword = substr(bin2hex(random_bytes(8)), 0, 12);
                $token = bin2hex(random_bytes(32));
                $expiresAt = date('Y-m-d H:i:s', time() + 30 * 60);
                $delOld = $pdo->prepare('DELETE FROM password_resets WHERE admin_name = :name AND used = 0');
                $delOld->execute(['name' => $adminName]);
                $insReset = $pdo->prepare('INSERT INTO password_resets (admin_name, new_password, token, expires_at) VALUES (:name, :pwd, :token, :expires)');
                $insReset->execute(['name' => $adminName, 'pwd' => $newPassword, 'token' => $token, 'expires' => $expiresAt]);
                $host = $_SERVER['HTTP_HOST'] ?? '127.0.0.1';
                $base = 'http://' . $host . dirname($_SERVER['PHP_SELF']) . '/forgot_confirm.php';
                $confirmUrl = $base . '?token=' . urlencode($token);
                $subject = '[管理系統] 密碼重置通知';

                $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:20px;">'
                    . '<h2 style="color:#333;">管理系統密碼重置</h2>'
                    . '<p>你好，</p>'
                    . '<p>您（或有人使用您的帳號）請求了密碼重置。我們已為您生成一組新密碼：</p>'
                    . '<div style="background:#f5f5f5;padding:12px;border-radius:4px;font-size:20px;letter-spacing:2px;font-weight:bold;color:#c0392b;">' . htmlspecialchars($newPassword) . '</div>'
                    . '<p style="margin-top:20px;">請點擊下方按鈕，確認將新密碼設為您的登入密碼：</p>'
                    . '<p style="text-align:center;margin:24px 0;"><a href="' . $confirmUrl . '" style="background:#2980b9;color:#fff;padding:12px 28px;border-radius:4px;text-decoration:none;font-size:16px;display:inline-block;">確認使用新密碼</a></p>'
                    . '<div style="border-top:1px solid #eee;padding-top:16px;margin-top:24px;">'
                    . '<p style="color:#888;font-size:13px;"><strong>重要說明：</strong>如果您未點擊上方按鈕，您的密碼將維持不變，新密碼不會被套用。</p>'
                    . '<p style="color:#888;font-size:13px;">此連結有效期為 30 分鐘，逾期後需重新請求。</p>'
                    . '<p style="color:#888;font-size:13px;">若此操作非本人所為，請忽略此郵件並立即登入更改您的密碼。</p>'
                    . '</div></body></html>';
                $headers = "MIME-Version: 1.0\r\nContent-type: text/html; charset=UTF-8\r\nFrom: 管理系統 <noreply@alanphp.local>\r\n";
                @mail($forgotEmail, $subject, $html, $headers);
            }
            $forgotMessage = '若該電子郵件有註冊管理員帳號，重置郵件已發送，請檢查收件匣（含垃圾郵件）。';
            $forgotIsError = false;
        } else {
            $forgotMessage = '請輸入有效的電子郵件格式。';
            $forgotIsError = true;
        }
    } else {
        $username = $_POST['username'] ?? null;
        $submittedPassword = $_POST['password'] ?? null;
        if (is_string($username) && is_string($submittedPassword)) {
            require_once __DIR__ . '/../conn/conn.inc.php';
            $powerOptions = require __DIR__ . '/admin_permissions.php';
            $checkLock = $pdo->prepare('SELECT COUNT(*) AS cnt FROM login_attempts WHERE username = :username AND attempted_at > DATE_SUB(NOW(), INTERVAL ' . LOGIN_LOCKOUT_MINUTES . ' MINUTE)');
            $checkLock->execute(['username' => $username]);
            $recentAttempts = (int) $checkLock->fetch()['cnt'];
            if ($recentAttempts >= LOGIN_MAX_ATTEMPTS) {
                $oldestLock = $pdo->prepare('SELECT MIN(attempted_at) AS oldest FROM login_attempts WHERE username = :username AND attempted_at > DATE_SUB(NOW(), INTERVAL ' . LOGIN_LOCKOUT_MINUTES . ' MINUTE)');
                $oldestLock->execute(['username' => $username]);
                $oldest = $oldestLock->fetch()['oldest'];
                if ($oldest !== null) {
                    $loginLockout = max(0, (int) ceil((strtotime($oldest) + LOGIN_LOCKOUT_MINUTES * 60 - time()) / 60));
                }
                $loginError = true;

            } else {
                $statement = $pdo->prepare('SELECT id, admin_name, admin_nic, admin_pwd, admin_power FROM admin WHERE admin_name = :username LIMIT 1');
                $statement->execute(['username' => $username]);
                $admin = $statement->fetch();
                if ($admin !== false) {
                    $storedPassword = (string) $admin['admin_pwd'];
                    $passwordIsHashed = password_get_info($storedPassword)['algo'] !== null;
                    $passwordMatches = $passwordIsHashed
                        ? password_verify($submittedPassword, $storedPassword)
                        : hash_equals($storedPassword, $submittedPassword);
                } else {
                    $passwordMatches = false;
                }
                if ($passwordMatches) {
                    $clear = $pdo->prepare('DELETE FROM login_attempts WHERE username = :username');
                    $clear->execute(['username' => $username]);
                    $logSuccess = $pdo->prepare("INSERT INTO admin_login_log (admin_id, username, ip_address, user_agent, status) VALUES (:aid, :username, :ip, :ua, 'success')");
                    $logSuccess->execute(['aid' => $admin['id'], 'username' => $username, 'ip' => $_SERVER['REMOTE_ADDR'] ?? '', 'ua' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)]);
                    $_SESSION['admin_id'] = (int) $admin['id'];
                    $_SESSION['admin_name'] = (string) $admin['admin_name'];
                    $_SESSION['admin_nic'] = (string) $admin['admin_nic'];
                    $storedPermissions = is_string($admin['admin_power']) ? json_decode($admin['admin_power'], true) : (is_array($admin['admin_power']) ? $admin['admin_power'] : []);
                    if (!is_array($storedPermissions)) { $storedPermissions = []; }
                    $storedPermissions = array_values(array_intersect(array_keys($powerOptions), array_filter($storedPermissions, 'is_string')));
                    $_SESSION['admin_power'] = $storedPermissions;
                    $_SESSION['last_activity'] = time();
                    header('Location: dashboard.php');
                    exit;
                }
                $record = $pdo->prepare('INSERT INTO login_attempts (username, ip_address) VALUES (:username, :ip)');
                $record->execute(['username' => $username, 'ip' => $_SERVER['REMOTE_ADDR'] ?? '']);
                $recentAttempts++;
                $cleanup = $pdo->prepare('DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL ' . LOGIN_LOCKOUT_MINUTES . ' MINUTE)');
                $cleanup->execute();
                $logFail = $pdo->prepare("INSERT INTO admin_login_log (admin_id, username, ip_address, user_agent, status) VALUES (:aid, :username, :ip, :ua, 'failure')");
                $logFail->execute(['aid' => null, 'username' => $username, 'ip' => $_SERVER['REMOTE_ADDR'] ?? '', 'ua' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)]);
            }
            $loginError = true;
        }
    }
}
require_once __DIR__ . '/theme.php';

?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>管理系統登入</title>
    <link rel="stylesheet" href="assets/css/admin.css">
    <style>
        .login-links { text-align: center; margin-top: 16px; }
        .login-links a { color: #5d7fc4; text-decoration: none; font-size: 14px; }
        .login-links a:hover { text-decoration: underline; }
        .login-success { color: #27ae60; font-size: 14px; text-align: center; padding: 8px 0; }
    </style>
</head>
<body class="login-page" data-theme="<?= $styleOption ?>">
    <main class="login-card">
        <div class="brand-mark" aria-hidden="true">A</div>
        <p class="eyebrow">ADMINISTRATION</p>
        <h1>管理系統登入</h1>
        <p class="page-description">請輸入您的帳號與密碼</p>

        <?php if ($forgotMode): ?>
            <p class="page-description" style="font-size:14px;">請輸入管理員登記的電子郵件，我們將發送重置郵件。</p>
            <?php if ($forgotMessage !== null): ?>
                <p class="<?= $forgotIsError ? 'login-error' : 'login-success' ?>" role="alert"><?= $forgotMessage ?></p>
            <?php endif; ?>
            <form class="login-form" action="" method="post">
                <label for="forgot_email">管理者電子郵件</label>
                <input id="forgot_email" name="forgot_email" type="email" placeholder="請輸入管理員登記的 Email" autocomplete="email" required>
                <input type="hidden" name="forgot_submit" value="1">
                <button class="button button-primary login-submit" type="submit">發送重置郵件</button>
            </form>
            <p class="login-links"><a href="login.php">&larr; 返回登入</a></p>
        <?php else: ?>
            <?php if ($loginLockout > 0): ?>
                <p class="login-error" role="alert">帳號已暫時鎖定，請 <?= $loginLockout ?> 分鐘後再試。</p>
            <?php elseif ($loginError): ?>
                <?php $remaining = LOGIN_MAX_ATTEMPTS - $recentAttempts; ?>
                <p class="login-error" role="alert">
                    帳號或密碼錯誤，請再試一次。
                    <?php if ($remaining <= 2 && $remaining > 0): ?>
                        <br><small>剩余 <?= $remaining ?> 次機會後將鎖定 <?= LOGIN_LOCKOUT_MINUTES ?> 分鐘。</small>
                    <?php endif; ?>
                </p>
            <?php endif; ?>
            <form class="login-form" action="" method="post">
                <label for="username">帳號</label>
                <input id="username" name="username" type="text" placeholder="請輸入帳號" autocomplete="username" required>
                <label for="password">密碼</label>
                <input id="password" name="password" type="password" placeholder="請輸入密碼" autocomplete="current-password" required>
                <button class="button button-primary login-submit" type="submit">登入</button>
            </form>
            <p class="login-links"><a href="login.php?forgot=1">忘記密碼？</a></p>
        <?php endif; ?>
    </main>
</body>
</html>

