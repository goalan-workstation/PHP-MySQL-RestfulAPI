<?php

declare(strict_types=1);

$dbHost = getenv('DB_HOST') ?: '127.0.0.1';
$dbPort = getenv('DB_PORT') ?: '3306';
$dbName = getenv('DB_NAME') ?: 'alan_php';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPassword = getenv('DB_PASSWORD') ?: '';

$dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";

try {
    $pdo = new PDO($dsn, $dbUser, $dbPassword, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 10,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="zh-Hant"><head><meta charset="UTF-8">'
        . '<title>資料庫連線失敗</title>'
        . '<style>body{font-family:sans-serif;display:flex;align-items:center;justify-content:center;'
        . 'height:100vh;margin:0;background:#f5f5f5}.box{background:#fff;padding:2rem 3rem;'
        . 'border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,.1);text-align:center}'
        . 'h1{color:#c0392b;font-size:1.2rem;margin:0 0 .5rem}p{color:#555;margin:0}</style>'
        . '</head><body><div class="box"><h1>⚠ 資料庫連線失敗</h1>'
        . '<p>無法連接到 MySQL 資料庫（' . htmlspecialchars($dbHost . ':' . $dbPort) . '）。</p>'
        . '<p style="margin-top:.75rem;font-size:.85rem">請確認 XAMPP MySQL 服務已啟動（D:\xampp\mysql_start.bat）。</p>'
        . '<p style="margin-top:1rem;font-size:.75rem;color:#999">'
        . htmlspecialchars('詳細錯誤：' . $e->getMessage(), ENT_QUOTES) . '</p>'
        . '</div></body></html>';
    exit;
}
