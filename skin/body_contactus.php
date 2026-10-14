<?php
declare(strict_types=1);

// body_contactus.php：聯絡我們 API（RESTful JSON；靜態 contactus.html 以 fetch 呼叫）
// GET  body_contactus.php          → site 設定（標題/描述/橫幅/色系/區塊/購物車/登入）
// POST body_contactus.php          → 寫入 contact_us（name/email/title/content，CHK=0 未處理）

require_once __DIR__ . '/../conn/conn.inc.php';
require_once __DIR__ . '/../api_common.php';

$info = frontendWebInfo($pdo);
$styleOption = frontendStyleOption($pdo);
$themes = frontendThemes();
$member = frontendMemberStatus();
$site = [
    'success' => true,
    'title' => $info['title'],
    'description' => $info['description'],
    'banner' => $info['banner'],
    'style_option' => $styleOption,
    'theme' => $themes[$styleOption],
    'blocks' => $info['blocks'],
    'site_enabled' => $info['site_enabled'],
    'cart_count' => frontendSessionCartQty(),
    'logged_in' => $member['logged_in'],
    'member_name' => $member['member_name'],
    'csrf_token' => frontendCsrfToken(),
    'urls' => [
        'home' => 'index.html',
        'contact' => 'contactus.html',
    ],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? null;
    if (!is_string($token) || $token === '' || $token !== frontendCsrfToken()) {
        apiError('無效的表單權杖，請刷新後再試。', 403);
    }
    $name = trim((string) ($_POST['name'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $title = trim((string) ($_POST['title'] ?? ''));
    $content = trim((string) ($_POST['content'] ?? ''));
    if ($name === '' || $email === '' || $title === '' || $content === '') {
        apiError('請完整填寫姓名、Email、主旨與內容。');
    }
    if (mb_strlen($name) > 100 || mb_strlen($email) > 150 || mb_strlen($title) > 200 || mb_strlen($content) > 5000) {
        apiError('欄位內容過長，請縮短後再送出。');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        apiError('請輸入有效的 Email。');
    }
    try {
        $stmt = $pdo->prepare('INSERT INTO contact_us (name, email, title, content, contact_memo, `CHK`, creat_time, updata_time) VALUES (:name, :email, :title, :content, :memo, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)');
        $stmt->execute([
            'name' => $name,
            'email' => $email,
            'title' => $title,
            'content' => $content,
            'memo' => '',
        ]);
    } catch (Throwable) {
        apiError('訊息送出失敗，請稍後再試。', 500);
    }
    $site['message'] = '訊息已送出，我們會盡快回覆，謝謝！';
    apiJson($site);
}

apiJson($site);
