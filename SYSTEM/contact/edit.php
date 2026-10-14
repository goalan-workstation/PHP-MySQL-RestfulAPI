<?php
declare(strict_types=1);

session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: ../login.php');
    exit;
}

require_once __DIR__ . '/../theme.php';

if (!isset($_SESSION['contact_csrf_token'])) {
    $_SESSION['contact_csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['contact_csrf_token'];
$idParameter = $_GET['id'] ?? null;
$id = $idParameter === null
    ? false
    : filter_var($idParameter, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($id === false) {
    http_response_code(400);
    exit('無效的聯絡訊息編號。');
}

$statement = $pdo->prepare(
    'SELECT id, name, email, title, content, contact_memo, `CHK` AS chk,
            DATE_FORMAT(updata_time, \'%Y-%m-%d %H:%i:%s\') AS updated_at
     FROM contact_us
     WHERE id = :id'
);
$statement->execute(['id' => $id]);
$contact = $statement->fetch();
if ($contact === false) {
    http_response_code(404);
    exit('找不到這則聯絡訊息。');
}
$returnPage = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$returnPage = $returnPage === false ? 1 : $returnPage;
$requestedSort = $_GET['sort'] ?? '';
$returnSort = in_array($requestedSort, ['date', 'status'], true) ? $requestedSort : '';
$requestedOrder = $_GET['order'] ?? 'desc';
$returnOrder = in_array($requestedOrder, ['asc', 'desc'], true) ? $requestedOrder : 'desc';
$returnListQuery = http_build_query([
    'page' => $returnPage,
    'sort' => $returnSort,
    'order' => $returnOrder,
]);
$values = [
    'chk' => (string) $contact['chk'],
    'contact_memo' => (string) $contact['contact_memo'],
];
$errorMessage = null;
$saved = ($_GET['saved'] ?? '') === '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? null;
    $chk = $_POST['chk'] ?? '0';
    $memo = $_POST['contact_memo'] ?? null;
    if (!is_string($token) || !hash_equals($csrfToken, $token)) {
        http_response_code(400);
        $errorMessage = '表單已過期，請重新載入頁面後再試。';
    } elseif (!is_string($chk) || !in_array($chk, ['0', '1'], true) || !is_string($memo)) {
        http_response_code(400);
        $errorMessage = '訊息狀態或備註格式無效，請重新載入頁面後再試。';
    } else {
        try {
            $update = $pdo->prepare(
                'UPDATE contact_us
                 SET `CHK` = :chk, contact_memo = :contact_memo,
                     updata_time = CURRENT_TIMESTAMP
                 WHERE id = :id'
            );
            $update->execute([
                'chk' => $chk,
                'contact_memo' => $memo,
                'id' => $id,
            ]);
            $query = http_build_query([
                'id' => $id,
                'page' => $returnPage,
                'sort' => $returnSort,
                'order' => $returnOrder,
                'saved' => 1,
            ]);
            header('Location: edit.php?' . $query);
            exit;
        } catch (Throwable $error) {
            error_log('Contact message update failed: ' . $error->getMessage());
            $errorMessage = '聯絡訊息狀態或備註儲存失敗，請檢查伺服器記錄後再試。';
        }
    }
    $values = [
        'chk' => is_string($chk) && in_array($chk, ['0', '1'], true) ? $chk : $values['chk'],
        'contact_memo' => is_string($memo) ? $memo : $values['contact_memo'],
    ];
}
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>聯絡訊息內容</title>
    <link rel="stylesheet" href="../assets/css/admin.css?v=20261006-contact-edit">
</head>
<body class="dashboard-page" data-theme="<?= $styleOption ?>">
    <main class="dashboard">
        <nav class="breadcrumb" aria-label="麵包屑">
            <a href="../dashboard.php">首頁</a>
            <span aria-hidden="true">›</span>
            <a href="list.php?<?= htmlspecialchars($returnListQuery, ENT_QUOTES, 'UTF-8') ?>">聯絡我們管理</a>
            <span aria-hidden="true">›</span>
            <span aria-current="page">聯絡訊息內容</span>
        </nav>
        <header class="dashboard-header">
            <div>
                <p class="eyebrow">CONTACT MESSAGE</p>
                <h1>聯絡訊息內容</h1>
            </div>
            <div class="dashboard-actions">
                <a class="button button-secondary" href="list.php?<?= htmlspecialchars($returnListQuery, ENT_QUOTES, 'UTF-8') ?>">返回聯絡訊息列表</a>
                <div class="account-actions">
                    <?= adminIdentityMarkup() ?>
                    <a class="button button-logout" href="../logout.php">登出</a>
                </div>
            </div>
        </header>
        <?php if ($saved): ?><p class="form-message form-message-success" role="status">聯絡訊息已更新。</p><?php endif; ?>
        <?php if ($errorMessage !== null): ?>
            <p class="form-message form-message-error" role="alert"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
        <form class="settings-form contact-edit-form" method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="page" value="<?= $returnPage ?>">
            <label class="settings-toggle" for="contact-chk">
                <input id="contact-chk" name="chk" type="checkbox" value="1" <?= $values['chk'] === '1' ? 'checked' : '' ?>>
                <span>訊息已讀取</span>
            </label>
            <label for="contact_memo">訊息備註</label>
            <textarea id="contact_memo" name="contact_memo" rows="4"><?= htmlspecialchars($values['contact_memo'], ENT_QUOTES, 'UTF-8') ?></textarea>
            <button class="button button-primary settings-submit" type="submit">儲存</button>
        </form>
        <section class="contact-detail-card" aria-label="聯絡訊息">
            <dl class="contact-details">
                <dt>姓名</dt>
                <dd><?= htmlspecialchars($contact['name'], ENT_QUOTES, 'UTF-8') ?></dd>
                <dt>信箱</dt>
                <dd><?= htmlspecialchars($contact['email'], ENT_QUOTES, 'UTF-8') ?></dd>
                <dt>標題</dt>
                <dd><?= htmlspecialchars($contact['title'], ENT_QUOTES, 'UTF-8') ?></dd>
                <dt>詢問時間</dt>
                <dd><?= htmlspecialchars($contact['updated_at'], ENT_QUOTES, 'UTF-8') ?></dd>
                <dt>內容</dt>
                <dd class="contact-content"><?= htmlspecialchars($contact['content'], ENT_QUOTES, 'UTF-8') ?></dd>
            </dl>
        </section>
    </main>
</body>
</html>
