<?php
declare(strict_types=1);

// body_album_list.php：相簿 API（RESTful JSON；index.html?album 以 fetch 呼叫）
// GET skin/body_album_list.php?page=1   → 列表（每頁 12 筆，只取 CHK=1）
// GET skin/body_album_list.php?id=123   → 單筆內頁（含照片清單）

require_once __DIR__ . '/../conn/conn.inc.php';
require_once __DIR__ . '/../api_common.php';

const ALBUM_PAGE_SIZE = 12;

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
    'album_enabled' => ($info['blocks']['album'] ?? false) === true,
    'cart_count' => frontendSessionCartQty(),
    'logged_in' => $member['logged_in'],
    'member_name' => $member['member_name'],
    'csrf_token' => frontendCsrfToken(),
    'urls' => [
        'home' => 'index.html',
        'album_list' => 'index.html?album',
    ],
];

// ── 單筆內頁（含照片）────────────────────────────────────────────────
$idParam = $_GET['id'] ?? null;
if ($idParam !== null) {
    $id = filter_var($idParam, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) {
        apiError('無效的相簿編號。');
    }
    $stmt = $pdo->prepare('SELECT id, title, album_pic, album_jason_pic FROM album WHERE id = :id AND `CHK` = 1 LIMIT 1');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();
    if ($row === false) {
        apiError('找不到這個相簿。', 404);
    }
    $photos = [];
    try {
        $decoded = json_decode((string) $row['album_jason_pic'], true, 512, JSON_THROW_ON_ERROR);
        if (is_array($decoded)) {
            foreach ($decoded as $item) {
                if (is_array($item)) {
                    // {"original":"../album_sub/img/xxx.jpg"}  (相對於 SYSTEM/album/)
                    $orig = str_replace(['../', '..\\'], '', (string)($item['original'] ?? ''));
                    $photos[] = 'SYSTEM/' . $orig;
                } elseif (is_string($item)) {
                    // plain filename (backward compat)
                    $photos[] = frontendImageUrl('SYSTEM/album', $item);
                }
            }
        }
    } catch (Throwable) {
        $photos = [];
    }
    $site['detail'] = [
        'id' => (int) $row['id'],
        'title' => (string) $row['title'],
        'cover' => frontendImageUrl('SYSTEM/album', (string) $row['album_pic']),
        'photos' => $photos,
        'back_url' => 'index.html?album',
    ];
    apiJson($site);
}

// ── 列表（分頁）──────────────────────────────────────────────────────
$page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$page = $page === false ? 1 : $page;
$items = [];
$total = 0;
if (($info['blocks']['album'] ?? false) === true) {
    $total = (int) $pdo->query('SELECT COUNT(*) FROM album WHERE `CHK` = 1')->fetchColumn();
    $offset = ($page - 1) * ALBUM_PAGE_SIZE;
    $stmt = $pdo->prepare('SELECT id, title, album_pic, album_jason_pic FROM album WHERE `CHK` = 1 ORDER BY data_sort ASC, id DESC LIMIT :limit OFFSET :offset');
    $stmt->bindValue(':limit', ALBUM_PAGE_SIZE, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    foreach ($stmt->fetchAll() as $row) {
        $count = 0;
        try {
            $decoded = json_decode((string) $row['album_jason_pic'], true, 512, JSON_THROW_ON_ERROR);
            if (is_array($decoded)) {
                $count = count($decoded);
            }
        } catch (Throwable) {
            $count = 0;
        }
        $items[] = [
            'id' => (int) $row['id'],
            'title' => (string) $row['title'],
            'pic' => frontendImageUrl('SYSTEM/album', (string) $row['album_pic']),
            'photo_count' => $count,
            'detail_url' => 'index.html?album&id=' . (int) $row['id'],
        ];
    }
}

$site['list'] = $items;
$site['page'] = $page;
$site['page_size'] = ALBUM_PAGE_SIZE;
$site['total'] = $total;
$site['total_pages'] = $total > 0 ? (int) ceil($total / ALBUM_PAGE_SIZE) : 1;
apiJson($site);
