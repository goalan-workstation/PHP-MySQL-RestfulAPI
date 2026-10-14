<?php
declare(strict_types=1);

// 前台共用 API 底層：JSON 輸出 / CORS-free 同源 / 讀後台 web_info + web_style
// 引用方式：require_once __DIR__ . '/api_common.php';

function apiJson(mixed $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function apiError(string $message, int $status = 400): void
{
    apiJson(['success' => false, 'error' => $message], $status);
}

// 後台五色系（對應 SYSTEM/web_style/edit.php $colorOptions + SYSTEM/assets/css/admin.css）
function frontendThemes(): array
{
    return [
        0 => ['name' => '淡藍色（預設）', 'primary' => '#3478bb', 'bg' => '#f5f8fc', 'light' => '#eaf1f8'],
        1 => ['name' => '森林綠', 'primary' => '#34805f', 'bg' => '#f5faf7', 'light' => '#e9f4ee'],
        2 => ['name' => '薰衣草紫', 'primary' => '#7654a8', 'bg' => '#f8f6fb', 'light' => '#efe9f7'],
        3 => ['name' => '暖陽橘', 'primary' => '#b96c2e', 'bg' => '#fcf8f4', 'light' => '#faf0e4'],
        4 => ['name' => '玫瑰粉', 'primary' => '#b34f6b', 'bg' => '#fcf7f9', 'light' => '#f9e9ee'],
    ];
}

function frontendStyleOption(PDO $pdo): int
{
    try {
        $option = $pdo->query('SELECT style_option FROM web_style ORDER BY id LIMIT 1')->fetchColumn();
    } catch (Throwable) {
        return 0;
    }
    $option = (int) ($option === false ? 0 : $option);
    return ($option >= 0 && $option <= 4) ? $option : 0;
}

function frontendWebInfo(PDO $pdo): array
{
    $defaults = ['title' => 'ALAN_PHP', 'description' => '', 'banner' => '', 'blocks' => [], 'site_enabled' => false];
    $defaults['cart_enabled'] = false;
    try {
        $row = $pdo->query(
            'SELECT web_title, web_description, web_img, web_json_set, `CHK` FROM web_info ORDER BY id LIMIT 1'
        )->fetch();
    } catch (Throwable) {
        return $defaults;
    }
    if ($row === false) {
        return $defaults;
    }
    // 總開關：chk=1 才檢查 web_json_set 各區塊；chk=0 全部不顯示（聯絡前端固定顯示；關於已於 2026-10-11 從 top 移除）
    $siteEnabled = (int) ($row['CHK'] ?? 0) === 1;
    $blocks = ['news' => false, 'activity' => false, 'album' => false, 'product' => false, 'member' => false];
    // 權限聯動：product 未開啟 → member 關閉；product 開啟 → 保留既有 member 勾選值
    if ($siteEnabled && is_string($row['web_json_set'] ?? null) && $row['web_json_set'] !== '') {
        try {
            $decoded = json_decode((string) $row['web_json_set'], true, 512, JSON_THROW_ON_ERROR);
            if (is_array($decoded)) {
                foreach (['news', 'activity', 'album', 'product', 'member'] as $key) {
                    $blocks[$key] = ($decoded[$key] ?? false) === true;
                }
            }
        } catch (Throwable) {
            $blocks = ['news' => false, 'activity' => false, 'album' => false, 'product' => false, 'member' => false];
        }
    }
    if (!($blocks['product'] ?? false)) {
        $blocks['member'] = false;
    }
    $banner = (string) ($row['web_img'] ?? '');
    return [
        'title' => (string) ($row['web_title'] ?? $defaults['title']),
        'description' => (string) ($row['web_description'] ?? ''),
        'banner' => $banner !== '' ? 'SYSTEM/web_info/' . $banner : '',
        'blocks' => $blocks,
        'cart_enabled' => ($blocks['product'] ?? false) === true,
        'site_enabled' => $siteEnabled,
    ];
}

function frontendSessionCartQty(): int
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $cart = $_SESSION['frontend_cart'] ?? null;
    if (!is_array($cart) || !is_array($cart['items'] ?? null)) {
        return 0;
    }
    $total = 0;
    foreach ($cart['items'] as $qty) {
        $qty = filter_var($qty, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($qty !== false) {
            $total += $qty;
        }
    }
    return $total;
}

function frontendCartCount(PDO $pdo): int
{
    try {
        $rows = $pdo->query('SELECT jason_cart FROM shopping_cart WHERE `CHK` = 0 AND `view` = 0')->fetchAll();
    } catch (Throwable) {
        return 0;
    }
    $count = 0;
    foreach ($rows as $row) {
        $raw = (string) ($row['jason_cart'] ?? '[]');
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            continue;
        }
        if (is_array($decoded)) {
            $count += count($decoded);
        }
    }
    return $count;
}

function frontendMemberStatus(): array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $loggedIn = isset($_SESSION['member_id']);
    return [
        'logged_in' => $loggedIn,
        'member_id' => $loggedIn ? (int) $_SESSION['member_id'] : null,
        'member_name' => $loggedIn ? (string) ($_SESSION['member_name'] ?? '') : '',
    ];
}

function frontendCsrfToken(string $key = 'frontend_csrf_token'): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (!isset($_SESSION[$key]) || !is_string($_SESSION[$key])) {
        $_SESSION[$key] = bin2hex(random_bytes(32));
    }
    return $_SESSION[$key];
}

// DB 第一張圖：後台存 img/<hash>.ext（縮圖 <hash>_thumb.ext），前台一律用原圖路徑
function frontendImageUrl(string $baseDir, ?string $storedPath): string
{
    if (!is_string($storedPath) || $storedPath === '') {
        return '';
    }
    if (preg_match('~^(?:https?://|data:|blob:|/)~', $storedPath) === 1) {
        return $storedPath;
    }
    if (str_starts_with($storedPath, 'img/')) {
        return $baseDir . '/' . $storedPath;
    }
    return $storedPath;
}
