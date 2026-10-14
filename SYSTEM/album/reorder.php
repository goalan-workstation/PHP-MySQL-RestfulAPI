<?php
declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=UTF-8');

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => '登入已逾時，請重新登入。']);
    exit;
}

require_once __DIR__ . '/../../conn/conn.inc.php';

$payload = json_decode(file_get_contents('php://input'), true);
$csrfToken = $_SESSION['album_csrf_token'] ?? '';
$page = filter_var($payload['page'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$ids = $payload['ids'] ?? null;
$showAll = ($payload['show_all'] ?? false) === true;

if (!is_array($payload)
    || !is_string($payload['csrf_token'] ?? null)
    || !is_string($csrfToken)
    || $csrfToken === ''
    || !hash_equals($csrfToken, $payload['csrf_token'])
    || $page === false
    || !is_array($ids)
    || $ids === []
    || count($ids) > 10000
) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => '排序資料無效，請重新整理後再試。']);
    exit;
}

$validIds = [];
foreach ($ids as $id) {
    $validId = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($validId === false) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => '相簿編號無效。']);
        exit;
    }
    $validIds[] = $validId;
}
if (count(array_unique($validIds)) !== count($validIds)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => '相簿排序中有重複的編號。']);
    exit;
}

$allIds = $pdo->query('SELECT id FROM album ORDER BY data_sort ASC, id DESC')
    ->fetchAll(PDO::FETCH_COLUMN);
$expected = $showAll
    ? array_map('intval', $allIds)
    : array_map('intval', array_slice($allIds, ($page - 1) * 9, 9));
if (count($expected) !== count($validIds) || array_diff($expected, $validIds) !== []) {
    http_response_code(409);
    echo json_encode(['success' => false, 'error' => '相簿清單已變更，請重新整理後再調整排序。']);
    exit;
}

$pdo->beginTransaction();
try {
    $update = $pdo->prepare('UPDATE album SET data_sort = :sort, updata_time = CURRENT_TIMESTAMP WHERE id = :id');
    $offset = $showAll ? 0 : ($page - 1) * 9;
    foreach ($validIds as $position => $id) {
        $update->execute(['sort' => $offset + $position, 'id' => $id]);
    }
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $error;
}

echo json_encode(['success' => true]);
