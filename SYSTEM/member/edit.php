<?php
declare(strict_types=1);

session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: ../login.php');
    exit;
}

require_once __DIR__ . '/../theme.php';

if (!isset($_SESSION['member_csrf_token'])) {
    $_SESSION['member_csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['member_csrf_token'];
$idParameter = $_GET['id'] ?? $_POST['id'] ?? null;
$id = $idParameter === null
    ? null
    : filter_var($idParameter, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($idParameter !== null && $id === false) {
    http_response_code(400);
    exit('無效的會員編號。');
}

$member = false;
if ($id !== null) {
    $statement = $pdo->prepare(
        'SELECT id, member_name, member_email, member_phone, member_address,
                member_memo, user_id, user_pwd, `CHK` AS chk
         FROM member
         WHERE id = :id'
    );
    $statement->execute(['id' => $id]);
    $member = $statement->fetch();
    if ($member === false) {
        http_response_code(404);
        exit('找不到這位會員。');
    }
}

$values = [
    'member_name' => $member === false ? '' : $member['member_name'],
    'member_email' => $member === false ? '' : $member['member_email'],
    'member_phone' => $member === false ? '' : $member['member_phone'],
    'member_address' => $member === false ? '' : $member['member_address'],
    'member_memo' => $member === false ? '' : $member['member_memo'],
    'user_id' => $member === false ? '' : $member['user_id'],
    'chk' => $member === false ? '0' : (string) $member['chk'],
];
$errorMessage = null;
$saved = ($_GET['saved'] ?? '') === '1';
$searchInput = $_GET['q'] ?? $_POST['q'] ?? '';
$search = is_string($searchInput) ? trim($searchInput) : '';
$returnPage = filter_var($_GET['page'] ?? $_POST['page'] ?? 1, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$returnPage = $returnPage === false ? 1 : $returnPage;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? null;
    $input = [];
    foreach (array_keys($values) as $field) {
        $input[$field] = $field === 'chk' ? ($_POST[$field] ?? '0') : ($_POST[$field] ?? null);
    }
    $userId = trim($_POST['user_id'] ?? '');
    $userPwd = $_POST['user_pwd'] ?? '';
    $validFields = true;
    foreach ($input as $field => $value) {
        if (!is_string($value)) {
            $validFields = false;
            break;
        }
    }
    if (!is_string($token) || !hash_equals($csrfToken, $token)) {
        http_response_code(400);
        $errorMessage = '表單已過期，請重新載入頁面後再試。';
    } elseif (
        !$validFields
        || trim($input['member_name']) === ''
        || trim($userId) === ''
        || ($input['member_email'] !== '' && filter_var($input['member_email'], FILTER_VALIDATE_EMAIL) === false)
        || !in_array($input['chk'], ['0', '1'], true)
        || ($id === null && $userPwd === '')
    ) {
        $errorMessage = $id === null
            ? '請確認會員姓名、帳號及密碼為必填，且信箱格式及會員狀態正確。'
            : '請確認會員姓名、帳號、信箱格式及會員狀態正確。';
    } else {
        try {
            if ($id === null) {
                $save = $pdo->prepare(
                    'INSERT INTO member
                        (member_name, member_email, member_phone, member_address,
                         member_memo, user_id, user_pwd, `CHK`)
                     VALUES
                        (:member_name, :member_email, :member_phone, :member_address,
                         :member_memo, :user_id, :user_pwd, :chk)'
                );
                $save->execute([
                    'member_name' => trim($input['member_name']),
                    'member_email' => trim($input['member_email']),
                    'member_phone' => trim($input['member_phone']),
                    'member_address' => trim($input['member_address']),
                    'member_memo' => trim($input['member_memo']),
                    'user_id' => $userId,
                    'user_pwd' => password_hash($userPwd, PASSWORD_DEFAULT),
                    'chk' => $input['chk'],
                ]);
                $id = (int) $pdo->lastInsertId();
            } else {
                if ($userPwd !== '') {
                    $save = $pdo->prepare(
                        'UPDATE member
                         SET member_name = :member_name, member_email = :member_email,
                             member_phone = :member_phone, member_address = :member_address,
                             member_memo = :member_memo, user_id = :user_id,
                             user_pwd = :user_pwd, `CHK` = :chk,
                             updata_time = CURRENT_TIMESTAMP
                         WHERE id = :id'
                    );
                    $save->execute([
                        'member_name' => trim($input['member_name']),
                        'member_email' => trim($input['member_email']),
                        'member_phone' => trim($input['member_phone']),
                        'member_address' => trim($input['member_address']),
                        'member_memo' => trim($input['member_memo']),
                        'user_id' => $userId,
                        'user_pwd' => password_hash($userPwd, PASSWORD_DEFAULT),
                        'chk' => $input['chk'],
                        'id' => $id,
                    ]);
                } else {
                    $save = $pdo->prepare(
                        'UPDATE member
                         SET member_name = :member_name, member_email = :member_email,
                             member_phone = :member_phone, member_address = :member_address,
                             member_memo = :member_memo, user_id = :user_id,
                             `CHK` = :chk,
                             updata_time = CURRENT_TIMESTAMP
                         WHERE id = :id'
                    );
                    $save->execute([
                        'member_name' => trim($input['member_name']),
                        'member_email' => trim($input['member_email']),
                        'member_phone' => trim($input['member_phone']),
                        'member_address' => trim($input['member_address']),
                        'member_memo' => trim($input['member_memo']),
                        'user_id' => $userId,
                        'chk' => $input['chk'],
                        'id' => $id,
                    ]);
                }
            }
            $query = http_build_query(array_filter([
                'q' => $search === '' ? null : $search,
                'page' => $returnPage,
                'saved' => 1,
            ], static fn ($value): bool => $value !== null));
            header('Location: edit.php?id=' . $id . '&' . $query);
            exit;
        } catch (Throwable $error) {
            error_log('Member save failed: ' . $error->getMessage());
            $errorMessage = '會員儲存失敗，請檢查伺服器記錄後再試。';
        }
    }
    foreach ($values as $field => $value) {
        $values[$field] = is_string($input[$field] ?? null) ? $input[$field] : $value;
    }
    $values['chk'] = is_string($input['chk'] ?? null) && in_array($input['chk'], ['0', '1'], true)
        ? $input['chk']
        : '0';
}
$memberOrders = [];
if ($id !== null) {
    $orders = $pdo->prepare(
        'SELECT id, cart_code
         FROM shopping_cart
         WHERE member_id = :member_id
         ORDER BY id DESC'
    );
    $orders->execute(['member_id' => $id]);
    $memberOrders = $orders->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $id === null ? '新增會員' : '編輯會員' ?></title>
    <link rel="stylesheet" href="../assets/css/admin.css?v=20261006-members-orders">
</head>
<body class="dashboard-page" data-theme="<?= $styleOption ?>">
    <main class="dashboard">
        <nav class="breadcrumb" aria-label="麵包屑">
            <a href="../dashboard.php">首頁</a>
            <span aria-hidden="true">›</span>
            <a href="list.php<?= $search === '' ? '' : '?' . htmlspecialchars(http_build_query(['q' => $search, 'page' => $returnPage]), ENT_QUOTES, 'UTF-8') ?>">會員管理</a>
            <span aria-hidden="true">›</span>
            <span aria-current="page"><?= $id === null ? '新增會員' : '編輯會員' ?></span>
        </nav>
        <header class="dashboard-header">
            <div>
                <p class="eyebrow">MEMBER MANAGEMENT</p>
                <h1><?= $id === null ? '新增會員' : '編輯會員' ?></h1>
            </div>
            <div class="dashboard-actions">
                <a class="button button-secondary" href="list.php<?= $search === '' ? '' : '?' . htmlspecialchars(http_build_query(['q' => $search, 'page' => $returnPage]), ENT_QUOTES, 'UTF-8') ?>">返回會員列表</a>
                <div class="account-actions">
                    <?= adminIdentityMarkup() ?>
                    <a class="button button-logout" href="../logout.php">登出</a>
                </div>
            </div>
        </header>
        <?php if ($saved): ?><p class="form-message form-message-success" role="status">會員資料已儲存。</p><?php endif; ?>
        <?php if ($errorMessage !== null): ?>
            <p class="form-message form-message-error" role="alert"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
        <form class="settings-form member-form" method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="page" value="<?= $returnPage ?>">
            <?php if ($id !== null): ?><input type="hidden" name="id" value="<?= $id ?>"><?php endif; ?>
            <label for="member_name">會員姓名</label>
            <input id="member_name" name="member_name" type="text" value="<?= htmlspecialchars($values['member_name'], ENT_QUOTES, 'UTF-8') ?>" required>

            <label for="user_id">會員帳號</label>
            <input id="user_id" name="user_id" type="text" value="<?= htmlspecialchars($values['user_id'], ENT_QUOTES, 'UTF-8') ?>" required>

            <label for="user_pwd">會員密碼<?= $id === null ? '<span style="color:#b42318"> *</span>' : '(留空表示不修改)' ?></label>
            <input id="user_pwd" name="user_pwd" type="password" autocomplete="new-password">

            <label for="member_email">會員信箱</label>
            <input id="member_email" name="member_email" type="email" value="<?= htmlspecialchars($values['member_email'], ENT_QUOTES, 'UTF-8') ?>">
            <label for="member_phone">會員電話</label>
            <input id="member_phone" name="member_phone" type="tel" value="<?= htmlspecialchars($values['member_phone'], ENT_QUOTES, 'UTF-8') ?>">
            <label for="member_address">會員地址</label>
            <textarea id="member_address" name="member_address" rows="3"><?= htmlspecialchars($values['member_address'], ENT_QUOTES, 'UTF-8') ?></textarea>
            <label for="member_memo">會員備註 (此欄位僅後台管理員備註用 前端無法查看)</label>
            <textarea id="member_memo" name="member_memo" rows="5"><?= htmlspecialchars($values['member_memo'], ENT_QUOTES, 'UTF-8') ?></textarea>
            <label class="settings-toggle" for="chk">
                <input id="chk" name="chk" type="checkbox" value="1" <?= $values['chk'] === '1' ? 'checked' : '' ?>>
                <span>啟用會員</span>
            </label>
            <button class="button button-primary settings-submit" type="submit">儲存會員</button>
        </form>
        <?php if ($id !== null): ?>
            <section class="member-orders-card" aria-labelledby="member-orders-heading">
                <h2 id="member-orders-heading">會員訂購紀錄</h2>
                <?php if ($memberOrders === []): ?>
                    <p class="news-empty">此會員目前沒有訂單。</p>
                <?php else: ?>
                    <div class="member-order-links">
                        <?php foreach ($memberOrders as $order): ?>
                            <a class="button button-secondary" href="../shopping_cart/edit.php?id=<?= (int) $order['id'] ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars($order['cart_code'], ENT_QUOTES, 'UTF-8') ?></a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </main>
</body>
</html>
