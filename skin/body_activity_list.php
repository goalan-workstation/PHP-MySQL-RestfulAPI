<?php
declare(strict_types=1);

// body_activity_list.php：最新活動 API（RESTful JSON；index.html?activity 以 fetch 呼叫）
// GET skin/body_activity_list.php?page=1   → 列表（每頁 12 筆，只取 CHK=1）
// GET skin/body_activity_list.php?id=123   → 單筆內文（CHK=1 才可看）

require_once __DIR__ . '/../conn/conn.inc.php';
require_once __DIR__ . '/../api_common.php';

const ACTIVITY_PAGE_SIZE = 12;

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
    'activity_enabled' => ($info['blocks']['activity'] ?? false) === true,
    'cart_count' => frontendSessionCartQty(),
    'logged_in' => $member['logged_in'],
    'member_name' => $member['member_name'],
    'csrf_token' => frontendCsrfToken(),
    'urls' => [
        'home' => 'index.html',
        'activity_list' => 'index.html?activity',
    ],
];

// ── 單筆內文 ──────────────────────────────────────────────
$idParam = $_GET['id'] ?? null;
if ($idParam !== null) {
    $id = filter_var($idParam, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) {
        apiError('無效的活動編號。');
    }
    $stmt = $pdo->prepare('SELECT id, title, title_2, description, activity_pic, activity_spic, creat_time, updata_time FROM activity WHERE id = :id AND `CHK` = 1 LIMIT 1');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();
    if ($row === false) {
        apiError('找不到這則活動。', 404);
    }
    $pic = (string) $row['activity_pic'] !== '' ? (string) $row['activity_pic'] : (string) $row['activity_spic'];
    $site['detail'] = [
        'id' => (int) $row['id'],
        'title' => (string) $row['title'],
        'title_2' => (string) $row['title_2'],
        'description' => (string) $row['description'],
        'pic' => frontendImageUrl('SYSTEM/activity', $pic),
        'creat_time' => (string) $row['creat_time'],
        'updat_time' => (string) $row['updata_time'],
        'back_url' => 'index.html?activity',
    ];
    apiJson($site);
}

// ── 列表（分頁）──────────────────────────────────────────────
$page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$page = $page === false ? 1 : $page;
$items = [];
$total = 0;
if (($info['blocks']['activity'] ?? false) === true) {
    $total = (int) $pdo->query('SELECT COUNT(*) FROM activity WHERE `CHK` = 1')->fetchColumn();
    $offset = ($page - 1) * ACTIVITY_PAGE_SIZE;
    $stmt = $pdo->prepare('SELECT id, title, title_2, activity_pic, activity_spic, creat_time FROM activity WHERE `CHK` = 1 ORDER BY data_sort ASC, id DESC LIMIT :limit OFFSET :offset');
    $stmt->bindValue(':limit', ACTIVITY_PAGE_SIZE, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    foreach ($stmt->fetchAll() as $row) {
        $pic = (string) $row['activity_pic'] !== '' ? (string) $row['activity_pic'] : (string) $row['activity_spic'];
        $items[] = [
            'id' => (int) $row['id'],
            'title' => (string) $row['title'],
            'title_2' => (string) $row['title_2'],
            'pic' => frontendImageUrl('SYSTEM/activity', $pic),
            'creat_time' => (string) $row['creat_time'],
            'detail_url' => 'index.html?activity&id=' . (int) $row['id'],
        ];
    }
}

$site['list'] = $items;
$site['page'] = $page;
$site['page_size'] = ACTIVITY_PAGE_SIZE;
$site['total'] = $total;
$site['total_pages'] = $total > 0 ? (int) ceil($total / ACTIVITY_PAGE_SIZE) : 1;
apiJson($site);
