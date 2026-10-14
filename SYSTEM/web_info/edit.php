<?php
declare(strict_types=1);

session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: ../login.php');
    exit;
}

require_once __DIR__ . '/../theme.php';
require_once __DIR__ . '/banner_storage.php';

if (!isset($_SESSION['web_info_csrf_token'])) {
    $_SESSION['web_info_csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['web_info_csrf_token'];

$sectionOptions = [
    'news' => '最新消息',
    'activity' => '最新活動',
    'album' => '相簿管理',
    'product' => '產品管理',
    'member' => '會員管理',
];
$statement = $pdo->query(
    'SELECT id, web_title, web_description, web_json_set, web_img, chk
     FROM web_info
     ORDER BY id
     LIMIT 1'
);
$webInfo = $statement->fetch();
$bannerPath = $webInfo === false ? '' : (string) $webInfo['web_img'];
$bannerExists = webBannerManagedImagePath($bannerPath) !== null;
$bannerThumbnailPath = webBannerEnsureThumbnail($bannerPath);
$savedSections = array_fill_keys(array_keys($sectionOptions), false);
if ($webInfo !== false) {
    try {
        $decodedSections = json_decode((string) $webInfo['web_json_set'], true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        throw new RuntimeException('The saved website section settings contain invalid JSON.', 0, $error);
    }
    if (!is_array($decodedSections)) {
        throw new RuntimeException('The saved website section settings must be a JSON object.');
    }
    foreach ($sectionOptions as $key => $_label) {
        if (array_key_exists($key, $decodedSections)) {
            if (!is_bool($decodedSections[$key])) {
                throw new RuntimeException('A saved website section setting is not a boolean value.');
            }
            $savedSections[$key] = $decodedSections[$key];
        }
    }
}
$errorMessage = null;
$saved = isset($_GET['saved']) && $_GET['saved'] === '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? null;
    $webTitle = $_POST['web_title'] ?? null;
    $webDescription = $_POST['web_description'] ?? null;
    $chk = $_POST['chk'] ?? '0';
    $submittedSections = $_POST['web_json_set'] ?? [];
    $submittedChk = $chk;

    if (!is_string($submittedToken) || !hash_equals($csrfToken, $submittedToken)) {
        http_response_code(400);
        $errorMessage = '表單已過期，請重新載入頁面後再試。';
    } elseif (
        !is_string($webTitle)
        || !is_string($webDescription)
        || !is_string($chk)
        || !in_array($chk, ['0', '1'], true)
        || !is_array($submittedSections)
        || array_filter($submittedSections, static fn ($key): bool => !is_string($key)) !== []
        || array_diff($submittedSections, array_keys($sectionOptions)) !== []
        || trim($webTitle) === ''
        || trim($webDescription) === ''
    ) {
        $errorMessage = '請確認已正確填寫網站設定。';
    } else {
        $selectedSections = array_fill_keys(array_keys($sectionOptions), false);
        foreach (array_unique($submittedSections) as $key) {
            $selectedSections[$key] = true;
        }
        // 規則：開啟產品管理區時，會員管理區一併開啟
        if ($selectedSections['product']) {
            $selectedSections['member'] = true;
        }
        $sectionSettingsJson = json_encode(
            $selectedSections,
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );

        $stagedBanner = null;
        $promotedBanner = false;
        $newBannerPath = $bannerPath;
        try {
            if (isset($_FILES['web_img'])) {
                if (!is_array($_FILES['web_img']) || !isset($_FILES['web_img']['error'])) {
                    throw new RuntimeException('上傳圖片資料無效。');
                }
                if ($_FILES['web_img']['error'] !== UPLOAD_ERR_NO_FILE) {
                    $stagedBanner = webBannerStageUpload($_FILES['web_img']);
                    $newBannerPath = $stagedBanner['path'];
                    webBannerPromoteImage($stagedBanner);
                    $promotedBanner = true;
                }
            }

            if ($webInfo !== false) {
                $saveSql = 'UPDATE web_info
                            SET web_title = :web_title,
                                web_description = :web_description,
                                web_json_set = :web_json_set,
                                chk = :chk,';
                $saveParameters = [
                    'web_title' => $webTitle,
                    'web_description' => $webDescription,
                    'web_json_set' => $sectionSettingsJson,
                    'chk' => $chk,
                    'id' => $webInfo['id'],
                ];
                if ($stagedBanner !== null) {
                    $saveSql .= ' web_img = :web_img,';
                    $saveParameters['web_img'] = $newBannerPath;
                }
                $saveSql .= ' updata_time = CURRENT_TIMESTAMP WHERE id = :id';
                $save = $pdo->prepare($saveSql);
                $save->execute($saveParameters);
            } else {
                $save = $pdo->prepare(
                    'INSERT INTO web_info
                        (web_title, web_description, web_json_set, web_img, chk)
                     VALUES
                        (:web_title, :web_description, :web_json_set, :web_img, :chk)'
                );
                $save->execute([
                    'web_title' => $webTitle,
                    'web_description' => $webDescription,
                    'web_json_set' => $sectionSettingsJson,
                    'web_img' => $newBannerPath,
                    'chk' => $chk,
                ]);
            }
        } catch (PDOException $error) {
            if ($stagedBanner !== null && is_file($stagedBanner['staged_path'])) {
                unlink($stagedBanner['staged_path']);
            }
            if ($stagedBanner !== null && is_file($stagedBanner['staged_thumbnail_path'])) {
                unlink($stagedBanner['staged_thumbnail_path']);
            }
            if ($promotedBanner) {
                try {
                    webBannerRemoveImage($newBannerPath);
                } catch (Throwable $cleanupError) {
                    error_log('Failed to clean up rejected banner upload: ' . $cleanupError->getMessage());
                }
            }
            error_log('Website settings database save failed: ' . $error->getMessage());
            $errorMessage = '網站設定儲存失敗，請檢查伺服器記錄後再試。';
        } catch (RuntimeException $error) {
            if ($stagedBanner !== null && is_file($stagedBanner['staged_path'])) {
                unlink($stagedBanner['staged_path']);
            }
            if ($stagedBanner !== null && is_file($stagedBanner['staged_thumbnail_path'])) {
                unlink($stagedBanner['staged_thumbnail_path']);
            }
            if ($promotedBanner) {
                try {
                    webBannerRemoveImage($newBannerPath);
                } catch (Throwable $cleanupError) {
                    error_log('Failed to clean up rejected banner upload: ' . $cleanupError->getMessage());
                }
            }
            $errorMessage = $error->getMessage();
        } catch (Throwable $error) {
            if ($stagedBanner !== null && is_file($stagedBanner['staged_path'])) {
                unlink($stagedBanner['staged_path']);
            }
            if ($stagedBanner !== null && is_file($stagedBanner['staged_thumbnail_path'])) {
                unlink($stagedBanner['staged_thumbnail_path']);
            }
            if ($promotedBanner) {
                try {
                    webBannerRemoveImage($newBannerPath);
                } catch (Throwable $cleanupError) {
                    error_log('Failed to clean up rejected banner upload: ' . $cleanupError->getMessage());
                }
            }
            error_log('Website settings save failed: ' . $error->getMessage());
            $errorMessage = '網站設定儲存失敗，請檢查伺服器記錄後再試。';
        }

        if ($errorMessage === null) {
            if ($stagedBanner !== null && $bannerPath !== '') {
                try {
                    webBannerRemoveImage($bannerPath);
                } catch (Throwable $cleanupError) {
                    error_log('Old website banner cleanup failed: ' . $cleanupError->getMessage());
                }
            }
            header('Location: edit.php?saved=1');
            exit;
        }
    }
}

$formValues = [
    'web_title' => $webInfo === false ? '' : $webInfo['web_title'],
    'web_description' => $webInfo === false ? '' : $webInfo['web_description'],
    'web_json_set' => $savedSections,
    'web_img' => $bannerPath,
    'chk' => $webInfo === false ? '0' : (string) $webInfo['chk'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $errorMessage !== null) {
    $formValues['web_title'] = is_string($_POST['web_title'] ?? null) ? $_POST['web_title'] : '';
    $formValues['web_description'] = is_string($_POST['web_description'] ?? null)
        ? $_POST['web_description']
        : '';
    $formValues['chk'] = is_string($submittedChk) && in_array($submittedChk, ['0', '1'], true)
        ? $submittedChk
        : '0';
    $formValues['web_json_set'] = array_fill_keys(array_keys($sectionOptions), false);
    if (is_array($submittedSections)) {
        foreach ($submittedSections as $key) {
            if (is_string($key) && array_key_exists($key, $formValues['web_json_set'])) {
                $formValues['web_json_set'][$key] = true;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>網站基本設定</title>
    <link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body class="dashboard-page" data-theme="<?= $styleOption ?>">
    <main class="dashboard">
        <nav class="breadcrumb" aria-label="麵包屑">
            <a href="../dashboard.php">首頁</a>
            <span aria-hidden="true">›</span>
            <span aria-current="page">網站基本設定</span>
        </nav>

        <header class="dashboard-header">
            <div>
                <p class="eyebrow">WEBSITE SETTINGS</p>
                <h1>網站基本設定</h1>
                <p class="page-description">修改網站標題、描述與啟用狀態</p>
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
            <p class="form-message form-message-success" role="status">網站設定已儲存。</p>
        <?php endif; ?>
        <?php if ($errorMessage !== null): ?>
            <p class="form-message form-message-error" role="alert">
                <?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?>
            </p>
        <?php endif; ?>

        <form class="settings-form" method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <label for="web_title">網站標題</label>
            <input
                id="web_title"
                name="web_title"
                type="text"
                value="<?= htmlspecialchars($formValues['web_title'], ENT_QUOTES, 'UTF-8') ?>"
                required
            >

            <label for="web_description">網站描述</label>
            <textarea
                id="web_description"
                name="web_description"
                rows="5"
                required
            ><?= htmlspecialchars($formValues['web_description'], ENT_QUOTES, 'UTF-8') ?></textarea>

            <label class="settings-toggle" for="chk">
                <input
                    id="chk"
                    name="chk"
                    type="checkbox"
                    value="1"
                    <?= $formValues['chk'] === '1' ? 'checked' : '' ?>
                >
                <span>啟用網站設定</span>
            </label>

            <fieldset class="admin-permissions">
                <legend>網站區塊顯示</legend>
                <div class="admin-permission-options">
                    <?php foreach ($sectionOptions as $key => $label): ?>
                        <label class="admin-permission-option" for="web_section_<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>">
                            <input
                                id="web_section_<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>"
                                name="web_json_set[]"
                                type="checkbox"
                                value="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>"
                                <?= $formValues['web_json_set'][$key] ? 'checked' : '' ?>
                            >
                            <span><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </fieldset>

            <fieldset class="admin-permissions">
                <legend>首頁橫幅管理</legend>
                <label for="web_img">上傳首頁橫幅圖片</label>
                <input
                    id="web_img"
                    name="web_img"
                    type="file"
                    accept="image/jpeg,image/png,image/webp"
                >
                <p class="field-hint">支援 JPG、PNG、WebP；儲存圖片上限 5 MB，超過會自動壓縮。建議使用寬幅圖片。</p>
                <?php if ($bannerExists && $bannerThumbnailPath !== null): ?>
                    <div class="activity-image-preview">
                        <p>目前首頁橫幅縮圖</p>
                        <img
                            class="website-banner-preview"
                            src="<?= htmlspecialchars($bannerThumbnailPath, ENT_QUOTES, 'UTF-8') ?>"
                            alt="目前首頁橫幅縮圖"
                        >
                    </div>
                <?php endif; ?>
            </fieldset>

            <button class="button button-primary settings-submit" type="submit">儲存設定</button>
        </form>

        <script>
            (function () {
                var product = document.getElementById('web_section_product');
                var member = document.getElementById('web_section_member');
                if (!product || !member) return;
                // 規則：勾選產品管理時，會員管理一併勾選並鎖定（不可取消）
                function sync() {
                    if (product.checked) {
                        member.checked = true;
                        member.disabled = true;
                    } else {
                        member.disabled = false;
                    }
                }
                product.addEventListener('change', sync);
                sync();
            })();
        </script>
    </main>
</body>
</html>
