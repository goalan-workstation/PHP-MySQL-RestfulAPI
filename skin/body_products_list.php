<?php
declare(strict_types=1);

// body_products_list.php：產品專區 API（RESTful JSON；index.html?products 以 fetch 呼叫）
// GET skin/body_products_list.php?page=1   → 列表（每頁 12 筆，只取 CHK=1）
// GET skin/body_products_list.php?id=123   → 單筆內文（含多圖，CHK=1 才可看）

require_once __DIR__ . '/../conn/conn.inc.php';
require_once __DIR__ . '/../api_common.php';

const PRODUCTS_PAGE_SIZE = 12;

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
    'products_enabled' => ($info['blocks']['product'] ?? false) === true,
    'cart_count' => frontendSessionCartQty(),
    'logged_in' => $member['logged_in'],
    'member_name' => $member['member_name'],
    'csrf_token' => frontendCsrfToken(),
    'urls' => [
        'home' => 'index.html',
        'products_list' => 'index.html?products',
    ],
];

// 產品多圖：product_jason_pic 為 JSON 陣列（img/<hash>.ext），相對於 SYSTEM/product/
function frontendProductPhotos(string $raw): array
{
    $photos = [];
    $raw = trim($raw);
    if ($raw === '') {
        return $photos;
    }
    try {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        return $photos;
    }
    if (!is_array($decoded)) {
        return $photos;
    }
    foreach ($decoded as $item) {
        if (is_string($item) && $item !== '') {
            $photos[] = frontendImageUrl('SYSTEM/product', $item);
        }
    }
    return array_values(array_filter($photos));
}

// ── 單筆內文（含多圖）─────────────────────────────────────────────
$idParam = $_GET['id'] ?? null;
if ($idParam !== null) {
    $id = filter_var($idParam, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) {
        apiError('無效的產品編號。');
    }
    $stmt = $pdo->prepare('SELECT id, title, title_2, title_description, product_pic, product_jason_pic, product_num, product_renum, product_price, data_sort, creat_time, updata_time FROM products WHERE id = :id AND `CHK` = 1 LIMIT 1');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();
    if ($row === false) {
        apiError('找不到這件產品。', 404);
    }
    // 上一筆 / 下一筆（同列表排序 data_sort ASC, id DESC，只取 CHK=1）
    $orderStmt = $pdo->query('SELECT id, title FROM products WHERE `CHK` = 1 ORDER BY data_sort ASC, id DESC');
    $orderedIds = [];
    $orderedTitles = [];
    foreach ($orderStmt->fetchAll() as $orderRow) {
        $orderedIds[] = (int) $orderRow['id'];
        $orderedTitles[(int) $orderRow['id']] = (string) $orderRow['title'];
    }
    $pos = array_search($id, $orderedIds, true);
    $prev = null;
    $next = null;
    if ($pos !== false) {
        if (isset($orderedIds[$pos - 1])) {
            $prevId = $orderedIds[$pos - 1];
            $prev = ['id' => $prevId, 'title' => $orderedTitles[$prevId] ?? '', 'detail_url' => 'index.html?products&id=' . $prevId];
        }
        if (isset($orderedIds[$pos + 1])) {
            $nextId = $orderedIds[$pos + 1];
            $next = ['id' => $nextId, 'title' => $orderedTitles[$nextId] ?? '', 'detail_url' => 'index.html?products&id=' . $nextId];
        }
    }
    $site['detail'] = [
        'id' => (int) $row['id'],
        'title' => (string) $row['title'],
        'title_2' => (string) $row['title_2'],
        'description' => (string) $row['title_description'],
        'pic' => frontendImageUrl('SYSTEM/product', (string) $row['product_pic']),
        'photos' => frontendProductPhotos((string) $row['product_jason_pic']),
        'product_num' => (int) $row['product_num'],
        'product_renum' => (int) $row['product_renum'],
        'price' => (int) $row['product_price'],
        'creat_time' => (string) $row['creat_time'],
        'updat_time' => (string) $row['updata_time'],
        'back_url' => 'index.html?products',
        'prev' => $prev,
        'next' => $next,
    ];
    apiJson($site);
}

// ── 列表（分頁）──────────────────────────────────────────────
$page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$page = $page === false ? 1 : $page;
$items = [];
$total = 0;
if (($info['blocks']['product'] ?? false) === true) {
    $total = (int) $pdo->query('SELECT COUNT(*) FROM products WHERE `CHK` = 1')->fetchColumn();
    $offset = ($page - 1) * PRODUCTS_PAGE_SIZE;
    $stmt = $pdo->prepare('SELECT id, title, title_2, product_pic, product_num, product_renum, product_price FROM products WHERE `CHK` = 1 ORDER BY data_sort ASC, id DESC LIMIT :limit OFFSET :offset');
    $stmt->bindValue(':limit', PRODUCTS_PAGE_SIZE, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    foreach ($stmt->fetchAll() as $row) {
        $items[] = [
            'id' => (int) $row['id'],
            'title' => (string) $row['title'],
            'title_2' => (string) $row['title_2'],
            'pic' => frontendImageUrl('SYSTEM/product', (string) $row['product_pic']),
            'product_num' => (int) $row['product_num'],
            'product_renum' => (int) $row['product_renum'],
            'price' => (int) $row['product_price'],
            'detail_url' => 'index.html?products&id=' . (int) $row['id'],
        ];
    }
}

$site['list'] = $items;
$site['page'] = $page;
$site['page_size'] = PRODUCTS_PAGE_SIZE;
$site['total'] = $total;
$site['total_pages'] = $total > 0 ? (int) ceil($total / PRODUCTS_PAGE_SIZE) : 1;
apiJson($site);
