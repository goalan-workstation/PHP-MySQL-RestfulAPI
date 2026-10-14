<?php
declare(strict_types=1);

session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: ../login.php');
    exit;
}

require_once __DIR__ . '/../theme.php';
require_once __DIR__ . '/image_storage.php';

const ALBUM_PER_PAGE = 9;

if (!isset($_SESSION['album_csrf_token'])) {
    $_SESSION['album_csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['album_csrf_token'];
$showAll = ($_GET['show'] ?? $_POST['show'] ?? '') === 'all';
$requestedPage = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$requestedPage = $requestedPage === false ? 1 : $requestedPage;
$errorMessage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $submittedToken = $_POST['csrf_token'] ?? null;
    $albumId = filter_var($_POST['delete_id'], FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);
    if (!is_string($submittedToken)
        || !hash_equals($csrfToken, $submittedToken)
        || $albumId === false
    ) {
        http_response_code(400);
        $errorMessage = '刪除請求無效，請重新整理頁面後再試。';
    } else {
        $find = $pdo->prepare('SELECT album_pic, album_jason_pic FROM album WHERE id = :id');
        $find->execute(['id' => $albumId]);
        $album = $find->fetch();
        if ($album === false) {
            http_response_code(404);
            $errorMessage = '找不到要刪除的相簿。';
        } else {
            $images = albumDecodeSubImages($album['album_jason_pic']);
            $delete = $pdo->prepare('DELETE FROM album WHERE id = :id');
            $delete->execute(['id' => $albumId]);
            albumRemoveImage($album['album_pic']);
            foreach ($images as $image) {
                albumRemoveImage($image['original'], true);
            }
            $remainingCount = (int) $pdo->query('SELECT COUNT(*) FROM album')->fetchColumn();
            $remainingPages = max(1, (int) ceil($remainingCount / ALBUM_PER_PAGE));
            $redirectPage = min($requestedPage, $remainingPages);
            header('Location: list.php?page=' . $redirectPage . ($showAll ? '&show=all' : '') . '&deleted=1');
            exit;
        }
    }
}

$count = (int) $pdo->query('SELECT COUNT(*) FROM album')->fetchColumn();
$pageCount = max(1, (int) ceil($count / ALBUM_PER_PAGE));
$page = min($requestedPage, $pageCount);
$sql = 'SELECT id, title, album_pic, album_jason_pic, data_sort
        FROM album
        ORDER BY data_sort ASC, id DESC';
if (!$showAll) {
    $sql .= ' LIMIT :limit OFFSET :offset';
}
$statement = $pdo->prepare($sql);
if (!$showAll) {
    $statement->bindValue(':limit', ALBUM_PER_PAGE, PDO::PARAM_INT);
    $statement->bindValue(':offset', ($page - 1) * ALBUM_PER_PAGE, PDO::PARAM_INT);
}
$statement->execute();
$albums = $statement->fetchAll();
$deleted = ($_GET['deleted'] ?? '') === '1';
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>相簿管理</title>
    <link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body class="dashboard-page" data-theme="<?= $styleOption ?>">
    <main class="dashboard">
        <nav class="breadcrumb" aria-label="麵包屑">
            <a href="../dashboard.php">首頁</a>
            <span aria-hidden="true">›</span>
            <span aria-current="page">相簿管理</span>
        </nav>
        <header class="dashboard-header">
            <div>
                <p class="eyebrow">ALBUM MANAGEMENT</p>
                <h1>相簿管理</h1>
                <p class="page-description">共 <?= $count ?> 本相簿，拖曳圖片可調整排序</p>
            </div>
            <div class="dashboard-actions">
                <a class="button button-primary" href="edit.php">新增相簿</a>
                <?php if ($showAll): ?>
                    <a class="button button-secondary" href="list.php">分頁顯示</a>
                <?php else: ?>
                    <a class="button button-secondary" href="list.php?show=all">全部顯示</a>
                <?php endif; ?>
                <a class="button button-secondary" href="../dashboard.php">返回管理首頁</a>
                <div class="account-actions">
                    <?= adminIdentityMarkup() ?>
                    <a class="button button-logout" href="../logout.php">登出</a>
                </div>
            </div>
        </header>

        <?php if ($deleted): ?>
            <p class="form-message form-message-success" role="status">相簿已刪除。</p>
        <?php endif; ?>
        <?php if ($errorMessage !== null): ?>
            <p class="form-message form-message-error" role="alert"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>

        <?php if ($albums === []): ?>
            <p class="news-empty">目前沒有相簿，請新增一本相簿。</p>
        <?php else: ?>
            <div class="album-grid" id="album-grid" data-page="<?= $page ?>" data-show-all="<?= $showAll ? '1' : '0' ?>">
                <?php foreach ($albums as $album): ?>
                    <?php
                    $thumbnail = albumThumbnailPath($album['album_pic']);
                    $thumbnailFile = $thumbnail === null ? null : albumManagedImagePath($thumbnail);
                    $albumImageCount = count(albumDecodeSubImages($album['album_jason_pic']));
                    ?>
                    <article class="album-card" draggable="true" data-id="<?= (int) $album['id'] ?>">
                        <p class="album-upload-count">已上傳 <?= $albumImageCount ?> 張圖片</p>
                        <a class="album-image-link" href="edit.php?id=<?= (int) $album['id'] ?>" aria-label="編輯相簿">
                            <?php if ($thumbnail !== null && $thumbnailFile !== null && is_file($thumbnailFile)): ?>
                                <img src="<?= htmlspecialchars($thumbnail, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($album['title'], ENT_QUOTES, 'UTF-8') ?>">
                            <?php else: ?>
                                <span class="album-image-missing" aria-label="沒有圖片"></span>
                            <?php endif; ?>
                        </a>
                        <p class="album-card-title" title="<?= htmlspecialchars($album['title'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($album['title'], ENT_QUOTES, 'UTF-8') ?></p>
                        <div class="album-card-actions">
                            <a class="button button-secondary" href="edit.php?id=<?= (int) $album['id'] ?>">編輯</a>
                            <form method="post" onsubmit="return confirm('確定要刪除這本相簿及其圖片嗎？');">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="page" value="<?= $page ?>">
                                <input type="hidden" name="show" value="<?= $showAll ? 'all' : '' ?>">
                                <button class="button button-danger" type="submit" name="delete_id" value="<?= (int) $album['id'] ?>">刪除</button>
                            </form>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
            <p class="album-sort-status" id="album-sort-status" role="status" aria-live="polite"></p>
            <?php if (!$showAll && $pageCount > 1): ?>
                <nav class="pagination" aria-label="相簿列表分頁">
                    <?php if ($page > 1): ?>
                        <a class="button button-secondary" href="list.php?page=<?= $page - 1 ?>">上一頁</a>
                    <?php endif; ?>
                    <span>第 <?= $page ?> / <?= $pageCount ?> 頁</span>
                    <?php if ($page < $pageCount): ?>
                        <a class="button button-secondary" href="list.php?page=<?= $page + 1 ?>">下一頁</a>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </main>
    <script>
        (() => {
            const grid = document.getElementById('album-grid');
            if (!grid) return;
            const status = document.getElementById('album-sort-status');
            let dragged = null;
            grid.addEventListener('dragstart', (event) => {
                const card = event.target.closest('.album-card');
                if (!card) return;
                dragged = card;
                card.classList.add('is-dragging');
                event.dataTransfer.effectAllowed = 'move';
            });
            grid.addEventListener('dragover', (event) => {
                event.preventDefault();
                const target = event.target.closest('.album-card');
                if (!dragged || !target || target === dragged) return;
                const bounds = target.getBoundingClientRect();
                const after = event.clientY > bounds.top + bounds.height / 2;
                grid.insertBefore(dragged, after ? target.nextSibling : target);
            });
            grid.addEventListener('dragend', async () => {
                if (!dragged) return;
                dragged.classList.remove('is-dragging');
                dragged = null;
                status.textContent = '正在儲存排序…';
                try {
                    const response = await fetch('reorder.php', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify({
                            csrf_token: <?= json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
                            page: Number(grid.dataset.page),
                            show_all: grid.dataset.showAll === '1',
                            ids: Array.from(grid.querySelectorAll('.album-card'), (card) => Number(card.dataset.id))
                        })
                    });
                    const result = await response.json();
                    if (!response.ok || !result.success) throw new Error(result.error || '排序儲存失敗。');
                    status.textContent = '排序已儲存。';
                } catch (error) {
                    status.textContent = error.message;
                    window.setTimeout(() => window.location.reload(), 1200);
                }
            });
        })();
    </script>
</body>
</html>
