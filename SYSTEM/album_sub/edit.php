<?php
declare(strict_types=1);

session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: ../login.php');
    exit;
}

require_once __DIR__ . '/../theme.php';
require_once __DIR__ . '/../album/image_storage.php';

if (!isset($_SESSION['album_csrf_token'])) {
    $_SESSION['album_csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['album_csrf_token'];
$albumIdParameter = $_GET['id'] ?? $_POST['id'] ?? null;
$albumId = $albumIdParameter === null
    ? null
    : filter_var($albumIdParameter, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($albumId === false || $albumId === null) {
    http_response_code(400);
    exit('相簿編號無效。');
}

$statement = $pdo->prepare('SELECT title, album_jason_pic FROM album WHERE id = :id');
$statement->execute(['id' => $albumId]);
$album = $statement->fetch();
if ($album === false) {
    http_response_code(404);
    exit('找不到這本相簿。');
}
$images = albumDecodeSubImages($album['album_jason_pic']);
$action = $_GET['action'] ?? 'manage';
$editPath = $_GET['image'] ?? '';
$editIndex = null;
if ($action === 'edit') {
    foreach ($images as $index => $image) {
        if ($image['original'] === $editPath) {
            $editIndex = $index;
            break;
        }
    }
    if ($editIndex === null) {
        http_response_code(404);
        exit('找不到要編輯的附加圖片。');
    }
}
$errorMessage = null;
$saved = ($_GET['saved'] ?? '') === '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? null;
    if (!is_string($token) || !hash_equals($csrfToken, $token)) {
        http_response_code(400);
        $errorMessage = '表單已過期，請重新載入頁面後再試。';
    } elseif (isset($_POST['delete_image']) || ($_POST['action'] ?? '') === 'delete') {
        $path = $_POST['delete_image'] ?? $_POST['image'] ?? '';
        $index = null;
        foreach ($images as $imageIndex => $image) {
            if ($image['original'] === $path) {
                $index = $imageIndex;
                break;
            }
        }
        if ($index === null) {
            http_response_code(404);
            $errorMessage = '找不到要刪除的附加圖片。';
        } else {
            array_splice($images, $index, 1);
            $save = $pdo->prepare(
                'UPDATE album SET album_jason_pic = :images, updata_time = CURRENT_TIMESTAMP WHERE id = :id'
            );
            $save->execute([
                'images' => json_encode($images, JSON_THROW_ON_ERROR),
                'id' => $albumId,
            ]);
            albumRemoveImage($path, true);
            header('Location: edit.php?id=' . $albumId . '&saved=1');
            exit;
        }
    } elseif (($_POST['action'] ?? '') === 'reorder') {
        $orderedPaths = $_POST['image_order'] ?? null;
        $byPath = [];
        foreach ($images as $image) {
            $byPath[$image['original']] = $image;
        }
        $reordered = [];
        if (is_array($orderedPaths)) {
            foreach ($orderedPaths as $path) {
                if (!is_string($path) || !isset($byPath[$path]) || isset($reordered[$path])) {
                    $reordered = [];
                    break;
                }
                $reordered[$path] = $byPath[$path];
            }
        }
        if (count($reordered) !== count($images)) {
            http_response_code(400);
            $errorMessage = '排序資料無效，請重新整理後再試。';
        } else {
            $images = array_values($reordered);
            $save = $pdo->prepare(
                'UPDATE album SET album_jason_pic = :images, updata_time = CURRENT_TIMESTAMP WHERE id = :id'
            );
            $save->execute([
                'images' => json_encode($images, JSON_THROW_ON_ERROR),
                'id' => $albumId,
            ]);
            header('Location: edit.php?id=' . $albumId . '&saved=1');
            exit;
        }
    } elseif (($action === 'replace') || ($_POST['action'] ?? '') === 'replace') {
        $path = $_POST['image'] ?? '';
        $index = null;
        foreach ($images as $imageIndex => $image) {
            if ($image['original'] === $path) {
                $index = $imageIndex;
                break;
            }
        }
        if ($index === null || !isset($_FILES['image_file']) || !is_array($_FILES['image_file'])) {
            http_response_code(400);
            $errorMessage = '替換圖片資料無效。';
        } else {
            $staged = null;
            try {
                $staged = albumStageUpload($_FILES['image_file'], true);
                albumPromoteImage($staged);
                $images[$index] = ['original' => $staged['original']];
                try {
                    $save = $pdo->prepare(
                        'UPDATE album SET album_jason_pic = :images, updata_time = CURRENT_TIMESTAMP WHERE id = :id'
                    );
                    $save->execute([
                        'images' => json_encode($images, JSON_THROW_ON_ERROR),
                        'id' => $albumId,
                    ]);
                } catch (Throwable $error) {
                    albumRemoveImage($staged['original'], true);
                    throw $error;
                }
                albumRemoveImage($path, true);
                header('Location: edit.php?id=' . $albumId . '&saved=1');
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
    } else {
        $files = $_FILES['images'] ?? null;
        if (!is_array($files) || !isset($files['name'], $files['tmp_name'], $files['error'], $files['size'])
            || !is_array($files['name'])
        ) {
            $errorMessage = '請選擇要上傳的圖片。';
        } else {
            $selected = [];
            foreach ($files['name'] as $index => $name) {
                if (($files['error'][$index] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $selected[] = [
                        'name' => $name,
                        'tmp_name' => $files['tmp_name'][$index] ?? '',
                        'error' => $files['error'][$index],
                        'size' => $files['size'][$index] ?? 0,
                    ];
                }
            }
            if ($selected === []) {
                $errorMessage = '請先選擇至少一張圖片。';
            } elseif (count($images) + count($selected) > ALBUM_MAX_SUB_IMAGES) {
                $errorMessage = '每本相簿最多只能有 10 張附加圖片。';
            } else {
                $stagedImages = [];
                $promotedImages = [];
                try {
                    foreach ($selected as $file) {
                        $stagedImages[] = albumStageUpload($file, true);
                    }
                    foreach ($stagedImages as $staged) {
                        albumPromoteImage($staged);
                        $promotedImages[] = $staged['original'];
                        $images[] = ['original' => $staged['original']];
                    }
                    try {
                        $save = $pdo->prepare(
                            'UPDATE album SET album_jason_pic = :images, updata_time = CURRENT_TIMESTAMP WHERE id = :id'
                        );
                        $save->execute([
                            'images' => json_encode($images, JSON_THROW_ON_ERROR),
                            'id' => $albumId,
                        ]);
                    } catch (Throwable $error) {
                        foreach ($promotedImages as $path) {
                            albumRemoveImage($path, true);
                        }
                        throw $error;
                    }
                    header('Location: edit.php?id=' . $albumId . '&saved=1');
                    exit;
                } catch (RuntimeException $error) {
                    foreach ($promotedImages as $path) {
                        albumRemoveImage($path, true);
                    }
                    foreach ($stagedImages as $staged) {
                        foreach ([$staged['stage_original'], $staged['stage_thumbnail']] as $stagePath) {
                            if (is_file($stagePath)) {
                                unlink($stagePath);
                            }
                        }
                    }
                    $errorMessage = $error->getMessage();
                }
            }
        }
    }
}

$editingImage = $editIndex === null ? null : $images[$editIndex];
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>相簿圖片管理</title>
    <link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body class="dashboard-page" data-theme="<?= $styleOption ?>">
    <main class="dashboard">
        <nav class="breadcrumb" aria-label="麵包屑">
            <a href="../dashboard.php">首頁</a>
            <span aria-hidden="true">›</span>
            <a href="../album/list.php">相簿管理</a>
            <span aria-hidden="true">›</span>
            <a href="../album/edit.php?id=<?= $albumId ?>"><?= htmlspecialchars($album['title'], ENT_QUOTES, 'UTF-8') ?></a>
            <span aria-hidden="true">›</span>
            <span aria-current="page">附加圖片</span>
        </nav>
        <header class="dashboard-header">
            <div>
                <p class="eyebrow">ALBUM IMAGES</p>
                <h1>附加圖片</h1>
                <p class="page-description">最多 <?= ALBUM_MAX_SUB_IMAGES ?> 張，超過 5 MB 自動壓縮；拖曳縮圖可調整順序。</p>
            </div>
            <div class="dashboard-actions">
                <a class="button button-secondary" href="../album/edit.php?id=<?= $albumId ?>">返回相簿編輯</a>
                <div class="account-actions">
                    <?= adminIdentityMarkup() ?>
                    <a class="button button-logout" href="../logout.php">登出</a>
                </div>
            </div>
        </header>
        <?php if ($saved): ?>
            <p class="form-message form-message-success" role="status">圖片已儲存。</p>
        <?php endif; ?>
        <?php if ($errorMessage !== null): ?>
            <p class="form-message form-message-error" role="alert"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>

        <?php if ($action === 'edit' && $editingImage !== null): ?>
            <form class="settings-form album-upload-form" method="post" enctype="multipart/form-data" action="edit.php?id=<?= $albumId ?>&amp;action=edit&amp;image=<?= rawurlencode($editingImage['original']) ?>">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="replace">
                <input type="hidden" name="image" value="<?= htmlspecialchars($editingImage['original'], ENT_QUOTES, 'UTF-8') ?>">
                <label for="image_file">選擇新圖片（JPG、PNG、WebP，超過 5 MB 自動壓縮）</label>
                <input id="image_file" name="image_file" type="file" accept="image/jpeg,image/png,image/webp" required>
                <button class="button button-primary settings-submit" type="submit">替換圖片</button>
            </form>
        <?php else: ?>
            <form class="settings-form album-upload-form" method="post" enctype="multipart/form-data" action="edit.php?id=<?= $albumId ?>">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <label for="images">新增圖片（可複選，超過 5 MB 自動壓縮，尚可新增 <?= max(0, ALBUM_MAX_SUB_IMAGES - count($images)) ?> 張）</label>
                <input id="images" name="images[]" type="file" accept="image/jpeg,image/png,image/webp" multiple <?= count($images) >= ALBUM_MAX_SUB_IMAGES ? 'disabled' : '' ?>>
                <p class="field-hint">多張圖片可同時上傳，選取檔案總大小需小於 45 MB。</p>
                <p class="form-message form-message-error" id="album-upload-error" role="alert" hidden>選取圖片總大小超過 45 MB，請分批上傳。</p>
                <button class="button button-primary settings-submit" type="submit" <?= count($images) >= ALBUM_MAX_SUB_IMAGES ? 'disabled' : '' ?>>上傳圖片</button>
            </form>
        <?php endif; ?>

        <section class="album-gallery-section">
            <div class="album-gallery-heading">
                <h2>圖片列表（<?= count($images) ?> / <?= ALBUM_MAX_SUB_IMAGES ?>）</h2>
            </div>
            <p class="field-hint album-sort-hint">拖曳圖片可以改變排序，完成後請按「儲存圖片順序」。</p>
            <?php if ($images === []): ?>
                <p class="news-empty">目前沒有附加圖片。</p>
            <?php else: ?>
                <form method="post" action="edit.php?id=<?= $albumId ?>" id="sub-image-order-form">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <?php foreach ($images as $image): ?>
                        <input type="hidden" name="image_order[]" value="<?= htmlspecialchars($image['original'], ENT_QUOTES, 'UTF-8') ?>">
                    <?php endforeach; ?>
                    <input type="hidden" name="action" value="reorder">
                    <div class="album-sub-grid" id="sub-image-grid" data-album-id="<?= $albumId ?>">
                        <?php foreach ($images as $image): ?>
                            <?php
                            $thumbnail = albumThumbnailPath($image['original'], true);
                            $thumbnailFile = $thumbnail === null ? null : albumManagedImagePath($thumbnail, true);
                            ?>
                            <article class="album-sub-card" draggable="true" data-image="<?= htmlspecialchars($image['original'], ENT_QUOTES, 'UTF-8') ?>">
                                <?php if ($thumbnail !== null && $thumbnailFile !== null && is_file($thumbnailFile)): ?>
                                    <img src="<?= htmlspecialchars($thumbnail, ENT_QUOTES, 'UTF-8') ?>" alt="">
                                <?php endif; ?>
                                <div class="album-card-actions">
                                    <a class="button button-secondary" href="edit.php?id=<?= $albumId ?>&amp;action=edit&amp;image=<?= rawurlencode($image['original']) ?>">編輯</a>
                                    <button class="button button-danger" type="submit" name="delete_image" value="<?= htmlspecialchars($image['original'], ENT_QUOTES, 'UTF-8') ?>" formnovalidate onclick="return confirm('確定要刪除此圖片嗎？');">刪除</button>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                    <button class="button button-secondary album-save-order" type="submit">儲存圖片順序</button>
                </form>
            <?php endif; ?>
        </section>
    </main>
    <?php if ($images !== []): ?>
        <script>
            (() => {
                const grid = document.getElementById('sub-image-grid');
                const form = document.getElementById('sub-image-order-form');
                if (!grid || !form) return;
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
                grid.addEventListener('dragend', () => {
                    if (!dragged) return;
                    dragged.classList.remove('is-dragging');
                    dragged = null;
                    const inputs = Array.from(form.querySelectorAll('input[name="image_order[]"]'));
                    const byPath = new Map(inputs.map((input) => [input.value, input]));
                    grid.querySelectorAll('.album-sub-card').forEach((card) => form.insertBefore(byPath.get(card.dataset.image), grid));
                });
                form.addEventListener('submit', (event) => {
                    const submitter = event.submitter;
                    if (submitter && submitter.name === 'delete_image') return;
                    const ordered = Array.from(grid.querySelectorAll('.album-sub-card'), (card) => card.dataset.image);
                    form.querySelectorAll('input[name="image_order[]"]').forEach((input, index) => {
                        input.value = ordered[index];
                    });
                });
            })();
        </script>
    <?php endif; ?>
    <script>
        (() => {
            const input = document.getElementById('images');
            const error = document.getElementById('album-upload-error');
            if (!input || !error) return;
            input.addEventListener('change', () => {
                const total = Array.from(input.files).reduce((size, file) => size + file.size, 0);
                error.hidden = total <= 45 * 1024 * 1024;
                input.setCustomValidity(error.hidden ? '' : '選取圖片總大小需小於 45 MB。');
            });
        })();
    </script>
</body>
</html>
