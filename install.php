<?php
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

try {
    $pdo = get_db();
} catch (Throwable $e) {
    die("Cannot connect to the database:\n" . $e->getMessage() . "\n\nOpen dbtest.php for diagnostics.");
}

$driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
echo "Database connection established ($driver).\n";

try {
    $hasUsers = false;
    if ($driver === 'sqlite') {
        $hasUsers = (bool)$pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'")->fetchColumn();
    } else {
        $have = array_map('current', $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM));
        $hasUsers = in_array('users', $have);
    }

    if ($hasUsers && empty($_GET['force'])) {
        echo "Cartivon by Codevix Technologies\n";
        echo "© 2026 Codevix Technologies. All rights reserved.\n\n";
        echo "Cartivon is already installed and ready!\n\n";
        echo "Default Logins:\n";
        echo "• Admin:   admin@71mart.com / admin123\n";
        echo "• Cashier: cashier@71mart.com / cashier123\n\n";
        echo "To re-run installation and reseed from scratch, add ?force=1 to the URL.\n";
        exit;
    }

    echo "Running schema installation...\n";
    init_database($pdo, true);

    echo "Cartivon installed successfully.\n\n";
    echo "Default Logins:\n";
    echo "• Admin:   admin@71mart.com / admin123\n";
    echo "• Cashier: cashier@71mart.com / cashier123\n";
} catch (Throwable $e) {
    echo "Installation error: " . $e->getMessage() . "\n";
}
