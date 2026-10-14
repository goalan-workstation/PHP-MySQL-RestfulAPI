<?php
declare(strict_types=1);

// body_news_list.php：最新消息 API（RESTful JSON；靜態 skin/body_news_list.html 以 fetch 呼叫）
// GET skin/body_news_list.php?page=1       → 列表（每頁 12 筆，只取 CHK=1）
// GET skin/body_news_list.php?id=123       → 單筆內文（CHK=1 才可看）
// 共用：site 設定（標題/描述/橫幅/色系/區塊/購物車/登入）+ news 區塊開關

require_once __DIR__ . '/../conn/conn.inc.php';
require_once __DIR__ . '/../api_common.php';

const NEWS_PAGE_SIZE = 12;

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
    'news_enabled' => ($info['blocks']['news'] ?? false) === true,
    'cart_count' => frontendSessionCartQty(),
    'logged_in' => $member['logged_in'],
    'member_name' => $member['member_name'],
    'csrf_token' => frontendCsrfToken(),
    'urls' => [
        'home' => 'index.html',
        'news_list' => 'index.html?news',
    ],
];

$idParam = $_GET['id'] ?? null;
if ($idParam !== null) {
    $id = filter_var($idParam, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) {
        apiError('無效的消息編號。');
    }
    $stmt = $pdo->prepare('SELECT id, title, title_2, description, news_pic, creat_time, updata_time FROM news WHERE id = :id AND `CHK` = 1 LIMIT 1');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();
    if ($row === false) {
        apiError('找不到這則消息。', 404);
    }
    $site['detail'] = [
        'id' => (int) $row['id'],
        'title' => (string) $row['title'],
        'title_2' => (string) $row['title_2'],
        'description' => (string) $row['description'],
        'pic' => frontendImageUrl('SYSTEM/news', (string) $row['news_pic']),
        'creat_time' => (string) $row['creat_time'],
        'updat_time' => (string) $row['updata_time'],
        'back_url' => 'index.html?news',
    ];
    apiJson($site);
}

$page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$page = $page === false ? 1 : $page;
$items = [];
$total = 0;
if (($info['blocks']['news'] ?? false) === true) {
    $total = (int) $pdo->query('SELECT COUNT(*) FROM news WHERE `CHK` = 1')->fetchColumn();
    $offset = ($page - 1) * NEWS_PAGE_SIZE;
    $stmt = $pdo->prepare('SELECT id, title, title_2, news_pic, creat_time FROM news WHERE `CHK` = 1 ORDER BY data_sort ASC, id DESC LIMIT :limit OFFSET :offset');
    $stmt->bindValue(':limit', NEWS_PAGE_SIZE, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    foreach ($stmt->fetchAll() as $row) {
        $items[] = [
            'id' => (int) $row['id'],
            'title' => (string) $row['title'],
            'title_2' => (string) $row['title_2'],
            'pic' => frontendImageUrl('SYSTEM/news', (string) $row['news_pic']),
            'creat_time' => (string) $row['creat_time'],
            'detail_url' => 'index.html?news&id=' . (int) $row['id'],
        ];
    }
}

$site['list'] = $items;
$site['page'] = $page;
$site['page_size'] = NEWS_PAGE_SIZE;
$site['total'] = $total;
$site['total_pages'] = $total > 0 ? (int) ceil($total / NEWS_PAGE_SIZE) : 1;
apiJson($site);

