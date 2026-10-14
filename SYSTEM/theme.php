<?php
declare(strict_types=1);

require_once __DIR__ . '/../conn/conn.inc.php';

$styleOption = $pdo->query(
    'SELECT style_option FROM web_style ORDER BY id LIMIT 1'
)->fetchColumn();

if ($styleOption === false) {
    $styleOption = 0;
} else {
    $styleOption = (int) $styleOption;
}

if ($styleOption < 0 || $styleOption > 4) {
    throw new RuntimeException('The saved website color option is invalid.');
}

if (isset($_SESSION['admin_id'])) {
    if (!isset($_SESSION['admin_nic'])) {
        $adminStatement = $pdo->prepare(
            'SELECT admin_nic, admin_name
             FROM admin
             WHERE id = :id'
        );
        $adminStatement->execute(['id' => $_SESSION['admin_id']]);
        $adminIdentity = $adminStatement->fetch();
        if ($adminIdentity === false) {
            throw new RuntimeException('The current administrator account could not be loaded.');
        }
        $_SESSION['admin_nic'] = $adminIdentity['admin_nic'] !== ''
            ? $adminIdentity['admin_nic']
            : $adminIdentity['admin_name'];
    }
}

function adminIdentityMarkup(): string
{
    if (!isset($_SESSION['admin_id'])) {
        return '';
    }

    $nickname = is_string($_SESSION['admin_nic'] ?? null) ? $_SESSION['admin_nic'] : '';
    return '<span class="current-admin">目前登入的管理者為 <span class="current-admin-nickname">'
        . htmlspecialchars($nickname, ENT_QUOTES, 'UTF-8')
        . '</span></span>';
}
