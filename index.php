<?php
declare(strict_types=1);

// index.php：前台共用設定 + 首頁 API（RESTful JSON；靜態 index.html 以 fetch 呼叫）
// GET  index.php?action=site     → 網站標題/描述/橫幅/色系/區塊開關/購物車數/登入狀態
// GET  index.php?action=home     → site + 首頁四區塊（news 5 / activity 4 / album 全部 / products 5）
// POST index.php?action=login    → {user_id, user_pwd} 會員登入（member.user_id + bcrypt）
// POST index.php?action=logout   → 會員登出

require_once __DIR__ . '/conn/conn.inc.php';
require_once __DIR__ . '/api_common.php';

$action = $_GET['action'] ?? $_POST['action'] ?? 'home';
if (!is_string($action)) {
    $action = 'home';
}

$info = frontendWebInfo($pdo);
$styleOption = frontendStyleOption($pdo);
$themes = frontendThemes();
$member = frontendMemberStatus();
$aboutContent = '';
$aboutUpdated = '';
try {
    $aboutRow = $pdo->query('SELECT about_us, updata_time FROM about_us ORDER BY id LIMIT 1')->fetch();
    if ($aboutRow !== false) {
        $aboutContent = (string) ($aboutRow['about_us'] ?? '');
        $aboutUpdated = (string) ($aboutRow['updata_time'] ?? '');
    }
} catch (Throwable) {
    $aboutContent = '';
    $aboutUpdated = '';
}
$site = [
    'success' => true,
    'title' => $info['title'],
    'description' => $info['description'],
    'banner' => $info['banner'],
    'style_option' => $styleOption,
    'theme' => $themes[$styleOption],
    'themes' => $themes,
    'blocks' => $info['blocks'],
    'site_enabled' => $info['site_enabled'],
    'cart_count' => frontendSessionCartQty(),
    'logged_in' => $member['logged_in'],
    'member_id' => $member['member_id'],
    'member_name' => $member['member_name'],
    'csrf_token' => frontendCsrfToken(),
    'about_content' => $aboutContent,
    'about_updated' => $aboutUpdated,
    'urls' => [
        'home' => 'index.html',
        'news_list' => 'index.html?news',
        'activity_list' => 'index.html?activity',
        'album_list' => 'index.html?album',
        'products_list' => 'index.html?products',
        'member' => 'index.html?member',
        'about' => 'aboutus.html',
        'contact' => 'contactus.html',
        'cart' => 'shopping_cart.html',
    ],
];

if ($action === 'site') {
    apiJson($site);
}

if ($action === 'home') {
    $blocks = $site['blocks'];
    $news = [];
    if (($blocks['news'] ?? false) === true) {
        $stmt = $pdo->prepare('SELECT id, title, title_2, news_pic, creat_time FROM news WHERE `CHK` = 1 ORDER BY data_sort ASC, id DESC LIMIT :limit');
        $stmt->bindValue(':limit', 3, PDO::PARAM_INT);
        $stmt->execute();
        foreach ($stmt->fetchAll() as $row) {
            $news[] = [
                'id' => (int) $row['id'],
                'title' => (string) $row['title'],
                'title_2' => (string) $row['title_2'],
                'pic' => frontendImageUrl('SYSTEM/news', (string) $row['news_pic']),
                'creat_time' => (string) $row['creat_time'],
                'detail_url' => 'index.html?news&id=' . (int) $row['id'],
            ];
        }
    }

    $activity = [];
    if (($blocks['activity'] ?? false) === true) {
        $stmt = $pdo->prepare('SELECT id, title, title_2, activity_pic, activity_spic, creat_time FROM activity WHERE `CHK` = 1 ORDER BY data_sort ASC, id DESC');
        $stmt->execute();
        foreach ($stmt->fetchAll() as $row) {
            $pic = (string) $row['activity_pic'] !== '' ? (string) $row['activity_pic'] : (string) $row['activity_spic'];
            $activity[] = [
                'id' => (int) $row['id'],
                'title' => (string) $row['title'],
                'title_2' => (string) $row['title_2'],
                'pic' => frontendImageUrl('SYSTEM/activity', $pic),
                'creat_time' => (string) $row['creat_time'],
                'detail_url' => 'index.html?activity&id=' . (int) $row['id'],
            ];
        }
    }

    $album = [];
    if (($blocks['album'] ?? false) === true) {
        $rows = $pdo->query('SELECT id, title, album_pic, album_jason_pic FROM album WHERE `CHK` = 1 ORDER BY data_sort ASC, id DESC')->fetchAll();
        foreach ($rows as $row) {
            $count = 0;
            try {
                $decoded = json_decode((string) $row['album_jason_pic'], true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    $count = count($decoded);
                }
            } catch (Throwable) {
                $count = 0;
            }
            $album[] = [
                'id' => (int) $row['id'],
                'title' => (string) $row['title'],
                'pic' => frontendImageUrl('SYSTEM/album', (string) $row['album_pic']),
                'photo_count' => $count,
                'detail_url' => 'index.html?album&id=' . (int) $row['id'],
            ];
        }
    }

    $products = [];
    if (($blocks['product'] ?? false) === true) {
        $stmt = $pdo->prepare('SELECT id, title, title_2, product_pic, product_num, product_renum, product_price FROM products WHERE `CHK` = 1 ORDER BY data_sort ASC, id DESC LIMIT :limit');
        $stmt->bindValue(':limit', 8, PDO::PARAM_INT);
        $stmt->execute();
        foreach ($stmt->fetchAll() as $row) {
            $products[] = [
                'id' => (int) $row['id'],
                'title' => (string) $row['title'],
                'title_2' => (string) $row['title_2'],
                'pic' => frontendImageUrl('SYSTEM/product', (string) $row['product_pic']),
                'price' => (int) $row['product_price'],
                'product_num' => (int) ($row['product_num'] ?? 0),
                'product_renum' => (int) ($row['product_renum'] ?? 0),
                'detail_url' => 'index.html?products&id=' . (int) $row['id'],
            ];
        }
    }

    $site['home'] = ['news' => $news, 'activity' => $activity, 'album' => $album, 'products' => $products];
    apiJson($site);
}

if ($action === 'login') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        apiError('請使用 POST 登入。', 405);
    }
    $userId = trim((string) ($_POST['user_id'] ?? ''));
    $userPwd = (string) ($_POST['user_pwd'] ?? '');
    if ($userId === '' || $userPwd === '') {
        apiError('請輸入帳號與密碼。');
    }
    $stmt = $pdo->prepare('SELECT id, member_name, user_pwd, `CHK` FROM member WHERE user_id = :user_id LIMIT 1');
    $stmt->execute(['user_id' => $userId]);
    $memberRow = $stmt->fetch();
    if ($memberRow === false || (int) $memberRow['CHK'] !== 1) {
        apiError('帳號不存在或尚未啟用。', 401);
    }
    if (!password_verify($userPwd, (string) $memberRow['user_pwd'])) {
        apiError('密碼錯誤。', 401);
    }
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $_SESSION['member_id'] = (int) $memberRow['id'];
    $_SESSION['member_name'] = (string) $memberRow['member_name'];
    apiJson(['success' => true, 'member_id' => (int) $memberRow['id'], 'member_name' => (string) $memberRow['member_name']]);
}

if ($action === 'logout') {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    unset($_SESSION['member_id'], $_SESSION['member_name']);
    apiJson(['success' => true]);
}

apiError('未知的 action。', 404);
