<?php
declare(strict_types=1);

define('APP_NAME', 'Cartivon');
define('APP_VERSION', '3.1');
define('PARENT_COMPANY', 'Codevix Technologies');
define('COPYRIGHT_TEXT', '© 2026 Codevix Technologies. All rights reserved.');
define('BASE_URL', '');

// Database Driver: 'sqlite' (zero setup, recommended for local execution) or 'mysql'
define('DB_DRIVER', getenv('DB_DRIVER') ?: 'sqlite');

// SQLite Database file path
define('DB_FILE', __DIR__ . '/superpos.db');

// MySQL Settings (used when DB_DRIVER === 'mysql')
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_NAME', getenv('DB_NAME') ?: 'superpos');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');

define('CURRENCY', '৳');
define('BRANCH_ID', 1);

date_default_timezone_set('Asia/Dhaka');
