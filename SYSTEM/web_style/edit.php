<?php
declare(strict_types=1);

session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: ../login.php');
    exit;
}

require_once __DIR__ . '/../theme.php';

if (!isset($_SESSION['web_style_csrf_token'])) {
    $_SESSION['web_style_csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['web_style_csrf_token'];
$statement = $pdo->query('SELECT id, style_option FROM web_style ORDER BY id LIMIT 1');
$webStyle = $statement->fetch();
$selectedOption = $webStyle === false ? 0 : (int) $webStyle['style_option'];
$errorMessage = null;
$saved = isset($_GET['saved']) && $_GET['saved'] === '1';

$colorOptions = [
    0 => ['名稱' => '淡藍色（預設）', '色碼' => '#3478bb'],
    1 => ['名稱' => '森林綠', '色碼' => '#34805f'],
    2 => ['名稱' => '薰衣草紫', '色碼' => '#7654a8'],
    3 => ['名稱' => '暖陽橘', '色碼' => '#b96c2e'],
    4 => ['名稱' => '玫瑰粉', '色碼' => '#b34f6b'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? null;
    $option = filter_var($_POST['style_option'] ?? null, FILTER_VALIDATE_INT);

    if (!is_string($submittedToken) || !hash_equals($csrfToken, $submittedToken)) {
        http_response_code(400);
        $errorMessage = '表單已過期，請重新載入頁面後再試。';
    } elseif ($option === false || !array_key_exists($option, $colorOptions)) {
        $errorMessage = '請選擇有效的網站色系。';
    } else {
        if ($webStyle === false) {
            $save = $pdo->prepare(
                'INSERT INTO web_style (style_option) VALUES (:style_option)'
            );
            $save->execute(['style_option' => $option]);
        } else {
            $save = $pdo->prepare(
                'UPDATE web_style
                 SET style_option = :style_option,
                     updata_time = CURRENT_TIMESTAMP
                 WHERE id = :id'
            );
            $save->execute([
                'style_option' => $option,
                'id' => $webStyle['id'],
            ]);
        }

        header('Location: edit.php?saved=1');
        exit;
    }

    if ($option !== false && array_key_exists($option, $colorOptions)) {
        $selectedOption = $option;
    }
}
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>網站色系設定</title>
    <link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body class="dashboard-page" data-theme="<?= $styleOption ?>">
    <main class="dashboard">
        <nav class="breadcrumb" aria-label="麵包屑">
            <a href="../dashboard.php">首頁</a>
            <span aria-hidden="true">›</span>
            <span aria-current="page">網站色系</span>
        </nav>

        <header class="dashboard-header">
            <div>
                <p class="eyebrow">WEBSITE STYLE</p>
                <h1>網站色系</h1>
                <p class="page-description">選擇管理介面使用的主色系</p>
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
            <p class="form-message form-message-success" role="status">網站色系已儲存。</p>
        <?php endif; ?>
        <?php if ($errorMessage !== null): ?>
            <p class="form-message form-message-error" role="alert">
                <?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?>
            </p>
        <?php endif; ?>

        <form class="settings-form" method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <fieldset class="theme-options">
                <legend>選擇色系</legend>
                <?php foreach ($colorOptions as $option => $color): ?>
                    <label class="theme-option">
                        <input
                            type="radio"
                            name="style_option"
                            value="<?= $option ?>"
                            <?= $selectedOption === $option ? 'checked' : '' ?>
                        >
                        <span class="theme-swatch" style="--swatch-color: <?= htmlspecialchars($color['色碼'], ENT_QUOTES, 'UTF-8') ?>"></span>
                        <span><?= htmlspecialchars($color['名稱'], ENT_QUOTES, 'UTF-8') ?></span>
                    </label>
                <?php endforeach; ?>
            </fieldset>

            <button class="button button-primary settings-submit" type="submit">儲存色系</button>
        </form>
    </main>
</body>
</html>
