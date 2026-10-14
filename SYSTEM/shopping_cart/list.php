<?php
declare(strict_types=1);

session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: ../login.php');
    exit;
}

require_once __DIR__ . '/../theme.php';

const CART_PER_PAGE = 10;

if (!isset($_SESSION['shopping_cart_csrf_token'])) {
    $_SESSION['shopping_cart_csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['shopping_cart_csrf_token'];
$requestedPage = filter_var($_GET['page'] ?? $_POST['page'] ?? 1, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$requestedPage = $requestedPage === false ? 1 : $requestedPage;
$requestedSort = $_GET['sort'] ?? $_POST['sort'] ?? '';
$sort = in_array($requestedSort, ['code', 'status', 'view'], true) ? $requestedSort : '';
$order = $_GET['order'] ?? $_POST['order'] ?? 'desc';
$order = in_array($order, ['asc', 'desc'], true) ? $order : 'desc';
$codeOrder = $sort === 'code' && $order === 'asc' ? 'desc' : 'asc';
$statusOrder = $sort === 'status' && $order === 'asc' ? 'desc' : 'asc';
$viewOrder = $sort === 'view' && $order === 'asc' ? 'desc' : 'asc';
$codeIndicator = $sort === 'code' ? ($order === 'asc' ? ' ▲' : ' ▼') : '';
$statusIndicator = $sort === 'status' ? ($order === 'asc' ? ' ▲' : ' ▼') : '';
$viewIndicator = $sort === 'view' ? ($order === 'asc' ? ' ▲' : ' ▼') : '';
$orderBy = match ($sort) {
    'code' => 'cart.cart_code ' . strtoupper($order) . ', cart.id DESC',
    'status' => 'cart.`CHK` ' . strtoupper($order) . ', cart.id DESC',
    'view' => 'cart.`view` ' . strtoupper($order) . ', cart.id DESC',
    default => 'cart.id DESC',
};
$errorMessage = null;
$deleted = ($_GET['deleted'] ?? '') === '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $token = $_POST['csrf_token'] ?? null;
    $cartId = filter_var($_POST['delete_id'], FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);
    if (!is_string($token) || !hash_equals($csrfToken, $token) || $cartId === false) {
        http_response_code(400);
        $errorMessage = '刪除請求無效，請重新整理頁面後再試。';
    } else {
        $delete = $pdo->prepare('DELETE FROM shopping_cart WHERE id = :id');
        $delete->execute(['id' => $cartId]);
        if ($delete->rowCount() !== 1) {
            http_response_code(404);
            $errorMessage = '找不到要刪除的訂單。';
        } else {
            header('Location: list.php?' . http_build_query([
                'page' => $requestedPage,
                'sort' => $sort,
                'order' => $order,
                'deleted' => 1,
            ]));
            exit;
        }
    }
}

$count = (int) $pdo->query('SELECT COUNT(*) FROM shopping_cart')->fetchColumn();
$pageCount = max(1, (int) ceil($count / CART_PER_PAGE));
$page = min($requestedPage, $pageCount);
$offset = ($page - 1) * CART_PER_PAGE;
$statement = $pdo->prepare(
    'SELECT cart.id, cart.member_id, cart.cart_code, cart.`CHK` AS chk,
            cart.`view` AS is_view, member.member_name
     FROM shopping_cart AS cart
     JOIN member AS member ON member.id = cart.member_id
     ORDER BY ' . $orderBy . '
     LIMIT :limit OFFSET :offset'
);
$statement->bindValue(':limit', CART_PER_PAGE, PDO::PARAM_INT);
$statement->bindValue(':offset', $offset, PDO::PARAM_INT);
$statement->execute();
$carts = $statement->fetchAll();
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>購物車管理</title>
    <link rel="stylesheet" href="../assets/css/admin.css?v=20261006-shopping-cart-code-sort">
</head>
<body class="dashboard-page" data-theme="<?= $styleOption ?>">
    <main class="dashboard">
        <nav class="breadcrumb" aria-label="麵包屑">
            <a href="../dashboard.php">首頁</a>
            <span aria-hidden="true">›</span>
            <span aria-current="page">購物車管理</span>
        </nav>
        <header class="dashboard-header">
            <div>
                <p class="eyebrow">SHOPPING CART MANAGEMENT</p>
                <h1>購物車管理</h1>
                <p class="page-description">共 <?= $count ?> 筆訂單</p>
                <p class="page-description">點擊「訂單編號」或「訂單狀態」或「讀取狀態」欄位可切換排序</p>
            </div>
            <div class="dashboard-actions">
                <a class="button button-secondary" href="../dashboard.php">返回管理首頁</a>
                <div class="account-actions">
                    <?= adminIdentityMarkup() ?>
                    <a class="button button-logout" href="../logout.php">登出</a>
                </div>
            </div>
        </header>
        <?php if ($deleted): ?><p class="form-message form-message-success" role="status">訂單已刪除。</p><?php endif; ?>
        <?php if ($errorMessage !== null): ?>
            <p class="form-message form-message-error" role="alert"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
        <?php if ($carts === []): ?>
            <p class="news-empty">目前沒有訂單。</p>
        <?php else: ?>
            <div class="news-table-wrap">
                <table class="news-table cart-table">
                    <thead>
                        <tr>
                            <th scope="col" aria-sort="<?= $sort === 'code' ? ($order === 'asc' ? 'ascending' : 'descending') : 'none' ?>">
                                <a class="table-sort-link" href="list.php?page=1&amp;sort=code&amp;order=<?= $codeOrder ?>">訂單編號<?= $codeIndicator ?></a>
                            </th>
                            <th scope="col">會員姓名</th>
                            <th scope="col" aria-sort="<?= $sort === 'status' ? ($order === 'asc' ? 'ascending' : 'descending') : 'none' ?>">
                                <a class="table-sort-link" href="list.php?page=1&amp;sort=status&amp;order=<?= $statusOrder ?>">訂單狀態<?= $statusIndicator ?></a>
                            </th>
                            <th scope="col" aria-sort="<?= $sort === 'view' ? ($order === 'asc' ? 'ascending' : 'descending') : 'none' ?>">
                                <a class="table-sort-link" href="list.php?page=1&amp;sort=view&amp;order=<?= $viewOrder ?>">讀取狀態<?= $viewIndicator ?></a>
                            </th>
                            <th scope="col">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($carts as $cart): ?>
                            <tr>
                                <td title="<?= htmlspecialchars($cart['cart_code'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($cart['cart_code'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td title="<?= htmlspecialchars($cart['member_name'], ENT_QUOTES, 'UTF-8') ?>">
                                    <a class="button button-secondary cart-member-link" href="../member/edit.php?id=<?= (int) $cart['member_id'] ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars($cart['member_name'], ENT_QUOTES, 'UTF-8') ?></a>
                                </td>
                                <td><?= $cart['chk'] === 1 || $cart['chk'] === '1' ? '已完成' : '未完成' ?></td>
                                <td><?= $cart['is_view'] === 1 || $cart['is_view'] === '1' ? '已讀取' : '未讀取' ?></td>
                                <td>
                                    <div class="news-actions">
                                        <a class="button button-secondary" href="edit.php?id=<?= (int) $cart['id'] ?>&amp;page=<?= $page ?>&amp;sort=<?= urlencode($sort) ?>&amp;order=<?= urlencode($order) ?>">查看</a>
                                        <form method="post" onsubmit="return confirm('確定要刪除此訂單嗎？');">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="page" value="<?= $page ?>">
                                            <input type="hidden" name="sort" value="<?= htmlspecialchars($sort, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="order" value="<?= htmlspecialchars($order, ENT_QUOTES, 'UTF-8') ?>">
                                            <button class="button button-danger" type="submit" name="delete_id" value="<?= (int) $cart['id'] ?>">刪除</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($pageCount > 1): ?>
                <nav class="pagination" aria-label="訂單列表分頁">
                    <?php if ($page > 1): ?><a class="button button-secondary" href="list.php?<?= htmlspecialchars(http_build_query(['page' => $page - 1, 'sort' => $sort, 'order' => $order]), ENT_QUOTES, 'UTF-8') ?>">上一頁</a><?php endif; ?>
                    <span>第 <?= $page ?> / <?= $pageCount ?> 頁</span>
                    <?php if ($page < $pageCount): ?><a class="button button-secondary" href="list.php?<?= htmlspecialchars(http_build_query(['page' => $page + 1, 'sort' => $sort, 'order' => $order]), ENT_QUOTES, 'UTF-8') ?>">下一頁</a><?php endif; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </main>
</body>
</html>
