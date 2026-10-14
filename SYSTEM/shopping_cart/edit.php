<?php
declare(strict_types=1);

session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: ../login.php');
    exit;
}

require_once __DIR__ . '/../theme.php';

if (!isset($_SESSION['shopping_cart_csrf_token'])) {
    $_SESSION['shopping_cart_csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['shopping_cart_csrf_token'];
$idParameter = $_GET['id'] ?? $_POST['id'] ?? null;
$id = $idParameter === null
    ? null
    : filter_var($idParameter, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($id === null || $id === false) {
    http_response_code(400);
    exit('無效的訂單編號。');
}

$statement = $pdo->prepare(
    'SELECT cart.id, cart.member_id, cart.cart_code, cart.jason_cart,
            cart.`CHK` AS chk, cart.`view` AS is_view,
            member.member_name, member.member_email, member.member_phone,
    member.member_address
     FROM shopping_cart AS cart
     JOIN member AS member ON member.id = cart.member_id
     WHERE cart.id = :id'
);
$statement->execute(['id' => $id]);
$cart = $statement->fetch();
if ($cart === false) {
    http_response_code(404);
    exit('找不到這筆訂單。');
}

$errorMessage = null;
$saved = ($_GET['saved'] ?? '') === '1';
$returnPage = filter_var($_GET['page'] ?? $_POST['page'] ?? 1, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$returnPage = $returnPage === false ? 1 : $returnPage;
$sortInput = $_GET['sort'] ?? $_POST['sort'] ?? '';
$sort = in_array($sortInput, ['code', 'status', 'view'], true) ? $sortInput : '';
$order = $_GET['order'] ?? $_POST['order'] ?? 'desc';
$order = in_array($order, ['asc', 'desc'], true) ? $order : 'desc';
$values = [
    'chk' => (string) $cart['chk'],
    'view' => (string) $cart['is_view'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? null;
    $chk = $_POST['chk'] ?? '0';
    $view = $_POST['view'] ?? '0';
    if (!is_string($token) || !hash_equals($csrfToken, $token)) {
        http_response_code(400);
        $errorMessage = '表單已過期，請重新載入頁面後再試。';
    } elseif (
        !is_string($chk) || !in_array($chk, ['0', '1'], true)
        || !is_string($view) || !in_array($view, ['0', '1'], true)
    ) {
        http_response_code(400);
        $errorMessage = '訂單狀態無效，請重新載入頁面後再試。';
    } else {
        try {
            $update = $pdo->prepare(
                'UPDATE shopping_cart
                 SET `CHK` = :chk, `view` = :view, updata_time = CURRENT_TIMESTAMP
                 WHERE id = :id'
            );
            $update->execute([
                'chk' => $chk,
                'view' => $view,
                'id' => $id,
            ]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('The shopping cart record was not updated.');
            }
            header('Location: edit.php?id=' . $id . '&page=' . $returnPage . '&sort=' . urlencode($sort) . '&order=' . urlencode($order) . '&saved=1');
            exit;
        } catch (Throwable $error) {
            error_log('Shopping cart update failed: ' . $error->getMessage());
            $errorMessage = '訂單狀態儲存失敗，請檢查伺服器記錄後再試。';
        }
    }
    $values['chk'] = is_string($chk ?? null) && in_array($chk, ['0', '1'], true)
        ? $chk
        : $values['chk'];
    $values['view'] = is_string($view ?? null) && in_array($view, ['0', '1'], true)
        ? $view
        : $values['view'];
}

$decodedCart = json_decode($cart['jason_cart'], true);
$cartIsValid = json_last_error() === JSON_ERROR_NONE;
$cartItems = $cartIsValid && is_array($decodedCart) ? $decodedCart : [];
$formatCartValue = static function ($value): string {
    if (is_string($value) || is_int($value) || is_float($value)) {
        return (string) $value;
    }
    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }
    if ($value === null) {
        return '';
    }
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
};
$productIdsByTitle = [];
foreach ($pdo->query('SELECT id, title FROM products ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $product) {
    $title = (string) $product['title'];
    if (array_key_exists($title, $productIdsByTitle)) {
        $productIdsByTitle[$title] = null;
        continue;
    }
    $productIdsByTitle[$title] = (int) $product['id'];
}
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>訂單明細</title>
    <link rel="stylesheet" href="../assets/css/admin.css?v=20261006-shopping-cart-product-buttons">
</head>
<body class="dashboard-page" data-theme="<?= $styleOption ?>">
    <main class="dashboard">
        <nav class="breadcrumb" aria-label="麵包屑">
            <a href="../dashboard.php">首頁</a>
            <span aria-hidden="true">›</span>
            <a href="list.php?<?= htmlspecialchars(http_build_query(['page' => $returnPage, 'sort' => $sort, 'order' => $order]), ENT_QUOTES, 'UTF-8') ?>">購物車管理</a>
            <span aria-hidden="true">›</span>
            <span aria-current="page">訂單明細</span>
        </nav>
        <header class="dashboard-header">
            <div>
                <p class="eyebrow">SHOPPING CART MANAGEMENT</p>
                <h1>訂單明細</h1>
            </div>
            <div class="dashboard-actions">
                <a class="button button-secondary" href="list.php?<?= htmlspecialchars(http_build_query(['page' => $returnPage, 'sort' => $sort, 'order' => $order]), ENT_QUOTES, 'UTF-8') ?>">返回購物車列表</a>
                <div class="account-actions">
                    <?= adminIdentityMarkup() ?>
                    <a class="button button-logout" href="../logout.php">登出</a>
                </div>
            </div>
        </header>
        <?php if ($saved): ?><p class="form-message form-message-success" role="status">訂單狀態已儲存。</p><?php endif; ?>
        <?php if ($errorMessage !== null): ?>
            <p class="form-message form-message-error" role="alert"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
        <form class="settings-form cart-status-form" method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="id" value="<?= (int) $cart['id'] ?>">
            <input type="hidden" name="page" value="<?= $returnPage ?>">
            <input type="hidden" name="sort" value="<?= htmlspecialchars($sort, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="order" value="<?= htmlspecialchars($order, ENT_QUOTES, 'UTF-8') ?>">
            <label class="settings-toggle" for="view">
                <input id="view" name="view" type="checkbox" value="1" <?= $values['view'] === '1' ? 'checked' : '' ?>>
                <span>訂單已讀取</span>
            </label>
            <label class="settings-toggle" for="chk">
                <input id="chk" name="chk" type="checkbox" value="1" <?= $values['chk'] === '1' ? 'checked' : '' ?>>
                <span>訂單已完成</span>
            </label>
            <button class="button button-primary settings-submit" type="submit">儲存訂單狀態</button>
        </form>
        <section class="cart-member-card" aria-labelledby="cart-member-heading">
            <h2 id="cart-member-heading">會員資料</h2>
            <dl class="cart-member-details">
                <dt>會員姓名</dt><dd><?= htmlspecialchars($cart['member_name'], ENT_QUOTES, 'UTF-8') ?></dd>
                <dt>會員信箱</dt><dd><?= htmlspecialchars($cart['member_email'], ENT_QUOTES, 'UTF-8') ?></dd>
                <dt>會員電話</dt><dd><?= htmlspecialchars($cart['member_phone'], ENT_QUOTES, 'UTF-8') ?></dd>
                <dt>會員地址</dt><dd><?= htmlspecialchars($cart['member_address'], ENT_QUOTES, 'UTF-8') ?></dd>
            </dl>
        </section>
        <section class="cart-order-card" aria-labelledby="cart-order-heading">
            <h2 id="cart-order-heading">訂單內容</h2>
            <p class="cart-code"><strong>訂單編號：</strong><?= htmlspecialchars($cart['cart_code'], ENT_QUOTES, 'UTF-8') ?></p>
            <h3>購物清單</h3>
            <div class="news-table-wrap cart-items-wrap">
                <table class="news-table cart-items-table">
                    <thead>
                        <tr>
                            <th scope="col">產品名稱</th>
                            <th scope="col">數量</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$cartIsValid): ?>
                            <tr><td colspan="2"><?= htmlspecialchars($cart['jason_cart'], ENT_QUOTES, 'UTF-8') ?></td></tr>
                        <?php elseif ($cartItems === []): ?>
                            <tr><td colspan="2">此訂單沒有購買品項。</td></tr>
                        <?php else: ?>
                            <?php foreach ($cartItems as $item): ?>
                                <?php
                                $productName = is_array($item)
                                    ? ($item['product'] ?? $item['product_name'] ?? $item['name'] ?? $item['title'] ?? '')
                                    : $item;
                                $quantity = is_array($item)
                                    ? ($item['quantity'] ?? $item['qty'] ?? $item['count'] ?? '')
                                    : '';
                                ?>
                                <tr>
                                    <td>
                                        <?php $productNameText = $formatCartValue($productName); ?>
                                        <?php if ($productNameText !== '' && isset($productIdsByTitle[$productNameText])): ?>
                                            <a class="button button-secondary cart-product-link" href="../product/edit.php?id=<?= $productIdsByTitle[$productNameText] ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars($productNameText, ENT_QUOTES, 'UTF-8') ?></a>
                                        <?php else: ?>
                                            <?= htmlspecialchars($productNameText, ENT_QUOTES, 'UTF-8') ?>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($formatCartValue($quantity), ENT_QUOTES, 'UTF-8') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</body>
</html>
