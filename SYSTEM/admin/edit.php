<?php
declare(strict_types=1);

session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: ../login.php');
    exit;
}

require_once __DIR__ . '/../theme.php';

$powerOptions = require __DIR__ . '/../admin_permissions.php';

if (!isset($_SESSION['admin_csrf_token'])) {
    $_SESSION['admin_csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['admin_csrf_token'];
$idParameter = $_GET['id'] ?? $_POST['id'] ?? null;
$id = $idParameter === null
    ? null
    : filter_var($idParameter, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($idParameter !== null && $id === false) {
    http_response_code(400);
    exit('無效的管理員編號。');
}

$admin = false;
$protectedAdminId = $pdo->query('SELECT id FROM admin ORDER BY id ASC LIMIT 1')->fetchColumn();
$protectedAdminId = $protectedAdminId === false ? null : (int) $protectedAdminId;
if ($id !== null) {
    $statement = $pdo->prepare(
        'SELECT id, admin_name, admin_nic, admin_email, admin_power, admin_memo
         FROM admin
         WHERE id = :id'
    );
    $statement->execute(['id' => $id]);
    $admin = $statement->fetch();
    if ($admin === false) {
        http_response_code(404);
        exit('找不到這位管理員。');
    }
}
$isProtectedAdmin = $id !== null && (int) $id === $protectedAdminId;
$isCurrentAdmin = $id !== null && (int) $id === (int) $_SESSION['admin_id'];
if ($isProtectedAdmin && !$isCurrentAdmin) {
    http_response_code(403);
    exit('第一筆管理員的資料僅本人可修改。');
}
$canChangePassword = !$isProtectedAdmin || (int) $_SESSION['admin_id'] === (int) $id;

$values = [
    'admin_name' => $admin === false ? '' : $admin['admin_name'],
    'admin_nic' => $admin === false ? '' : $admin['admin_nic'],
    'admin_email' => $admin === false ? '' : $admin['admin_email'],
    'admin_memo' => $admin === false ? '' : $admin['admin_memo'],
];
$storedPower = $admin === false ? null : json_decode((string) $admin['admin_power'], true);
$selectedPowers = is_array($storedPower)
    ? array_values(array_filter(
        $storedPower,
        static fn ($permission): bool => is_string($permission) && array_key_exists($permission, $powerOptions)
    ))
    : [];
$errorMessage = null;
$saved = ($_GET['saved'] ?? '') === '1';
$returnPage = filter_var($_GET['page'] ?? $_POST['page'] ?? 1, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$returnPage = $returnPage === false ? 1 : $returnPage;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? null;
    $name = $_POST['admin_name'] ?? null;
    $nickname = $_POST['admin_nic'] ?? null;
    $email = $_POST['admin_email'] ?? '';
    $memo = $_POST['admin_memo'] ?? null;
    $power = $isProtectedAdmin
        ? $selectedPowers
        : ($_POST['admin_power'] ?? []);
    $submittedPassword = $_POST['admin_pwd'] ?? '';
    $submittedPasswordConfirmation = $_POST['admin_pwd_confirmation'] ?? '';
    $password = $canChangePassword ? $submittedPassword : '';
    $passwordConfirmation = $canChangePassword ? $submittedPasswordConfirmation : '';
    if (!is_string($token) || !hash_equals($csrfToken, $token)) {
        http_response_code(400);
        $errorMessage = '表單已過期，請重新載入頁面後再試。';
    } elseif (
        !$canChangePassword
        && ($submittedPassword !== '' || $submittedPasswordConfirmation !== '')
    ) {
        http_response_code(403);
        $errorMessage = '第一筆管理員密碼僅本人可修改。';
    } elseif (
        !is_string($name)
        || !is_string($nickname)
        || !is_string($memo)
        || !is_array($power)
        || array_filter($power, static fn ($permission): bool => !is_string($permission)) !== []
        || !is_string($password)
        || !is_string($passwordConfirmation)
        || (strlen($email) > 0 && filter_var($email, FILTER_VALIDATE_EMAIL) === false)
    ) {
        http_response_code(400);
        $errorMessage = '管理員帳號或密碼格式無效。';
    } elseif (array_diff($power, array_keys($powerOptions)) !== []) {
        http_response_code(400);
        $errorMessage = '管理員權限選項無效，請重新載入頁面後再試。';
    } elseif (trim($name) === '') {
        $errorMessage = '請輸入管理員帳號。';
    } elseif ($id === null && $password === '') {
        $errorMessage = '新增管理員時必須設定密碼。';
    } elseif ($password !== '' && strlen($password) < 8) {
        $errorMessage = '密碼至少需要 8 個字元。';
    } elseif ($password !== $passwordConfirmation) {
        $errorMessage = '兩次輸入的密碼不一致。';
    } else {
        $values['admin_name'] = trim($name);
        $values['admin_nic'] = trim($nickname);
        $values['admin_email'] = trim($email);
        $values['admin_memo'] = trim($memo);
        $selectedPowers = array_values(array_unique($power));
        try {
            $powerJson = json_encode($selectedPowers, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $duplicateSql = 'SELECT 1 FROM admin WHERE LOWER(admin_name) = LOWER(:admin_name)';
            $duplicateParameters = ['admin_name' => $values['admin_name']];
            if ($id !== null) {
                $duplicateSql .= ' AND id <> :exclude_id';
                $duplicateParameters['exclude_id'] = $id;
            }
            $duplicateCheck = $pdo->prepare($duplicateSql . ' LIMIT 1');
            $duplicateCheck->execute($duplicateParameters);
            if ($duplicateCheck->fetchColumn() !== false) {
                $errorMessage = '此管理員帳號已存在，請使用其他帳號。';
            } else {
                if ($id === null) {
                    $save = $pdo->prepare(
                        'INSERT INTO admin
                            (admin_name, admin_pwd, admin_nic, admin_email, admin_power, admin_memo)
                         VALUES
                            (:admin_name, :admin_pwd, :admin_nic, :admin_email, :admin_power, :admin_memo)'
                    );
                    $save->execute([
                        'admin_name' => $values['admin_name'],
                        'admin_pwd' => password_hash($password, PASSWORD_DEFAULT),
                        'admin_nic' => $values['admin_nic'],
                        'admin_email' => $values['admin_email'],
                        'admin_power' => $powerJson,
                        'admin_memo' => $values['admin_memo'],
                    ]);
                    $id = (int) $pdo->lastInsertId();
                } else {
                    if ($password === '') {
                        $save = $pdo->prepare(
                            'UPDATE admin
                             SET admin_name = :admin_name, admin_nic = :admin_nic,
                                 admin_email = :admin_email, admin_power = :admin_power,
                                 admin_memo = :admin_memo,
                                 updata_time = CURRENT_TIMESTAMP
                             WHERE id = :id'
                        );
                        $save->execute([
                            'admin_name' => $values['admin_name'],
                            'admin_nic' => $values['admin_nic'],
                            'admin_email' => $values['admin_email'],
                            'admin_power' => $powerJson,
                            'admin_memo' => $values['admin_memo'],
                            'id' => $id,
                        ]);
                    } else {
                        $save = $pdo->prepare(
                            'UPDATE admin
                             SET admin_name = :admin_name, admin_pwd = :admin_pwd,
                                 admin_nic = :admin_nic, admin_email = :admin_email,
                                 admin_power = :admin_power, admin_memo = :admin_memo,
                                 updata_time = CURRENT_TIMESTAMP
                             WHERE id = :id'
                        );
                        $save->execute([
                            'admin_name' => $values['admin_name'],
                            'admin_pwd' => password_hash($password, PASSWORD_DEFAULT),
                            'admin_nic' => $values['admin_nic'],
                            'admin_email' => $values['admin_email'],
                            'admin_power' => $powerJson,
                            'admin_memo' => $values['admin_memo'],
                            'id' => $id,
                        ]);
                    }
                    if ((int) $id === (int) $_SESSION['admin_id']) {
                        $_SESSION['admin_name'] = $values['admin_name'];
                        $_SESSION['admin_nic'] = $values['admin_nic'] !== ''
                            ? $values['admin_nic']
                            : $values['admin_name'];
                        $_SESSION['admin_power'] = $selectedPowers;
                    }
                }
                header('Location: edit.php?' . http_build_query([
                    'id' => $id,
                    'page' => $returnPage,
                    'saved' => 1,
                ]));
                exit;
            }
        } catch (Throwable $error) {
            error_log('Admin save failed: ' . $error->getMessage());
            $errorMessage = '管理員資料儲存失敗，請檢查伺服器記錄後再試。';
        }
    }
    if (is_string($name)) {
        $values['admin_name'] = trim($name);
    }
    if (is_string($nickname)) {
        $values['admin_nic'] = trim($nickname);
    }
    if (is_string($memo)) {
        $values['admin_memo'] = trim($memo);
    }
    if (is_array($power) && array_filter($power, static fn ($permission): bool => !is_string($permission)) === []) {
        $selectedPowers = array_values(array_intersect(array_unique($power), array_keys($powerOptions)));
    }
}
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $id === null ? '新增管理員' : '編輯管理員' ?></title>
    <link rel="stylesheet" href="../assets/css/admin.css?v=20261006-admin-management">
</head>
<body class="dashboard-page" data-theme="<?= $styleOption ?>">
    <main class="dashboard">
        <nav class="breadcrumb" aria-label="麵包屑">
            <a href="../dashboard.php">首頁</a>
            <span aria-hidden="true">›</span>
            <a href="list.php?page=<?= $returnPage ?>">網站管理員</a>
            <span aria-hidden="true">›</span>
            <span aria-current="page"><?= $id === null ? '新增管理員' : '編輯管理員' ?></span>
        </nav>
        <header class="dashboard-header">
            <div>
                <p class="eyebrow">ADMINISTRATOR MANAGEMENT</p>
                <h1><?= $id === null ? '新增管理員' : '編輯管理員' ?></h1>
            </div>
            <div class="dashboard-actions">
                <a class="button button-secondary" href="list.php?page=<?= $returnPage ?>">返回管理員列表</a>
                <div class="account-actions">
                    <?= adminIdentityMarkup() ?>
                    <a class="button button-logout" href="../logout.php">登出</a>
                </div>
            </div>
        </header>
        <?php if ($saved): ?><p class="form-message form-message-success" role="status">管理員資料已儲存。</p><?php endif; ?>
        <?php if ($errorMessage !== null): ?>
            <p class="form-message form-message-error" role="alert"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
        <form class="settings-form admin-form" method="post">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="page" value="<?= $returnPage ?>">
            <?php if ($id !== null): ?><input type="hidden" name="id" value="<?= $id ?>"><?php endif; ?>
            <label for="admin_name">管理員帳號</label>
            <input id="admin_name" name="admin_name" type="text" value="<?= htmlspecialchars($values['admin_name'], ENT_QUOTES, 'UTF-8') ?>" autocomplete="username" required>
            <label for="admin_nic">管理員暱稱</label>
            <input id="admin_nic" name="admin_nic" type="text" value="<?= htmlspecialchars($values['admin_nic'], ENT_QUOTES, 'UTF-8') ?>">
            <label for="admin_email">管理員電子郵件</label>
            <input id="admin_email" name="admin_email" type="email" value="<?= htmlspecialchars($values['admin_email'], ENT_QUOTES, 'UTF-8') ?>" placeholder="example@domain.com">
            <label for="admin_memo">管理員備註／安全提示（請勿填寫密碼字元）</label>
            <textarea id="admin_memo" name="admin_memo" rows="3"><?= htmlspecialchars($values['admin_memo'], ENT_QUOTES, 'UTF-8') ?></textarea>
            <fieldset class="admin-permissions">
                <legend>管理員權限</legend>
                <p class="field-hint"><?= $isProtectedAdmin ? '第一筆管理員的權限不可修改。' : '勾選此管理員可管理的網站區域。' ?></p>
                <div class="admin-permission-options">
                    <?php foreach ($powerOptions as $permission => $label): ?>
                        <?php $permissionId = 'admin-power-' . str_replace(['/', '.'], '-', $permission); ?>
                        <label class="admin-permission-option" for="<?= htmlspecialchars($permissionId, ENT_QUOTES, 'UTF-8') ?>">
                            <input
                                id="<?= htmlspecialchars($permissionId, ENT_QUOTES, 'UTF-8') ?>"
                                name="admin_power[]"
                                type="checkbox"
                                value="<?= htmlspecialchars($permission, ENT_QUOTES, 'UTF-8') ?>"
                                <?= in_array($permission, $selectedPowers, true) ? 'checked' : '' ?>
                                <?= $isProtectedAdmin ? 'disabled' : '' ?>
                            >
                            <span><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </fieldset>
            <?php if ($canChangePassword): ?>
                <label for="admin_pwd"><?= $id === null ? '管理員密碼' : '新密碼（不修改請留空）' ?></label>
                <input id="admin_pwd" name="admin_pwd" type="password" autocomplete="new-password" <?= $id === null ? 'required' : '' ?>>
                <label for="admin_pwd_confirmation">確認密碼</label>
                <input id="admin_pwd_confirmation" name="admin_pwd_confirmation" type="password" autocomplete="new-password" <?= $id === null ? 'required' : '' ?>>
                <p class="field-hint">密碼至少 8 個字元；密碼不會顯示於管理員列表。</p>
            <?php elseif ($isProtectedAdmin): ?>
                <p class="field-hint">第一筆管理員密碼僅本人可修改。</p>
            <?php endif; ?>
            <button class="button button-primary settings-submit" type="submit">儲存管理員</button>
        </form>
    </main>
</body>
</html>
