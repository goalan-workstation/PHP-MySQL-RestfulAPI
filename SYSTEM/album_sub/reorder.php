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
require_once __DIR__ . '/../album/image_storage.php';

$payload = json_decode(file_get_contents('php://input'), true);
$csrfToken = $_SESSION['album_csrf_token'] ?? '';
$albumId = filter_var($payload['album_id'] ?? null, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$orderedPaths = $payload['images'] ?? null;

if (!is_array($payload)
    || !is_string($payload['csrf_token'] ?? null)
    || !is_string($csrfToken)
    || $csrfToken === ''
    || !hash_equals($csrfToken, $payload['csrf_token'])
    || $albumId === false
    || !is_array($orderedPaths)
) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => '排序資料無效，請重新整理後再試。']);
    exit;
}

$statement = $pdo->prepare('SELECT album_jason_pic FROM album WHERE id = :id');
$statement->execute(['id' => $albumId]);
$json = $statement->fetchColumn();
if ($json === false) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => '找不到相簿。']);
    exit;
}

$images = albumDecodeSubImages($json);
$byPath = [];
foreach ($images as $image) {
    $byPath[$image['original']] = $image;
}
$reordered = [];
foreach ($orderedPaths as $path) {
    if (!is_string($path) || !isset($byPath[$path]) || isset($reordered[$path])) {
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => '附加圖片清單已變更，請重新整理後再排序。']);
        exit;
    }
    $reordered[$path] = $byPath[$path];
}
if (count($reordered) !== count($images)) {
    http_response_code(409);
    echo json_encode(['success' => false, 'error' => '附加圖片清單已變更，請重新整理後再排序。']);
    exit;
}

$save = $pdo->prepare(
    'UPDATE album SET album_jason_pic = :images, updata_time = CURRENT_TIMESTAMP WHERE id = :id'
);
$save->execute([
    'images' => json_encode(array_values($reordered), JSON_THROW_ON_ERROR),
    'id' => $albumId,
]);
echo json_encode(['success' => true]);
