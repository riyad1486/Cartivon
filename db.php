<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function ensure_database_schema(PDO $db, string $driver): void {
    try {
        if ($driver === 'sqlite') {
            $hasCoupons = (bool)$db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='coupons'")->fetchColumn();
            if (!$hasCoupons) {
                $db->exec("CREATE TABLE IF NOT EXISTS coupons (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    code VARCHAR(50) NOT NULL UNIQUE,
                    label VARCHAR(120) NOT NULL,
                    type VARCHAR(20) NOT NULL DEFAULT 'fixed',
                    val DECIMAL(10,2) NOT NULL DEFAULT 0,
                    min_order DECIMAL(10,2) NOT NULL DEFAULT 0,
                    max_discount DECIMAL(10,2) NULL,
                    usage_limit INT NOT NULL DEFAULT 0,
                    used_count INT NOT NULL DEFAULT 0,
                    expires_at DATE NULL,
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                );");
            }
        } else {
            // MySQL
            $hasCoupons = (bool)$db->query("SHOW TABLES LIKE 'coupons'")->fetchColumn();
            if (!$hasCoupons) {
                $db->exec("CREATE TABLE IF NOT EXISTS coupons (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    code VARCHAR(50) NOT NULL UNIQUE,
                    label VARCHAR(120) NOT NULL,
                    type ENUM('fixed','percent') NOT NULL DEFAULT 'fixed',
                    val DECIMAL(10,2) NOT NULL DEFAULT 0,
                    min_order DECIMAL(10,2) NOT NULL DEFAULT 0,
                    max_discount DECIMAL(10,2) NULL,
                    usage_limit INT NOT NULL DEFAULT 0,
                    used_count INT NOT NULL DEFAULT 0,
                    expires_at DATE NULL,
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB;");
            }
        }

        // Seed coupons if empty
        $cCount = (int)$db->query("SELECT COUNT(*) FROM coupons")->fetchColumn();
        if ($cCount === 0) {
            $db->exec("INSERT INTO coupons(code, label, type, val, min_order, usage_limit, used_count, is_active) VALUES
                ('SAVE50', 'Flat ৳50 Coupon Discount', 'fixed', 50, 200, 100, 3, 1),
                ('SUPER10', '10% Cart Coupon Discount', 'percent', 10, 500, 200, 8, 1),
                ('EID2026', '৳100 Special Voucher Discount', 'fixed', 100, 800, 50, 1, 1),
                ('WELCOME5', '5% Welcome Coupon', 'percent', 5, 100, 500, 12, 1),
                ('MART20', '৳20 Quick Discount', 'fixed', 20, 100, 0, 5, 1);");
        }

        // Ensure branches has is_active column
        if ($driver === 'sqlite') {
            $cols = array_column($db->query("PRAGMA table_info(branches)")->fetchAll(PDO::FETCH_ASSOC), 'name');
            if (!in_array('is_active', $cols)) {
                $db->exec("ALTER TABLE branches ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1;");
            }
        } else {
            $hasActiveCol = (bool)$db->query("SHOW COLUMNS FROM branches LIKE 'is_active'")->fetchColumn();
            if (!$hasActiveCol) {
                $db->exec("ALTER TABLE branches ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1;");
            }
        }
    } catch (Throwable $e) {
        error_log("Schema auto-ensure notice: " . $e->getMessage());
    }
}

function get_db(): PDO {
    static $db = null;
    if ($db !== null) {
        return $db;
    }

    $driver = defined('DB_DRIVER') ? DB_DRIVER : 'sqlite';

    if ($driver === 'sqlite') {
        $dbFile = defined('DB_FILE') ? DB_FILE : __DIR__ . '/superpos.db';
        $db = new PDO('sqlite:' . $dbFile, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        // Register custom SQL functions for MySQL compatibility in SQLite
        $db->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'));
        $db->sqliteCreateFunction('CURDATE', fn() => date('Y-m-d'));
        $db->sqliteCreateFunction('DATE', fn($d) => substr((string)$d, 0, 10));
        $db->sqliteCreateFunction('DATE_FORMAT', function($d, $fmt) {
            $ts = strtotime((string)$d) ?: time();
            $phpFmt = str_replace(
                ['%Y', '%m', '%d', '%H', '%i', '%s', '%M', '%b'],
                ['Y', 'm', 'd', 'H', 'i', 's', 'F', 'M'],
                $fmt
            );
            return date($phpFmt, $ts);
        });

        // Enable foreign key constraints in SQLite
        $db->exec('PRAGMA foreign_keys = ON;');

        // Check if database needs initial schema setup
        $hasTables = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'")->fetchColumn();
        if (!$hasTables) {
            init_database($db);
        }
        ensure_database_schema($db, 'sqlite');
    } else {
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $db = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        try { $db->exec("SET time_zone='+06:00'"); } catch (Throwable $e) {}
        try { $db->exec("SET SESSION sql_mode='NO_ENGINE_SUBSTITUTION'"); } catch (Throwable $e) {}

        $hasTables = (bool)$db->query("SHOW TABLES LIKE 'users'")->fetchColumn();
        if (!$hasTables) {
            init_database($db);
        }
        ensure_database_schema($db, 'mysql');
    }

    return $db;
}

function convert_mysql_schema_to_sqlite(string $sql): string {
    $sql = preg_replace('/--[^\n]*/', '', $sql);
    $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);
    $sql = preg_replace('/^\s*SET\s+[^;]+;/mi', '', $sql);
    $sql = preg_replace('/\)\s*ENGINE\s*=\s*\w+\s*;/i', ');', $sql);
    $sql = preg_replace('/COMMENT\s*\'[^\']*\'/i', '', $sql);
    $sql = preg_replace('/BIGINT\s+UNSIGNED\s+AUTO_INCREMENT\s+PRIMARY\s+KEY/i', 'INTEGER PRIMARY KEY AUTOINCREMENT', $sql);
    $sql = preg_replace('/INT\s+AUTO_INCREMENT\s+PRIMARY\s+KEY/i', 'INTEGER PRIMARY KEY AUTOINCREMENT', $sql);
    $sql = preg_replace('/ENUM\s*\([^\)]+\)/i', 'TEXT', $sql);
    $sql = preg_replace('/\bJSON\b/i', 'TEXT', $sql);
    $sql = preg_replace('/,\s*FULLTEXT\s+KEY\s+[^\)]+\)/i', '', $sql);
    $sql = preg_replace('/UNIQUE\s+KEY\s+\w*\s*\(([^\)]+)\)/i', 'UNIQUE ($1)', $sql);
    $sql = preg_replace('/UNIQUE\s+KEY\s*\(([^\)]+)\)/i', 'UNIQUE ($1)', $sql);
    $sql = preg_replace('/,\s*INDEX\s*\([^\)]+\)/i', '', $sql);
    $sql = preg_replace('/,\s*INDEX\s+\w+\s*\([^\)]+\)/i', '', $sql);
    return $sql;
}

function init_database(PDO $pdo, bool $force = false): void {
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $schemaFile = __DIR__ . '/superpos_schema.sql';
    if (!file_exists($schemaFile)) {
        throw new RuntimeException('Schema file superpos_schema.sql not found');
    }

    $rawSql = file_get_contents($schemaFile);
    if ($driver === 'sqlite') {
        $cleanSql = convert_mysql_schema_to_sqlite($rawSql);
    } else {
        $cleanSql = preg_replace('/--[^\n]*/', '', $rawSql);
    }

    $statements = array_filter(array_map('trim', preg_split('/;\s*\n/', $cleanSql)));
    foreach ($statements as $st) {
        if ($st !== '') {
            $pdo->exec($st);
        }
    }

    // Seed Core Data
    $pdo->exec("INSERT INTO branches(id,code,name,address) VALUES(1,'MAIN','71 Mart','Dhaka, Bangladesh')");
    
    $roleStmt = $pdo->prepare("INSERT INTO roles(name,is_system) VALUES(?,1)");
    foreach (['Super Admin','Shop Owner','Manager','Cashier','Inventory Manager','Accountant'] as $r) {
        $roleStmt->execute([$r]);
    }

    $pdo->exec("INSERT INTO units(id,name,short_name) VALUES(1,'Piece','pc'),(2,'Kilogram','kg')");

    $u = $pdo->prepare("INSERT INTO users(role_id,branch_id,name,email,password) VALUES(?,1,?,?,?)");
    $u->execute([1, 'Administrator', 'admin@71mart.com', password_hash('admin123', PASSWORD_DEFAULT)]);
    $u->execute([4, 'Cashier One', 'cashier@71mart.com', password_hash('cashier123', PASSWORD_DEFAULT)]);

    // Seed Demo Catalogue & Stock
    $demoProducts = [
        ['Miniket Rice 5kg', 'RC-005', '8901001', 'Grocery', 420, 480, 0, 60, 10],
        ['Soybean Oil 1L', 'OL-001', '8901002', 'Grocery', 165, 185, 5, 48, 12],
        ['Fresh Milk 1L', 'ML-001', '8901003', 'Dairy', 85, 95, 0, 36, 10],
        ['Eggs (dozen)', 'EG-012', '8901004', 'Dairy', 130, 150, 0, 25, 8],
        ['Masoor Dal 1kg', 'DL-001', '8901005', 'Grocery', 120, 140, 0, 40, 10],
        ['Sugar 1kg', 'SG-001', '8901006', 'Grocery', 115, 130, 0, 50, 15],
        ['Green Tea 25pk', 'TE-025', '8901007', 'Beverage', 150, 185, 15, 18, 6],
        ['Orange Juice 1L', 'JC-001', '8901008', 'Beverage', 110, 140, 15, 4, 6],
        ['Bath Soap', 'SP-001', '8901009', 'Personal Care', 38, 50, 15, 70, 20],
        ['Shampoo 180ml', 'SH-180', '8901010', 'Personal Care', 210, 265, 15, 14, 6],
        ['Biscuit Pack', 'BS-001', '8901011', 'Snacks', 22, 30, 15, 0, 20],
        ['Potato Chips', 'CH-001', '8901012', 'Snacks', 30, 40, 15, 55, 15]
    ];

    $cats = [];
    $catStmt = $pdo->prepare("INSERT INTO categories(name) VALUES(?)");
    $prodStmt = $pdo->prepare("INSERT INTO products(unit_id,category_id,name,sku,barcode,purchase_price,selling_price,vat_rate,min_stock,created_at,updated_at) VALUES(1,?,?,?,?,?,?,?,?,NOW(),NOW())");
    $invStmt = $pdo->prepare("INSERT INTO inventory(branch_id,product_id,quantity,unit_cost) VALUES(1,?,?,?)");
    $moveStmt = $pdo->prepare("INSERT INTO stock_movements(branch_id,product_id,type,quantity,unit_cost) VALUES(1,?,'opening',?,?)");

    foreach ($demoProducts as $p) {
        $catName = $p[3];
        if (!isset($cats[$catName])) {
            $catStmt->execute([$catName]);
            $cats[$catName] = $pdo->lastInsertId();
        }
        $prodStmt->execute([$cats[$catName], $p[0], $p[1], $p[2], $p[4], $p[5], $p[6], $p[8]]);
        $id = $pdo->lastInsertId();
        $invStmt->execute([$id, $p[7], $p[4]]);
        if ($p[7] > 0) {
            $moveStmt->execute([$id, $p[7], $p[4]]);
        }
    }

    // Seed Expense Categories
    $expCatStmt = $pdo->prepare("INSERT INTO expense_categories(name) VALUES(?)");
    foreach (["Store Supplies", "Tea & Refreshments", "Cleaning & Sanitation", "Utilities & Internet", "Transportation & Delivery", "Packaging Materials", "Other Expenses"] as $c) {
        $expCatStmt->execute([$c]);
    }

    // Seed Default Cash Register for Branch 1
    $pdo->exec("INSERT INTO cash_registers(branch_id, name, is_active) VALUES(1, 'Main Counter', 1)");

    // Seed Demo Regular Customers
    $custStmt = $pdo->prepare("INSERT INTO customers(name, phone, email, address, loyalty_points, credit_limit, is_active, created_at, updated_at) VALUES(?,?,?,?,?,?,1,NOW(),NOW())");
    $demoCustomers = [
        ["Rahim Ahmed", "01711223344", "rahim@gmail.com", "House 12, Road 5, Dhanmondi, Dhaka", 120, 5000],
        ["Nasrin Sultana", "01822334455", "nasrin@yahoo.com", "Sector 3, Uttara, Dhaka", 85, 3000],
        ["Kamal Hossain", "01933445566", "kamal@outlook.com", "Gulshan 2, Dhaka", 210, 10000]
    ];
    foreach ($demoCustomers as $c) {
        $custStmt->execute($c);
    }

    // Seed Demo Active Promo Coupons
    $pdo->exec("INSERT OR IGNORE INTO coupons(code, label, type, val, min_order, usage_limit, used_count, is_active) VALUES
        ('SAVE50', 'Flat ৳50 Coupon Discount', 'fixed', 50, 200, 100, 3, 1),
        ('SUPER10', '10% Cart Coupon Discount', 'percent', 10, 500, 200, 8, 1),
        ('EID2026', '৳100 Special Voucher Discount', 'fixed', 100, 800, 50, 1, 1),
        ('WELCOME5', '5% Welcome Coupon', 'percent', 5, 100, 500, 12, 1),
        ('MART20', '৳20 Quick Discount', 'fixed', 20, 100, 0, 5, 1);");
}

