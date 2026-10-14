<?php
declare(strict_types=1);

session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: ../login.php');
    exit;
}

require_once __DIR__ . '/../theme.php';
require_once __DIR__ . '/image_storage.php';

if (!isset($_SESSION['product_csrf_token'])) {
    $_SESSION['product_csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['product_csrf_token'];
$idParameter = $_GET['id'] ?? $_POST['id'] ?? null;
$id = $idParameter === null
    ? null
    : filter_var($idParameter, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($idParameter !== null && $id === false) {
    http_response_code(400);
    exit('無效的產品編號。');
}

$product = false;
if ($id !== null) {
    $statement = $pdo->prepare(
        'SELECT id, title, title_2, title_description, product_pic, product_jason_pic,
                product_num, product_renum, product_price, `CHK` AS chk
         FROM products
         WHERE id = :id'
    );
    $statement->execute(['id' => $id]);
    $product = $statement->fetch();
    if ($product === false) {
        http_response_code(404);
        exit('找不到這項產品。');
    }
}

$gallery = $product === false ? [] : productDecodeGallery($product['product_jason_pic']);
$values = [
    'title' => $product === false ? '' : $product['title'],
    'title_2' => $product === false ? '' : $product['title_2'],
    'title_description' => $product === false ? '' : $product['title_description'],
    'product_num' => $product === false ? '0' : (string) $product['product_num'],
    'product_renum' => $product === false ? '0' : (string) $product['product_renum'],
    'product_price' => $product === false ? '0' : (string) ($product['product_price'] ?? '0'),
    'chk' => $product === false ? '0' : (string) $product['chk'],
];
$errorMessage = null;
$saved = ($_GET['saved'] ?? '') === '1';
$returnPage = filter_var($_GET['page'] ?? $_POST['page'] ?? 1, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$returnPage = $returnPage === false ? 1 : $returnPage;
$showAll = ($_GET['show_all'] ?? $_POST['show_all'] ?? '0') === '1';
$sort = $_GET['sort'] ?? $_POST['sort'] ?? '';
$sort = in_array($sort, ['status', 'updated', 'sequence'], true) ? $sort : 'sequence';
$order = $_GET['order'] ?? $_POST['order'] ?? 'asc';
$order = in_array($order, ['asc', 'desc'], true) ? $order : 'asc';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action'])) {
    $token = $_POST['csrf_token'] ?? null;
    $title = $_POST['title'] ?? null;
    $title2 = $_POST['title_2'] ?? null;
    $description = $_POST['title_description'] ?? null;
    $productNum = $_POST['product_num'] ?? null;
    $productRenum = $_POST['product_renum'] ?? null;
    $productPrice = $_POST['product_price'] ?? null;
    $chk = $_POST['chk'] ?? '0';
    $coverUpload = $_FILES['product_pic'] ?? null;

    if (!is_string($token) || !hash_equals($csrfToken, $token)) {
        http_response_code(400);
        $errorMessage = '表單已過期，請重新載入頁面後再試。';
    } elseif (
        !is_string($title) || trim($title) === ''
        || !is_string($title2) || !is_string($description)
        || !is_string($productNum) || filter_var($productNum, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false
        || !is_string($productRenum) || filter_var($productRenum, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false
        || !is_string($productPrice) || filter_var($productPrice, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false
        || !is_string($chk) || !in_array($chk, ['0', '1'], true)
        || !is_array($coverUpload)
    ) {
        $errorMessage = '請確認產品標題、價格、數量、庫存與其他欄位內容正確。';
    } else {
        $stagedImages = [];
        $promotedImages = [];
        try {
            $coverHasUpload = ($coverUpload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
            if ($id === null && !$coverHasUpload) {
                throw new RuntimeException('新增產品時請上傳產品主圖。');
            }

            $coverStage = $coverHasUpload ? productStageUpload($coverUpload) : null;
            if ($coverStage !== null) {
                $stagedImages[] = $coverStage;
            }
            foreach ($stagedImages as $staged) {
                productPromoteImage($staged);
                $promotedImages[] = $staged;
            }

            
            $newCover = $coverStage['original'] ?? ($product === false ? '' : $product['product_pic']);
            $pdo->beginTransaction();
            if ($id === null) {
                $nextSort = (int) $pdo->query(
                    'SELECT COALESCE(MAX(data_sort), 0) + 1 FROM products'
                )->fetchColumn();
                $statement = $pdo->prepare(
                    'INSERT INTO products
                        (title, title_2, title_description, product_pic, product_jason_pic,
                         product_num, product_renum, product_price, data_sort, `CHK`)
                     VALUES
                        (:title, :title_2, :description, :picture, :gallery,
                         :quantity, :stock, :price, :data_sort, :chk)'
                );
                $statement->execute([
                    'title' => trim($title),
                    'title_2' => trim($title2),
                    'description' => trim($description),
                    'picture' => $newCover,
                    'gallery' => '[]',
                    'quantity' => (int) $productNum,
                    'stock' => (int) $productRenum,
                    'price' => (int) $productPrice,
                    'data_sort' => $nextSort,
                    'chk' => $chk,
                ]);
                $id = (int) $pdo->lastInsertId();
            } else {
                $statement = $pdo->prepare(
                    'UPDATE products
                     SET title = :title, title_2 = :title_2, title_description = :description,
                         product_pic = :picture,
                         product_num = :quantity, product_renum = :stock, product_price = :price, `CHK` = :chk,
                         updata_time = CURRENT_TIMESTAMP
                     WHERE id = :id'
                );
                $statement->execute([
                    'title' => trim($title),
                    'title_2' => trim($title2),
                    'description' => trim($description),
                    'picture' => $newCover,
                    'quantity' => (int) $productNum,
                    'stock' => (int) $productRenum,
                    'price' => (int) $productPrice,
                    'chk' => $chk,
                    'id' => $id,
                ]);
            }
            $pdo->commit();

            if ($coverStage !== null && $product !== false) {
                try {
                    productRemoveImage($product['product_pic']);
                } catch (Throwable $cleanupError) {
                    error_log('Old product cover cleanup failed: ' . $cleanupError->getMessage());
                }
            }
            header('Location: edit.php?id=' . $id . '&page=' . $returnPage . '&sort=' . urlencode($sort) . '&order=' . urlencode($order) . '&show_all=' . ($showAll ? '1' : '0') . '&saved=1');
            exit;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Product save failed: ' . $error->getMessage());
            foreach ($stagedImages as $staged) {
                if (!in_array($staged, $promotedImages, true)) {
                    foreach ([$staged['stage_original'], $staged['stage_thumbnail']] as $stagePath) {
                        if (is_file($stagePath)) {
                            unlink($stagePath);
                        }
                    }
                }
            }
            foreach ($promotedImages as $staged) {
                productRemoveImage($staged['original']);
            }
            $errorMessage = $error instanceof RuntimeException
                ? $error->getMessage()
                : '產品儲存失敗，請檢查伺服器記錄後再試。';
        }
    }
    $values = [
        'title' => is_string($title) ? $title : '',
        'title_2' => is_string($title2) ? $title2 : '',
        'title_description' => is_string($description) ? $description : '',
        'product_num' => is_string($productNum) ? $productNum : '0',
        'product_renum' => is_string($productRenum) ? $productRenum : '0',
        'product_price' => is_string($productPrice) ? $productPrice : '0',
        'chk' => is_string($chk) && in_array($chk, ['0', '1'], true) ? $chk : '0',
    ];
}

$coverThumbnail = $product === false ? null : productThumbnailPath($product['product_pic']);
$coverThumbnailFile = $coverThumbnail === null ? null : productManagedImagePath($coverThumbnail);
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $id === null ? '新增產品' : '編輯產品' ?></title>
    <link rel="stylesheet" href="../assets/css/admin.css?v=20261006-products">
</head>
<body class="dashboard-page" data-theme="<?= $styleOption ?>">
    <main class="dashboard">
        <nav class="breadcrumb" aria-label="麵包屑">
            <a href="../dashboard.php">首頁</a>
            <span aria-hidden="true">›</span>
            <a href="list.php?page=<?= $returnPage ?>&amp;sort=<?= urlencode($sort) ?>&amp;order=<?= urlencode($order) ?>&amp;show_all=<?= $showAll ? '1' : '0' ?>">產品管理</a>
            <span aria-hidden="true">›</span>
            <span aria-current="page"><?= $id === null ? '新增產品' : '編輯產品' ?></span>
        </nav>
        <header class="dashboard-header">
            <div>
                <p class="eyebrow">PRODUCT MANAGEMENT</p>
                <h1><?= $id === null ? '新增產品' : '編輯產品' ?></h1>
                <p class="page-description">附加圖片最多 <?= PRODUCT_MAX_GALLERY_IMAGES ?> 張；圖片超過 5 MB 時會自動壓縮。</p>
            </div>
            <div class="dashboard-actions">
                <a class="button button-secondary" href="list.php?page=<?= $returnPage ?>&amp;sort=<?= urlencode($sort) ?>&amp;order=<?= urlencode($order) ?>&amp;show_all=<?= $showAll ? '1' : '0' ?>">返回產品列表</a>
                <div class="account-actions">
                    <?= adminIdentityMarkup() ?>
                    <a class="button button-logout" href="../logout.php">登出</a>
                </div>
            </div>
        </header>
        <?php if ($saved): ?><p class="form-message form-message-success" role="status">產品已儲存。</p><?php endif; ?>
        <?php if ($errorMessage !== null): ?>
            <p class="form-message form-message-error" role="alert"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>

        <form class="settings-form product-form" method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="page" value="<?= $returnPage ?>">
            <input type="hidden" name="sort" value="<?= htmlspecialchars($sort, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="order" value="<?= htmlspecialchars($order, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="show_all" value="<?= $showAll ? '1' : '0' ?>">
            <?php if ($id !== null): ?><input type="hidden" name="id" value="<?= $id ?>"><?php endif; ?>
            <label for="title">產品標題</label>
            <input id="title" name="title" type="text" value="<?= htmlspecialchars($values['title'], ENT_QUOTES, 'UTF-8') ?>" required>
            <label for="title_2">產品簡介</label>
            <textarea id="title_2" name="title_2" rows="3"><?= htmlspecialchars($values['title_2'], ENT_QUOTES, 'UTF-8') ?></textarea>
            <label for="title_description">產品內容</label>
            <textarea id="title_description" name="title_description" rows="8"><?= htmlspecialchars($values['title_description'], ENT_QUOTES, 'UTF-8') ?></textarea>
            <div class="product-number-fields">
                <div>
                    <label for="product_price">產品價格（元）</label>
                    <input id="product_price" name="product_price" type="number" min="0" step="1" value="<?= htmlspecialchars($values['product_price'], ENT_QUOTES, 'UTF-8') ?>" required>
                </div>
                <div>
                    <label for="product_num">產品數量</label>
                    <input id="product_num" name="product_num" type="number" min="0" step="1" value="<?= htmlspecialchars($values['product_num'], ENT_QUOTES, 'UTF-8') ?>" required>
                </div>
                <div>
                    <label for="product_renum">產品庫存</label>
                    <input id="product_renum" name="product_renum" type="number" min="0" step="1" value="<?= htmlspecialchars($values['product_renum'], ENT_QUOTES, 'UTF-8') ?>" required>
                </div>
            </div>
            <label for="product_pic">產品主圖（JPG、PNG、WebP）<?= $id === null ? '，新增時必填' : '' ?></label>
            <input id="product_pic" name="product_pic" type="file" accept="image/jpeg,image/png,image/webp" <?= $id === null ? 'required' : '' ?>>
            <?php if ($coverThumbnail !== null && $coverThumbnailFile !== null && is_file($coverThumbnailFile)): ?>
                <div class="activity-image-preview">
                    <p>目前主圖</p>
                    <img class="activity-preview-thumbnail" src="<?= htmlspecialchars($coverThumbnail, ENT_QUOTES, 'UTF-8') ?>" alt="產品主圖">
                </div>
            <?php endif; ?>
            <label class="settings-toggle" for="chk">
                <input id="chk" name="chk" type="checkbox" value="1" <?= $values['chk'] === '1' ? 'checked' : '' ?>>
                <span>啟用產品</span>
            </label>
            <button class="button button-primary settings-submit" type="submit">儲存產品</button>
        </form>

        <?php if ($id !== null): ?>
            <section class="album-gallery-section" aria-labelledby="product-gallery-heading">
                <div class="album-gallery-heading">
                    <h2 id="product-gallery-heading">附加圖片（<?= count($gallery) ?> / <?= PRODUCT_MAX_GALLERY_IMAGES ?>）</h2>
                    <a class="button button-primary" href="../product_sub/edit.php?id=<?= $id ?>">管理附加圖片</a>
                </div>
                <?php if ($gallery === []): ?>
                    <p class="news-empty">目前沒有附加圖片。</p>
                <?php else: ?>
                    <div class="album-sub-grid" id="product-gallery" data-product-id="<?= $id ?>">
                        <?php foreach ($gallery as $image): ?>
                            <?php $thumbnail = productThumbnailPath($image); ?>
                            <?php $thumbnailFile = $thumbnail === null ? null : productManagedImagePath($thumbnail); ?>
                            <article class="album-sub-card" draggable="true" data-image="<?= htmlspecialchars($image, ENT_QUOTES, 'UTF-8') ?>">
                                <?php if ($thumbnail !== null && $thumbnailFile !== null && is_file($thumbnailFile)): ?>
                                    <img src="<?= htmlspecialchars($thumbnail, ENT_QUOTES, 'UTF-8') ?>" alt="">
                                <?php endif; ?>
                                <div class="album-card-actions">
                                    <a class="button button-secondary" href="../product_sub/edit.php?id=<?= $id ?>&amp;action=edit&amp;image=<?= rawurlencode($image) ?>">管理</a>
                                    <form method="post" action="../product_sub/edit.php?id=<?= $id ?>" onsubmit="return confirm('確定要刪除此產品圖片嗎？');">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="image" value="<?= htmlspecialchars($image, ENT_QUOTES, 'UTF-8') ?>">
                                        <button class="button button-danger" type="submit">刪除</button>
                                    </form>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                    <p class="album-sort-status" id="product-gallery-status" role="status" aria-live="polite">拖曳可改變排序</p>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </main>
    <?php if ($id !== null && $gallery !== []): ?>
        <script>
            (() => {
                const grid = document.getElementById("product-gallery");
                const status = document.getElementById("product-gallery-status");
                let dragged = null;
                grid.addEventListener("dragstart", (event) => {
                    const card = event.target.closest(".album-sub-card");
                    if (!card) return;
                    dragged = card;
                    card.classList.add("is-dragging");
                    event.dataTransfer.effectAllowed = "move";
                });
                grid.addEventListener("dragover", (event) => {
                    event.preventDefault();
                    const target = event.target.closest(".album-sub-card");
                    if (!dragged || !target || target === dragged) return;
                    const bounds = target.getBoundingClientRect();
                    grid.insertBefore(dragged, event.clientY > bounds.top + bounds.height / 2 ? target.nextSibling : target);
                });
                grid.addEventListener("dragend", async () => {
                    if (!dragged) return;
                    dragged.classList.remove("is-dragging");
                    dragged = null;
                    status.textContent = "正在儲存排序…";
                    try {
                        const response = await fetch("../product_sub/reorder.php", {
                            method: "POST",
                            headers: {"Content-Type": "application/json"},
                            body: JSON.stringify({
                                csrf_token: <?= json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
                                product_id: Number(grid.dataset.productId),
                                images: Array.from(grid.querySelectorAll(".album-sub-card"), (card) => card.dataset.image)
                            })
                        });
                        const result = await response.json();
                        if (!response.ok || !result.success) throw new Error(result.error || "排序儲存失敗。");
                        status.textContent = "排序已儲存。";
                    } catch (error) {
                        status.textContent = error.message;
                        location.reload();
                    }
                });
            })();
        </script>
    <?php endif; ?>

</body>
</html>