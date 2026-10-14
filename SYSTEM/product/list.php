<?php
declare(strict_types=1);

session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: ../login.php');
    exit;
}

require_once __DIR__ . '/../theme.php';
require_once __DIR__ . '/image_storage.php';

const PRODUCT_PER_PAGE = 10;

if (!isset($_SESSION['product_csrf_token'])) {
    $_SESSION['product_csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['product_csrf_token'];
$requestedPage = filter_var($_GET['page'] ?? $_POST['page'] ?? 1, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$requestedPage = $requestedPage === false ? 1 : $requestedPage;
$requestedSort = $_GET['sort'] ?? $_POST['sort'] ?? '';
$showAll = in_array($requestedSort, ['status', 'updated', 'sequence'], true)
    && ($_GET['show_all'] ?? $_POST['show_all'] ?? '1') === '1';
$sort = in_array($requestedSort, ['status', 'updated', 'sequence'], true) ? $requestedSort : 'sequence';
$order = $_GET['order'] ?? $_POST['order'] ?? ($sort === 'sequence' ? 'asc' : 'desc');
$order = in_array($order, ['asc', 'desc'], true) ? $order : 'desc';
$statusOrder = $sort === 'status' && $order === 'asc' ? 'desc' : 'asc';
$updatedOrder = $sort === 'updated' && $order === 'asc' ? 'desc' : 'asc';
$sequenceOrder = $sort === 'sequence' && $order === 'asc' ? 'desc' : 'asc';
$statusIndicator = $sort === 'status' ? ($order === 'asc' ? ' ▲' : ' ▼') : '';
$updatedIndicator = $sort === 'updated' ? ($order === 'asc' ? ' ▲' : ' ▼') : '';
$sequenceIndicator = $sort === 'sequence' ? ($order === 'asc' ? ' ▲' : ' ▼') : '';
$orderBy = match ($sort) {
    'status' => '`CHK` ' . strtoupper($order) . ', id DESC',
    'updated' => 'updata_time ' . strtoupper($order) . ', id DESC',
    default => 'data_sort ' . strtoupper($order) . ', id DESC',
};
$errorMessage = null;
$saved = ($_GET['saved'] ?? '') === '1';
$deleted = ($_GET['deleted'] ?? '') === '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reorder') {
    header('Content-Type: application/json; charset=utf-8');
    $sendError = static function (int $status, string $message): void {
        http_response_code($status);
        echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
        exit;
    };
    $token = $_POST['csrf_token'] ?? null;
    $submittedIds = $_POST['ids'] ?? null;
    if (!is_string($token) || !hash_equals($csrfToken, $token)
        || $sort !== 'sequence' || !is_array($submittedIds) || $submittedIds === []
    ) {
        $sendError(400, '排序請求無效，請重新整理頁面後再試。');
    }
    $reorderedIds = [];
    foreach ($submittedIds as $submittedId) {
        $productId = filter_var($submittedId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($productId === false || in_array($productId, $reorderedIds, true)) {
            $sendError(400, '排序資料無效，請重新整理頁面後再試。');
        }
        $reorderedIds[] = $productId;
    }
    try {
        $pdo->beginTransaction();
        $offset = $showAll ? 0 : ($requestedPage - 1) * PRODUCT_PER_PAGE;
        $allIds = array_map('intval', $pdo->query(
            'SELECT id FROM products ORDER BY data_sort ASC, id DESC FOR UPDATE'
        )->fetchAll(PDO::FETCH_COLUMN));
        $displayIds = $order === 'asc' ? $allIds : array_reverse($allIds);
        $visibleIds = $showAll ? $displayIds : array_slice($displayIds, $offset, PRODUCT_PER_PAGE);
        $expectedIds = $visibleIds;
        $submittedPageIds = $reorderedIds;
        sort($expectedIds, SORT_NUMERIC);
        sort($submittedPageIds, SORT_NUMERIC);
        if ($expectedIds !== $submittedPageIds) {
            $pdo->rollBack();
            $sendError(409, '列表已變更，請重新整理後再調整排序。');
        }
        array_splice($displayIds, $offset, count($reorderedIds), $reorderedIds);
        $allIds = $order === 'asc' ? $displayIds : array_reverse($displayIds);
        $updateOrder = $pdo->prepare('UPDATE products SET data_sort = :data_sort WHERE id = :id');
        foreach ($allIds as $index => $productId) {
            $updateOrder->execute(['data_sort' => $index + 1, 'id' => $productId]);
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Product reorder failed: ' . $error->getMessage());
        $sendError(500, '排序儲存失敗，請稍後再試。');
    }
    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $token = $_POST['csrf_token'] ?? null;
    $productId = filter_var($_POST['delete_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!is_string($token) || !hash_equals($csrfToken, $token) || $productId === false) {
        http_response_code(400);
        $errorMessage = '刪除請求無效，請重新整理頁面後再試。';
    } else {
        $find = $pdo->prepare('SELECT product_pic, product_jason_pic FROM products WHERE id = :id');
        $find->execute(['id' => $productId]);
        $product = $find->fetch();
        if ($product === false) {
            http_response_code(404);
            $errorMessage = '找不到要刪除的產品。';
        } else {
            try {
                $gallery = productDecodeGallery($product['product_jason_pic']);
                $delete = $pdo->prepare('DELETE FROM products WHERE id = :id');
                $delete->execute(['id' => $productId]);
                productRemoveImage($product['product_pic']);
                foreach ($gallery as $image) {
                    productRemoveImage($image);
                }
                $remaining = (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
                $pages = max(1, (int) ceil($remaining / PRODUCT_PER_PAGE));
                header('Location: list.php?page=' . min($requestedPage, $pages) . '&sort=' . urlencode($sort) . '&order=' . urlencode($order) . '&show_all=' . ($showAll ? '1' : '0') . '&deleted=1');
                exit;
            } catch (RuntimeException $error) {
                error_log('Product deletion failed: ' . $error->getMessage());
                $errorMessage = '產品刪除或圖片清理失敗，請檢查伺服器記錄。';
            }
        }
    }
}

$count = (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
$pageCount = max(1, (int) ceil($count / PRODUCT_PER_PAGE));
$page = $showAll ? 1 : min($requestedPage, $pageCount);
$offset = $showAll ? 0 : ($page - 1) * PRODUCT_PER_PAGE;
$sql = 'SELECT id, title, product_num, product_renum, product_price, data_sort, `CHK` AS chk,
               DATE_FORMAT(updata_time, \'%Y-%m-%d\') AS update_date
        FROM products
        ORDER BY ' . $orderBy;
if (!$showAll) {
    $sql .= ' LIMIT :limit OFFSET :offset';
}
$statement = $pdo->prepare($sql);
if (!$showAll) {
    $statement->bindValue(':limit', PRODUCT_PER_PAGE, PDO::PARAM_INT);
    $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
}
$statement->execute();
$products = $statement->fetchAll();
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>產品管理</title>
    <link rel="stylesheet" href="../assets/css/admin.css?v=20261006-products-list-4">
</head>
<body class="dashboard-page" data-theme="<?= $styleOption ?>">
    <main class="dashboard">
        <nav class="breadcrumb" aria-label="麵包屑">
            <a href="../dashboard.php">首頁</a>
            <span aria-hidden="true">›</span>
            <span aria-current="page">產品管理</span>
        </nav>
        <header class="dashboard-header">
            <div>
                <p class="eyebrow">PRODUCT MANAGEMENT</p>
                <h1>產品管理</h1>
                <p class="page-description">共 <?= $count ?> 項產品</p>
                <p class="page-description">點擊「排序」、「狀態」或「更新時間」欄位可切換排序</p>
                <p class="page-description">使用「排序」排列資料下可修改排序</p>
            </div>
            <div class="dashboard-actions">
                <a class="button button-primary" href="edit.php?page=<?= $page ?>&amp;sort=<?= urlencode($sort) ?>&amp;order=<?= urlencode($order) ?>&amp;show_all=<?= $showAll ? '1' : '0' ?>">新增產品</a>
                <a class="button button-secondary" href="../dashboard.php">返回管理首頁</a>
                <div class="account-actions">
                    <?= adminIdentityMarkup() ?>
                    <a class="button button-logout" href="../logout.php">登出</a>
                </div>
            </div>
        </header>
        <?php if ($saved): ?><p class="form-message form-message-success" role="status">產品已儲存。</p><?php endif; ?>
        <?php if ($deleted): ?><p class="form-message form-message-success" role="status">產品已刪除。</p><?php endif; ?>
        <?php if ($errorMessage !== null): ?>
            <p class="form-message form-message-error" role="alert"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
        <?php if ($sort === 'sequence' && $products !== []): ?>
            <p class="form-message news-sort-hint">拖曳「排序」欄位可調整排序</p>
            <p class="form-message" id="product-sort-status" role="status" aria-live="polite"></p>
        <?php endif; ?>
        <?php if ($products === []): ?>
            <p class="news-empty">目前沒有產品，請新增一項產品。</p>
        <?php else: ?>
            <div class="news-table-wrap">
                <table class="news-table news-list-table products-table">
                    <thead>
                        <tr>
                            <th class="product-sort-column" scope="col" aria-sort="<?= $sort === 'sequence' ? ($order === 'asc' ? 'ascending' : 'descending') : 'none' ?>">
                                <a class="table-sort-link" href="list.php?page=1&amp;sort=sequence&amp;order=<?= $sequenceOrder ?>">排序<?= $sequenceIndicator ?></a>
                            </th>
                            <th scope="col">產品標題</th>
                            <th scope="col">價格（元）</th>
                            <th scope="col">數量</th>
                            <th scope="col" aria-sort="<?= $sort === 'status' ? ($order === 'asc' ? 'ascending' : 'descending') : 'none' ?>">
                                <a class="table-sort-link" href="list.php?page=1&amp;sort=status&amp;order=<?= $statusOrder ?>">狀態<?= $statusIndicator ?></a>
                            </th>
                            <th scope="col" aria-sort="<?= $sort === 'updated' ? ($order === 'asc' ? 'ascending' : 'descending') : 'none' ?>">
                                <a class="table-sort-link" href="list.php?page=1&amp;sort=updated&amp;order=<?= $updatedOrder ?>">更新時間<?= $updatedIndicator ?></a>
                            </th>
                            <th scope="col">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($products as $product): ?>
                            <tr data-product-id="<?= (int) $product['id'] ?>">
                                <td class="product-sort-column news-sort-handle"<?= $sort === 'sequence' ? ' data-drag-handle draggable="true" title="拖曳調整排序"' : '' ?>><?= (int) $product['data_sort'] ?></td>
                                <td><?= htmlspecialchars($product['title'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= number_format((int) ($product['product_price'] ?? 0)) ?></td>
                                <td><?= (int) $product['product_num'] ?></td>
                                <td><?= $product['chk'] === 1 || $product['chk'] === '1' ? '啟用' : '停用' ?></td>
                                <td><?= htmlspecialchars($product['update_date'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td>
                                    <div class="news-actions">
                                        <a class="button button-secondary" href="edit.php?id=<?= (int) $product['id'] ?>&amp;page=<?= $page ?>&amp;sort=<?= urlencode($sort) ?>&amp;order=<?= urlencode($order) ?>&amp;show_all=<?= $showAll ? '1' : '0' ?>">編輯</a>
                                        <form method="post" onsubmit="return confirm('確定要刪除這項產品及其圖片嗎？');">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="page" value="<?= $page ?>">
                                            <input type="hidden" name="sort" value="<?= htmlspecialchars($sort, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="order" value="<?= htmlspecialchars($order, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="show_all" value="<?= $showAll ? '1' : '0' ?>">
                                            <button class="button button-danger" type="submit" name="delete_id" value="<?= (int) $product['id'] ?>">刪除</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if (!$showAll && $pageCount > 1): ?>
                <nav class="pagination" aria-label="產品列表分頁">
                    <?php if ($page > 1): ?><a class="button button-secondary" href="list.php?page=<?= $page - 1 ?>">上一頁</a><?php endif; ?>
                    <span>第 <?= $page ?> / <?= $pageCount ?> 頁</span>
                    <?php if ($page < $pageCount): ?><a class="button button-secondary" href="list.php?page=<?= $page + 1 ?>">下一頁</a><?php endif; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </main>
    <?php if ($sort === 'sequence' && $products !== []): ?>
        <script>
            const productTableBody = document.querySelector('.products-table tbody');
            const sortStatus = document.getElementById('product-sort-status');
            let draggedRow = null;
            let originalRows = [];
            let isSavingOrder = false;

            productTableBody.addEventListener('dragstart', (event) => {
                if (isSavingOrder) {
                    event.preventDefault();
                    return;
                }
                const handle = event.target.closest('[data-drag-handle]');
                draggedRow = handle ? handle.closest('tr[data-product-id]') : null;
                if (!draggedRow) {
                    event.preventDefault();
                    return;
                }
                originalRows = Array.from(productTableBody.querySelectorAll('tr[data-product-id]'));
                draggedRow.classList.add('is-dragging');
                event.dataTransfer.effectAllowed = 'move';
                event.dataTransfer.setData('text/plain', draggedRow.dataset.productId);
            });

            productTableBody.addEventListener('dragover', (event) => {
                if (!draggedRow) return;
                event.preventDefault();
                const targetRow = event.target.closest('tr[data-product-id]');
                if (!targetRow || targetRow === draggedRow) return;
                const bounds = targetRow.getBoundingClientRect();
                productTableBody.insertBefore(draggedRow, event.clientY < bounds.top + bounds.height / 2 ? targetRow : targetRow.nextSibling);
            });

            productTableBody.addEventListener('drop', async (event) => {
                if (!draggedRow) return;
                event.preventDefault();
                const currentRows = Array.from(productTableBody.querySelectorAll('tr[data-product-id]'));
                if (!currentRows.some((row, index) => row !== originalRows[index])) return;
                const formData = new FormData();
                formData.set('action', 'reorder');
                formData.set('csrf_token', <?= json_encode($csrfToken) ?>);
                formData.set('page', <?= $page ?>);
                formData.set('sort', 'sequence');
                formData.set('order', <?= json_encode($order) ?>);
                formData.set('show_all', <?= json_encode($showAll ? '1' : '0') ?>);
                currentRows.forEach((row) => formData.append('ids[]', row.dataset.productId));
                isSavingOrder = true;
                productTableBody.querySelectorAll('[data-drag-handle]').forEach((handle) => handle.draggable = false);
                try {
                    const response = await fetch('list.php', {method: 'POST', headers: {Accept: 'application/json'}, body: formData});
                    const result = await response.json();
                    if (!response.ok) throw new Error(result.error || '排序儲存失敗，請稍後再試。');
                    currentRows.forEach((row, index) => {
                        const position = <?= $order === 'asc' ? $offset : $count - $offset ?> <?= $order === 'asc' ? '+' : '-' ?> index <?= $order === 'asc' ? '+' : '-' ?> 1;
                        row.children[0].textContent = String(position);
                    });
                    sortStatus.textContent = '排序已儲存。';
                } catch (error) {
                    originalRows.forEach((row) => productTableBody.appendChild(row));
                    sortStatus.textContent = error.message;
                } finally {
                    isSavingOrder = false;
                    productTableBody.querySelectorAll('[data-drag-handle]').forEach((handle) => handle.draggable = true);
                }
            });
            productTableBody.addEventListener('dragend', () => {
                if (draggedRow) draggedRow.classList.remove('is-dragging');
                draggedRow = null;
            });
        </script>
    <?php endif; ?>
</body>
</html>
