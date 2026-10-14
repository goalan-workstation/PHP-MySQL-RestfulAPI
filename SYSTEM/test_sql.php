<?php
declare(strict_types=1);

// test_sql.php : 資料庫連線測試（放在 SYSTEM/）
// POST test_sql.php → JSON
// 回傳：success / mysql_version / table_count / error
//
// 做法：
//   1. 沿用 conn/conn.inc.php（讀環境變數 DB_HOST/DB_PORT/DB_NAME/DB_USER/DB_PASSWORD，
//      未設則預設 127.0.0.1:3306 / alan_php / root / 空密碼）建立 $pdo
//   2. 執行 SELECT 1 確認連線可用
//   3. 順帶 SHOW TABLES 統計表數，確認 alan_php 庫在且非空
//
// 本測試檔只讀不寫、不送 CSRF（非正式业务流程，僅供開發測試用）。
// 接受 GET / POST（瀏覽器可直接打開）。

require_once dirname(__DIR__) . '/conn/conn.inc.php';

header('Content-Type: application/json; charset=utf-8');
function testJson(mixed $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

// conn.inc.php 已在頂層建立 $pdo（連線失敗時它會自行 500 exit，不會走到這裡）
$pdo = $GLOBALS['pdo'] ?? null;
if ($pdo === null) {
    testJson(['success' => false, 'step' => 'pdo-missing', 'error' => 'conn.inc.php 未產生 $pdo。'], 500);
}

// 1. SELECT 1 驗證
try {
    $one = (int) $pdo->query('SELECT 1')->fetchColumn();
} catch (PDOException $e) {
    testJson(['success' => false, 'step' => 'select', 'error' => 'SELECT 1 失敗：' . $e->getMessage()], 500);
}

// 2. 表統計（確認匯入的資料在）
$tables = [];
try {
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException) {
    $tables = [];
}

// 3. MySQL 版本
$ver = '';
try { $ver = (string) $pdo->query('SELECT VERSION()')->fetchColumn(); } catch (PDOException) {}

// 4. web_style.style_option 範圍檢查（theme.php 會在此處抛 RuntimeException 導致空白頁）
$style = null;
$styleOk = null;
try {
    $v = $pdo->query('SELECT style_option FROM web_style ORDER BY id LIMIT 1')->fetchColumn();
    if ($v !== false) {
        $style = (int) $v;
        $styleOk = ($v !== false && $style >= 0 && $style <= 4);
    }
} catch (PDOException) {}

testJson([
    'success' => ($one === 1 && $tables !== []),
    'step' => 'ok',
    'select' => $one,
    'php_version' => PHP_VERSION,
    'database' => getenv('DB_NAME') ?: 'alan_php',
    'mysql_version' => $ver,
    'table_count' => count($tables),
    'tables' => $tables,
    'web_style' => ['style_option' => $style, 'in_valid_range_0_4' => $styleOk],
]);

