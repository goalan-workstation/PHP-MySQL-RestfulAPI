<?php
declare(strict_types=1);

session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: ../login.php');
    exit;
}

require_once __DIR__ . '/../theme.php';
require_once __DIR__ . '/image_storage.php';

const ACTIVITY_PER_PAGE = 10;

if (!isset($_SESSION['activity_csrf_token'])) {
    $_SESSION['activity_csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['activity_csrf_token'];
$returnPage = filter_var($_GET['page'] ?? $_POST['page'] ?? 1, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$returnPage = $returnPage === false ? 1 : $returnPage;
$sort = $_GET['sort'] ?? $_POST['sort'] ?? '';
$sort = in_array($sort, ['status', 'updated', 'sequence'], true) ? $sort : 'sequence';
$order = $_GET['order'] ?? $_POST['order'] ?? '';
$order = in_array($order, ['asc', 'desc'], true) ? $order : 'desc';
$idParameter = $_GET['id'] ?? $_POST['id'] ?? null;
$id = null;

if ($idParameter !== null) {
    $id = filter_var($idParameter, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);
    if ($id === false) {
        http_response_code(400);
        exit('無效的活動編號。');
    }
}

$activity = false;
if ($id !== null) {
    $statement = $pdo->prepare(
        'SELECT id, title, title_2, description, activity_spic, activity_pic, `CHK` AS chk
         FROM activity
         WHERE id = :id'
    );
    $statement->execute(['id' => $id]);
    $activity = $statement->fetch();
    if ($activity === false) {
        http_response_code(404);
        exit('找不到這筆活動。');
    }
}

$formValues = [
    'title' => $activity === false ? '' : $activity['title'],
    'title_2' => $activity === false ? '' : $activity['title_2'],
    'description' => $activity === false ? '' : $activity['description'],
    'chk' => $activity === false ? '0' : (string) $activity['chk'],
];
$errorMessage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? null;
    $title = $_POST['title'] ?? null;
    $title2 = $_POST['title_2'] ?? null;
    $description = $_POST['description'] ?? null;
    $chk = $_POST['chk'] ?? '0';
    $upload = $_FILES['activity_pic'] ?? null;
    $staged = null;

    if (!is_string($submittedToken) || !hash_equals($csrfToken, $submittedToken)) {
        http_response_code(400);
        $errorMessage = '表單已過期，請重新載入頁面後再試。';
    } elseif (
        !is_string($title)
        || trim($title) === ''
        || !is_string($title2)
        || !is_string($description)
        || !is_string($chk)
        || !in_array($chk, ['0', '1'], true)
        || !is_array($upload)
    ) {
        $errorMessage = '請填寫活動標題並確認其他欄位內容正確。';
    } else {
        try {
            $hasUpload = ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
            if ($id === null && !$hasUpload) {
                throw new RuntimeException('新增活動時請上傳活動圖片。');
            }

            if ($hasUpload) {
                $staged = activityStageUpload(
                    $upload,
                    $activity === false ? null : $activity['activity_pic']
                );
            }

            $save = function () use ($pdo, $id, $title, $title2, $description, $chk, $staged, $activity): void {
                if ($id === null) {
                    $nextSort = (int) $pdo->query(
                        'SELECT COALESCE(MAX(data_sort), 0) + 1 FROM activity'
                    )->fetchColumn();
                    $statement = $pdo->prepare(
                        'INSERT INTO activity (title, title_2, description, activity_spic, activity_pic, `CHK`, data_sort)
                         VALUES (
                             :title,
                             :title_2,
                             :description,
                             :activity_spic,
                             :activity_pic,
                             :chk,
                             :data_sort
                         )'
                    );
                    $statement->execute([
                        'title' => $title,
                        'title_2' => $title2,
                        'description' => $description,
                        'activity_spic' => $staged['thumbnail'],
                        'activity_pic' => $staged['original'],
                        'chk' => $chk,
                        'data_sort' => $nextSort,
                    ]);
                    return;
                }

                if ($staged !== null) {
                    $statement = $pdo->prepare(
                        'UPDATE activity
                         SET title = :title,
                             title_2 = :title_2,
                             description = :description,
                             activity_spic = :activity_spic,
                             activity_pic = :activity_pic,
                             `CHK` = :chk,
                             updata_time = CURRENT_TIMESTAMP
                         WHERE id = :id'
                    );
                    $statement->execute([
                        'title' => $title,
                        'title_2' => $title2,
                        'description' => $description,
                        'activity_spic' => $staged['thumbnail'],
                        'activity_pic' => $staged['original'],
                        'chk' => $chk,
                        'id' => $id,
                    ]);
                    return;
                }

                $statement = $pdo->prepare(
                    'UPDATE activity
                     SET title = :title,
                         title_2 = :title_2,
                         description = :description,
                         `CHK` = :chk,
                         updata_time = CURRENT_TIMESTAMP
                     WHERE id = :id'
                );
                $statement->execute([
                    'title' => $title,
                    'title_2' => $title2,
                    'description' => $description,
                    'chk' => $chk,
                    'id' => $id,
                ]);
            };

            if ($staged !== null) {
                $oldPaths = $activity === false
                    ? []
                    : [$activity['activity_spic'], $activity['activity_pic']];
                activityPromoteImages($staged, $oldPaths, $save);
            } else {
                $save();
            }

            $count = (int) $pdo->query('SELECT COUNT(*) FROM activity')->fetchColumn();
            $pageCount = max(1, (int) ceil($count / ACTIVITY_PER_PAGE));
            header('Location: list.php?page=' . min($returnPage, $pageCount) . '&sort=' . urlencode($sort) . '&order=' . urlencode($order) . '&saved=1');
            exit;
        } catch (RuntimeException $error) {
            $errorMessage = $error->getMessage();
        }
    }

    $formValues = [
        'title' => is_string($title) ? $title : '',
        'title_2' => is_string($title2) ? $title2 : '',
        'description' => is_string($description) ? $description : '',
        'chk' => is_string($chk) && in_array($chk, ['0', '1'], true) ? $chk : '0',
    ];
}

$thumbnailPath = $activity === false ? null : activityManagedImagePath($activity['activity_spic']);
$originalPath = $activity === false ? null : activityManagedImagePath($activity['activity_pic']);
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $id === null ? '新增活動' : '編輯活動' ?></title>
    <link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body class="dashboard-page" data-theme="<?= $styleOption ?>">
    <main class="dashboard">
        <nav class="breadcrumb" aria-label="麵包屑">
            <a href="../dashboard.php">首頁</a>
            <span aria-hidden="true">›</span>
            <a href="list.php?page=<?= $returnPage ?>&amp;sort=<?= urlencode($sort) ?>&amp;order=<?= urlencode($order) ?>">最新活動</a>
            <span aria-hidden="true">›</span>
            <span aria-current="page"><?= $id === null ? '新增活動' : '編輯活動' ?></span>
        </nav>

        <header class="dashboard-header">
            <div>
                <p class="eyebrow">ACTIVITY MANAGEMENT</p>
                <h1><?= $id === null ? '新增活動' : '編輯活動' ?></h1>
                <p class="page-description">填寫活動內容並上傳活動圖片</p>
            </div>
            <div class="dashboard-actions">
                <a class="button button-secondary" href="list.php?page=<?= $returnPage ?>&amp;sort=<?= urlencode($sort) ?>&amp;order=<?= urlencode($order) ?>">返回活動列表</a>
                <div class="account-actions">
                    <?= adminIdentityMarkup() ?>
                    <a class="button button-logout" href="../logout.php">登出</a>
                </div>
            </div>
        </header>

        <?php if ($errorMessage !== null): ?>
            <p class="form-message form-message-error" role="alert">
                <?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?>
            </p>
        <?php endif; ?>

        <form class="settings-form" method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="page" value="<?= $returnPage ?>">
            <input type="hidden" name="sort" value="<?= htmlspecialchars($sort, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="order" value="<?= htmlspecialchars($order, ENT_QUOTES, 'UTF-8') ?>">
            <?php if ($id !== null): ?>
                <input type="hidden" name="id" value="<?= $id ?>">
            <?php endif; ?>

            <label for="title">標題</label>
            <input
                id="title"
                name="title"
                type="text"
                value="<?= htmlspecialchars($formValues['title'], ENT_QUOTES, 'UTF-8') ?>"
                required
            >

            <label for="title_2">副標題</label>
            <input
                id="title_2"
                name="title_2"
                type="text"
                value="<?= htmlspecialchars($formValues['title_2'], ENT_QUOTES, 'UTF-8') ?>"
            >

            <label for="description">描述</label>
            <textarea id="description" name="description" rows="7"><?= htmlspecialchars($formValues['description'], ENT_QUOTES, 'UTF-8') ?></textarea>

            <label for="activity_pic">活動圖片（JPG、PNG、WebP，單檔上限 5 MB）</label>
            <input
                id="activity_pic"
                name="activity_pic"
                type="file"
                accept="image/jpeg,image/png,image/webp"
                <?= $id === null ? 'required' : '' ?>
            >
            <p class="field-hint">圖片超過 5 MB 時會自動壓縮，並產生縮圖；編輯時可不重新上傳。</p>

            <?php if ($thumbnailPath !== null && is_file($thumbnailPath)): ?>
                <div class="activity-image-preview">
                    <p>目前縮圖</p>
                    <img class="activity-preview-thumbnail" src="<?= htmlspecialchars($activity['activity_spic'], ENT_QUOTES, 'UTF-8') ?>" alt="目前活動縮圖">
                    <?php if ($originalPath !== null && is_file($originalPath)): ?>
                        <a href="<?= htmlspecialchars($activity['activity_pic'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">查看原圖</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <label class="settings-toggle" for="chk">
                <input
                    id="chk"
                    name="chk"
                    type="checkbox"
                    value="1"
                    <?= $formValues['chk'] === '1' ? 'checked' : '' ?>
                >
                <span>啟用活動</span>
            </label>

            <button class="button button-primary settings-submit" type="submit">儲存活動</button>
        </form>
    </main>
</body>
</html>
