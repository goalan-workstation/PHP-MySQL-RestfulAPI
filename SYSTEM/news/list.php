<?php
declare(strict_types=1);

session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: ../login.php');
    exit;
}

require_once __DIR__ . '/../theme.php';
require_once __DIR__ . '/image_storage.php';

const NEWS_PER_PAGE = 10;

if (!isset($_SESSION['news_csrf_token'])) {
    $_SESSION['news_csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['news_csrf_token'];
$deleteError = null;
$requestedPage = filter_var($_GET['page'] ?? $_POST['page'] ?? 1, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$requestedPage = $requestedPage === false ? 1 : $requestedPage;
$requestedSort = $_GET['sort'] ?? $_POST['sort'] ?? '';
$showAll = ($_GET['show_all'] ?? $_POST['show_all'] ?? '0') === '1';
$sort = $requestedSort;
$sort = in_array($sort, ['status', 'updated', 'sequence'], true) ? $sort : 'updated';
$defaultOrder = $sort === 'sequence' ? 'asc' : 'desc';
$order = $_GET['order'] ?? $_POST['order'] ?? $defaultOrder;
$order = in_array($order, ['asc', 'desc'], true) ? $order : 'desc';
$statusOrder = $sort === 'status' && $order === 'asc' ? 'desc' : 'asc';
$updatedOrder = $sort === 'updated' && $order === 'asc' ? 'desc' : 'asc';
$sequenceOrder = $sort === 'sequence' && $order === 'asc' ? 'desc' : 'asc';
$statusIndicator = $sort === 'status' ? ($order === 'asc' ? ' ▲' : ' ▼') : '';
$updatedIndicator = $sort === 'updated' ? ($order === 'asc' ? ' ▲' : ' ▼') : '';
$sequenceIndicator = $sort === 'sequence' ? ($order === 'asc' ? ' ▲' : ' ▼') : '';
$effectiveShowAll = $sort === 'sequence' ? true : $showAll;
$orderBy = match ($sort) {
    'status' => '`CHK` ' . strtoupper($order) . ', id DESC',
    'updated' => 'updata_time ' . strtoupper($order) . ', id DESC',
    default => 'data_sort ' . strtoupper($order) . ', id DESC',
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reorder') {
    header('Content-Type: application/json; charset=utf-8');

    $sendReorderError = static function (int $status, string $message): void {
        http_response_code($status);
        echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
        exit;
    };
    $submittedToken = $_POST['csrf_token'] ?? null;
    $submittedIds = $_POST['ids'] ?? null;

    if (
        !is_string($submittedToken)
        || !hash_equals($csrfToken, $submittedToken)
        || $sort !== 'sequence'
        || !is_array($submittedIds)
        || $submittedIds === []
    ) {
        $sendReorderError(400, '排序請求無效，請重新整理頁面後再試。');
    }

    $reorderedIds = [];
    foreach ($submittedIds as $submittedId) {
        $newsId = filter_var($submittedId, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if ($newsId === false || in_array($newsId, $reorderedIds, true)) {
            $sendReorderError(400, '排序資料無效，請重新整理頁面後再試。');
        }
        $reorderedIds[] = $newsId;
    }

    try {
        $pdo->beginTransaction();
        $reorderOffset = $effectiveShowAll ? 0 : ($requestedPage - 1) * NEWS_PER_PAGE;
        $allIds = $pdo->query(
            'SELECT id FROM news ORDER BY data_sort ASC, id DESC FOR UPDATE'
        )->fetchAll(PDO::FETCH_COLUMN);
        $allIds = array_map('intval', $allIds);
        $displayIds = $order === 'asc' ? $allIds : array_reverse($allIds);
        $pageIds = $effectiveShowAll
            ? $displayIds
            : array_slice($displayIds, $reorderOffset, NEWS_PER_PAGE);
        $expectedIds = $pageIds;
        $submittedPageIds = $reorderedIds;
        sort($expectedIds, SORT_NUMERIC);
        sort($submittedPageIds, SORT_NUMERIC);

        if ($expectedIds !== $submittedPageIds) {
            $pdo->rollBack();
            $sendReorderError(409, '列表已變更，請重新整理後再調整排序。');
        }

        array_splice($displayIds, $reorderOffset, count($reorderedIds), $reorderedIds);
        $allIds = $order === 'asc' ? $displayIds : array_reverse($displayIds);
        $updateOrder = $pdo->prepare('UPDATE news SET data_sort = :data_sort WHERE id = :id');
        foreach ($allIds as $index => $newsId) {
            $updateOrder->execute([
                'data_sort' => $index + 1,
                'id' => $newsId,
            ]);
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('News reorder failed: ' . $error->getMessage());
        $sendReorderError(500, '排序儲存失敗，請稍後再試。');
    }

    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? null;
    $newsId = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);

    if (
        !is_string($submittedToken)
        || !hash_equals($csrfToken, $submittedToken)
        || $newsId === false
    ) {
        http_response_code(400);
        $deleteError = '刪除請求無效，請重新整理頁面後再試。';
    } else {
        $find = $pdo->prepare('SELECT news_pic FROM news WHERE id = :id');
        $find->execute(['id' => $newsId]);
        $imagePath = $find->fetchColumn();

        if ($imagePath === false) {
            http_response_code(404);
            $deleteError = '找不到要刪除的消息。';
        } else {
            $delete = $pdo->prepare('DELETE FROM news WHERE id = :id');
            $delete->execute(['id' => $newsId]);
            newsRemoveStoredImage($imagePath);
            $remainingCount = (int) $pdo->query('SELECT COUNT(*) FROM news')->fetchColumn();
            $remainingPageCount = max(1, (int) ceil($remainingCount / NEWS_PER_PAGE));
            $redirectPage = min($requestedPage, $remainingPageCount);
            header('Location: list.php?page=' . $redirectPage . '&sort=' . urlencode($sort) . '&order=' . urlencode($order) . '&deleted=1');
            exit;
        }
    }
}

$count = (int) $pdo->query('SELECT COUNT(*) FROM news')->fetchColumn();
$pageCount = max(1, (int) ceil($count / NEWS_PER_PAGE));
$page = $effectiveShowAll ? 1 : min($requestedPage, $pageCount);
$offset = $effectiveShowAll ? 0 : ($page - 1) * NEWS_PER_PAGE;

$listSql =
    'SELECT id, title, title_2, description, news_pic, data_sort, `CHK` AS chk,
            DATE_FORMAT(updata_time, \'%Y-%m-%d\') AS update_date
     FROM news
     ORDER BY ' . $orderBy;
if (!$effectiveShowAll) {
    $listSql .= ' LIMIT :limit OFFSET :offset';
}
$statement = $pdo->prepare($listSql);
if (!$effectiveShowAll) {
    $statement->bindValue(':limit', NEWS_PER_PAGE, PDO::PARAM_INT);
    $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
}
$statement->execute();
$newsItems = $statement->fetchAll();
$deleted = isset($_GET['deleted']) && $_GET['deleted'] === '1';
$saved = isset($_GET['saved']) && $_GET['saved'] === '1';
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>最新消息管理</title>
    <link rel="stylesheet" href="../assets/css/admin.css?v=20261006-1002">
</head>
<body class="dashboard-page" data-theme="<?= $styleOption ?>">
    <main class="dashboard">
        <nav class="breadcrumb" aria-label="麵包屑">
            <a href="../dashboard.php">首頁</a>
            <span aria-hidden="true">›</span>
            <span aria-current="page">最新消息</span>
        </nav>

        <header class="dashboard-header">
            <div>
                <p class="eyebrow">NEWS MANAGEMENT</p>
                <h1>最新消息</h1>
                <p class="page-description">管理最新消息內容，共 <?= $count ?> 筆</p>
                <p class="page-description">點擊「排序」或「狀態」或「更新時間」欄位可切換排序</p>
                <p class="page-description">使用「排序」排列資料下可修改排序</p>
            </div>
            <div class="dashboard-actions news-list-actions">
                <a class="button button-primary list-add-button" href="edit.php?page=<?= $page ?>&amp;sort=<?= urlencode($sort) ?>&amp;order=<?= urlencode($order) ?>">新增消息</a>
                <a class="button button-secondary" href="../dashboard.php">返回管理首頁</a>
                <div class="account-actions">
                    <?= adminIdentityMarkup() ?>
                    <a class="button button-logout" href="../logout.php">登出</a>
                </div>
            </div>
        </header>

        <?php if ($saved): ?>
            <p class="form-message form-message-success" role="status">消息已儲存。</p>
        <?php endif; ?>
        <?php if ($deleted): ?>
            <p class="form-message form-message-success" role="status">消息已刪除。</p>
        <?php endif; ?>
        <?php if ($deleteError !== null): ?>
            <p class="form-message form-message-error" role="alert">
                <?= htmlspecialchars($deleteError, ENT_QUOTES, 'UTF-8') ?>
            </p>
        <?php endif; ?>
        <?php if ($sort === 'sequence' && $newsItems !== []): ?>
            <p class="form-message news-sort-hint">拖曳圖片可調整排序</p>
            <p class="form-message" id="news-sort-status" role="status" aria-live="polite"></p>
        <?php endif; ?>

        <?php if ($newsItems === []): ?>
            <p class="news-empty">目前沒有消息，請新增一筆消息。</p>
        <?php else: ?>
            <div class="news-table-wrap">
                <table class="news-table news-list-table">
                    <thead>
                        <tr>
                            <th scope="col">縮圖</th>
                            <th class="news-sort-column" scope="col" aria-sort="<?= $sort === 'sequence' ? ($order === 'asc' ? 'ascending' : 'descending') : 'none' ?>">
                                <a class="table-sort-link" href="list.php?page=1&amp;sort=sequence&amp;order=<?= $sequenceOrder ?>">排序<?= $sequenceIndicator ?></a>
                            </th>
                            <th scope="col">標題</th>
                            <th scope="col">副標題</th>
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
                        <?php foreach ($newsItems as $item): ?>
                            <tr data-news-id="<?= (int) $item['id'] ?>">
                                <td<?= $sort === 'sequence' ? ' class="news-sort-handle" data-drag-handle draggable="true" title="拖曳圖片調整排序"' : '' ?>>
                                    <?php
                                    $thumbnailRelativePath = newsThumbnailRelativePath($item['news_pic']);
                                    $thumbnailPath = $thumbnailRelativePath === null
                                        ? null
                                        : __DIR__ . '/' . $thumbnailRelativePath;
                                    ?>
                                    <?php if ($thumbnailRelativePath !== null && is_file($thumbnailPath)): ?>
                                        <img class="activity-thumbnail" src="<?= htmlspecialchars($thumbnailRelativePath, ENT_QUOTES, 'UTF-8') ?>" alt="">
                                    <?php endif; ?>
                                </td>
                                <td class="news-sort-column"><?= (int) $item['data_sort'] ?></td>
                                <td><?= htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars($item['title_2'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= $item['chk'] === 1 || $item['chk'] === '1' ? '啟用' : '停用' ?></td>
                                <td><?= htmlspecialchars($item['update_date'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td>
                                    <div class="news-actions">
                                        <a class="button button-secondary" href="edit.php?id=<?= (int) $item['id'] ?>&amp;page=<?= $page ?>&amp;sort=<?= urlencode($sort) ?>&amp;order=<?= urlencode($order) ?>">編輯</a>
                                        <form method="post" onsubmit="return confirm('確定要刪除這則消息嗎？');">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                                            <input type="hidden" name="page" value="<?= $page ?>">
                                            <input type="hidden" name="sort" value="<?= htmlspecialchars($sort, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="order" value="<?= htmlspecialchars($order, ENT_QUOTES, 'UTF-8') ?>">
                                            <button class="button button-danger" type="submit">刪除</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if (!$effectiveShowAll && $pageCount > 1): ?>
                <nav class="pagination" aria-label="消息列表分頁">
                    <?php if ($page > 1): ?>
                        <a class="button button-secondary" href="list.php?page=<?= $page - 1 ?>&amp;sort=<?= urlencode($sort) ?>&amp;order=<?= urlencode($order) ?>">上一頁</a>
                    <?php endif; ?>
                    <span>第 <?= $page ?> / <?= $pageCount ?> 頁</span>
                    <?php if ($page < $pageCount): ?>
                        <a class="button button-secondary" href="list.php?page=<?= $page + 1 ?>&amp;sort=<?= urlencode($sort) ?>&amp;order=<?= urlencode($order) ?>">下一頁</a>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </main>
    <?php if ($sort === 'sequence' && $newsItems !== []): ?>
        <script>
            const newsTableBody = document.querySelector('.news-list-table tbody');
            const sortStatus = document.getElementById('news-sort-status');
            let draggedRow = null;
            let originalRows = [];
            let isSavingOrder = false;

            newsTableBody.addEventListener('dragstart', (event) => {
                if (isSavingOrder) {
                    event.preventDefault();
                    return;
                }
                const handle = event.target.closest('[data-drag-handle]');
                draggedRow = handle ? handle.closest('tr[data-news-id]') : null;
                if (!draggedRow) {
                    event.preventDefault();
                    return;
                }

                originalRows = Array.from(newsTableBody.querySelectorAll('tr[data-news-id]'));
                draggedRow.classList.add('is-dragging');
                event.dataTransfer.effectAllowed = 'move';
                event.dataTransfer.setData('text/plain', draggedRow.dataset.newsId);
            });

            newsTableBody.addEventListener('dragover', (event) => {
                if (!draggedRow) {
                    return;
                }

                event.preventDefault();
                const targetRow = event.target.closest('tr[data-news-id]');
                if (!targetRow || targetRow === draggedRow) {
                    return;
                }

                const targetBounds = targetRow.getBoundingClientRect();
                const insertBefore = event.clientY < targetBounds.top + targetBounds.height / 2;
                newsTableBody.insertBefore(draggedRow, insertBefore ? targetRow : targetRow.nextSibling);
            });

            newsTableBody.addEventListener('drop', async (event) => {
                if (!draggedRow) {
                    return;
                }

                event.preventDefault();
                const currentRows = Array.from(newsTableBody.querySelectorAll('tr[data-news-id]'));
                const hasChanged = currentRows.some((row, index) => row !== originalRows[index]);
                if (!hasChanged) {
                    return;
                }

                const formData = new FormData();
                formData.set('action', 'reorder');
                formData.set('csrf_token', <?= json_encode($csrfToken) ?>);
                formData.set('page', <?= $page ?>);
                formData.set('sort', 'sequence');
                formData.set('order', <?= json_encode($order) ?>);
                currentRows.forEach((row) => formData.append('ids[]', row.dataset.newsId));
                isSavingOrder = true;
                newsTableBody.querySelectorAll('[data-drag-handle]').forEach((handle) => {
                    handle.draggable = false;
                });

                try {
                    const response = await fetch('list.php', {
                        method: 'POST',
                        headers: { Accept: 'application/json' },
                        body: formData
                    });
                    const result = await response.json();
                    if (!response.ok) {
                        throw new Error(result.error || '排序儲存失敗，請稍後再試。');
                    }
                    currentRows.forEach((row, index) => {
                        const displayedPosition = <?= $order === 'asc' ? $offset : $count - $offset ?> <?= $order === 'asc' ? '+' : '-' ?> index <?= $order === 'asc' ? '+' : '-' ?> 1;
                        row.children[1].textContent = String(displayedPosition);
                    });
                    sortStatus.textContent = '排序已儲存。';
                } catch (error) {
                    originalRows.forEach((row) => newsTableBody.appendChild(row));
                    sortStatus.textContent = error.message;
                } finally {
                    isSavingOrder = false;
                    newsTableBody.querySelectorAll('[data-drag-handle]').forEach((handle) => {
                        handle.draggable = true;
                    });
                }
            });

            newsTableBody.addEventListener('dragend', () => {
                if (draggedRow) {
                    draggedRow.classList.remove('is-dragging');
                }
                draggedRow = null;
            });
        </script>
    <?php endif; ?>
</body>
</html>
