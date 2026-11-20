<?php
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

echo "PHP " . PHP_VERSION . "\n";
echo "Driver: " . DB_DRIVER . "\n";

if (DB_DRIVER === 'sqlite') {
    echo "Database File: " . DB_FILE . "\n";
    echo "File Exists: " . (file_exists(DB_FILE) ? 'Yes (' . filesize(DB_FILE) . ' bytes)' : 'No') . "\n";
} else {
    echo "Host: " . DB_HOST . ":" . DB_PORT . "\n";
    echo "Database: " . DB_NAME . "\n";
    echo "User: " . DB_USER . "\n";
    if (DB_PASS === 'YOUR_NEW_DATABASE_PASSWORD') {
        echo "PROBLEM: The password in config.php is still the placeholder.\n";
    }
}

try {
    $p = get_db();
    echo "Connected OK\n";
    if (DB_DRIVER === 'sqlite') {
        echo "SQLite Version: " . $p->query('SELECT sqlite_version()')->fetchColumn() . "\n";
        $tables = $p->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
        echo "Tables (" . count($tables) . "): " . implode(', ', $tables) . "\n";
        $userCount = $p->query("SELECT COUNT(*) FROM users")->fetchColumn();
        $prodCount = $p->query("SELECT COUNT(*) FROM products")->fetchColumn();
        echo "Users seeded: $userCount\n";
        echo "Products seeded: $prodCount\n";
    } else {
        echo "MySQL Server: " . $p->query('SELECT VERSION()')->fetchColumn() . "\n";
        $tables = array_map('current', $p->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM));
        echo "Tables: " . (implode(', ', $tables) ?: '(none)') . "\n";
    }
} catch (Throwable $e) {
    echo "FAILED: " . $e->getMessage() . "\n";
}
