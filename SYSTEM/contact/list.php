<?php
declare(strict_types=1);

session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: ../login.php');
    exit;
}

require_once __DIR__ . '/../theme.php';

const CONTACT_PER_PAGE = 10;

if (!isset($_SESSION['contact_csrf_token'])) {
    $_SESSION['contact_csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['contact_csrf_token'];
$requestedPage = filter_var($_GET['page'] ?? $_POST['page'] ?? 1, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$requestedPage = $requestedPage === false ? 1 : $requestedPage;
$requestedSort = $_GET['sort'] ?? $_POST['sort'] ?? '';
$sort = in_array($requestedSort, ['date', 'status'], true) ? $requestedSort : '';
$requestedOrder = $_GET['order'] ?? $_POST['order'] ?? 'desc';
$order = in_array($requestedOrder, ['asc', 'desc'], true) ? $requestedOrder : 'desc';
$dateOrder = $sort === 'date' && $order === 'asc' ? 'desc' : 'asc';
$statusOrder = $sort === 'status' && $order === 'asc' ? 'desc' : 'asc';
$dateIndicator = $sort === 'date' ? ($order === 'asc' ? ' ▲' : ' ▼') : '';
$statusIndicator = $sort === 'status' ? ($order === 'asc' ? ' ▲' : ' ▼') : '';
$orderBy = match ($sort) {
    'date' => 'updata_time ' . strtoupper($order) . ', id DESC',
    'status' => '`CHK` ' . strtoupper($order) . ', id DESC',
    default => 'creat_time DESC, id DESC',
};
$errorMessage = null;
$deleted = ($_GET['deleted'] ?? '') === '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $token = $_POST['csrf_token'] ?? null;
    $contactId = filter_var($_POST['delete_id'], FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);
    if (!is_string($token) || !hash_equals($csrfToken, $token) || $contactId === false) {
        http_response_code(400);
        $errorMessage = '刪除請求無效，請重新整理頁面後再試。';
    } else {
        $delete = $pdo->prepare('DELETE FROM contact_us WHERE id = :id');
        $delete->execute(['id' => $contactId]);
        if ($delete->rowCount() !== 1) {
            http_response_code(404);
            $errorMessage = '找不到要刪除的聯絡訊息。';
        } else {
            $remaining = (int) $pdo->query('SELECT COUNT(*) FROM contact_us')->fetchColumn();
            $pages = max(1, (int) ceil($remaining / CONTACT_PER_PAGE));
            header('Location: list.php?' . http_build_query([
                'page' => min($requestedPage, $pages),
                'sort' => $sort,
                'order' => $order,
                'deleted' => 1,
            ]));
            exit;
        }
    }
}

$count = (int) $pdo->query('SELECT COUNT(*) FROM contact_us')->fetchColumn();
$pageCount = max(1, (int) ceil($count / CONTACT_PER_PAGE));
$page = min($requestedPage, $pageCount);
$offset = ($page - 1) * CONTACT_PER_PAGE;
$statement = $pdo->prepare(
    'SELECT id, name, title, `CHK` AS chk,
            DATE_FORMAT(updata_time, \'%Y-%m-%d\') AS contact_date
     FROM contact_us
     ORDER BY ' . $orderBy . '
     LIMIT :limit OFFSET :offset'
);
$statement->bindValue(':limit', CONTACT_PER_PAGE, PDO::PARAM_INT);
$statement->bindValue(':offset', $offset, PDO::PARAM_INT);
$statement->execute();
$contacts = $statement->fetchAll();
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>聯絡我們管理</title>
    <link rel="stylesheet" href="../assets/css/admin.css?v=20261006-contact-sort">
</head>
<body class="dashboard-page" data-theme="<?= $styleOption ?>">
    <main class="dashboard">
        <nav class="breadcrumb" aria-label="麵包屑">
            <a href="../dashboard.php">首頁</a>
            <span aria-hidden="true">›</span>
            <span aria-current="page">聯絡我們管理</span>
        </nav>
        <header class="dashboard-header">
            <div>
                <p class="eyebrow">CONTACT MESSAGES</p>
                <h1>聯絡我們管理</h1>
                <p class="page-description">共 <?= $count ?> 則訊息</p>
                <p class="page-description">點擊「聯絡日期」或「狀態」欄位可切換排序</p>
            </div>
            <div class="dashboard-actions">
                <a class="button button-secondary" href="../dashboard.php">返回管理首頁</a>
                <div class="account-actions">
                    <?= adminIdentityMarkup() ?>
                    <a class="button button-logout" href="../logout.php">登出</a>
                </div>
            </div>
        </header>
        <?php if ($deleted): ?><p class="form-message form-message-success" role="status">聯絡訊息已刪除。</p><?php endif; ?>
        <?php if ($errorMessage !== null): ?>
            <p class="form-message form-message-error" role="alert"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
        <?php if ($contacts === []): ?>
            <p class="news-empty">目前沒有聯絡訊息。</p>
        <?php else: ?>
            <div class="news-table-wrap">
                <table class="news-table contact-table">
                    <thead>
                        <tr>
                            <th scope="col">姓名</th>
                            <th scope="col">標題</th>
                            <th scope="col" aria-sort="<?= $sort === 'date' ? ($order === 'asc' ? 'ascending' : 'descending') : 'none' ?>">
                                <a class="table-sort-link" href="list.php?page=1&amp;sort=date&amp;order=<?= $dateOrder ?>">聯絡日期<?= $dateIndicator ?></a>
                            </th>
                            <th scope="col" aria-sort="<?= $sort === 'status' ? ($order === 'asc' ? 'ascending' : 'descending') : 'none' ?>">
                                <a class="table-sort-link" href="list.php?page=1&amp;sort=status&amp;order=<?= $statusOrder ?>">狀態<?= $statusIndicator ?></a>
                            </th>
                            <th scope="col">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($contacts as $contact): ?>
                            <tr>
                                <td title="<?= htmlspecialchars($contact['name'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($contact['name'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td title="<?= htmlspecialchars($contact['title'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($contact['title'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars($contact['contact_date'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= $contact['chk'] === 1 || $contact['chk'] === '1' ? '已讀取' : '未讀取' ?></td>
                                <td>
                                    <div class="news-actions">
                                        <a class="button button-secondary" href="edit.php?<?= htmlspecialchars(http_build_query(['id' => (int) $contact['id'], 'page' => $page, 'sort' => $sort, 'order' => $order]), ENT_QUOTES, 'UTF-8') ?>">查看</a>
                                        <form method="post" onsubmit="return confirm('確定要刪除此聯絡訊息嗎？');">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="page" value="<?= $page ?>">
                                            <input type="hidden" name="sort" value="<?= htmlspecialchars($sort, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="order" value="<?= htmlspecialchars($order, ENT_QUOTES, 'UTF-8') ?>">
                                            <button class="button button-danger" type="submit" name="delete_id" value="<?= (int) $contact['id'] ?>">刪除</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($pageCount > 1): ?>
                <nav class="pagination" aria-label="聯絡訊息列表分頁">
                    <?php if ($page > 1): ?><a class="button button-secondary" href="list.php?<?= htmlspecialchars(http_build_query(['page' => $page - 1, 'sort' => $sort, 'order' => $order]), ENT_QUOTES, 'UTF-8') ?>">上一頁</a><?php endif; ?>
                    <span>第 <?= $page ?> / <?= $pageCount ?> 頁</span>
                    <?php if ($page < $pageCount): ?><a class="button button-secondary" href="list.php?<?= htmlspecialchars(http_build_query(['page' => $page + 1, 'sort' => $sort, 'order' => $order]), ENT_QUOTES, 'UTF-8') ?>">下一頁</a><?php endif; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </main>
</body>
</html>
