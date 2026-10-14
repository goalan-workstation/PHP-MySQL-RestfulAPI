<?php
declare(strict_types=1);
// skin/body_member_list.php：會員 API

require_once __DIR__ . '/../conn/conn.inc.php';
require_once __DIR__ . '/../api_common.php';

const ORDER_PAGE_SIZE = 10;

$info     = frontendWebInfo($pdo);
$styleOpt = frontendStyleOption($pdo);
$themes   = frontendThemes();
$member   = frontendMemberStatus();
$site = [
    'success'      => true,
    'title'        => $info['title'],
    'description'  => $info['description'],
    'banner'       => $info['banner'],
    'style_option' => $styleOpt,
    'theme'        => $themes[$styleOpt],
    'blocks'       => $info['blocks'],
    'cart_count'   => frontendSessionCartQty(),
    'logged_in'    => $member['logged_in'],
    'member_id'    => $member['member_id'],
    'member_name'  => $member['member_name'],
    'csrf_token'   => frontendCsrfToken(),
];

function verifyCsrf(): void
{
    if (($_POST['csrf_token'] ?? '') !== frontendCsrfToken()) {
        apiError('無效的表單權杖，請刷新後再試。', 403);
    }
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? ($_POST['action'] ?? '');

// ── GET ?activate=CODE：啟用帳號 ─────────────────────────────────────
if ($method === 'GET' && isset($_GET['activate'])) {
    $code = trim((string)$_GET['activate']);
    if (strlen($code) < 16) {
        $site['success'] = false;
        $site['error']   = '無效的啟用連結。';
        apiJson($site, 400);
    }
    $stmt = $pdo->prepare('SELECT id, member_name, member_memo, `CHK` FROM member WHERE member_memo = :code AND `CHK` = 0 LIMIT 1');
    $stmt->execute(['code' => $code]);
    $row = $stmt->fetch();
    if ($row === false) {
        $site['success'] = false;
        $site['error']   = '啟用連結無效或已使用，請重新註冊。';
        apiJson($site, 404);
    }
    $pdo->prepare('UPDATE member SET `CHK` = 1, member_memo = "" WHERE id = :id')->execute(['id' => $row['id']]);
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $_SESSION['member_id']   = (int)$row['id'];
    $_SESSION['member_name'] = (string)$row['member_name'];
    $site['success']     = true;
    $site['member_name'] = (string)$row['member_name'];
    $site['message']     = '帳號已啟用，歡迎！';
    apiJson($site);
}

// ── GET ?edit：會員個人資料 ───────────────────────────────────────────
if ($method === 'GET' && isset($_GET['edit'])) {
    if (!$member['logged_in']) {
        $site['success'] = false;
        $site['error']   = '請先登入。';
        apiJson($site, 401);
    }
    $stmt = $pdo->prepare('SELECT member_name, member_email, member_phone, member_address FROM member WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $member['member_id']]);
    $m = $stmt->fetch();
    if ($m === false) {
        $site['success'] = false;
        $site['error']   = '找不到會員資料。';
        apiJson($site, 404);
    }
    $site['member'] = [
        'member_name'    => (string)$m['member_name'],
        'member_email'   => (string)$m['member_email'],
        'member_phone'   => (string)$m['member_phone'],
        'member_address' => (string)$m['member_address'],
    ];
    apiJson($site);
}

// ── 會員登入驗證：帳號格式 + 密碼長度 + 連續失敗鎖定 ──
// 規則：帳號 4-20 字元（英數._-）；密碼至少 6 字元；同一帳號連續失敗 5 次鎖 15 分鐘
const LOGIN_MAX_FAILS = 5;
const LOGIN_LOCK_MINUTES = 15;

function loginClientIp(): string
{
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    if (is_string($ip) && str_contains($ip, ',')) {
        $ip = trim(explode(',', $ip)[0]);
    }
    return is_string($ip) ? substr($ip, 0, 45) : '';
}

function loginEnsureTable(PDO $pdo): void
{
    try {
        $pdo->exec('CREATE TABLE IF NOT EXISTS login_attempts (id INT UNSIGNED NOT NULL AUTO_INCREMENT, username VARCHAR(100) NOT NULL, ip_address VARCHAR(45) NOT NULL DEFAULT \'\', attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), INDEX idx_username_time (username, attempted_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    } catch (Throwable) {
        // 建表失敗則略過鎖定（不擋正常登入）
    }
}

function loginRecentFails(PDO $pdo, string $username): int
{
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE username = :username AND attempted_at >= (NOW() - INTERVAL ' . LOGIN_LOCK_MINUTES . ' MINUTE)');
        $stmt->execute(['username' => $username]);
        return (int) $stmt->fetchColumn();
    } catch (Throwable) {
        return 0;
    }
}

function loginRecordFail(PDO $pdo, string $username): void
{
    try {
        $stmt = $pdo->prepare('INSERT INTO login_attempts (username, ip_address, attempted_at) VALUES (:username, :ip, NOW())');
        $stmt->execute(['username' => $username, 'ip' => loginClientIp()]);
    } catch (Throwable) {
        // 記錄失敗不擋流程
    }
}

function loginClearFails(PDO $pdo, string $username): void
{
    try {
        $pdo->prepare('DELETE FROM login_attempts WHERE username = :username')->execute(['username' => $username]);
    } catch (Throwable) {
        // 清除失敗不擋流程
    }
}

// ── POST ?action=login ──────────────────────────────────────────────
if ($action === 'login' && $method === 'POST') {
    verifyCsrf();
    $userId = trim((string)($_POST['user_id'] ?? ''));
    $pwd    = (string)($_POST['user_pwd'] ?? '');
    if ($userId === '' || $pwd === '') apiError('請輸入帳號與密碼。');
    // 帳號格式：4-20 字元，英數 + ._-
    if (!preg_match('/^[A-Za-z0-9._-]{4,20}$/', $userId)) apiError('帳號格式不正確（4-20 字元，僅英數及 ._ -）。', 400);
    // 密碼長度：至少 6 字元
    if (strlen($pwd) < 6) apiError('密碼至少需要 6 個字元。', 400);
    // 連續失敗鎖定
    loginEnsureTable($pdo);
    $recentFails = loginRecentFails($pdo, $userId);
    if ($recentFails >= LOGIN_MAX_FAILS) apiError('登入失敗次數過多，帳號已暫時鎖定 ' . LOGIN_LOCK_MINUTES . ' 分鐘，請稍後再試。', 429);
    $stmt = $pdo->prepare('SELECT id, member_name, user_pwd, `CHK` FROM member WHERE user_id = :uid LIMIT 1');
    $stmt->execute(['uid' => $userId]);
    $row = $stmt->fetch();
    if ($row === false) { loginRecordFail($pdo, $userId); apiError('帳號不存在。', 401); }
    $chk = (int)$row['CHK'];
    if ($chk === 0) apiError('帳號尚未啟用，請開啟信箱中的確認信完成啟用。', 403);
    if ($chk === 2) apiError('帳號已被停用，請聯絡客服。', 403);
    if (!password_verify($pwd, (string)$row['user_pwd'])) {
        loginRecordFail($pdo, $userId);
        $remain = LOGIN_MAX_FAILS - loginRecentFails($pdo, $userId);
        apiError($remain > 0 ? '密碼錯誤（剩餘嘗試 ' . $remain . ' 次）。' : '登入失敗次數過多，帳號已暫時鎖定 ' . LOGIN_LOCK_MINUTES . ' 分鐘。', 401);
    }
    loginClearFails($pdo, $userId);
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $_SESSION['member_id']   = (int)$row['id'];
    $_SESSION['member_name'] = (string)$row['member_name'];
    $site['success']     = true;
    $site['logged_in']   = true;
    $site['member_name'] = (string)$row['member_name'];
    $site['message']     = '登入成功。';
    apiJson($site);
}

// ── POST ?action=register ───────────────────────────────────────────
if ($action === 'register' && $method === 'POST') {
    verifyCsrf();
    $mName  = trim((string)($_POST['member_name'] ?? ''));
    $mEmail = trim((string)($_POST['member_email'] ?? ''));
    $userId = trim((string)($_POST['user_id'] ?? ''));
    $pwd    = (string)($_POST['user_pwd'] ?? '');
    if ($mName === '' || $mEmail === '' || $userId === '' || $pwd === '') {
        apiError('請完整填寫所有欄位。');
    }
    if (!filter_var($mEmail, FILTER_VALIDATE_EMAIL)) apiError('Email 格式不正確。');
    if (strlen($pwd) < 6) apiError('密碼至少需要 6 個字元。');
    // Email 重複檢查
    $stmt = $pdo->prepare('SELECT id, `CHK` FROM member WHERE member_email = :email LIMIT 1');
    $stmt->execute(['email' => $mEmail]);
    $existing = $stmt->fetch();
    if ($existing !== false) {
        if ((int)$existing['CHK'] === 0) {
            apiError('此 Email 已註冊但尚未啟用，請至信箱完成啟用。');
        }
        apiError('此 Email 已被註冊，請改用其他 Email 或直接登入。', 409);
    }
    // user_id 重複檢查
    $stmt2 = $pdo->prepare('SELECT id FROM member WHERE user_id = :uid LIMIT 1');
    $stmt2->execute(['uid' => $userId]);
    if ($stmt2->fetch() !== false) apiError('此帳號名稱已被使用，請更換。', 409);
    // 建立帳號（立即啟用，CHK=1；member_memo 僅供後台管理員註記，不在此寫入）
    $stmt3 = $pdo->prepare(
        'INSERT INTO member (member_name, member_email, user_id, user_pwd, CHK, creat_time, updata_time)
         VALUES (:name, :email, :uid, :pwd, 1, NOW(), NOW())'
    );
    $stmt3->execute([
        'name'  => $mName, 'email' => $mEmail, 'uid' => $userId,
        'pwd'   => password_hash($pwd, PASSWORD_DEFAULT),
    ]);
    $site['success'] = true;
    $site['message'] = "註冊成功！現在可以直接登入使用。";
    apiJson($site);
}

// ── POST ?action=edit ───────────────────────────────────────────────
if ($action === 'edit' && $method === 'POST') {
    verifyCsrf();
    if (!$member['logged_in']) apiError('請先登入。', 401);
    $memberId = $member['member_id'];
    $mName    = trim((string)($_POST['member_name'] ?? ''));
    $mEmail   = trim((string)($_POST['member_email'] ?? ''));
    $mPhone   = trim((string)($_POST['member_phone'] ?? ''));
    $mAddr    = trim((string)($_POST['member_address'] ?? ''));
    $newPwd   = (string)($_POST['user_pwd'] ?? '');
    if ($mName === '') apiError('暱稱不可為空。');
    if ($mEmail !== '' && !filter_var($mEmail, FILTER_VALIDATE_EMAIL)) apiError('Email 格式不正確。');
    if ($newPwd !== '' && strlen($newPwd) < 6) apiError('新密碼至少需要 6 個字元。');
    if ($mEmail !== '') {
        $stmt = $pdo->prepare('SELECT id FROM member WHERE member_email = :email AND id != :id LIMIT 1');
        $stmt->execute(['email' => $mEmail, 'id' => $memberId]);
        if ($stmt->fetch() !== false) apiError('此 Email 已被其他帳號使用。', 409);
    }
    $sql    = 'UPDATE member SET member_name=:name, member_phone=:phone, member_address=:addr, updata_time=NOW()';
    $params = ['name' => $mName, 'phone' => $mPhone, 'addr' => $mAddr];
    if ($mEmail !== '') { $sql .= ', member_email=:email'; $params['email'] = $mEmail; }
    if ($newPwd !== '' && strlen($newPwd) >= 6) { $sql .= ', user_pwd=:pwd'; $params['pwd'] = password_hash($newPwd, PASSWORD_DEFAULT); }
    $sql .= ' WHERE id=:id';
    $params['id'] = $memberId;
    $pdo->prepare($sql)->execute($params);
    $_SESSION['member_name'] = $mName;
    $site['success'] = true;
    $site['message'] = '會員資料已更新。';
    apiJson($site);
}

// ── GET/POST ?action=logout ─────────────────────────────────────────────
if ($action === 'logout') {
    if ($method === 'POST') {
        verifyCsrf();
    }
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    unset($_SESSION['member_id'], $_SESSION['member_name']);
    if ($method === 'GET') {
        header('Location: ../index.html?member');
        exit;
    }
    $site['success']     = true;
    $site['logged_in']   = false;
    $site['member_name'] = '';
    $site['message']     = '已登出。';
    apiJson($site);
}

// ── POST ?action=forgot ─────────────────────────────────────────────
if ($action === 'forgot' && $method === 'POST') {
    $mEmail = trim((string)($_POST['member_email'] ?? ''));
    if ($mEmail === '' || !filter_var($mEmail, FILTER_VALIDATE_EMAIL)) apiError('請輸入有效的 Email。');
    $stmt = $pdo->prepare('SELECT id, member_name, member_email FROM member WHERE member_email = :email AND `CHK` IN (0,1) LIMIT 1');
    $stmt->execute(['email' => $mEmail]);
    $row = $stmt->fetch();
    if ($row !== false) {
        $resetCode = bin2hex(random_bytes(16));
        $resetUrl  = 'index.html?member&reset=' . $resetCode;
        $subject   = "密碼重置 - " . $info['title'];
        $body      = "您好，\n\n收到密碼重置請求。請點以下連結重置密碼：\n{$resetUrl}\n\n如非本人操作請忽略此信。\n";
        @mail($mEmail, $subject, $body, "From: no-reply@" . (($_SERVER['SERVER_NAME'] ?? 'localhost')) . "\r\n");
    }
    $site['success'] = true;
    $site['message'] = '若該 Email 已註冊，重置連結已寄出，請檢查信箱。';
    apiJson($site);
}

// ── GET default：會員狀態 + 購買紀錄 ─────────────────────────────────
$page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$page = $page === false ? 1 : $page;
$orders      = [];
$totalOrders = 0;
$totalPages  = 1;
if ($member['logged_in']) {
    $stTotal = $pdo->prepare('SELECT COUNT(*) FROM shopping_cart WHERE member_id = :id AND `CHK` = 1');
    $stTotal->execute(['id' => $member['member_id']]);
    $totalOrders = (int)$stTotal->fetchColumn();
    $totalPages  = max(1, (int)ceil($totalOrders / ORDER_PAGE_SIZE));
    $offset = ($page - 1) * ORDER_PAGE_SIZE;
    $st = $pdo->prepare(
        'SELECT cart_code, creat_time, jason_cart FROM shopping_cart
         WHERE member_id = :id AND `CHK` = 1
         ORDER BY creat_time DESC
         LIMIT :limit OFFSET :offset'
    );
    $st->bindValue('id', $member['member_id'], PDO::PARAM_INT);
    $st->bindValue('limit', ORDER_PAGE_SIZE, PDO::PARAM_INT);
    $st->bindValue('offset', $offset, PDO::PARAM_INT);
    $st->execute();
    foreach ($st->fetchAll() as $row) {
        $cnt = 0;
        try {
            $decoded = json_decode((string)$row['jason_cart'], true);
            if (is_array($decoded)) $cnt = count($decoded);
        } catch (Throwable) { /* ignore */ }
        $orders[] = [
            'cart_code'   => (string)$row['cart_code'],
            'creat_time'  => (string)$row['creat_time'],
            'item_count'  => $cnt,
            'status_text' => '已完成',
        ];
    }
}
$site['orders']       = $orders;
$site['total_orders'] = $totalOrders;
$site['orders_page']  = $page;
$site['total_pages']  = $totalPages;
apiJson($site);

$site['total_orders'] = $totalOrders;
$site['orders_page']  = $page;
$site['total_pages']  = $totalPages;
apiJson($site);


