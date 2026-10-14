<?php
declare(strict_types=1);

session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: ../login.php');
    exit;
}

require_once __DIR__ . '/../theme.php';

const ADMIN_PER_PAGE = 10;

if (!isset($_SESSION['admin_csrf_token'])) {
    $_SESSION['admin_csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['admin_csrf_token'];
$protectedAdminId = $pdo->query('SELECT id FROM admin ORDER BY id ASC LIMIT 1')->fetchColumn();
$protectedAdminId = $protectedAdminId === false ? null : (int) $protectedAdminId;
$requestedPage = filter_var($_GET['page'] ?? $_POST['page'] ?? 1, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$requestedPage = $requestedPage === false ? 1 : $requestedPage;
$errorMessage = null;
$deleted = ($_GET['deleted'] ?? '') === '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $token = $_POST['csrf_token'] ?? null;
    $adminId = filter_var($_POST['delete_id'], FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);
    if (!is_string($token) || !hash_equals($csrfToken, $token) || $adminId === false) {
        http_response_code(400);
        $errorMessage = '刪除請求無效，請重新整理頁面後再試。';
    } elseif ((int) $adminId === (int) $_SESSION['admin_id']) {
        http_response_code(403);
        $errorMessage = '無法刪除目前登入的管理員帳號。';
    } elseif ((int) $adminId === $protectedAdminId) {
        http_response_code(403);
        $errorMessage = '第一筆管理員帳號不可刪除。';
    } else {
        try {
            $pdo->beginTransaction();
            $pdo->query('SELECT id FROM admin ORDER BY id ASC FOR UPDATE')->fetchAll(PDO::FETCH_COLUMN);
            $adminCount = (int) $pdo->query('SELECT COUNT(*) FROM admin')->fetchColumn();
            if ($adminCount <= 1) {
                $pdo->rollBack();
                http_response_code(409);
                $errorMessage = '系統至少需要保留一個管理員帳號。';
            } else {
                $delete = $pdo->prepare('DELETE FROM admin WHERE id = :id');
                $delete->execute(['id' => $adminId]);
                if ($delete->rowCount() !== 1) {
                    $pdo->rollBack();
                    http_response_code(404);
                    $errorMessage = '找不到要刪除的管理員。';
                } else {
                    $pdo->commit();
                    $remaining = (int) $pdo->query('SELECT COUNT(*) FROM admin')->fetchColumn();
                    $pageCount = max(1, (int) ceil($remaining / ADMIN_PER_PAGE));
                    header('Location: list.php?' . http_build_query([
                        'page' => min($requestedPage, $pageCount),
                        'deleted' => 1,
                    ]));
                    exit;
                }
            }
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Admin delete failed: ' . $error->getMessage());
            $errorMessage = '管理員刪除失敗，請檢查伺服器記錄後再試。';
        }
    }
}

$count = (int) $pdo->query('SELECT COUNT(*) FROM admin')->fetchColumn();
$pageCount = max(1, (int) ceil($count / ADMIN_PER_PAGE));
$page = min($requestedPage, $pageCount);
$offset = ($page - 1) * ADMIN_PER_PAGE;
$statement = $pdo->prepare(
    'SELECT id, admin_name, admin_nic, admin_memo,
            DATE_FORMAT(creat_time, \'%Y-%m-%d %H:%i:%s\') AS created_at
     FROM admin
     ORDER BY id DESC
     LIMIT :limit OFFSET :offset'
);
$statement->bindValue(':limit', ADMIN_PER_PAGE, PDO::PARAM_INT);
$statement->bindValue(':offset', $offset, PDO::PARAM_INT);
$statement->execute();
$admins = $statement->fetchAll();
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>網站管理員</title>
    <link rel="stylesheet" href="../assets/css/admin.css?v=20261006-admin-management">
</head>
<body class="dashboard-page" data-theme="<?= $styleOption ?>">
    <main class="dashboard">
        <nav class="breadcrumb" aria-label="麵包屑">
            <a href="../dashboard.php">首頁</a>
            <span aria-hidden="true">›</span>
            <span aria-current="page">網站管理員</span>
        </nav>
        <header class="dashboard-header">
            <div>
                <p class="eyebrow">ADMINISTRATOR MANAGEMENT</p>
                <h1>網站管理員</h1>
                <p class="page-description">共 <?= $count ?> 位管理員</p>
            </div>
            <div class="dashboard-actions">
                <a class="button button-primary" href="edit.php">新增管理員</a>
                <a class="button button-secondary" href="../dashboard.php">返回管理首頁</a>
                <div class="account-actions">
                    <?= adminIdentityMarkup() ?>
                    <a class="button button-logout" href="../logout.php">登出</a>
                </div>
            </div>
        </header>
        <?php if ($deleted): ?><p class="form-message form-message-success" role="status">管理員已刪除。</p><?php endif; ?>
        <?php if ($errorMessage !== null): ?>
            <p class="form-message form-message-error" role="alert"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
        <?php if ($admins === []): ?>
            <p class="news-empty">目前沒有管理員，請新增管理員帳號。</p>
        <?php else: ?>
            <div class="news-table-wrap">
                <table class="news-table">
                    <thead>
                        <tr>
                            <th scope="col">管理員帳號</th>
                            <th scope="col">暱稱</th>
                            <th scope="col">管理員備註</th>
                            <th scope="col">建立時間</th>
                            <th scope="col">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($admins as $admin): ?>
                            <tr>
                                <td><?= htmlspecialchars($admin['admin_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars($admin['admin_nic'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars($admin['admin_memo'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars($admin['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td>
                                    <div class="news-actions">
                                        <?php if ((int) $admin['id'] !== $protectedAdminId || (int) $admin['id'] === (int) $_SESSION['admin_id']): ?>
                                        <a class="button button-secondary" href="edit.php?<?= htmlspecialchars(http_build_query(['id' => (int) $admin['id'], 'page' => $page]), ENT_QUOTES, 'UTF-8') ?>">編輯</a>
                                        <?php endif; ?>
                                        <?php if ((int) $admin['id'] !== $protectedAdminId): ?>
                                        <form method="post" onsubmit="return confirm('確定要刪除此管理員帳號嗎？');">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="page" value="<?= $page ?>">
                                            <button class="button button-danger" type="submit" name="delete_id" value="<?= (int) $admin['id'] ?>" <?= (int) $admin['id'] === (int) $_SESSION['admin_id'] ? 'disabled' : '' ?>>刪除</button>
                                        </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($pageCount > 1): ?>
                <nav class="pagination" aria-label="管理員列表分頁">
                    <?php if ($page > 1): ?><a class="button button-secondary" href="list.php?page=<?= $page - 1 ?>">上一頁</a><?php endif; ?>
                    <span>第 <?= $page ?> / <?= $pageCount ?> 頁</span>
                    <?php if ($page < $pageCount): ?><a class="button button-secondary" href="list.php?page=<?= $page + 1 ?>">下一頁</a><?php endif; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </main>
</body>
</html>
