<?php
declare(strict_types=1);

session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: ../login.php');
    exit;
}

require_once __DIR__ . '/../theme.php';

const MEMBER_PER_PAGE = 10;

if (!isset($_SESSION['member_csrf_token'])) {
    $_SESSION['member_csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['member_csrf_token'];
$searchInput = $_GET['q'] ?? $_POST['q'] ?? '';
$search = is_string($searchInput) ? trim($searchInput) : '';
$requestedPage = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$requestedPage = $requestedPage === false ? 1 : $requestedPage;
$errorMessage = null;
$deleted = ($_GET['deleted'] ?? '') === '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $token = $_POST['csrf_token'] ?? null;
    $memberId = filter_var($_POST['delete_id'], FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);
    if (!is_string($token) || !hash_equals($csrfToken, $token) || $memberId === false) {
        http_response_code(400);
        $errorMessage = '刪除請求無效，請重新整理頁面後再試。';
    } else {
        $delete = $pdo->prepare('DELETE FROM member WHERE id = :id');
        $delete->execute(['id' => $memberId]);
        if ($delete->rowCount() !== 1) {
            http_response_code(404);
            $errorMessage = '找不到要刪除的會員。';
        } else {
            $query = http_build_query(['q' => $search, 'page' => $requestedPage, 'deleted' => 1]);
            header('Location: list.php?' . $query);
            exit;
        }
    }
}

$where = '';
$parameters = [];
if ($search !== '') {
    $where = ' WHERE member_name LIKE :name_search
               OR member_email LIKE :email_search
               OR member_phone LIKE :phone_search
               OR member_address LIKE :address_search';
    $searchTerm = '%' . $search . '%';
    $parameters = [
        'name_search' => $searchTerm,
        'email_search' => $searchTerm,
        'phone_search' => $searchTerm,
        'address_search' => $searchTerm,
    ];
}
$countStatement = $pdo->prepare('SELECT COUNT(*) FROM member' . $where);
$countStatement->execute($parameters);
$count = (int) $countStatement->fetchColumn();
$pageCount = max(1, (int) ceil($count / MEMBER_PER_PAGE));
$page = min($requestedPage, $pageCount);
$offset = ($page - 1) * MEMBER_PER_PAGE;
$statement = $pdo->prepare(
    'SELECT id, member_name, member_email, member_phone, `CHK` AS chk
     FROM member' . $where . '
     ORDER BY id DESC
     LIMIT :limit OFFSET :offset'
);
foreach ($parameters as $key => $value) {
    $statement->bindValue(':' . $key, $value, PDO::PARAM_STR);
}
$statement->bindValue(':limit', MEMBER_PER_PAGE, PDO::PARAM_INT);
$statement->bindValue(':offset', $offset, PDO::PARAM_INT);
$statement->execute();
$members = $statement->fetchAll();
$queryParameters = $search === '' ? [] : ['q' => $search];
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>會員管理</title>
    <link rel="stylesheet" href="../assets/css/admin.css?v=20261006-members-2">
</head>
<body class="dashboard-page" data-theme="<?= $styleOption ?>">
    <main class="dashboard">
        <nav class="breadcrumb" aria-label="麵包屑">
            <a href="../dashboard.php">首頁</a>
            <span aria-hidden="true">›</span>
            <span aria-current="page">會員管理</span>
        </nav>
        <header class="dashboard-header">
            <div>
                <p class="eyebrow">MEMBER MANAGEMENT</p>
                <h1>會員管理</h1>
                <p class="page-description">共 <?= $count ?> 位會員</p>
            </div>
            <div class="dashboard-actions">
                <a class="button button-primary" href="edit.php<?= $search === '' ? '' : '?' . htmlspecialchars(http_build_query(['q' => $search, 'page' => $page]), ENT_QUOTES, 'UTF-8') ?>">新增會員</a>
                <a class="button button-secondary" href="../dashboard.php">返回管理首頁</a>
                <div class="account-actions">
                    <?= adminIdentityMarkup() ?>
                    <a class="button button-logout" href="../logout.php">登出</a>
                </div>
            </div>
        </header>
        <form class="member-search-form" method="get" role="search">
            <label for="member-search">會員搜尋</label>
            <input id="member-search" name="q" type="search" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>" placeholder="姓名、信箱、電話或地址">
            <button class="button button-primary" type="submit">搜尋</button>
            <?php if ($search !== ''): ?><a class="button button-secondary" href="list.php">清除</a><?php endif; ?>
        </form>
        <?php if ($deleted): ?><p class="form-message form-message-success" role="status">會員已刪除。</p><?php endif; ?>
        <?php if ($errorMessage !== null): ?>
            <p class="form-message form-message-error" role="alert"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
        <?php if ($members === []): ?>
            <p class="news-empty"><?= $search === '' ? '目前沒有會員，請新增會員資料。' : '找不到符合搜尋條件的會員。' ?></p>
        <?php else: ?>
            <div class="news-table-wrap">
                <table class="news-table news-list-table member-table">
                    <thead>
                        <tr>
                            <th scope="col">會員姓名</th>
                            <th scope="col">會員信箱</th>
                            <th scope="col">會員電話</th>
                            <th scope="col">會員狀態</th>
                            <th scope="col">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($members as $member): ?>
                            <tr>
                                <td title="<?= htmlspecialchars($member['member_name'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($member['member_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td title="<?= htmlspecialchars($member['member_email'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($member['member_email'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td title="<?= htmlspecialchars($member['member_phone'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($member['member_phone'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= $member['chk'] === 1 || $member['chk'] === '1' ? '啟用' : '停用' ?></td>
                                <td>
                                    <div class="news-actions">
                                        <a class="button button-secondary" href="edit.php?<?= htmlspecialchars(http_build_query(array_merge(['id' => (int) $member['id']], $queryParameters, ['page' => $page])), ENT_QUOTES, 'UTF-8') ?>">編輯</a>
                                        <form method="post" onsubmit="return confirm('確定要刪除此會員資料嗎？');">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="page" value="<?= $page ?>">
                                            <button class="button button-danger" type="submit" name="delete_id" value="<?= (int) $member['id'] ?>">刪除</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($pageCount > 1): ?>
                <nav class="pagination" aria-label="會員列表分頁">
                    <?php if ($page > 1): ?><a class="button button-secondary" href="list.php?<?= htmlspecialchars(http_build_query(array_merge($queryParameters, ['page' => $page - 1])), ENT_QUOTES, 'UTF-8') ?>">上一頁</a><?php endif; ?>
                    <span>第 <?= $page ?> / <?= $pageCount ?> 頁</span>
                    <?php if ($page < $pageCount): ?><a class="button button-secondary" href="list.php?<?= htmlspecialchars(http_build_query(array_merge($queryParameters, ['page' => $page + 1])), ENT_QUOTES, 'UTF-8') ?>">下一頁</a><?php endif; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </main>
</body>
</html>
