<?php
declare(strict_types=1);

session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: ../login.php');
    exit;
}

require_once __DIR__ . '/../theme.php';

$statement = $pdo->query(
    'SELECT id, about_us FROM about_us ORDER BY id LIMIT 1'
);
$about = $statement->fetch();
$errorMessage = null;
$saved = isset($_GET['saved']) && $_GET['saved'] === '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? null;
    $aboutUs = $_POST['about_us'] ?? null;

    if (!is_string($submittedToken) || !isset($_SESSION['about_us_csrf_token']) || !hash_equals($_SESSION['about_us_csrf_token'], $submittedToken)) {
        $errorMessage = '表單已過期，請重新載入頁面後再試。';
    } elseif ($aboutUs === null || !is_string($aboutUs)) {
        $errorMessage = '請輸入關於我們內容。';
    } else {
        if ($about !== false) {
            $save = $pdo->prepare(
                'UPDATE about_us
                 SET about_us = :about_us,
                     updata_time = CURRENT_TIMESTAMP
                 WHERE id = :id'
            );
            $save->execute([
                'about_us' => $aboutUs,
                'id' => $about['id'],
            ]);
        } else {
            $save = $pdo->prepare(
                'INSERT INTO about_us (about_us) VALUES (:about_us)'
            );
            $save->execute(['about_us' => $aboutUs]);
        }

        header('Location: edit.php?saved=1');
        exit;
    }
}

if (!isset($_SESSION['about_us_csrf_token'])) {
    $_SESSION['about_us_csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['about_us_csrf_token'];
$formValue = $about === false ? '' : (string) $about['about_us'];
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>關於我們管理</title>
    <link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body class="dashboard-page" data-theme="<?= $styleOption ?>">
    <main class="dashboard">
        <nav class="breadcrumb" aria-label="麵包屑">
            <a href="../dashboard.php">首頁</a>
            <span aria-hidden="true">›</span>
            <span aria-current="page">關於我們管理</span>
        </nav>

        <header class="dashboard-header">
            <div>
                <p class="eyebrow">ABOUT US</p>
                <h1>關於我們管理</h1>
                <p class="page-description">修改關於我們內容</p>
            </div>
            <div class="dashboard-actions">
                <a class="button button-secondary" href="../dashboard.php">返回管理首頁</a>
                <div class="account-actions">
                    <?= adminIdentityMarkup() ?>
                    <a class="button button-logout" href="../logout.php">登出</a>
                </div>
            </div>
        </header>

        <?php if ($saved): ?>
            <p class="form-message form-message-success" role="status">關於我們內容已儲存。</p>
        <?php endif; ?>
        <?php if ($errorMessage !== null): ?>
            <p class="form-message form-message-error" role="alert">
                <?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?>
            </p>
        <?php endif; ?>

        <form class="settings-form" method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <label for="about_us">關於我們內容</label>
            <textarea
                id="about_us"
                name="about_us"
                rows="12"
                required
            ><?= htmlspecialchars($formValue, ENT_QUOTES, 'UTF-8') ?></textarea>

            <button class="button button-primary settings-submit" type="submit">儲存設定</button>
        </form>
    </main>
</body>
</html>
