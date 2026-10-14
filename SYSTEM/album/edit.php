<?php
declare(strict_types=1);

session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: ../login.php');
    exit;
}

require_once __DIR__ . '/../theme.php';
require_once __DIR__ . '/image_storage.php';

if (!isset($_SESSION['album_csrf_token'])) {
    $_SESSION['album_csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['album_csrf_token'];
$idParameter = $_GET['id'] ?? $_POST['id'] ?? null;
$id = $idParameter === null
    ? null
    : filter_var($idParameter, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($idParameter !== null && $id === false) {
    http_response_code(400);
    exit('無效的相簿編號。');
}

$album = false;
if ($id !== null) {
    $statement = $pdo->prepare('SELECT id, title, album_pic, album_jason_pic, `CHK` AS chk FROM album WHERE id = :id');
    $statement->execute(['id' => $id]);
    $album = $statement->fetch();
    if ($album === false) {
        http_response_code(404);
        exit('找不到這本相簿。');
    }
}

$subImages = $album === false ? [] : albumDecodeSubImages($album['album_jason_pic']);
$values = [
    'title' => $album === false ? '' : $album['title'],
    'chk' => $album === false ? '0' : (string) $album['chk'],
];
$errorMessage = null;
$saved = ($_GET['saved'] ?? '') === '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? null;
    $title = $_POST['title'] ?? null;
    $chk = $_POST['chk'] ?? '0';
    $upload = $_FILES['album_pic'] ?? null;

    if (!is_string($token) || !hash_equals($csrfToken, $token)) {
        http_response_code(400);
        $errorMessage = '表單已過期，請重新載入頁面後再試。';
    } elseif (!is_string($title) || trim($title) === '' || !is_string($chk)
        || !in_array($chk, ['0', '1'], true) || !is_array($upload)
    ) {
        $errorMessage = '請填寫相簿名稱並確認欄位內容正確。';
    } else {
        $staged = null;
        try {
            $hasUpload = ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
            if ($id === null && !$hasUpload) {
                throw new RuntimeException('新增相簿時請上傳相簿封面圖片。');
            }
            if ($hasUpload) {
                $staged = albumStageUpload($upload);
                albumPromoteImage($staged);
            }

            try {
                if ($id === null) {
                    $nextSort = (int) $pdo->query(
                        'SELECT COALESCE(MAX(data_sort), 0) + 1 FROM album'
                    )->fetchColumn();
                    $statement = $pdo->prepare(
                        'INSERT INTO album (title, album_pic, album_jason_pic, data_sort, `CHK`)
                         VALUES (:title, :album_pic, :images, :data_sort, :chk)'
                    );
                    $statement->execute([
                        'title' => trim($title),
                        'album_pic' => $staged['original'],
                        'images' => '[]',
                        'data_sort' => $nextSort,
                        'chk' => $chk,
                    ]);
                    $id = (int) $pdo->lastInsertId();
                } else {
                    $statement = $pdo->prepare(
                        'UPDATE album
                         SET title = :title, `CHK` = :chk,
                             album_pic = :album_pic,
                             updata_time = CURRENT_TIMESTAMP
                         WHERE id = :id'
                    );
                    $statement->execute([
                        'title' => trim($title),
                        'chk' => $chk,
                        'album_pic' => $staged['original'] ?? $album['album_pic'],
                        'id' => $id,
                    ]);
                }
            } catch (Throwable $error) {
                if ($staged !== null) {
                    albumRemoveImage($staged['original']);
                }
                throw $error;
            }

            if ($staged !== null && $album !== false && $album['album_pic'] !== $staged['original']) {
                albumRemoveImage($album['album_pic']);
            }
            header('Location: edit.php?id=' . $id . '&saved=1');
            exit;
        } catch (RuntimeException $error) {
            if ($staged !== null) {
                foreach ([$staged['stage_original'], $staged['stage_thumbnail']] as $stagePath) {
                    if (is_file($stagePath)) {
                        unlink($stagePath);
                    }
                }
            }
            $errorMessage = $error->getMessage();
        }
    }
    $values = [
        'title' => is_string($title) ? $title : '',
        'chk' => is_string($chk) && in_array($chk, ['0', '1'], true) ? $chk : '0',
    ];
}

$coverThumbnail = $album === false ? null : albumThumbnailPath($album['album_pic']);
$coverThumbnailFile = $coverThumbnail === null ? null : albumManagedImagePath($coverThumbnail);
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $id === null ? '新增相簿' : '編輯相簿' ?></title>
    <link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body class="dashboard-page" data-theme="<?= $styleOption ?>">
    <main class="dashboard">
        <nav class="breadcrumb" aria-label="麵包屑">
            <a href="../dashboard.php">首頁</a>
            <span aria-hidden="true">›</span>
            <a href="list.php">相簿管理</a>
            <span aria-hidden="true">›</span>
            <span aria-current="page"><?= $id === null ? '新增相簿' : '編輯相簿' ?></span>
        </nav>
        <header class="dashboard-header">
            <div>
                <p class="eyebrow">ALBUM MANAGEMENT</p>
                <h1><?= $id === null ? '新增相簿' : '編輯相簿' ?></h1>
                <p class="page-description">圖片超過 5 MB 時會自動壓縮；附加圖片最多 10 張。</p>
            </div>
            <div class="dashboard-actions">
                <a class="button button-secondary" href="list.php">返回相簿列表</a>
                <div class="account-actions">
                    <?= adminIdentityMarkup() ?>
                    <a class="button button-logout" href="../logout.php">登出</a>
                </div>
            </div>
        </header>
        <?php if ($saved): ?>
            <p class="form-message form-message-success" role="status">相簿已儲存。</p>
        <?php endif; ?>
        <?php if ($errorMessage !== null): ?>
            <p class="form-message form-message-error" role="alert"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>

        <form class="settings-form" method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <?php if ($id !== null): ?>
                <input type="hidden" name="id" value="<?= $id ?>">
            <?php endif; ?>
            <label for="title">相簿名稱</label>
            <input id="title" name="title" type="text" value="<?= htmlspecialchars($values['title'], ENT_QUOTES, 'UTF-8') ?>" required>
            <label for="album_pic">相簿封面（JPG、PNG、WebP；超過 5 MB 自動壓縮）</label>
            <input id="album_pic" name="album_pic" type="file" accept="image/jpeg,image/png,image/webp" <?= $id === null ? 'required' : '' ?>>
            <?php if ($coverThumbnail !== null && $coverThumbnailFile !== null && is_file($coverThumbnailFile)): ?>
                <div class="activity-image-preview">
                    <p>目前封面</p>
                    <img class="activity-preview-thumbnail" src="<?= htmlspecialchars($coverThumbnail, ENT_QUOTES, 'UTF-8') ?>" alt="相簿封面">
                </div>
            <?php endif; ?>
            <label class="settings-toggle" for="chk">
                <input id="chk" name="chk" type="checkbox" value="1" <?= $values['chk'] === '1' ? 'checked' : '' ?>>
                <span>啟用相簿</span>
            </label>
            <button class="button button-primary settings-submit" type="submit">儲存相簿</button>
        </form>

        <?php if ($id !== null): ?>
            <section class="album-gallery-section" aria-labelledby="sub-images-heading">
                <div class="album-gallery-heading">
                    <h2 id="sub-images-heading">附加圖片（<?= count($subImages) ?> / <?= ALBUM_MAX_SUB_IMAGES ?>）</h2>
                    <a class="button button-primary" href="../album_sub/edit.php?id=<?= $id ?>">新增圖片</a>
                </div>
                <?php if ($subImages === []): ?>
                    <p class="news-empty">目前沒有附加圖片。</p>
                <?php else: ?>
                    <div class="album-sub-grid" id="sub-image-grid" data-album-id="<?= $id ?>">
                        <?php foreach ($subImages as $image): ?>
                            <?php
                            $thumbnail = albumThumbnailPath($image['original'], true);
                            $thumbnailFile = $thumbnail === null ? null : albumManagedImagePath($thumbnail, true);
                            ?>
                            <article class="album-sub-card" draggable="true" data-image="<?= htmlspecialchars($image['original'], ENT_QUOTES, 'UTF-8') ?>">
                                <?php if ($thumbnail !== null && $thumbnailFile !== null && is_file($thumbnailFile)): ?>
                                    <img src="<?= htmlspecialchars($thumbnail, ENT_QUOTES, 'UTF-8') ?>" alt="">
                                <?php endif; ?>
                                <div class="album-card-actions">
                                    <a class="button button-secondary" href="../album_sub/edit.php?id=<?= $id ?>&amp;action=edit&amp;image=<?= rawurlencode($image['original']) ?>">編輯</a>
                                    <form method="post" action="../album_sub/edit.php?id=<?= $id ?>" onsubmit="return confirm('確定要刪除此圖片嗎？');">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="image" value="<?= htmlspecialchars($image['original'], ENT_QUOTES, 'UTF-8') ?>">
                                        <button class="button button-danger" type="submit">刪除</button>
                                    </form>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                    <p class="album-sort-status" id="sub-image-sort-status" role="status" aria-live="polite">拖曳可改變排序</p>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </main>
    <?php if ($id !== null && $subImages !== []): ?>
        <script>
            (() => {
                const grid = document.getElementById('sub-image-grid');
                const status = document.getElementById('sub-image-sort-status');
                let dragged = null;
                grid.addEventListener('dragstart', (event) => {
                    const card = event.target.closest('.album-sub-card');
                    if (!card) return;
                    dragged = card;
                    card.classList.add('is-dragging');
                    event.dataTransfer.effectAllowed = 'move';
                });
                grid.addEventListener('dragover', (event) => {
                    event.preventDefault();
                    const target = event.target.closest('.album-sub-card');
                    if (!dragged || !target || target === dragged) return;
                    const bounds = target.getBoundingClientRect();
                    grid.insertBefore(dragged, event.clientY > bounds.top + bounds.height / 2 ? target.nextSibling : target);
                });
                grid.addEventListener('dragend', async () => {
                    if (!dragged) return;
                    dragged.classList.remove('is-dragging');
                    dragged = null;
                    status.textContent = '正在儲存排序…';
                    try {
                        const response = await fetch('../album_sub/reorder.php', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify({
                                csrf_token: <?= json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
                                album_id: Number(grid.dataset.albumId),
                                images: Array.from(grid.querySelectorAll('.album-sub-card'), (card) => card.dataset.image)
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
    <?php endif; ?>
</body>
</html>
