<?php
declare(strict_types=1);

// body_shopping_cart.php：購物車 session 暫存 API（RESTful JSON；shopping_cart.html 以 fetch 呼叫）
// POST body_shopping_cart.php?action=add    → 加入購物車（product_id + qty，同一 session 沿用同一組訂單編號，只累加品項）
// POST body_shopping_cart.php?action=update → 更新數量（product_id + qty，qty=0 移除，超過庫存擋下）
// POST body_shopping_cart.php?action=remove → 移除單品（product_id）
// POST body_shopping_cart.php?action=clear  → 清空購物車（保留訂單編號）
// POST body_shopping_cart.php?action=checkout → 結帳（需登入；寫 shopping_cart：member_id + cart_code + jason_cart[收件資料+購物清單]）
// GET  body_shopping_cart.php               → site 設定 + session 購物車（cart_code + 品項 + 總數）

require_once __DIR__ . '/../conn/conn.inc.php';
require_once __DIR__ . '/../api_common.php';

$info = frontendWebInfo($pdo);
$styleOption = frontendStyleOption($pdo);
$themes = frontendThemes();
$member = frontendMemberStatus();

// session 購物車結構：['cart_code' => string, 'items' => [product_id => qty]]
function frontendSessionCart(): array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $cart = $_SESSION['frontend_cart'] ?? null;
    if (!is_array($cart)) {
        $cart = ['cart_code' => '', 'items' => []];
    }
    if (!is_string($cart['cart_code'] ?? null)) {
        $cart['cart_code'] = '';
    }
    if (!is_array($cart['items'] ?? null)) {
        $cart['items'] = [];
    }
    $items = [];
    foreach ($cart['items'] as $productId => $qty) {
        $productId = filter_var($productId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $qty = filter_var($qty, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($productId !== false && $qty !== false) {
            $items[$productId] = $qty;
        }
    }
    $cart = ['cart_code' => $cart['cart_code'], 'items' => $items];
    $_SESSION['frontend_cart'] = $cart;
    return $cart;
}

function frontendCartCode(PDO $pdo): string
{
    $cart = frontendSessionCart();
    if ($cart['cart_code'] !== '') {
        return $cart['cart_code'];
    }
    // 訂單編號：日期 + 隨機，並避開 shopping_cart.cart_code 已存在值
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $code = date('YmdHis') . strtoupper(bin2hex(random_bytes(3)));
        try {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM shopping_cart WHERE cart_code = :code');
            $stmt->execute(['code' => $code]);
            if ((int) $stmt->fetchColumn() === 0) {
                $cart['cart_code'] = $code;
                $_SESSION['frontend_cart'] = $cart;
                return $code;
            }
        } catch (Throwable) {
            $cart['cart_code'] = $code;
            $_SESSION['frontend_cart'] = $cart;
            return $code;
        }
    }
    $cart['cart_code'] = date('YmdHis') . strtoupper(bin2hex(random_bytes(4)));
    $_SESSION['frontend_cart'] = $cart;
    return $cart['cart_code'];
}

// 結帳收件預設值：已登入帶會員檔姓名/信箱/電話/地址
function frontendOrderDefaults(PDO $pdo, array $member): array
{
    $defaults = ['name' => '', 'email' => '', 'phone' => '', 'address' => ''];
    if (!($member['logged_in'] ?? false) || ($member['member_id'] ?? null) === null) {
        return $defaults;
    }
    try {
        $stmt = $pdo->prepare('SELECT member_name, member_email, member_phone, member_address FROM member WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $member['member_id']]);
        $row = $stmt->fetch();
    } catch (Throwable) {
        return $defaults;
    }
    if ($row === false) {
        return $defaults;
    }
    return [
        'name' => (string) ($row['member_name'] ?? ''),
        'email' => (string) ($row['member_email'] ?? ''),
        'phone' => (string) ($row['member_phone'] ?? ''),
        'address' => (string) ($row['member_address'] ?? ''),
    ];
}

function frontendCartResponse(PDO $pdo, array $info, int $styleOption, array $themes, array $member): array
{
    $cart = frontendSessionCart();
    $items = [];
    $totalQty = 0;
    if ($cart['items'] !== []) {
        $ids = array_keys($cart['items']);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare('SELECT id, title, product_pic, product_price FROM products WHERE id IN (' . $placeholders . ') AND `CHK` = 1');
        $stmt->execute($ids);
        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $rows[(int) $row['id']] = $row;
        }
        foreach ($cart['items'] as $productId => $qty) {
            if (!isset($rows[$productId])) {
                continue;
            }
            $row = $rows[$productId];
            $items[] = [
                'product_id' => $productId,
                'title' => (string) $row['title'],
                'pic' => frontendImageUrl('SYSTEM/product', (string) $row['product_pic']),
                'price' => (int) $row['product_price'],
                'qty' => $qty,
                'subtotal' => (int) $row['product_price'] * $qty,
                'detail_url' => 'index.html?products&id=' . $productId,
            ];
            $totalQty += $qty;
        }
    }
    return [
        'success' => true,
        'title' => $info['title'],
        'description' => $info['description'],
        'banner' => $info['banner'],
        'style_option' => $styleOption,
        'theme' => $themes[$styleOption],
        'blocks' => $info['blocks'],
        'site_enabled' => $info['site_enabled'],
        'cart_count' => $totalQty,
        'logged_in' => $member['logged_in'],
        'member_name' => $member['member_name'],
        'csrf_token' => frontendCsrfToken(),
        'cart_code' => $cart['cart_code'],
        'cart_items' => $items,
        'cart_total_qty' => $totalQty,
        'order_defaults' => frontendOrderDefaults($pdo, $member),
        'urls' => [
            'home' => 'index.html',
            'products_list' => 'index.html?products',
            'cart' => 'shopping_cart.html',
        ],
    ];
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ── 加入購物車：產生訂單編號 + 暫存 product_id/qty 到 session ──
if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? null;
    if (!is_string($token) || $token === '' || $token !== frontendCsrfToken()) {
        apiError('無效的表單權杖，請刷新後再試。', 403);
    }
    $productId = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $qty = filter_var($_POST['qty'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($productId === false) {
        apiError('無效的產品編號。');
    }
    if ($qty === false) {
        apiError('訂購數量無效。');
    }
    $stmt = $pdo->prepare('SELECT id, title, product_num FROM products WHERE id = :id AND `CHK` = 1 LIMIT 1');
    $stmt->execute(['id' => $productId]);
    $product = $stmt->fetch();
    if ($product === false) {
        apiError('找不到這件產品或已下架。', 404);
    }
    $stock = (int) $product['product_num'];
    if ($stock <= 0) {
        apiError('這件產品目前沒有庫存。');
    }
    $cart = frontendSessionCart();
    $newQty = ($cart['items'][$productId] ?? 0) + $qty;
    if ($newQty > $stock) {
        apiError('訂購數量超過庫存（剩餘 ' . $stock . ' 件）。');
    }
    $cartCode = frontendCartCode($pdo);
    $cart = frontendSessionCart();
    $cart['items'][$productId] = $newQty;
    $_SESSION['frontend_cart'] = $cart;
    $site = frontendCartResponse($pdo, $info, $styleOption, $themes, $member);
    $site['message'] = '已加入購物車（訂單編號 ' . $cartCode . '）。';
    $site['added'] = ['product_id' => $productId, 'qty' => $newQty, 'title' => (string) $product['title']];
    apiJson($site);
}

// ── 更新數量：product_id + qty（qty=0 視為移除；超過庫存擋下）──
if ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? null;
    if (!is_string($token) || $token === '' || $token !== frontendCsrfToken()) {
        apiError('無效的表單權杖，請刷新後再試。', 403);
    }
    $productId = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $qty = filter_var($_POST['qty'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    if ($productId === false || $qty === false) {
        apiError('數量無效。');
    }
    $cart = frontendSessionCart();
    if (!isset($cart['items'][$productId])) {
        apiError('購物車內沒有這件產品。', 404);
    }
    if ($qty === 0) {
        unset($cart['items'][$productId]);
        $_SESSION['frontend_cart'] = $cart;
        $site = frontendCartResponse($pdo, $info, $styleOption, $themes, $member);
        $site['message'] = '已移除該產品。';
        apiJson($site);
    }
    $stmt = $pdo->prepare('SELECT id, product_num FROM products WHERE id = :id AND `CHK` = 1 LIMIT 1');
    $stmt->execute(['id' => $productId]);
    $product = $stmt->fetch();
    if ($product === false) {
        unset($cart['items'][$productId]);
        $_SESSION['frontend_cart'] = $cart;
        $site = frontendCartResponse($pdo, $info, $styleOption, $themes, $member);
        $site['message'] = '該產品已下架，已從購物車移除。';
        apiJson($site);
    }
    $stock = (int) $product['product_num'];
    if ($qty > $stock) {
        apiError('訂購數量超過庫存（剩餘 ' . $stock . ' 件）。');
    }
    $cart['items'][$productId] = $qty;
    $_SESSION['frontend_cart'] = $cart;
    $site = frontendCartResponse($pdo, $info, $styleOption, $themes, $member);
    $site['message'] = '數量已更新。';
    apiJson($site);
}

// ── 移除單品：product_id ──
if ($action === 'remove' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? null;
    if (!is_string($token) || $token === '' || $token !== frontendCsrfToken()) {
        apiError('無效的表單權杖，請刷新後再試。', 403);
    }
    $productId = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($productId === false) {
        apiError('無效的產品編號。');
    }
    $cart = frontendSessionCart();
    unset($cart['items'][$productId]);
    $_SESSION['frontend_cart'] = $cart;
    $site = frontendCartResponse($pdo, $info, $styleOption, $themes, $member);
    $site['message'] = '已移除該產品。';
    apiJson($site);
}

// ── 清空購物車（保留訂單編號，品項清空）──
if ($action === 'clear' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? null;
    if (!is_string($token) || $token === '' || $token !== frontendCsrfToken()) {
        apiError('無效的表單權杖，請刷新後再試。', 403);
    }
    $cart = frontendSessionCart();
    $cart['items'] = [];
    $_SESSION['frontend_cart'] = $cart;
    $site = frontendCartResponse($pdo, $info, $styleOption, $themes, $member);
    $site['message'] = '購物車已清空。';
    apiJson($site);
}

// ── 結帳：需登入；寫入 shopping_cart（member_id + cart_code + jason_cart），清空 session 購物車 ──
if ($action === 'checkout' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? null;
    if (!is_string($token) || $token === '' || $token !== frontendCsrfToken()) {
        apiError('無效的表單權杖，請刷新後再試。', 403);
    }
    if (!$member['logged_in'] || $member['member_id'] === null) {
        apiError('請先登入會員才能結帳。', 401);
    }
    $cart = frontendSessionCart();
    if ($cart['items'] === [] || $cart['cart_code'] === '') {
        apiError('購物車是空的，無法結帳。');
    }
    // 收件資料：姓名/信箱/電話/地址（未填則用會員檔預設帶入）
    $stmt = $pdo->prepare('SELECT member_name, member_email, member_phone, member_address FROM member WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $member['member_id']]);
    $memberRow = $stmt->fetch();
    if ($memberRow === false) {
        apiError('找不到會員資料，請重新登入。', 401);
    }
    $orderName = trim((string) ($_POST['order_name'] ?? ''));
    $orderEmail = trim((string) ($_POST['order_email'] ?? ''));
    $orderPhone = trim((string) ($_POST['order_phone'] ?? ''));
    $orderAddress = trim((string) ($_POST['order_address'] ?? ''));
    if ($orderName === '') {
        $orderName = (string) $memberRow['member_name'];
    }
    if ($orderEmail === '') {
        $orderEmail = (string) $memberRow['member_email'];
    }
    if ($orderPhone === '') {
        $orderPhone = (string) $memberRow['member_phone'];
    }
    if ($orderAddress === '') {
        $orderAddress = (string) $memberRow['member_address'];
    }
    if ($orderName === '' || $orderEmail === '' || $orderPhone === '' || $orderAddress === '') {
        apiError('請完整填寫收件人姓名、信箱、電話與地址。');
    }
    if (!filter_var($orderEmail, FILTER_VALIDATE_EMAIL)) {
        apiError('收件人 Email 格式不正確。');
    }
    // 重查產品（上架 + 庫存），組購物清單
    $ids = array_keys($cart['items']);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare('SELECT id, title, title_2, product_price, product_num FROM products WHERE id IN (' . $placeholders . ') AND `CHK` = 1');
    $stmt->execute($ids);
    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $rows[(int) $row['id']] = $row;
    }
    $orderItems = [];
    $orderTotal = 0;
    foreach ($cart['items'] as $productId => $qty) {
        if (!isset($rows[$productId])) {
            apiError('購物車內有已下架的產品，請先移除後再結帳。');
        }
        $row = $rows[$productId];
        $stock = (int) $row['product_num'];
        if ($qty > $stock) {
            apiError('「' . (string) $row['title'] . '」庫存不足（剩餘 ' . $stock . ' 件）。');
        }
        $price = (int) $row['product_price'];
        $orderItems[] = [
            'product' => (string) $row['title'],
            'quantity' => $qty,
        ];
        $orderTotal += $price * $qty;
    }
    // 訂單編號沿用 session 那組；若與 DB 重複則重產一組
    $cartCode = $cart['cart_code'];
    try {
        $check = $pdo->prepare('SELECT COUNT(*) FROM shopping_cart WHERE cart_code = :code');
        $check->execute(['code' => $cartCode]);
        if ((int) $check->fetchColumn() > 0) {
            $_SESSION['frontend_cart']['cart_code'] = '';
            $cartCode = frontendCartCode($pdo);
            $cart = frontendSessionCart();
        }
    } catch (Throwable) {
        // 檢查失敗仍沿用原編號，由 INSERT 唯一約束把關
    }
    // 購物清單：純陣列只留產品名 + 數量（後台 edit.php 逐列顯示）
    $jasonCart = json_encode($orderItems, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    try {
        $insert = $pdo->prepare('INSERT INTO shopping_cart (member_id, cart_code, jason_cart, `CHK`, `view`, creat_time, updata_time) VALUES (:member_id, :cart_code, :jason_cart, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)');
        $insert->execute([
            'member_id' => $member['member_id'],
            'cart_code' => $cartCode,
            'jason_cart' => $jasonCart,
        ]);
    } catch (Throwable) {
        apiError('結帳寫單失敗，請稍後再試。', 500);
    }
    // 結帳成功：清空 session 購物車（含訂單編號，下次加購產新編號）
    $_SESSION['frontend_cart'] = ['cart_code' => '', 'items' => []];
    $site = frontendCartResponse($pdo, $info, $styleOption, $themes, $member);
    $site['message'] = '結帳成功！訂單編號 ' . $cartCode . '，謝謝您的訂購。';
    $site['order'] = ['cart_code' => $cartCode, 'total' => $orderTotal, 'item_count' => count($orderItems)];
    apiJson($site);
}

apiJson(frontendCartResponse($pdo, $info, $styleOption, $themes, $member));
