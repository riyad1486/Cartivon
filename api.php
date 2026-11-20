<?php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json'); header('X-Content-Type-Options: nosniff');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

function out($x, $c = 200) { http_response_code($c); echo json_encode($x); exit; }

try {
  $db = get_db();
} catch (Throwable $e) {
  out(['error' => 'Database connection failed: ' . $e->getMessage()], 500);
}

// Active branch for queries: only Super Admin can switch active branch; others are strictly locked to their assigned branch_id
$userBranch = (int)($_SESSION['u']['branch_id'] ?? BRANCH_ID);
$userRole = (string)($_SESSION['u']['role'] ?? '');
$isSuperAdminUser = ($userRole === 'Super Admin');

if ($isSuperAdminUser && isset($_SESSION['u']['active_branch_id'])) {
  $BR = (int)$_SESSION['u']['active_branch_id'];
} else {
  $BR = $userBranch;
  if (isset($_SESSION['u'])) {
    $_SESSION['u']['active_branch_id'] = $userBranch;
  }
}

function rows($s, $p = []) {
  global $db;
  if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
    $s = preg_replace('/\s+FOR\s+UPDATE\b/i', '', $s);
  }
  $q = $db->prepare($s);
  $q->execute($p);
  return $q->fetchAll();
}
function one($s, $p = []) { return rows($s, $p)[0] ?? null; }
function run($s, $p = []) {
  global $db;
  if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
    $s = preg_replace('/\s+FOR\s+UPDATE\b/i', '', $s);
  }
  $db->prepare($s)->execute($p);
  return $db->lastInsertId();
}
function fl($r, $k) { foreach ($k as $x) if (isset($r[$x])) $r[$x] = (float)$r[$x]; return $r; }
function uid() { return $_SESSION['u']['id'] ?? null; }
function audit($a, $t, $id = null, $n = null) {
  run("INSERT INTO audit_logs(user_id,branch_id,action,auditable_type,auditable_id,new_values,ip_address) VALUES(?,?,?,?,?,?,?)",
    [uid(), $GLOBALS['BR'], $a, $t, $id, $n ? json_encode($n) : null, $_SERVER['REMOTE_ADDR'] ?? '']);
}
function fail($m) { throw new RuntimeException($m); }
function get_setting($branchId, $key, $default = null) {
  $r = one("SELECT value FROM settings WHERE (branch_id=? OR branch_id IS NULL) AND `key`=? ORDER BY branch_id DESC LIMIT 1", [$branchId, $key]);
  if (!$r) return $default;
  $decoded = json_decode($r['value'], true);
  return $decoded !== null ? $decoded : $r['value'];
}
function set_setting($branchId, $key, $value) {
  $valJson = json_encode($value, JSON_UNESCAPED_UNICODE);
  $ex = one("SELECT id FROM settings WHERE branch_id=? AND `key`=?", [$branchId, $key]);
  if ($ex) {
    run("UPDATE settings SET value=? WHERE id=?", [$valJson, $ex['id']]);
  } else {
    run("INSERT INTO settings(branch_id, `key`, value) VALUES(?, ?, ?)", [$branchId, $key, $valJson]);
  }
}
const INV = "i.product_id=p.id AND i.branch_id=? AND i.variant_id IS NULL AND i.batch_no=''";

function receipt($id) {
  global $isSuperAdmin, $BR;
  $s = one("SELECT s.*,u.name cashier,b.name branch_name,b.address branch_address,b.phone branch_phone,b.bin_number,
    c.name customer_name,c.phone customer_phone,c.loyalty_points customer_points,c.due_balance customer_due
    FROM sales s 
    JOIN users u ON u.id=s.cashier_id 
    LEFT JOIN branches b ON b.id=s.branch_id 
    LEFT JOIN customers c ON c.id=s.customer_id 
    WHERE s.id=?", [$id]);
  if (!$s) fail('Sale not found');
  if (!$isSuperAdmin && (int)$s['branch_id'] !== (int)$BR) {
    fail('Access denied: invoice belongs to another branch');
  }
  
  $it = rows("SELECT name_snapshot name,quantity qty,unit_price price,discount_amount discount,line_total total,unit_cost cost,(line_total - unit_cost*quantity) profit FROM sale_items WHERE sale_id=?", [$id]);
  $py = rows("SELECT method,amount,reference FROM payments WHERE sale_id=? AND direction='in'", [$id]);
  
  $saleTotalCost = 0.0;
  $saleTotalProfit = 0.0;
  foreach ($it as $r) {
    $saleTotalCost += ((float)$r['cost'] * (float)$r['qty']);
    $saleTotalProfit += (float)$r['profit'];
  }
  
  return [
    'sale' => [
      'id' => (int)$s['id'],
      'invoice_no' => $s['invoice_no'],
      'status' => $s['status'],
      'grand_total' => (float)$s['grand_total'],
      'subtotal' => (float)$s['subtotal'],
      'created_at' => $s['sold_at'],
      'cashier_name' => $s['cashier'],
      'customer_name' => $s['customer_name'] ?: 'Walk-in',
      'customer_phone' => $s['customer_phone'] ?: '',
      'branch_name' => $s['branch_name'] ?: 'Cartivon Store'
    ],
    'id' => (int)$s['id'],
    'inv' => $s['invoice_no'],
    'date' => $s['sold_at'],
    'status' => $s['status'],
    'cashier' => $s['cashier'],
    'shift_id' => (int)($s['shift_id'] ?? 0),
    'store' => $s['branch_name'] ?: 'Cartivon Store',
    'store_address' => $s['branch_address'] ?: 'Dhaka, Bangladesh',
    'store_phone' => $s['branch_phone'] ?: '',
    'bin' => $s['bin_number'] ?: '000000000-0000',
    'customer' => $s['customer_name'] ? [
      'id' => (int)$s['customer_id'],
      'name' => $s['customer_name'],
      'phone' => $s['customer_phone'],
      'points' => (int)$s['customer_points'],
      'earned' => (int)($s['points_earned'] ?? 0),
      'redeemed' => (int)($s['points_redeemed'] ?? 0),
      'due' => (float)($s['customer_due'] ?? 0)
    ] : null,
    'sub' => (float)$s['subtotal'],
    'cdv' => (float)$s['cart_discount_amount'],
    'vat' => (float)$s['tax_total'],
    'grand' => (float)$s['grand_total'],
    'cost' => (float)$saleTotalCost,
    'profit' => (float)$saleTotalProfit,
    'margin' => (float)$s['grand_total'] > 0 ? round(($saleTotalProfit / (float)$s['grand_total']) * 100, 1) : 0.0,
    'change' => (float)$s['change_due'],
    'header' => (string)get_setting((int)($s['branch_id'] ?: $GLOBALS['BR']), 'receipt_header', ''),
    'footer' => (string)get_setting((int)($s['branch_id'] ?: $GLOBALS['BR']), 'receipt_footer', 'Thank you for shopping! Exchange within 7 days with original receipt.'),
    'note' => $s['note'] ?? '',
    'items' => array_map(fn($r) => fl($r, ['qty', 'price', 'discount', 'total', 'cost', 'profit']), $it),
    'pay' => array_map(fn($r) => [
      'method' => $r['method'],
      'amount' => (float)$r['amount'],
      'reference' => $r['reference'] ?? ''
    ], $py)
  ];
}

$rawInput = file_get_contents('php://input');
$in = (!empty($rawInput) ? json_decode($rawInput, true) : null) ?: ($_POST ?: []);
$a = $_GET['a'] ?? '';

try {
  if ($a === 'login') {
    $email = trim($in['email'] ?? '');
    $u = one("SELECT u.*,r.name role,b.name branch_name FROM users u JOIN roles r ON r.id=u.role_id LEFT JOIN branches b ON b.id=u.branch_id WHERE u.email=? AND u.is_active=1", [$email]);
    $ok = $u && password_verify($in['password'] ?? '', $u['password']);
    run("INSERT INTO login_history(user_id,email,ip_address,user_agent,success) VALUES(?,?,?,?,?)",
      [$u['id'] ?? null, $email, $_SERVER['REMOTE_ADDR'] ?? '', substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255), $ok ? 1 : 0]);
    if (!$ok) out(['error' => 'Invalid email or password'], 401);
    
    session_regenerate_id(true);
    $branchId = (int)($u['branch_id'] ?: BRANCH_ID);
    $branchName = $u['branch_name'] ?: 'Main Branch';
    
    $_SESSION['u'] = [
      'id' => (int)$u['id'],
      'name' => $u['name'],
      'role' => $u['role'],
      'branch_id' => $branchId,
      'branch_name' => $branchName,
      'active_branch_id' => $branchId,
      'active_branch_name' => $branchName
    ];
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
    run("UPDATE users SET last_login_at=NOW() WHERE id=?", [$u['id']]);
    out(['user' => $_SESSION['u'], 'csrf' => $_SESSION['csrf']]);
  }

  if (!isset($_SESSION['u'])) out(['error' => 'Not signed in'], 401);
  if ($a === 'me') out(['user' => $_SESSION['u'], 'csrf' => $_SESSION['csrf']]);
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && !hash_equals($_SESSION['csrf'] ?? '', $_SERVER['HTTP_X_CSRF'] ?? '')) {
    out(['error' => 'Invalid CSRF token'], 403);
  }
  if ($a === 'logout') { session_destroy(); out(['ok' => 1]); }

  $role = $_SESSION['u']['role'];
  $isSuperAdmin = ($role === 'Super Admin');
  $isAdmin = in_array($role, ['Super Admin', 'Shop Owner', 'Manager', 'Admin']);

  // Role permissions whitelist
  $perm = [
    'Cashier' => ['dash', 'products', 'product_branch_stock', 'sales', 'sale_get', 'sale_reprint', 'checkout', 'refund', 'branches', 'customers', 'customer_save', 'customer_by_phone', 'customer_due_pay', 'shift_status', 'shift_open', 'shift_close', 'shift_cash_action', 'expenses', 'expense_categories', 'expense_save', 'reports', 'audit_logs', 'password_change', 'customer_history', 'settings_get', 'coupons', 'coupon_check', 'suppliers', 'purchases', 'reward_points_data', 'reward_points_convert'],
    'Inventory Manager' => ['dash', 'products', 'product_branch_stock', 'product_save', 'product_delete', 'stock_adjust', 'stock_return', 'branches', 'reports', 'settings_get', 'suppliers', 'supplier_save', 'purchases', 'purchase_save', 'coupons', 'reward_points_data', 'reward_points_convert'],
    'Accountant' => ['dash', 'sales', 'sale_get', 'sale_reprint', 'product_branch_stock', 'branches', 'expenses', 'expense_categories', 'reports', 'settings_get', 'suppliers', 'purchases', 'customer_due_pay', 'coupons', 'reward_points_data', 'reward_points_convert']
  ];
  if (!$isAdmin && !in_array($a, $perm[$role] ?? [])) {
    out(['error' => 'You do not have permission for this action'], 403);
  }

  switch ($a) {
  case 'dash':
    $period = $_GET['period'] ?? '7days'; // '7days'|'week', '30days'|'month', '6months', 'this_year', 'last_year', 'custom'
    $branchParam = $_GET['branch_id'] ?? '';
    
    // Branch filter: only Super Admin can view all branches or select other branch; Shop Owner & Cashier are strictly locked to $BR
    $targetBranch = ($branchParam === 'all' && $isSuperAdmin) ? 0 : ($isSuperAdmin && $branchParam !== '' ? (int)$branchParam : $BR);
    $bWhere = $targetBranch > 0 ? " AND branch_id = $targetBranch" : "";
    $sBWhere = $targetBranch > 0 ? " AND s.branch_id = $targetBranch" : "";

    $now = time();
    $until = date('Y-m-d 23:59:59');
    $seriesMap = [];

    if ($period === '30days' || $period === 'month') {
      $period = '30days';
      for ($i = 29; $i >= 0; $i--) {
        $t = strtotime("-{$i} days", $now);
        $k = date('Y-m-d', $t);
        $seriesMap[$k] = ['d' => $k, 'label' => date('M j', $t), 'v' => 0.0, 'c' => 0];
      }
      $since = date('Y-m-d 00:00:00', strtotime('-29 days', $now));
      $qRows = rows("SELECT DATE(sold_at) d, SUM(grand_total) v, COUNT(*) c FROM sales WHERE status='completed' $bWhere AND sold_at>=? AND sold_at<=? GROUP BY DATE(sold_at)", [$since, $until]);
    } elseif ($period === '6months') {
      for ($i = 5; $i >= 0; $i--) {
        $t = strtotime("-{$i} months", $now);
        $k = date('Y-m', $t);
        $seriesMap[$k] = ['d' => $k, 'label' => date('M Y', $t), 'v' => 0.0, 'c' => 0];
      }
      $since = date('Y-m-01 00:00:00', strtotime('-5 months', $now));
      $qRows = rows("SELECT DATE_FORMAT(sold_at, '%Y-%m') d, SUM(grand_total) v, COUNT(*) c FROM sales WHERE status='completed' $bWhere AND sold_at>=? AND sold_at<=? GROUP BY DATE_FORMAT(sold_at, '%Y-%m')", [$since, $until]);
    } elseif ($period === 'this_year') {
      $currentYear = (int)date('Y', $now);
      $currentMonth = (int)date('n', $now);
      for ($m = 1; $m <= $currentMonth; $m++) {
        $k = sprintf('%04d-%02d', $currentYear, $m);
        $t = strtotime("$k-01");
        $seriesMap[$k] = ['d' => $k, 'label' => date('M Y', $t), 'v' => 0.0, 'c' => 0];
      }
      $since = date('Y-01-01 00:00:00', $now);
      $qRows = rows("SELECT DATE_FORMAT(sold_at, '%Y-%m') d, SUM(grand_total) v, COUNT(*) c FROM sales WHERE status='completed' $bWhere AND sold_at>=? AND sold_at<=? GROUP BY DATE_FORMAT(sold_at, '%Y-%m')", [$since, $until]);
    } elseif ($period === 'last_year') {
      $lastYear = (int)date('Y', $now) - 1;
      for ($m = 1; $m <= 12; $m++) {
        $k = sprintf('%04d-%02d', $lastYear, $m);
        $t = strtotime("$k-01");
        $seriesMap[$k] = ['d' => $k, 'label' => date('M Y', $t), 'v' => 0.0, 'c' => 0];
      }
      $since = sprintf('%04d-01-01 00:00:00', $lastYear);
      $until = sprintf('%04d-12-31 23:59:59', $lastYear);
      $qRows = rows("SELECT DATE_FORMAT(sold_at, '%Y-%m') d, SUM(grand_total) v, COUNT(*) c FROM sales WHERE status='completed' $bWhere AND sold_at>=? AND sold_at<=? GROUP BY DATE_FORMAT(sold_at, '%Y-%m')", [$since, $until]);
    } elseif ($period === 'custom') {
      $fromStr = trim($_GET['from'] ?? '');
      $toStr = trim($_GET['to'] ?? '');
      $fromTs = $fromStr ? strtotime($fromStr) : strtotime('-30 days', $now);
      $toTs = $toStr ? strtotime($toStr) : $now;
      if ($fromTs > $toTs) { $tmp = $fromTs; $fromTs = $toTs; $toTs = $tmp; }
      
      // Maximum 2 years restriction (730 days)
      $maxSpan = 730 * 86400;
      if (($toTs - $fromTs) > $maxSpan) {
        $fromTs = $toTs - $maxSpan;
      }
      
      $since = date('Y-m-d 00:00:00', $fromTs);
      $until = date('Y-m-d 23:59:59', $toTs);
      $diffDays = (int)ceil(($toTs - $fromTs) / 86400);

      if ($diffDays <= 45) {
        for ($t = $fromTs; $t <= $toTs; $t += 86400) {
          $k = date('Y-m-d', $t);
          $seriesMap[$k] = ['d' => $k, 'label' => date('M j', $t), 'v' => 0.0, 'c' => 0];
        }
        $qRows = rows("SELECT DATE(sold_at) d, SUM(grand_total) v, COUNT(*) c FROM sales WHERE status='completed' $bWhere AND sold_at>=? AND sold_at<=? GROUP BY DATE(sold_at)", [$since, $until]);
      } else {
        $cur = strtotime(date('Y-m-01', $fromTs));
        $end = strtotime(date('Y-m-01', $toTs));
        while ($cur <= $end) {
          $k = date('Y-m', $cur);
          $seriesMap[$k] = ['d' => $k, 'label' => date('M Y', $cur), 'v' => 0.0, 'c' => 0];
          $cur = strtotime('+1 month', $cur);
        }
        $qRows = rows("SELECT DATE_FORMAT(sold_at, '%Y-%m') d, SUM(grand_total) v, COUNT(*) c FROM sales WHERE status='completed' $bWhere AND sold_at>=? AND sold_at<=? GROUP BY DATE_FORMAT(sold_at, '%Y-%m')", [$since, $until]);
      }
    } else {
      // Default: 7 days
      $period = '7days';
      for ($i = 6; $i >= 0; $i--) {
        $t = strtotime("-{$i} days", $now);
        $k = date('Y-m-d', $t);
        $seriesMap[$k] = ['d' => $k, 'label' => date('D, M j', $t), 'v' => 0.0, 'c' => 0];
      }
      $since = date('Y-m-d 00:00:00', strtotime('-6 days', $now));
      $qRows = rows("SELECT DATE(sold_at) d, SUM(grand_total) v, COUNT(*) c FROM sales WHERE status='completed' $bWhere AND sold_at>=? AND sold_at<=? GROUP BY DATE(sold_at)", [$since, $until]);
    }

    foreach ($qRows as $r) {
      if (isset($seriesMap[$r['d']])) {
        $seriesMap[$r['d']]['v'] = (float)$r['v'];
        $seriesMap[$r['d']]['c'] = (int)$r['c'];
      }
    }
    $series = array_values($seriesMap);

    $monthStart = date('Y-m-01 00:00:00');
    $t = one("SELECT COALESCE(SUM(grand_total),0) s,COUNT(*) n FROM sales WHERE status='completed' $bWhere AND DATE(sold_at)=CURDATE()");
    $prToday = one("SELECT 
      COALESCE(SUM(si.line_total - si.unit_cost*si.quantity), 0) p,
      COALESCE(SUM(si.unit_cost*si.quantity), 0) c
      FROM sale_items si JOIN sales s ON s.id=si.sale_id WHERE s.status='completed' $sBWhere AND DATE(s.sold_at)=CURDATE()");

    $st = one("SELECT 
      COUNT(*) total,
      COALESCE(SUM(COALESCE(i.quantity,0)>0 AND COALESCE(i.quantity,0)<=p.min_stock),0) low,
      COALESCE(SUM(COALESCE(i.quantity,0)<=0),0) outs,
      COALESCE(SUM(COALESCE(i.quantity,0)),0) total_units,
      COALESCE(SUM(COALESCE(i.quantity,0)*p.purchase_price),0) val_cost,
      COALESCE(SUM(COALESCE(i.quantity,0)*p.selling_price),0) val_retail
      FROM products p LEFT JOIN inventory i ON " . INV . " WHERE p.deleted_at IS NULL", [$targetBranch ?: $BR]);

    $mo = one("SELECT COALESCE(SUM(grand_total),0) s FROM sales WHERE status='completed' $bWhere AND sold_at>=?", [$monthStart]);

    $prPeriod = one("SELECT 
      COALESCE(SUM(si.line_total - si.unit_cost*si.quantity), 0) p,
      COALESCE(SUM(si.unit_cost*si.quantity), 0) c
      FROM sale_items si JOIN sales s ON s.id=si.sale_id WHERE s.status='completed' $sBWhere AND s.sold_at>=? AND s.sold_at<=?", [$since, $until]);

    // Period Purchases (ক্রয়)
    $purPeriod = one("SELECT 
      COALESCE(SUM(grand_total), 0) total, 
      COUNT(*) count 
      FROM purchases 
      WHERE status!='cancelled' $bWhere 
      AND ((order_date>=? AND order_date<=?) OR (created_at>=? AND created_at<=?))", 
      [substr($since, 0, 10), substr($until, 0, 10), $since, $until]);
    $periodPurchases = (float)$purPeriod['total'];
    $periodPurchasesCount = (int)$purPeriod['count'];

    // Period Expenses (খরচ)
    $expPeriod = (float)one("SELECT COALESCE(SUM(amount),0) total FROM expenses WHERE 1=1 $bWhere AND expense_date>=? AND expense_date<=?", 
      [substr($since, 0, 10), substr($until, 0, 10)])['total'];
    $todayExp = (float)one("SELECT COALESCE(SUM(amount),0) total FROM expenses WHERE 1=1 $bWhere AND expense_date=CURDATE()")['total'];

    // Account Payable (দেনা / সাপ্লায়ার বাকি): Unpaid purchases + active suppliers opening balance
    $purPayable = (float)one("SELECT COALESCE(SUM(grand_total - paid_amount), 0) val FROM purchases WHERE status!='cancelled' $bWhere")['val'];
    $supPayable = (float)one("SELECT COALESCE(SUM(opening_balance), 0) val FROM suppliers WHERE is_active=1")['val'];
    $accountPayable = max(0, $purPayable + $supPayable);

    // Account Receivable (পাওনা / কাস্টমার বাকি): Customer due balances
    $accountReceivable = (float)one("SELECT COALESCE(SUM(due_balance), 0) val FROM customers WHERE is_active=1")['val'];

    $pTotal = array_sum(array_column($series, 'v'));
    $pCount = array_sum(array_column($series, 'c'));
    $pAvg = count($series) > 0 ? round($pTotal / count($series), 2) : 0;
    $pMax = count($series) > 0 ? max(array_column($series, 'v')) : 0;

    $stockCost = (float)$st['val_cost'];
    $stockRetail = (float)$st['val_retail'];
    $stockProfit = $stockRetail - $stockCost;
    $stockUnits = (float)$st['total_units'];

    $periodGrossProfit = (float)$prPeriod['p'];
    $periodNetProfit = $periodGrossProfit - $expPeriod;
    $periodMargin = $pTotal > 0 ? round(($periodGrossProfit / $pTotal) * 100, 1) : 0.0;

    $todayGrossProfit = (float)$prToday['p'];
    $todayNetProfit = $todayGrossProfit - $todayExp;
    $todayMargin = ((float)$t['s']) > 0 ? round(($todayGrossProfit / (float)$t['s']) * 100, 1) : 0.0;

    // Average Order Value (AOV / Basket Size)
    $aov = $pCount > 0 ? round($pTotal / $pCount, 2) : 0;
    $todayAov = ((int)$t['n']) > 0 ? round(((float)$t['s']) / (int)$t['n'], 2) : 0;

    // Yesterday comparison
    $yDate = date('Y-m-d', strtotime('-1 day'));
    $y = one("SELECT COALESCE(SUM(grand_total),0) s, COUNT(*) n FROM sales WHERE status='completed' $bWhere AND DATE(sold_at)=?", [$yDate]);
    $yesterdaySales = (float)$y['s'];
    $todayGrowth = $yesterdaySales > 0 ? round(((((float)$t['s']) - $yesterdaySales) / $yesterdaySales) * 100, 1) : null;

    // Payment methods breakdown & Cash vs Digital split
    $payMethods = array_map(fn($r) => ['m' => $r['method'], 'v' => (float)$r['v']],
      rows("SELECT p.method,SUM(p.amount) v FROM payments p JOIN sales s ON s.id=p.sale_id WHERE p.direction='in' AND s.status='completed' $sBWhere GROUP BY p.method"));
    $cashPay = 0; $digitalPay = 0;
    foreach ($payMethods as $pm) {
      if ($pm['m'] === 'cash') $cashPay += $pm['v'];
      else $digitalPay += $pm['v'];
    }

    $couponsActive = (int)(one("SELECT COUNT(*) n FROM coupons WHERE is_active=1")['n'] ?? 0);
    $couponsTotal = (int)(one("SELECT COUNT(*) n FROM coupons")['n'] ?? 0);

    out([
      'period' => $period,
      'since' => $since,
      'until' => $until,
      'target_branch' => $targetBranch,

      // 8 Primary Dashboard KPIs
      'sales' => $pTotal,
      'period_sales' => $pTotal,
      'period_total' => $pTotal,
      'period_count' => $pCount,

      'purchases' => $periodPurchases,
      'period_purchases' => $periodPurchases,
      'period_purchases_count' => $periodPurchasesCount,

      'expenses' => $expPeriod,
      'period_expenses' => $expPeriod,

      'profit' => $periodNetProfit,
      'period_net_profit' => $periodNetProfit,
      'period_gross_profit' => $periodGrossProfit,
      'period_margin' => $periodMargin,

      'account_payable' => $accountPayable,
      'account_receivable' => $accountReceivable,

      'stock_cost' => $stockCost,
      'stock_retail' => $stockRetail,
      'stock_potential_profit' => $stockProfit,
      'stock_units' => $stockUnits,
      'products' => (int)$st['total'],
      'low' => (int)$st['low'],
      'out' => (int)$st['outs'],
      'value' => $stockCost,

      'coupons_active' => $couponsActive,
      'coupons_total' => $couponsTotal,

      'period_avg' => $pAvg,
      'period_highest' => $pMax,
      'period_cost' => (float)$prPeriod['c'],
      'aov' => $aov,

      'today' => (float)$t['s'],
      'count' => (int)$t['n'],
      'today_cost' => (float)$prToday['c'],
      'today_profit' => $todayGrossProfit,
      'today_net_profit' => $todayNetProfit,
      'today_expenses' => $todayExp,
      'today_margin' => $todayMargin,
      'today_aov' => $todayAov,
      'yesterday_sales' => $yesterdaySales,
      'today_growth' => $todayGrowth,

      'month' => (float)$mo['s'],
      'customers_total' => (int)one("SELECT COUNT(*) n FROM customers WHERE is_active=1")['n'],
      'refunds_period' => (float)(one("SELECT COALESCE(SUM(grand_total),0) v FROM sales WHERE status='refunded' $bWhere AND sold_at>=? AND sold_at<=?", [$since, $until])['v'] ?? 0),
      'series' => $series,
      'top' => array_map(fn($r) => ['n' => $r['n'], 'q' => (float)$r['q'], 'rev' => (float)$r['rev']],
        rows("SELECT si.name_snapshot n,SUM(si.quantity) q,SUM(si.line_total) rev FROM sale_items si JOIN sales s ON s.id=si.sale_id WHERE s.status='completed' $sBWhere AND s.sold_at>=? AND s.sold_at<=? GROUP BY si.name_snapshot ORDER BY q DESC LIMIT 6", [$since, $until])),
      'pay' => $payMethods,
      'cash_total' => $cashPay,
      'digital_total' => $digitalPay,
      'recent' => array_map(fn($r) => fl($r, ['grand']),
        rows("SELECT s.id,s.invoice_no inv,s.sold_at date,s.grand_total grand,COALESCE(b.name, 'Main') store,COALESCE(u.name, 'Cashier') cashier FROM sales s LEFT JOIN branches b ON b.id=s.branch_id LEFT JOIN users u ON u.id=s.cashier_id WHERE s.status='completed' $sBWhere ORDER BY s.id DESC LIMIT 8"))
    ]);

  /* ==================== CASHIER SHIFT & REGISTER ==================== */
  case 'shift_status':
    $s = one("SELECT s.*, r.name register_name FROM shifts s JOIN cash_registers r ON r.id=s.register_id WHERE s.user_id=? AND s.status='open' ORDER BY s.id DESC LIMIT 1", [uid()]);
    if (!$s) {
      out(['has_shift' => false]);
    }
    $sid = (int)$s['id'];
    $salesSummary = rows("SELECT p.method, SUM(p.amount) total FROM payments p JOIN sales sa ON sa.id=p.sale_id WHERE sa.shift_id=? AND sa.status='completed' AND p.direction='in' GROUP BY p.method", [$sid]);
    $orderCount = (int)one("SELECT COUNT(*) n FROM sales WHERE shift_id=? AND status='completed'", [$sid])['n'];
    $payMap = ['cash' => 0.0, 'card' => 0.0, 'bkash' => 0.0, 'nagad' => 0.0, 'rocket' => 0.0];
    foreach ($salesSummary as $row) {
      $payMap[$row['method']] = (float)$row['total'];
    }
    $cashExpenses = (float)one("SELECT COALESCE(SUM(amount),0) as total FROM expenses WHERE branch_id=? AND created_by=? AND expense_date=CURDATE() AND method='cash'", [$BR, uid()])['total'];
    $expectedCash = (float)$s['opening_cash'] + $payMap['cash'] - $cashExpenses;
    out([
      'has_shift' => true,
      'shift' => [
        'id' => $sid,
        'register' => $s['register_name'],
        'opened_at' => $s['opened_at'],
        'opening_cash' => (float)$s['opening_cash'],
        'note' => $s['note'] ?? ''
      ],
      'summary' => [
        'order_count' => $orderCount,
        'cash_sales' => $payMap['cash'],
        'card_sales' => $payMap['card'],
        'bkash_sales' => $payMap['bkash'],
        'nagad_sales' => $payMap['nagad'],
        'rocket_sales' => $payMap['rocket'],
        'mobile_sales' => $payMap['bkash'] + $payMap['nagad'] + $payMap['rocket'],
        'cash_expenses' => $cashExpenses,
        'expected_cash' => round($expectedCash, 2),
        'total_collected' => round(array_sum($payMap), 2)
      ]
    ]);

  case 'shift_open':
    $existing = one("SELECT id FROM shifts WHERE user_id=? AND status='open'", [uid()]);
    if ($existing) fail('You already have an open shift');
    $openCash = max(0, (float)($in['opening_cash'] ?? 0));
    $note = trim($in['note'] ?? '') ?: 'Standard Opening Float';
    $reg = one("SELECT id FROM cash_registers WHERE branch_id=? AND is_active=1 LIMIT 1", [$BR]);
    if (!$reg) {
      $regId = run("INSERT INTO cash_registers(branch_id, name, is_active) VALUES(?, 'Counter 1', 1)", [$BR]);
    } else {
      $regId = $reg['id'];
    }
    $sid = run("INSERT INTO shifts(register_id, user_id, opened_at, opening_cash, status, note) VALUES(?,?,NOW(),?,'open',?)",
      [$regId, uid(), $openCash, $note]);
    audit('shift.open', 'shift', (int)$sid, ['opening_cash' => $openCash]);
    out(['ok' => 1, 'shift_id' => (int)$sid]);

  case 'shift_close':
    $s = one("SELECT * FROM shifts WHERE user_id=? AND status='open' ORDER BY id DESC LIMIT 1", [uid()]);
    if (!$s) fail('No active shift found to close');
    $sid = (int)$s['id'];
    $counted = max(0, (float)($in['counted_cash'] ?? 0));
    $note = trim($in['note'] ?? '');
    
    $cashSales = (float)one("SELECT COALESCE(SUM(p.amount),0) as total FROM payments p JOIN sales sa ON sa.id=p.sale_id WHERE sa.shift_id=? AND sa.status='completed' AND p.direction='in' AND p.method='cash'", [$sid])['total'];
    $cashExpenses = (float)one("SELECT COALESCE(SUM(amount),0) as total FROM expenses WHERE branch_id=? AND created_by=? AND expense_date=CURDATE() AND method='cash'", [$BR, uid()])['total'];
    $expected = (float)$s['opening_cash'] + $cashSales - $cashExpenses;
    $diff = round($counted - $expected, 2);

    run("UPDATE shifts SET closed_at=NOW(), expected_cash=?, counted_cash=?, difference=?, status='closed', note=? WHERE id=?",
      [$expected, $counted, $diff, $note, $sid]);
    audit('shift.close', 'shift', $sid, ['counted' => $counted, 'expected' => $expected, 'diff' => $diff]);
    
    out([
      'ok' => 1,
      'z_report' => [
        'shift_id' => $sid,
        'opened_at' => $s['opened_at'],
        'closed_at' => date('Y-m-d H:i:s'),
        'opening_cash' => (float)$s['opening_cash'],
        'cash_sales' => $cashSales,
        'cash_expenses' => $cashExpenses,
        'expected_cash' => $expected,
        'counted_cash' => $counted,
        'difference' => $diff,
        'note' => $note
      ]
    ]);

  case 'shift_cash_action':
    $type = ($in['type'] ?? '') === 'out' ? 'out' : 'in';
    $amount = max(0, (float)($in['amount'] ?? 0));
    $note = trim($in['note'] ?? '');
    if ($amount <= 0) fail('Enter a valid amount');
    
    if ($type === 'out') {
      $cat = one("SELECT id FROM expense_categories WHERE name='Store Supplies' OR name='Other Expenses' LIMIT 1");
      $catId = $cat ? $cat['id'] : 1;
      run("INSERT INTO expenses(branch_id, category_id, amount, expense_date, method, note, created_by, created_at) VALUES(?,?,?,CURDATE(),'cash',?,?,NOW())",
        [$BR, $catId, $amount, 'Cash Drawer Payout: ' . ($note ?: 'Cash out'), uid()]);
    }
    audit('shift.cash_' . $type, 'shift', null, ['amount' => $amount, 'note' => $note]);
    out(['ok' => 1]);

  /* ==================== CUSTOMER LOYALTY ==================== */
  case 'customers':
    out(rows("SELECT c.*,
      (SELECT COUNT(*) FROM sales WHERE customer_id=c.id AND status='completed') as order_count,
      (SELECT COALESCE(SUM(grand_total),0) FROM sales WHERE customer_id=c.id AND status='completed') as total_spent
      FROM customers c WHERE c.is_active=1 ORDER BY c.name ASC"));

  case 'customer_save':
    $n = trim($in['name'] ?? '');
    $phone = trim($in['phone'] ?? '');
    if ($n === '' || $phone === '') fail('Customer Name and Phone number are required');
    $email = trim($in['email'] ?? '') ?: null;
    $addr = trim($in['address'] ?? '') ?: null;
    $limit = max(0, (float)($in['credit_limit'] ?? 0));
    $id = !empty($in['id']) ? (int)$in['id'] : 0;

    $ex = one("SELECT id FROM customers WHERE phone=? AND id!=?", [$phone, $id]);
    if ($ex) fail('A customer with this phone number already exists');

    $db->beginTransaction();
    if ($id > 0) {
      run("UPDATE customers SET name=?, phone=?, email=?, address=?, credit_limit=?, updated_at=NOW() WHERE id=?",
        [$n, $phone, $email, $addr, $limit, $id]);
    } else {
      $id = run("INSERT INTO customers(name, phone, email, address, credit_limit, loyalty_points, is_active, created_at, updated_at) VALUES(?,?,?,?,?,0,1,NOW(),NOW())",
        [$n, $phone, $email, $addr, $limit]);
    }
    audit(empty($in['id']) ? 'customer.create' : 'customer.update', 'customer', (int)$id, ['name' => $n, 'phone' => $phone]);
    $db->commit();
    out(['ok' => 1, 'id' => (int)$id]);

  case 'customer_by_phone':
    $phone = trim($_GET['phone'] ?? '');
    if ($phone === '') out(null);
    $clean = preg_replace('/[^\d]/', '', $phone);
    $c = one("SELECT c.*,
      (SELECT COUNT(*) FROM sales WHERE customer_id=c.id AND status='completed') as order_count,
      (SELECT COALESCE(SUM(grand_total),0) FROM sales WHERE customer_id=c.id AND status='completed') as total_spent
      FROM customers c WHERE c.phone=? AND c.is_active=1", [$phone]);
    if (!$c && $clean !== '' && strlen($clean) >= 3) {
      $c = one("SELECT c.*,
        (SELECT COUNT(*) FROM sales WHERE customer_id=c.id AND status='completed') as order_count,
        (SELECT COALESCE(SUM(grand_total),0) FROM sales WHERE customer_id=c.id AND status='completed') as total_spent
        FROM customers c WHERE c.phone LIKE ? AND c.is_active=1 LIMIT 1", ['%' . $clean . '%']);
    }
    out($c ?: null);

  case 'customer_points_adjust':
    if (!$isAdmin) fail('Only Admin or Manager can adjust points');
    $cid = (int)($in['id'] ?? 0);
    $pts = (int)($in['points'] ?? 0);
    $note = trim($in['note'] ?? '') ?: 'Manual Adjustment';
    $cust = one("SELECT id, name, loyalty_points FROM customers WHERE id=?", [$cid]);
    if (!$cust) fail('Customer not found');
    $newPts = max(0, (int)$cust['loyalty_points'] + $pts);
    run("UPDATE customers SET loyalty_points=?, updated_at=NOW() WHERE id=?", [$newPts, $cid]);
    audit('customer.points_adjust', 'customer', $cid, ['change' => $pts, 'new_total' => $newPts, 'note' => $note]);
    out(['ok' => 1, 'loyalty_points' => $newPts]);

  /* ==================== EXPENSES & PETTY CASH ==================== */
  case 'expense_categories':
    out(rows("SELECT id, name FROM expense_categories ORDER BY name ASC"));

  case 'expenses':
    out(rows("SELECT e.*, c.name as category_name, u.name as user_name
      FROM expenses e JOIN expense_categories c ON c.id=e.category_id LEFT JOIN users u ON u.id=e.created_by
      WHERE e.branch_id=? ORDER BY e.id DESC LIMIT 100", [$BR]));

  case 'expense_save':
    $catId = (int)($in['category_id'] ?? 0);
    $amt = max(0, (float)($in['amount'] ?? 0));
    if ($catId <= 0 || $amt <= 0) fail('Please select a category and enter a valid amount');
    $method = in_array($in['method'] ?? '', ['cash', 'bank', 'bkash', 'nagad', 'other']) ? $in['method'] : 'cash';
    $note = trim($in['note'] ?? '') ?: null;
    
    $eid = run("INSERT INTO expenses(branch_id, category_id, amount, expense_date, method, note, created_by, created_at) VALUES(?,?,?,CURDATE(),?,?,?,NOW())",
      [$BR, $catId, $amt, $method, $note, uid()]);
    audit('expense.create', 'expense', (int)$eid, ['amount' => $amt, 'method' => $method]);
    out(['ok' => 1, 'id' => (int)$eid]);

  /* ==================== STORES & BRANCHES ==================== */
  case 'branches':
    if (!$isSuperAdmin) {
      out(rows("SELECT b.id, b.code, b.name, b.address, b.phone, b.email, b.bin_number, b.is_active,
        (SELECT COUNT(*) FROM users WHERE branch_id=b.id) as user_count,
        (SELECT COUNT(*) FROM sales WHERE branch_id=b.id) as sale_count
        FROM branches b WHERE b.id=? AND b.is_active=1", [$BR]));
    }
    out(rows("SELECT b.id, b.code, b.name, b.address, b.phone, b.email, b.bin_number, b.is_active,
      (SELECT COUNT(*) FROM users WHERE branch_id=b.id) as user_count,
      (SELECT COUNT(*) FROM sales WHERE branch_id=b.id) as sale_count
      FROM branches b WHERE b.is_active=1 ORDER BY b.id ASC"));

  case 'branch_save':
    if (!$isSuperAdmin) fail('Only Super Admin can add or edit stores');
    $n = trim($in['name'] ?? '');
    $code = strtoupper(trim($in['code'] ?? ''));
    if ($n === '' || $code === '') fail('Store Name and Code are required');
    $addr = trim($in['address'] ?? '') ?: null;
    $phone = trim($in['phone'] ?? '') ?: null;
    $bin = trim($in['bin_number'] ?? '') ?: null;

    $db->beginTransaction();
    if (!empty($in['id'])) {
      $id = (int)$in['id'];
      $ex = one("SELECT id FROM branches WHERE code=? AND id!=?", [$code, $id]);
      if ($ex) fail("Store code '$code' already exists");
      run("UPDATE branches SET name=?, code=?, address=?, phone=?, bin_number=?, updated_at=NOW() WHERE id=?",
        [$n, $code, $addr, $phone, $bin, $id]);
    } else {
      $ex = one("SELECT id FROM branches WHERE code=?", [$code]);
      if ($ex) fail("Store code '$code' already exists");
      $id = run("INSERT INTO branches(name, code, address, phone, bin_number, is_active, created_at, updated_at) VALUES(?,?,?,?,?,1,NOW(),NOW())",
        [$n, $code, $addr, $phone, $bin]);
      
      // Auto-initialize inventory for existing products in the new store
      $prods = rows("SELECT id, purchase_price FROM products WHERE deleted_at IS NULL");
      $invStmt = $db->prepare("INSERT INTO inventory(branch_id, product_id, quantity, unit_cost) VALUES(?,?,0,?)");
      foreach ($prods as $pr) {
        $invStmt->execute([$id, $pr['id'], $pr['purchase_price']]);
      }
    }
    audit(empty($in['id']) ? 'branch.create' : 'branch.update', 'branch', (int)$id, ['name' => $n, 'code' => $code]);
    $db->commit();
    out(['ok' => 1, 'id' => (int)$id]);

  case 'branch_switch':
    if (!$isSuperAdmin) fail('Only Super Admin can switch store context. Shop Owner and Cashier are strictly locked to their assigned store.');
    $newBid = (int)($in['branch_id'] ?? 0);
    if ($newBid === 0) {
      $_SESSION['u']['active_branch_id'] = 0;
      $_SESSION['u']['active_branch_name'] = 'All Stores';
    } else {
      $b = one("SELECT id, name FROM branches WHERE id=?", [$newBid]);
      if (!$b) fail('Store not found');
      $_SESSION['u']['active_branch_id'] = (int)$b['id'];
      $_SESSION['u']['active_branch_name'] = $b['name'];
    }
    out(['ok' => 1, 'user' => $_SESSION['u']]);

  case 'branch_delete':
    if (!$isSuperAdmin) fail('Only Super Admin can delete stores');
    $id = (int)($in['id'] ?? 0);
    if ($id <= 0) fail('Invalid store ID');
    
    $totalBranches = (int)one("SELECT COUNT(*) n FROM branches WHERE is_active=1")['n'];
    if ($totalBranches <= 1) fail('Cannot delete the only remaining store outlet');
    
    $b = one("SELECT * FROM branches WHERE id=?", [$id]);
    if (!$b) fail('Store not found');
    
    // Check if there are active register shifts
    $activeShift = one("SELECT s.id FROM shifts s JOIN cash_registers r ON r.id=s.register_id WHERE r.branch_id=? AND s.status='open'", [$id]);
    if ($activeShift) fail('Cannot delete store while a cash register shift is open. Please close shifts first.');
    
    $db->beginTransaction();
    $otherBranch = one("SELECT id, name FROM branches WHERE id!=? AND is_active=1 ORDER BY id ASC LIMIT 1", [$id]);
    $fallbackId = $otherBranch ? (int)$otherBranch['id'] : 1;
    
    // Reassign staff to fallback branch
    run("UPDATE users SET branch_id=? WHERE branch_id=?", [$fallbackId, $id]);
    
    // Check if branch has sales history
    $salesCount = (int)one("SELECT COUNT(*) n FROM sales WHERE branch_id=?", [$id])['n'];
    if ($salesCount > 0) {
      // Archive so past invoices and tax records remain accessible
      run("UPDATE branches SET is_active=0, code=code || '_ARCH_' || id, updated_at=NOW() WHERE id=?", [$id]);
      $msg = "Store '{$b['name']}' has past sales history. It has been safely archived and deactivated.";
    } else {
      run("DELETE FROM inventory WHERE branch_id=?", [$id]);
      run("DELETE FROM cash_registers WHERE branch_id=?", [$id]);
      run("DELETE FROM settings WHERE branch_id=?", [$id]);
      run("DELETE FROM branches WHERE id=?", [$id]);
      $msg = "Store '{$b['name']}' was completely deleted.";
    }
    
    audit('branch.delete', 'branch', $id, ['name' => $b['name']]);
    
    if (($_SESSION['u']['active_branch_id'] ?? null) == $id) {
      $_SESSION['u']['active_branch_id'] = $fallbackId;
      $_SESSION['u']['active_branch_name'] = $otherBranch['name'] ?? 'Main Store';
    }
    
    $db->commit();
    out(['ok' => 1, 'message' => $msg, 'active_branch_id' => $_SESSION['u']['active_branch_id']]);

  case 'roles':
    out(rows("SELECT id, name FROM roles ORDER BY id ASC"));

  case 'users':
    if (!$isAdmin) fail('Access denied');
    $userBFilter = !$isSuperAdmin ? " WHERE u.branch_id = $BR" : "";
    out(rows("SELECT u.id, u.name, u.email, u.phone, u.is_active, u.branch_id, u.role_id, r.name as role, b.name as branch_name, u.last_login_at
      FROM users u JOIN roles r ON r.id=u.role_id LEFT JOIN branches b ON b.id=u.branch_id $userBFilter ORDER BY u.id ASC"));

  case 'user_save':
    if (!$isAdmin) fail('Only Admin and Manager can add or manage cashiers');
    $n = trim($in['name'] ?? '');
    $email = strtolower(trim($in['email'] ?? ''));
    $pass = trim($in['password'] ?? '');
    $roleId = (int)($in['role_id'] ?? 4); // Default: Cashier (role 4)
    $branchId = $isSuperAdmin ? (int)($in['branch_id'] ?? $BR) : $BR;
    $phone = trim($in['phone'] ?? '') ?: null;
    $id = !empty($in['id']) ? (int)$in['id'] : 0;

    if ($n === '' || $email === '') fail('Name and Email are required');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Invalid email format');
    if ($id === 0 && $pass === '') fail('Password is required for new cashier/user');

    if ($roleId === 1 && !$isSuperAdmin) {
      fail('Only Super Admin can assign the Super Admin role');
    }

    $ex = one("SELECT id FROM users WHERE email=? AND id!=?", [$email, $id]);
    if ($ex) fail('A user with this email already exists');

    $db->beginTransaction();
    if ($id > 0) {
      if ($pass !== '') {
        $hash = password_hash($pass, PASSWORD_DEFAULT);
        run("UPDATE users SET name=?, email=?, phone=?, role_id=?, branch_id=?, password=?, updated_at=NOW() WHERE id=?",
          [$n, $email, $phone, $roleId, $branchId, $hash, $id]);
      } else {
        run("UPDATE users SET name=?, email=?, phone=?, role_id=?, branch_id=?, updated_at=NOW() WHERE id=?",
          [$n, $email, $phone, $roleId, $branchId, $id]);
      }
      audit('user.update', 'user', $id, ['name' => $n, 'email' => $email]);
    } else {
      $hash = password_hash($pass, PASSWORD_DEFAULT);
      $id = run("INSERT INTO users(role_id, branch_id, name, email, password, phone, is_active, created_at, updated_at) VALUES(?,?,?,?,?,?,1,NOW(),NOW())",
        [$roleId, $branchId, $n, $email, $hash, $phone]);
      audit('user.create', 'user', (int)$id, ['name' => $n, 'email' => $email]);
    }
    $db->commit();
    out(['ok' => 1, 'id' => (int)$id]);

  case 'user_toggle':
    if (!$isAdmin) fail('Access denied');
    $uid = (int)($in['id'] ?? 0);
    if ($uid === uid()) fail('You cannot deactivate your own account');
    $u = one("SELECT id, is_active FROM users WHERE id=?", [$uid]);
    if (!$u) fail('User not found');
    $newStatus = $u['is_active'] ? 0 : 1;
    run("UPDATE users SET is_active=?, updated_at=NOW() WHERE id=?", [$newStatus, $uid]);
    audit('user.toggle_active', 'user', $uid, ['is_active' => $newStatus]);
    out(['ok' => 1, 'is_active' => $newStatus]);

  case 'products':
    out(array_map(fn($r) => fl($r, ['cost', 'price', 'vat', 'min_stock', 'stock', 'other_branches_stock']),
      rows("SELECT p.id,p.name,p.sku,p.barcode,c.name cat,p.purchase_price cost,p.selling_price price,p.vat_rate vat,p.min_stock,
        COALESCE(i.quantity,0) stock,
        (SELECT COALESCE(SUM(quantity),0) FROM inventory WHERE product_id=p.id AND branch_id!=?) as other_branches_stock
        FROM products p 
        LEFT JOIN categories c ON c.id=p.category_id 
        LEFT JOIN inventory i ON " . INV . " 
        WHERE p.deleted_at IS NULL AND p.is_active=1 
        ORDER BY p.name", [$BR, $BR])));

  case 'product_branch_stock':
    $pid = (int)($_GET['product_id'] ?? $_GET['id'] ?? 0);
    if ($pid <= 0) fail('Product ID is required');
    $prod = one("SELECT p.id, p.name, p.sku, p.barcode, p.selling_price, p.purchase_price, u.short_name as unit, c.name as category_name
      FROM products p 
      LEFT JOIN units u ON u.id=p.unit_id 
      LEFT JOIN categories c ON c.id=p.category_id 
      WHERE p.id=? AND p.deleted_at IS NULL", [$pid]);
    if (!$prod) fail('Product not found');

    $branchStocks = rows("SELECT b.id as branch_id, b.name as branch_name, b.code as branch_code,
      b.address as branch_address, b.phone as branch_phone,
      COALESCE(i.quantity, 0) as quantity,
      COALESCE(u.short_name, 'pc') as unit
      FROM branches b
      LEFT JOIN inventory i ON i.branch_id = b.id AND i.product_id = ?
      CROSS JOIN products p ON p.id = ?
      LEFT JOIN units u ON u.id = p.unit_id
      WHERE b.is_active = 1
      ORDER BY b.id ASC", [$pid, $pid]);

    out([
      'product' => fl($prod, ['selling_price', 'purchase_price']),
      'current_branch_id' => $BR,
      'branches_stock' => array_map(fn($r) => fl($r, ['quantity']), $branchStocks)
    ]);

  case 'product_save':
    $n = trim($in['name'] ?? ''); $sku = trim($in['sku'] ?? '');
    if ($n === '' || $sku === '') fail('Name and SKU are required');
    $cost = max(0, (float)($in['cost'] ?? 0));
    $price = max(0, (float)($in['price'] ?? 0));
    $vat = min(100, max(0, (float)($in['vat'] ?? 0)));
    $min = max(0, (float)($in['min_stock'] ?? 0));
    $bc = trim($in['barcode'] ?? '') ?: null;
    $cn = trim($in['cat'] ?? '') ?: 'General';

    $db->beginTransaction();
    $c = one("SELECT id FROM categories WHERE name=?", [$cn]);
    $cid = $c ? $c['id'] : run("INSERT INTO categories(name) VALUES(?)", [$cn]);

    if (!empty($in['id'])) {
      $id = (int)$in['id'];
      run("UPDATE products SET name=?,sku=?,barcode=?,category_id=?,purchase_price=?,selling_price=?,vat_rate=?,min_stock=?,updated_at=NOW() WHERE id=? AND deleted_at IS NULL",
        [$n, $sku, $bc, $cid, $cost, $price, $vat, $min, $id]);
    } else {
      $id = run("INSERT INTO products(unit_id,name,sku,barcode,category_id,purchase_price,selling_price,vat_rate,min_stock,created_at,updated_at) VALUES(1,?,?,?,?,?,?,?,?,NOW(),NOW())",
        [$n, $sku, $bc, $cid, $cost, $price, $vat, $min]);
      
      $branches = rows("SELECT id FROM branches");
      foreach ($branches as $br) {
        $q = ($br['id'] == $BR) ? max(0, (float)($in['stock'] ?? 0)) : 0;
        run("INSERT INTO inventory(branch_id,product_id,quantity,unit_cost) VALUES(?,?,?,?)", [$br['id'], $id, $q, $cost]);
        if ($q > 0) {
          run("INSERT INTO stock_movements(branch_id,product_id,type,quantity,unit_cost,user_id) VALUES(?,?,'opening',?,?,?)", [$br['id'], $id, $q, $cost, uid()]);
        }
      }
    }
    audit(empty($in['id']) ? 'product.create' : 'product.update', 'product', $id, ['name' => $n, 'sku' => $sku, 'price' => $price]);
    $db->commit();
    out(['ok' => 1]);

  case 'product_delete':
    $id = (int)($in['id'] ?? 0);
    $p = one("SELECT name, sku, selling_price FROM products WHERE id=?", [$id]);
    run("UPDATE products SET deleted_at=NOW() WHERE id=?", [$id]);
    audit('product.delete', 'product', $id, [
      'product_name' => $p['name'] ?? ('Product #' . $id),
      'sku' => $p['sku'] ?? '',
      'price' => (float)($p['selling_price'] ?? 0)
    ]);
    out(['ok' => 1]);

  case 'stock_adjust':
    $pid = (int)($in['id'] ?? 0);
    $q = (float)($in['qty'] ?? 0);
    $o = ($in['type'] ?? '') === 'out';
    if ($q <= 0) fail('Enter a valid quantity');

    $db->beginTransaction();
    $i = one("SELECT id,quantity FROM inventory WHERE branch_id=? AND product_id=? AND variant_id IS NULL AND batch_no='' FOR UPDATE", [$BR, $pid]);
    if (!$i) {
      run("INSERT INTO inventory(branch_id,product_id,quantity) VALUES(?,?,0)", [$BR, $pid]);
      $i = ['id' => $db->lastInsertId(), 'quantity' => 0];
    }
    if ($o && $q > $i['quantity']) fail('Cannot remove more than current stock');
    $prevQty = (float)$i['quantity'];
    $newQty = $prevQty + ($o ? -$q : $q);
    run("UPDATE inventory SET quantity=quantity+? WHERE id=?", [$o ? -$q : $q, $i['id']]);
    run("INSERT INTO stock_movements(branch_id,product_id,type,quantity,note,user_id) VALUES(?,?,?,?,?,?)",
      [$BR, $pid, $o ? 'adjustment_out' : 'adjustment_in', $o ? -$q : $q, trim($in['note'] ?? '') ?: null, uid()]);
    $p = one("SELECT name, sku FROM products WHERE id=?", [$pid]);
    audit('stock.adjust', 'product', $pid, [
      'product_name' => $p['name'] ?? ('Product #' . $pid),
      'sku' => $p['sku'] ?? '',
      'adjustment_type' => $o ? 'Stock Out (−)' : 'Stock In (+)',
      'qty_adjusted' => $o ? -$q : $q,
      'previous_stock' => $prevQty,
      'new_stock' => $newQty,
      'note' => trim($in['note'] ?? '') ?: 'Manual Adjustment'
    ]);
    $db->commit();
    out(['ok' => 1]);

  case 'stock_return':
    $pid = (int)($in['product_id'] ?? 0);
    $qty = (float)($in['qty'] ?? 0);
    $type = ($in['type'] ?? 'supplier') === 'damaged' ? 'damaged' : 'supplier';
    $supplierId = (int)($in['supplier_id'] ?? 0);
    $unitCost = max(0, (float)($in['unit_cost'] ?? 0));
    $reason = trim($in['reason'] ?? '') ?: 'Stock Return';
    $note = trim($in['note'] ?? '') ?: null;
    $settlement = $in['settlement'] ?? 'credit_note'; // 'credit_note', 'cash', 'none'
    $curUser = uid() ?: 1;

    if ($pid <= 0) fail('Please select a valid product');
    if ($qty <= 0) fail('Please enter a valid return quantity');

    $db->beginTransaction();
    $p = one("SELECT p.id, p.name, p.purchase_price, COALESCE(i.quantity, 0) stock, i.id iid FROM products p LEFT JOIN inventory i ON " . INV . " WHERE p.id=? AND p.deleted_at IS NULL", [$BR, $pid]);
    if (!$p) fail('Product not found in this branch');
    if ($qty > (float)$p['stock']) fail('Cannot return more than available store stock (' . $p['stock'] . ' available)');

    if ($unitCost <= 0) $unitCost = (float)$p['purchase_price'];
    $totalAmount = round($qty * $unitCost, 2);

    // Deduct stock from inventory
    $inv = one("SELECT id, quantity FROM inventory WHERE branch_id=? AND product_id=? AND variant_id IS NULL AND (batch_no='' OR batch_no IS NULL)", [$BR, $pid]);
    if ($inv) {
      run("UPDATE inventory SET quantity=quantity-? WHERE id=?", [$qty, $inv['id']]);
    } else {
      fail('Inventory record not found for this product');
    }

    $refNo = 'PR-' . date('Ymd-His') . '-' . bin2hex(random_bytes(2));
    $movType = ($type === 'damaged') ? 'damaged' : 'purchase_return';
    run("INSERT INTO stock_movements(branch_id, product_id, type, quantity, unit_cost, reference_type, note, user_id) VALUES(?,?,?,?,?,'return',?,?)",
      [$BR, $pid, $movType, -$qty, $unitCost, $reason . ($note ? ": $note" : ''), $curUser]);

    if ($type === 'damaged') {
      run("INSERT INTO damaged_stock(branch_id, product_id, quantity, reason, note, user_id, created_at) VALUES(?,?,?,?,?,?,NOW())",
        [$BR, $pid, $qty, $reason, $note, $curUser]);
    } else {
      // Return to supplier
      $lastPur = $supplierId > 0 ? one("SELECT id FROM purchases WHERE supplier_id=? ORDER BY id DESC LIMIT 1", [$supplierId]) : null;
      if ($lastPur) {
        run("INSERT INTO purchase_returns(purchase_id, reference_no, total, reason, created_by, created_at) VALUES(?,?,?,?,?,NOW())",
          [$lastPur['id'], $refNo, $totalAmount, $reason, $curUser]);
      }

      if ($settlement === 'cash' && $supplierId > 0) {
        run("INSERT INTO payments(supplier_id, direction, method, amount, reference, user_id) VALUES(?,'in','cash',?,?,?)",
          [$supplierId, $totalAmount, 'Stock Return Cash Refund: ' . $refNo, $curUser]);
      }
    }

    audit('stock.return', 'product', $pid, ['qty' => $qty, 'type' => $type, 'amount' => $totalAmount, 'reason' => $reason]);
    $db->commit();
    out([
      'ok' => 1,
      'reference_no' => $refNo,
      'product_name' => $p['name'],
      'qty' => $qty,
      'unit_cost' => $unitCost,
      'total' => $totalAmount,
      'remaining_stock' => (float)$p['stock'] - $qty
    ]);

  /* ==================== COMPLETE SALE (CHECKOUT) ==================== */
  case 'checkout':
    $items = $in['items'] ?? [];
    if (!$items || !is_array($items)) fail('Cart is empty');
    $uuid = preg_match('/^[0-9a-f-]{36}$/i', $in['uuid'] ?? '') ? $in['uuid'] : null;
    if ($uuid && ($ex = one("SELECT id FROM sales WHERE client_uuid=?", [$uuid]))) {
      out(receipt((int)$ex['id']));
    }

    usort($items, fn($x, $y) => (int)$x['id'] <=> (int)$y['id']);
    $db->beginTransaction();
    $L = []; $sub = 0; $seen = [];

    foreach ($items as $it) {
      $qty = (float)($it['qty'] ?? 0);
      if ($qty <= 0) fail('Invalid quantity');
      if (isset($seen[$it['id']])) fail('Duplicate product in cart');
      $seen[$it['id']] = 1;

      $p = one("SELECT p.id,p.name,p.purchase_price,p.selling_price,p.vat_rate,i.id iid,COALESCE(i.quantity,0) stock FROM products p LEFT JOIN inventory i ON " . INV . " WHERE p.id=? AND p.deleted_at IS NULL AND p.is_active=1 FOR UPDATE", [$BR, (int)$it['id']]);
      if (!$p) fail('Product not found');
      if ($qty > (float)$p['stock']) fail('Insufficient stock: ' . $p['name']);

      if (!$p['iid']) {
        run("INSERT INTO inventory(branch_id,product_id,quantity,unit_cost) VALUES(?,?,0,?)", [$BR, $p['id'], $p['purchase_price']]);
        $p['iid'] = $db->lastInsertId();
      }

      $g = $qty * $p['selling_price'];
      $dt = in_array($it['dt'] ?? '', ['percent', 'fixed']) ? $it['dt'] : 'none';
      $dv = max(0, (float)($it['dv'] ?? 0));
      $dd = min($g, $dt === 'percent' ? $g * min($dv, 100) / 100 : ($dt === 'fixed' ? $dv : 0));
      $L[] = ['p' => $p, 'qty' => $qty, 'dt' => $dt, 'dv' => $dv, 'dd' => $dd, 'net' => $g - $dd];
      $sub += $g - $dd;
    }

    $cdt = in_array($in['cd']['type'] ?? '', ['percent', 'fixed']) ? $in['cd']['type'] : 'none';
    $cdv = max(0, (float)($in['cd']['val'] ?? 0));
    $c = min($sub, $cdt === 'percent' ? $sub * min($cdv, 100) / 100 : ($cdt === 'fixed' ? $cdv : 0));

    // Promo Coupon Voucher verification & discount
    $couponCode = strtoupper(trim($in['coupon'] ?? ''));
    $coupDisc = 0;
    if ($couponCode !== '') {
      $cp = one("SELECT * FROM coupons WHERE UPPER(code)=? AND is_active=1", [$couponCode]);
      if ($cp) {
        $expired = $cp['expires_at'] && strtotime($cp['expires_at']) < strtotime(date('Y-m-d'));
        $exceeded = $cp['usage_limit'] > 0 && $cp['used_count'] >= $cp['usage_limit'];
        if (!$expired && !$exceeded && $sub >= (float)$cp['min_order']) {
          $val = (float)$cp['val'];
          $coupDisc = $cp['type'] === 'percent' ? ($sub * min($val, 100) / 100) : $val;
          $coupDisc = min($coupDisc, max(0, $sub - $c));
          $c += $coupDisc;
          run("UPDATE coupons SET used_count = used_count + 1 WHERE id=?", [$cp['id']]);
        }
      }
    }

    // Loyalty points redemption using dynamic store settings
    $customerId = !empty($in['customer_id']) ? (int)$in['customer_id'] : null;
    $pointsRedeemed = 0;
    $pointVal = (float)get_setting($BR, 'loyalty_value', 1);
    if ($pointVal <= 0) $pointVal = 1;

    $reqPoints = (int)($in['points_to_deduct'] ?? $in['points_redeemed'] ?? 0);
    if ($customerId && $reqPoints > 0) {
      $cust = one("SELECT loyalty_points FROM customers WHERE id=? FOR UPDATE", [$customerId]);
      if ($cust) {
        $availPts = (int)$cust['loyalty_points'];
        $pointsRedeemed = min($availPts, $reqPoints);
        $loyaltyDiscountTk = round($pointsRedeemed * $pointVal, 2);
        if ($loyaltyDiscountTk > ($sub - $c)) {
          $loyaltyDiscountTk = max(0, $sub - $c);
          $pointsRedeemed = (int)ceil($loyaltyDiscountTk / $pointVal);
        }
        $c += $loyaltyDiscountTk;
      }
    }

    $vat = 0;
    foreach ($L as &$l) {
      $l['tot'] = $l['net'] - ($sub > 0 ? $c * $l['net'] / $sub : 0);
      $l['vat'] = $l['tot'] * $l['p']['vat_rate'] / (100 + $l['p']['vat_rate']);
      $vat += $l['vat'];
    }
    unset($l);

    $grand = round($sub - $c, 2);
    
    // Parse payments (cash, card, bkash, nagad, rocket, due)
    $payInput = $in['pay'] ?? [];
    $payMethods = [
      'cash' => max(0, (float)($payInput['cash'] ?? 0)),
      'card' => max(0, (float)($payInput['card'] ?? 0)),
      'bkash' => max(0, (float)($payInput['bkash'] ?? 0)),
      'nagad' => max(0, (float)($payInput['nagad'] ?? 0)),
      'rocket' => max(0, (float)($payInput['rocket'] ?? 0)),
      'due' => max(0, (float)($payInput['due'] ?? 0))
    ];
    $paid = round(array_sum($payMethods), 2);
    if ($paid + 0.001 < $grand) fail('Payment is less than the total');
    
    $chg = round($paid - $grand, 2);
    if ($chg > $payMethods['cash'] + 0.001) fail('Change can only be given from cash');

    // Store Credit / Due verification
    if ($payMethods['due'] > 0) {
      if (!$customerId) fail('A registered customer must be selected to charge Store Credit / Due (বাকি)');
      $cAcc = one("SELECT credit_limit, due_balance FROM customers WHERE id=? FOR UPDATE", [$customerId]);
      $currDue = (float)($cAcc['due_balance'] ?? 0);
      $cLimit = (float)($cAcc['credit_limit'] ?? 0);
      if (($currDue + $payMethods['due']) > ($cLimit + 0.01)) {
        fail("Customer credit limit of ৳$cLimit exceeded. Current unpaid due: ৳$currDue");
      }
      run("UPDATE customers SET due_balance = due_balance + ? WHERE id=?", [$payMethods['due'], $customerId]);
    }

    // Get current cashier shift
    $currShift = one("SELECT id FROM shifts WHERE user_id=? AND status='open' ORDER BY id DESC LIMIT 1", [uid()]);
    $shiftId = $currShift ? (int)$currShift['id'] : null;

    // Award loyalty points based on store configuration
    $loyaltyRate = (float)get_setting($BR, 'loyalty_rate', 50);
    if ($loyaltyRate <= 0) $loyaltyRate = 50;
    $pointsEarned = $customerId ? (int)floor($grand / $loyaltyRate) : 0;
    $orderNote = trim($in['note'] ?? '') ?: null;

    $invPrefix = trim((string)get_setting($BR, 'invoice_prefix', '71M')) ?: '71M';

    $sid = run("INSERT INTO sales(branch_id,cashier_id,customer_id,shift_id,invoice_no,client_uuid,status,subtotal,item_discount_total,cart_discount_type,cart_discount_value,cart_discount_amount,tax_total,grand_total,paid_total,change_due,points_earned,points_redeemed,note,sold_at,created_at,updated_at) VALUES(?,?,?,?,?,?, 'completed',?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW(),NOW())",
      [$BR, uid(), $customerId, $shiftId, 'T' . bin2hex(random_bytes(6)), $uuid, round($sub, 2), round(array_sum(array_column($L, 'dd')), 2), $cdt, $cdv, round($c, 2), round($vat, 2), $grand, $paid, $chg, $pointsEarned, $pointsRedeemed, $orderNote]);
    run("UPDATE sales SET invoice_no=? WHERE id=?", [$invPrefix . '-' . str_pad((string)$sid, 6, '0', STR_PAD_LEFT), $sid]);

    // Update customer loyalty points
    if ($customerId) {
      run("UPDATE customers SET loyalty_points = loyalty_points - ? + ? WHERE id=?", [$pointsRedeemed, $pointsEarned, $customerId]);
    }

    foreach ($L as $l) {
      $p = $l['p'];
      run("INSERT INTO sale_items(sale_id,product_id,name_snapshot,quantity,unit_price,unit_cost,discount_type,discount_value,discount_amount,vat_rate,vat_amount,line_total) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)",
        [$sid, $p['id'], $p['name'], $l['qty'], $p['selling_price'], $p['purchase_price'], $l['dt'], $l['dv'], round($l['dd'], 2), $p['vat_rate'], round($l['vat'], 2), round($l['tot'], 2)]);
      run("UPDATE inventory SET quantity=quantity-? WHERE id=?", [$l['qty'], $p['iid']]);
      run("INSERT INTO stock_movements(branch_id,product_id,type,quantity,unit_cost,reference_type,reference_id,user_id) VALUES(?,?,'sale',?,?,'sale',?,?)",
        [$BR, $p['id'], -$l['qty'], $p['purchase_price'], $sid, uid()]);
    }

    // Insert payment transactions
    $details = $in['pay_details'] ?? [];
    foreach ($payMethods as $methodName => $methodAmt) {
      if ($methodName === 'cash') {
        $actualCash = $methodAmt - $chg;
        if ($actualCash > 0) {
          run("INSERT INTO payments(sale_id,direction,method,amount,reference,shift_id,user_id) VALUES(?,'in',?,?,'Cash Tendered',?,?)",
            [$sid, 'cash', round($actualCash, 2), $shiftId, uid()]);
        }
      } elseif ($methodAmt > 0) {
        $ref = '';
        if ($methodName === 'due') {
          $ref = 'Store Credit / Bakir Khata';
        } elseif ($methodName === 'card' && !empty($details['card_last4'])) {
          $ref = 'Card *' . $details['card_last4'];
        } elseif (in_array($methodName, ['bkash', 'nagad', 'rocket'])) {
          $refParts = [];
          if (!empty($details['mfs_sender'])) {
            $refParts[] = 'From: ' . $details['mfs_sender'];
          }
          if (!empty($details[$methodName . '_trx'])) {
            $refParts[] = 'TrxID: ' . $details[$methodName . '_trx'];
          }
          $ref = !empty($refParts) ? implode(' | ', $refParts) : ($methodName . ' payment');
        }
        run("INSERT INTO payments(sale_id,direction,method,amount,reference,shift_id,user_id) VALUES(?,'in',?,?,?,?,?)",
          [$sid, $methodName, round($methodAmt, 2), $ref, $shiftId, uid()]);
      }
    }

    audit('sale.create', 'sale', (int)$sid, [
      'invoice_no' => $invPrefix . '-' . str_pad((string)$sid, 6, '0', STR_PAD_LEFT),
      'grand_total' => $grand,
      'subtotal' => round($sub, 2),
      'discount_amount' => round($c, 2),
      'tax_total' => round($vat, 2),
      'paid_total' => $paid,
      'change_due' => $chg,
      'items_count' => count($L),
      'points_earned' => $pointsEarned,
      'points_redeemed' => $pointsRedeemed,
      'customer_id' => $customerId,
      'cashier' => $_SESSION['u']['name'] ?? 'Cashier',
      'payment_methods' => array_filter($payMethods, fn($v) => $v > 0)
    ]);
    $db->commit();
    out(receipt((int)$sid));

  case 'sales':
    $targetBranch = $isSuperAdmin ? (int)($_GET['branch_id'] ?? $BR) : $BR;
    $bFilter = $targetBranch > 0 ? " WHERE s.branch_id = $targetBranch" : "";
    out(array_map(fn($r) => fl($r, ['grand', 'items', 'cost', 'profit', 'margin']),
      rows("SELECT s.id,s.invoice_no inv,s.sold_at date,s.status,s.grand_total grand,u.name cashier,b.name store,c.name customer,
        (SELECT SUM(quantity) FROM sale_items WHERE sale_id=s.id) items,
        (SELECT COALESCE(SUM(unit_cost*quantity),0) FROM sale_items WHERE sale_id=s.id) cost,
        (SELECT COALESCE(SUM(line_total - unit_cost*quantity),0) FROM sale_items WHERE sale_id=s.id) profit,
        CASE WHEN s.grand_total > 0 THEN ROUND((((SELECT COALESCE(SUM(line_total - unit_cost*quantity),0) FROM sale_items WHERE sale_id=s.id) * 100.0) / s.grand_total), 1) ELSE 0 END margin
        FROM sales s JOIN users u ON u.id=s.cashier_id LEFT JOIN branches b ON b.id=s.branch_id LEFT JOIN customers c ON c.id=s.customer_id $bFilter ORDER BY s.id DESC LIMIT 100")));

  case 'sale_get':
    $rawId = trim((string)($_GET['id'] ?? ''));
    $sid = 0;
    if (is_numeric($rawId) && (int)$rawId > 0) {
      $sid = (int)$rawId;
    } elseif ($rawId !== '') {
      $cleanId = ltrim($rawId, '#');
      if (is_numeric($cleanId) && (int)$cleanId > 0) {
        $sid = (int)$cleanId;
      } else {
        $found = one("SELECT id FROM sales WHERE invoice_no=? OR invoice_no LIKE ?", [$rawId, '%'.$rawId]);
        if ($found) $sid = (int)$found['id'];
      }
    }
    if (!$sid) fail('Sale not found');
    out(receipt($sid));

  case 'sale_reprint':
    $rawId = trim((string)($_GET['id'] ?? ''));
    $sid = 0;
    if (is_numeric($rawId) && (int)$rawId > 0) {
      $sid = (int)$rawId;
    } elseif ($rawId !== '') {
      $cleanId = ltrim($rawId, '#');
      if (is_numeric($cleanId) && (int)$cleanId > 0) {
        $sid = (int)$cleanId;
      } else {
        $found = one("SELECT id FROM sales WHERE invoice_no=? OR invoice_no LIKE ?", [$rawId, '%'.$rawId]);
        if ($found) $sid = (int)$found['id'];
      }
    }
    if (!$sid) fail('Sale not found');
    $r = receipt($sid);
    audit('sale.reprint', 'sale', $sid, [
      'invoice_no' => $r['inv'],
      'grand_total' => $r['grand'],
      'reprinted_by' => $_SESSION['u']['name'] ?? 'Staff',
      'source' => 'Audit Trail Reprint'
    ]);
    out($r);

  case 'refund':
    $sid = (int)($in['id'] ?? 0);
    $db->beginTransaction();
    $s = one("SELECT * FROM sales WHERE id=? FOR UPDATE", [$sid]);
    if (!$s || $s['status'] !== 'completed') fail('This sale cannot be refunded');
    if (!$isSuperAdmin && (int)$s['branch_id'] !== (int)$BR) {
      fail('You can only refund sales made in your own branch');
    }
    $curUser = uid() ?: 1;
    $refNo = 'R' . date('Ymd-His') . '-' . bin2hex(random_bytes(2));
    $reason = trim($in['reason'] ?? '') ?: 'Customer Return';
    $rid = run("INSERT INTO returns(sale_id,reference_no,refund_total,refund_method,reason,approved_by,created_by,created_at) VALUES(?,?,?,'cash',?,?,?,NOW())",
      [$sid, $refNo, $s['grand_total'], $reason, $curUser, $curUser]);

    foreach (rows("SELECT id,product_id,quantity,line_total FROM sale_items WHERE sale_id=?", [$sid]) as $r) {
      run("INSERT INTO return_items(return_id,sale_item_id,quantity,amount,restock) VALUES(?,?,?,?,1)", [$rid, $r['id'], $r['quantity'], $r['line_total']]);
      $invEx = one("SELECT id FROM inventory WHERE branch_id=? AND product_id=? AND variant_id IS NULL AND (batch_no='' OR batch_no IS NULL)", [$s['branch_id'], $r['product_id']]);
      if ($invEx) {
        run("UPDATE inventory SET quantity=quantity+? WHERE id=?", [$r['quantity'], $invEx['id']]);
      } else {
        run("INSERT INTO inventory(branch_id, product_id, quantity) VALUES(?,?,?)", [$s['branch_id'], $r['product_id'], $r['quantity']]);
      }
      run("INSERT INTO stock_movements(branch_id,product_id,type,quantity,reference_type,reference_id,user_id) VALUES(?,?,'sale_return',?,'return',?,?)", [$s['branch_id'], $r['product_id'], $r['quantity'], $rid, $curUser]);
      run("UPDATE sale_items SET returned_qty=quantity WHERE id=?", [$r['id']]);
    }
    
    // Reverse customer points if applicable
    if (!empty($s['customer_id'])) {
      run("UPDATE customers SET loyalty_points = loyalty_points - ? + ? WHERE id=?",
        [(int)($s['points_earned'] ?? 0), (int)($s['points_redeemed'] ?? 0), $s['customer_id']]);
    }

    run("UPDATE sales SET status='refunded',updated_at=NOW() WHERE id=?", [$sid]);
    run("INSERT INTO payments(sale_id,direction,method,amount,user_id) VALUES(?,'out','cash',?,?)", [$sid, $s['grand_total'], $curUser]);
    audit('sale.refund', 'sale', $sid, ['refund_total' => $s['grand_total'], 'reason' => $reason]);
    $db->commit();
    out(['ok' => 1, 'refund_total' => (float)$s['grand_total'], 'reference_no' => $refNo]);

  /* ==================== REPORTS & ANALYTICS ==================== */
  case 'reports':
    $type = $_GET['type'] ?? 'profit';
    $from = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
    $to = $_GET['to'] ?? date('Y-m-d');
    $targetBr = $isSuperAdmin ? (int)($_GET['branch_id'] ?? $BR) : $BR;
    $bFilter = $targetBr > 0 ? " AND s.branch_id=$targetBr" : "";

    if ($type === 'profit') {
      $rows = rows("SELECT DATE(s.sold_at) d,
        COALESCE(SUM(s.grand_total),0) revenue,
        COALESCE(SUM(si.unit_cost*si.quantity),0) cost,
        COALESCE(SUM(s.grand_total)-SUM(si.unit_cost*si.quantity),0) profit,
        COUNT(DISTINCT s.id) orders
        FROM sales s JOIN sale_items si ON si.sale_id=s.id
        WHERE s.status='completed' $bFilter AND DATE(s.sold_at) BETWEEN ? AND ?
        GROUP BY DATE(s.sold_at) ORDER BY d", [$from, $to]);
      $totR = array_sum(array_column($rows, 'revenue'));
      $totC = array_sum(array_column($rows, 'cost'));
      $totP = array_sum(array_column($rows, 'profit'));
      $totO = array_sum(array_column($rows, 'orders'));
      $expenses = (float)one("SELECT COALESCE(SUM(amount),0) v FROM expenses WHERE expense_date BETWEEN ? AND ?" . ($targetBr > 0 ? " AND branch_id=$targetBr" : ""), [$from, $to])['v'];
      out(['rows' => array_map(fn($r) => fl($r, ['revenue','cost','profit']), $rows),
           'totals' => ['revenue' => $totR, 'cost' => $totC, 'gross_profit' => $totP,
                        'expenses' => $expenses, 'net_profit' => round($totP - $expenses, 2),
                        'orders' => $totO, 'margin' => $totR > 0 ? round($totP / $totR * 100, 1) : 0]]);

    } elseif ($type === 'cashier') {
      out(rows("SELECT u.id, u.name, r.name role,
        COUNT(DISTINCT s.id) sales_count,
        COALESCE(SUM(s.grand_total),0) total_revenue,
        COALESCE(AVG(s.grand_total),0) avg_sale,
        MAX(s.sold_at) last_sale
        FROM users u JOIN roles r ON r.id=u.role_id
        LEFT JOIN sales s ON s.cashier_id=u.id AND s.status='completed' AND DATE(s.sold_at) BETWEEN ? AND ? $bFilter
        WHERE u.is_active=1
        GROUP BY u.id ORDER BY total_revenue DESC", [$from, $to]));

    } elseif ($type === 'inventory') {
      $bId = $targetBr ?: $BR;
      out(rows("SELECT p.id, p.name, p.sku, c.name cat, p.purchase_price cost, p.selling_price price,
        COALESCE(i.quantity,0) stock,
        COALESCE(i.quantity,0) * p.purchase_price stock_value,
        COALESCE(i.quantity,0) * p.selling_price retail_value,
        p.min_stock,
        CASE WHEN COALESCE(i.quantity,0) <= 0 THEN 'out'
             WHEN COALESCE(i.quantity,0) <= p.min_stock THEN 'low'
             ELSE 'ok' END status
        FROM products p
        LEFT JOIN categories c ON c.id=p.category_id
        LEFT JOIN inventory i ON " . INV . "
        WHERE p.deleted_at IS NULL AND p.is_active=1
        ORDER BY p.name", [$bId]));

    } elseif ($type === 'category') {
      out(rows("SELECT c.name category,
        COUNT(DISTINCT si.product_id) products,
        COALESCE(SUM(si.quantity),0) qty_sold,
        COALESCE(SUM(si.line_total),0) revenue
        FROM sale_items si
        JOIN sales s ON s.id=si.sale_id
        JOIN products p ON p.id=si.product_id
        LEFT JOIN categories c ON c.id=p.category_id
        WHERE s.status='completed' $bFilter AND DATE(s.sold_at) BETWEEN ? AND ?
        GROUP BY c.name ORDER BY revenue DESC", [$from, $to]));
    }
    out([]);

  /* ==================== AUDIT LOG ==================== */
  case 'audit_logs':
    $limit = min(500, max(10, (int)($_GET['limit'] ?? 50)));
    $module = trim($_GET['module'] ?? '');
    $search = trim($_GET['search'] ?? '');
    $targetBranch = $isSuperAdmin ? (int)($_GET['branch_id'] ?? 0) : $BR;
    $period = trim($_GET['period'] ?? 'all');
    $customFrom = trim($_GET['from'] ?? '');
    $customTo = trim($_GET['to'] ?? '');

    $dateWhere = "";
    $dateParams = [];

    $now = time();
    if ($period === '7days' || $period === 'week') {
      $since = date('Y-m-d 00:00:00', strtotime('-6 days', $now));
      $until = date('Y-m-d 23:59:59', $now);
      $dateWhere = " AND a.created_at >= ? AND a.created_at <= ?";
      $dateParams = [$since, $until];
    } elseif ($period === '30days' || $period === 'month') {
      $since = date('Y-m-d 00:00:00', strtotime('-29 days', $now));
      $until = date('Y-m-d 23:59:59', $now);
      $dateWhere = " AND a.created_at >= ? AND a.created_at <= ?";
      $dateParams = [$since, $until];
    } elseif ($period === '6months') {
      $since = date('Y-m-d 00:00:00', strtotime('-6 months', $now));
      $until = date('Y-m-d 23:59:59', $now);
      $dateWhere = " AND a.created_at >= ? AND a.created_at <= ?";
      $dateParams = [$since, $until];
    } elseif ($period === 'this_year') {
      $since = date('Y-01-01 00:00:00');
      $until = date('Y-m-d 23:59:59', $now);
      $dateWhere = " AND a.created_at >= ? AND a.created_at <= ?";
      $dateParams = [$since, $until];
    } elseif ($period === 'last_year') {
      $ly = (int)date('Y') - 1;
      $since = "{$ly}-01-01 00:00:00";
      $until = "{$ly}-12-31 23:59:59";
      $dateWhere = " AND a.created_at >= ? AND a.created_at <= ?";
      $dateParams = [$since, $until];
    } elseif ($period === 'custom' && $customFrom !== '' && $customTo !== '') {
      $since = date('Y-m-d 00:00:00', strtotime($customFrom));
      $until = date('Y-m-d 23:59:59', strtotime($customTo));
      $dateWhere = " AND a.created_at >= ? AND a.created_at <= ?";
      $dateParams = [$since, $until];
    }

    $sql = "SELECT a.id, a.action, a.auditable_type, a.auditable_id, a.new_values, a.old_values, a.ip_address, a.created_at,
      u.name as user_name, u.email as user_email, r.name as user_role,
      b.name as branch_name, b.code as branch_code
      FROM audit_logs a
      LEFT JOIN users u ON u.id=a.user_id
      LEFT JOIN roles r ON r.id=u.role_id
      LEFT JOIN branches b ON b.id=a.branch_id
      WHERE 1=1";
    $params = [];

    if ($targetBranch > 0) {
      $sql .= " AND a.branch_id = ?";
      $params[] = $targetBranch;
    }
    if ($module !== '' && $module !== 'all') {
      $sql .= " AND a.action LIKE ?";
      $params[] = $module . '.%';
    }
    if ($search !== '') {
      $sql .= " AND (a.action LIKE ? OR a.auditable_type LIKE ? OR u.name LIKE ? OR a.new_values LIKE ?)";
      $term = "%$search%";
      $params[] = $term;
      $params[] = $term;
      $params[] = $term;
      $params[] = $term;
    }
    if ($dateWhere !== '') {
      $sql .= $dateWhere;
      foreach ($dateParams as $dp) $params[] = $dp;
    }

    $sql .= " ORDER BY a.id DESC LIMIT ?";
    $params[] = $limit;

    $logs = rows($sql, $params);

    $bFilter = $targetBranch > 0 ? " AND branch_id = $targetBranch" : "";
    $statDateWhere = str_replace("a.", "", $dateWhere);
    $todayDate = date('Y-m-d');
    $todayParams = $targetBranch > 0 ? [$todayDate, $targetBranch] : [$todayDate];

    $totalLogs = (int)(one("SELECT COUNT(*) n FROM audit_logs WHERE 1=1 $bFilter $statDateWhere", $dateParams)['n'] ?? 0);
    $todayLogs = (int)(one("SELECT COUNT(*) n FROM audit_logs WHERE DATE(created_at) = ?" . ($targetBranch > 0 ? " AND branch_id = ?" : ""), $todayParams)['n'] ?? 0);
    $uniqueUsers = (int)(one("SELECT COUNT(DISTINCT user_id) n FROM audit_logs WHERE user_id IS NOT NULL $bFilter $statDateWhere", $dateParams)['n'] ?? 0);
    $sensitiveCount = (int)(one("SELECT COUNT(*) n FROM audit_logs WHERE action IN ('sale.refund', 'product.delete', 'branch.delete', 'user.password_change', 'user.toggle_active', 'coupon.delete', 'stock.return') $bFilter $statDateWhere", $dateParams)['n'] ?? 0);

    out([
      'logs' => $logs,
      'stats' => [
        'total' => $totalLogs,
        'today' => $todayLogs,
        'active_users' => $uniqueUsers,
        'sensitive' => $sensitiveCount
      ],
      'period' => $period
    ]);

  /* ==================== CUSTOMER PURCHASE HISTORY ==================== */
  case 'customer_history':
    $cid = (int)($_GET['id'] ?? 0);
    if (!$cid) fail('Customer ID required');
    $cust = one("SELECT * FROM customers WHERE id=?", [$cid]);
    if (!$cust) fail('Customer not found');
    $cBranchFilter = !$isSuperAdmin ? " AND s.branch_id = $BR" : "";
    $sales = rows("SELECT s.id, s.invoice_no inv, s.sold_at date, s.grand_total grand, s.points_earned, s.points_redeemed, s.status, u.name cashier, b.name store
      FROM sales s JOIN users u ON u.id=s.cashier_id LEFT JOIN branches b ON b.id=s.branch_id
      WHERE s.customer_id=? $cBranchFilter ORDER BY s.id DESC LIMIT 50", [$cid]);
    out(['customer' => $cust, 'sales' => array_map(fn($r) => fl($r, ['grand']), $sales)]);

  /* ==================== PASSWORD CHANGE ==================== */
  case 'password_change':
    $current = $in['current'] ?? '';
    $newPass = $in['new_password'] ?? '';
    if (strlen($newPass) < 6) fail('New password must be at least 6 characters');
    $u = one("SELECT password FROM users WHERE id=?", [uid()]);
    if (!$u || !password_verify($current, $u['password'])) fail('Current password is incorrect');
    run("UPDATE users SET password=?, updated_at=NOW() WHERE id=?", [password_hash($newPass, PASSWORD_DEFAULT), uid()]);
    audit('user.password_change', 'user', uid());
    out(['ok' => 1]);

  /* ==================== SETTINGS ==================== */
  case 'settings_get':
    $branch = one("SELECT * FROM branches WHERE id=?", [$BR]);
    $defaults = [
      'store_name' => $branch['name'] ?? '71 Mart',
      'store_address' => $branch['address'] ?? 'Dhaka, Bangladesh',
      'store_phone' => $branch['phone'] ?? '01700000000',
      'bin_number' => $branch['bin_number'] ?? '001234567-0101',
      'currency' => '৳',
      'currency_code' => 'BDT',
      'invoice_prefix' => $branch['code'] ?? '71M',
      'loyalty_rate' => 50,
      'loyalty_value' => 1,
      'vat_default' => 0,
      'low_stock_threshold' => 5,
      'receipt_header' => 'Fresh & Quality Superstore',
      'receipt_footer' => 'Thank you for shopping! Exchange within 7 days with original receipt.',
      'sound_enabled' => 1,
      'allow_negative_stock' => 0
    ];

    $dbSettings = rows("SELECT `key`, value FROM settings WHERE branch_id=? OR branch_id IS NULL ORDER BY branch_id ASC", [$BR]);
    foreach ($dbSettings as $row) {
      $val = json_decode($row['value'], true);
      $defaults[$row['key']] = $val !== null ? $val : $row['value'];
    }
    out($defaults);

  case 'settings_save':
    if (!$isAdmin) fail('Only Admin or Super Admin can edit terminal and store settings');
    $keys = [
      'store_name', 'store_address', 'store_phone', 'bin_number',
      'currency', 'currency_code', 'invoice_prefix',
      'loyalty_rate', 'loyalty_value', 'vat_default',
      'low_stock_threshold', 'receipt_header', 'receipt_footer',
      'sound_enabled', 'allow_negative_stock'
    ];

    $db->beginTransaction();
    foreach ($keys as $k) {
      if (array_key_exists($k, $in)) {
        set_setting($BR, $k, $in[$k]);
      }
    }

    $stName = trim($in['store_name'] ?? '');
    $stAddr = trim($in['store_address'] ?? '');
    $stPhone = trim($in['store_phone'] ?? '');
    $stBin = trim($in['bin_number'] ?? '');
    $stPrefix = trim($in['invoice_prefix'] ?? '');

    if ($stName !== '') {
      run("UPDATE branches SET name=?, address=?, phone=?, bin_number=?, code=? WHERE id=?",
        [$stName, $stAddr, $stPhone, $stBin, $stPrefix ?: 'MAIN', $BR]);
      $_SESSION['u']['branch_name'] = $stName;
      $_SESSION['u']['active_branch_name'] = $stName;
    }

    audit('settings.update', 'settings', $BR, $in);
    $db->commit();
    out(['ok' => 1, 'message' => 'Settings updated successfully']);

  case 'backup_download':
    if (!$isAdmin) fail('Only Admin or Super Admin can download system backup');
    $dbFile = DB_FILE;
    if (file_exists($dbFile)) {
      audit('system.backup_download', 'database', null);
      header('Content-Type: application/octet-stream');
      header('Content-Disposition: attachment; filename="cartivon_backup_' . date('Y-m-d_H-i-s') . '.db"');
      header('Content-Length: ' . filesize($dbFile));
      readfile($dbFile);
      exit;
    } else {
      fail('Database file not found');
    }

  case 'export_products_csv':
    if (!$isAdmin && $role !== 'Inventory Manager') fail('Access denied');
    $items = rows("SELECT p.id, p.sku, p.barcode, p.name, c.name as category, p.purchase_price, p.selling_price, p.vat_rate, COALESCE(i.quantity,0) as stock FROM products p JOIN categories c ON c.id=p.category_id LEFT JOIN inventory i ON " . INV . " WHERE p.deleted_at IS NULL ORDER BY p.name ASC", [$BR]);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="products_export_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'SKU', 'Barcode', 'Product Name', 'Category', 'Purchase Cost (Tk)', 'Selling Price (Tk)', 'VAT Rate (%)', 'Stock Qty'], ',', '"', '\\');
    foreach ($items as $r) {
      fputcsv($out, [$r['id'], $r['sku'], $r['barcode'], $r['name'], $r['category'], $r['purchase_price'], $r['selling_price'], $r['vat_rate'], $r['stock']], ',', '"', '\\');
    }
    fclose($out);
    exit;

  case 'export_sales_csv':
    if (!$isAdmin) fail('Access denied');
    $sales = rows("SELECT s.invoice_no, b.name as branch, u.name as cashier, COALESCE(c.name, 'Walk-in') as customer, s.subtotal, s.cart_discount_amount as discount, s.tax_total, s.grand_total, s.status, s.sold_at FROM sales s LEFT JOIN branches b ON b.id=s.branch_id JOIN users u ON u.id=s.cashier_id LEFT JOIN customers c ON c.id=s.customer_id ORDER BY s.id DESC LIMIT 1000");
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="sales_export_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Invoice No', 'Store Outlet', 'Cashier', 'Customer', 'Subtotal (Tk)', 'Discount (Tk)', 'VAT (Tk)', 'Grand Total (Tk)', 'Status', 'Date Time'], ',', '"', '\\');
    foreach ($sales as $r) {
      fputcsv($out, [$r['invoice_no'], $r['branch'], $r['cashier'], $r['customer'], $r['subtotal'], $r['discount'], $r['tax_total'], $r['grand_total'], $r['status'], $r['sold_at']], ',', '"', '\\');
    }
    fclose($out);
    exit;

  case 'export_customers_csv':
    if (!$isAdmin) fail('Access denied');
    $customers = rows("SELECT c.name, c.phone, c.email, c.loyalty_points, c.due_balance, c.credit_limit, (SELECT COUNT(*) FROM sales WHERE customer_id=c.id AND status='completed') as total_orders, (SELECT COALESCE(SUM(grand_total),0) FROM sales WHERE customer_id=c.id AND status='completed') as total_spent FROM customers c WHERE c.is_active=1 ORDER BY c.name ASC");
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="customers_export_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Customer Name', 'Phone', 'Email', 'Loyalty Points', 'Total Orders', 'Total Spent (Tk)', 'Due Balance (Tk)', 'Credit Limit (Tk)'], ',', '"', '\\');
    foreach ($customers as $r) {
      fputcsv($out, [$r['name'], $r['phone'], $r['email'], $r['loyalty_points'], $r['total_orders'], $r['total_spent'], $r['due_balance'], $r['credit_limit']], ',', '"', '\\');
    }
    fclose($out);
    exit;

  /* ==================== SUPPLIERS & PURCHASES / STOCK IN ==================== */
  case 'suppliers':
    out(rows("SELECT s.*, 
      (SELECT COUNT(*) FROM purchases WHERE supplier_id=s.id) as purchase_count,
      (SELECT COALESCE(SUM(grand_total),0) FROM purchases WHERE supplier_id=s.id) as total_purchased
      FROM suppliers s ORDER BY s.name ASC"));

  case 'supplier_save':
    if (!$isAdmin && $role !== 'Inventory Manager') fail('Access denied');
    $id = (int)($in['id'] ?? 0);
    $n = trim($in['name'] ?? '');
    $contact = trim($in['contact_person'] ?? '') ?: null;
    $phone = trim($in['phone'] ?? '') ?: null;
    $email = trim($in['email'] ?? '') ?: null;
    $addr = trim($in['address'] ?? '') ?: null;
    if ($n === '') fail('Supplier company name is required');

    if ($id > 0) {
      run("UPDATE suppliers SET name=?, contact_person=?, phone=?, email=?, address=?, updated_at=NOW() WHERE id=?",
        [$n, $contact, $phone, $email, $addr, $id]);
      audit('supplier.update', 'supplier', $id, ['name' => $n]);
    } else {
      $id = run("INSERT INTO suppliers(name, contact_person, phone, email, address, opening_balance, is_active, created_at, updated_at) VALUES(?,?,?,?,?,0,1,NOW(),NOW())",
        [$n, $contact, $phone, $email, $addr]);
      audit('supplier.create', 'supplier', (int)$id, ['name' => $n]);
    }
    out(['ok' => 1, 'id' => (int)$id]);

  case 'purchases':
    out(rows("SELECT p.*, s.name as supplier_name, s.phone as supplier_phone, u.name as created_by_name,
      (SELECT COUNT(*) FROM purchase_items WHERE purchase_id=p.id) as item_count,
      (SELECT COALESCE(SUM(qty_received),0) FROM purchase_items WHERE purchase_id=p.id) as total_qty
      FROM purchases p 
      JOIN suppliers s ON s.id=p.supplier_id 
      LEFT JOIN users u ON u.id=p.created_by
      WHERE p.branch_id=? ORDER BY p.id DESC LIMIT 100", [$BR]));

  case 'purchase_save':
    if (!$isAdmin && $role !== 'Inventory Manager') fail('Access denied');
    $supplierId = (int)($in['supplier_id'] ?? 0);
    $refNo = trim($in['reference_no'] ?? '') ?: ('PO-' . date('Ymd-His'));
    $invNo = trim($in['supplier_invoice_no'] ?? '') ?: null;
    $items = $in['items'] ?? [];
    if ($supplierId <= 0) fail('Please select a supplier');
    if (!$items || !is_array($items)) fail('Please add at least one product item');

    $db->beginTransaction();
    $subtotal = 0;
    foreach ($items as $it) {
      $qty = max(0, (float)($it['qty'] ?? 0));
      $cost = max(0, (float)($it['cost'] ?? 0));
      $subtotal += ($qty * $cost);
    }
    $paid = max(0, (float)($in['paid_amount'] ?? $subtotal));

    $pid = run("INSERT INTO purchases(branch_id, supplier_id, reference_no, supplier_invoice_no, status, order_date, received_date, subtotal, discount, tax_total, shipping, grand_total, paid_amount, created_by, created_at, updated_at) VALUES(?,?,?,?,'received',CURDATE(),CURDATE(),?,0,0,0,?,?,?,NOW(),NOW())",
      [$BR, $supplierId, $refNo, $invNo, $subtotal, $subtotal, $paid, uid()]);

    foreach ($items as $it) {
      $prodId = (int)$it['product_id'];
      $qty = max(0, (float)$it['qty']);
      $cost = max(0, (float)$it['cost']);
      $lineTotal = round($qty * $cost, 2);

      run("INSERT INTO purchase_items(purchase_id, product_id, qty_ordered, qty_received, unit_cost, tax_rate, line_total) VALUES(?,?,?,?,?,0,?)",
        [$pid, $prodId, $qty, $qty, $cost, $lineTotal]);

      // Update purchase cost on product
      run("UPDATE products SET purchase_price=?, updated_at=NOW() WHERE id=?", [$cost, $prodId]);

      // Add to inventory
      $inv = one("SELECT id, quantity FROM inventory WHERE branch_id=? AND product_id=? AND variant_id IS NULL AND batch_no=''", [$BR, $prodId]);
      if ($inv) {
        run("UPDATE inventory SET quantity=quantity+?, unit_cost=? WHERE id=?", [$qty, $cost, $inv['id']]);
      } else {
        run("INSERT INTO inventory(branch_id, product_id, quantity, unit_cost) VALUES(?,?,?,?)", [$BR, $prodId, $qty, $cost]);
      }

      run("INSERT INTO stock_movements(branch_id, product_id, type, quantity, unit_cost, reference_type, reference_id, user_id) VALUES(?,?,'purchase',?,?, 'purchase', ?, ?)",
        [$BR, $prodId, $qty, $cost, $pid, uid()]);
    }

    audit('purchase.create', 'purchase', (int)$pid, ['supplier_id' => $supplierId, 'grand_total' => $subtotal]);
    $db->commit();
    out(['ok' => 1, 'id' => (int)$pid, 'reference_no' => $refNo]);

  /* ==================== CUSTOMER STORE CREDIT / DUE PAYMENT ==================== */
  case 'customer_due_pay':
    $cid = (int)($in['id'] ?? 0);
    $amt = max(0, (float)($in['amount'] ?? 0));
    $method = in_array($in['method'] ?? '', ['cash', 'bank', 'bkash', 'nagad', 'rocket']) ? $in['method'] : 'cash';
    $note = trim($in['note'] ?? '') ?: 'Due Payment Collection';
    if ($cid <= 0 || $amt <= 0) fail('Select a customer and enter a valid payment amount');

    $cust = one("SELECT id, name, phone, due_balance FROM customers WHERE id=?", [$cid]);
    if (!$cust) fail('Customer not found');
    $prevDue = (float)($cust['due_balance'] ?? 0);
    $newDue = max(0, round($prevDue - $amt, 2));
    run("UPDATE customers SET due_balance=?, updated_at=NOW() WHERE id=?", [$newDue, $cid]);

    $currShift = one("SELECT id FROM shifts WHERE user_id=? AND status='open' ORDER BY id DESC LIMIT 1", [uid()]);
    $shiftId = $currShift ? (int)$currShift['id'] : null;

    run("INSERT INTO payments(direction, method, amount, reference, shift_id, user_id, created_at) VALUES('in', ?, ?, ?, ?, ?, NOW())",
      [$method, $amt, "Due Collection - " . $cust['name'] . " (" . $cust['phone'] . ")", $shiftId, uid()]);

    audit('customer.due_payment', 'customer', $cid, ['amount' => $amt, 'method' => $method, 'prev_due' => $prevDue, 'new_due' => $newDue]);

    out([
      'ok' => 1,
      'receipt' => [
        'customer_name' => $cust['name'],
        'customer_phone' => $cust['phone'],
        'amount_paid' => $amt,
        'method' => $method,
        'previous_due' => $prevDue,
        'current_due' => $newDue,
        'date' => date('Y-m-d H:i:s'),
        'cashier' => $_SESSION['u']['name']
      ]
    ]);

  /* ==================== COUPONS & VOUCHER GENERATOR ==================== */
  case 'coupons':
    out(rows("SELECT * FROM coupons ORDER BY id DESC"));

  case 'coupon_save':
    if (!$isAdmin) fail('Only Admin can create or edit coupons');
    $id = (int)($in['id'] ?? 0);
    $code = strtoupper(trim($in['code'] ?? ''));
    if ($code === '') fail('Coupon code is required');
    $label = trim($in['label'] ?? '') ?: ($code . ' Discount');
    $type = in_array($in['type'] ?? '', ['percent', 'fixed']) ? $in['type'] : 'fixed';
    $val = max(0, (float)($in['val'] ?? 0));
    if ($val <= 0) fail('Discount value must be greater than 0');
    if ($type === 'percent' && $val > 100) fail('Percentage discount cannot exceed 100%');
    $minOrder = max(0, (float)($in['min_order'] ?? 0));
    $limit = max(0, (int)($in['usage_limit'] ?? 0));
    $expires = !empty($in['expires_at']) ? trim($in['expires_at']) : null;
    $isActive = isset($in['is_active']) ? (int)(bool)$in['is_active'] : 1;

    $db->beginTransaction();
    if ($id > 0) {
      $ex = one("SELECT id FROM coupons WHERE code=? AND id!=?", [$code, $id]);
      if ($ex) fail("Coupon code '$code' is already in use");
      run("UPDATE coupons SET code=?, label=?, type=?, val=?, min_order=?, usage_limit=?, expires_at=?, is_active=? WHERE id=?",
        [$code, $label, $type, $val, $minOrder, $limit, $expires, $isActive, $id]);
    } else {
      $ex = one("SELECT id FROM coupons WHERE code=?", [$code]);
      if ($ex) fail("Coupon code '$code' already exists");
      $id = run("INSERT INTO coupons(code, label, type, val, min_order, usage_limit, used_count, expires_at, is_active, created_at) VALUES(?,?,?,?,?,?,0,?,?,NOW())",
        [$code, $label, $type, $val, $minOrder, $limit, $expires, $isActive]);
    }
    audit('coupon.save', 'coupon', (int)$id, ['code' => $code, 'val' => $val]);
    $db->commit();
    out(['ok' => 1, 'id' => (int)$id, 'code' => $code]);

  case 'coupon_delete':
    if (!$isAdmin) fail('Only Admin can delete coupons');
    $id = (int)($in['id'] ?? 0);
    $cp = one("SELECT code FROM coupons WHERE id=?", [$id]);
    if (!$cp) fail('Coupon not found');
    run("DELETE FROM coupons WHERE id=?", [$id]);
    audit('coupon.delete', 'coupon', $id, ['code' => $cp['code']]);
    out(['ok' => 1]);

  case 'coupon_check':
    $code = strtoupper(trim($_GET['code'] ?? ''));
    if ($code === '') fail('Please enter a promo coupon code');
    $cp = one("SELECT * FROM coupons WHERE UPPER(code)=?", [$code]);
    if (!$cp) fail("Coupon code '$code' is invalid");
    if (!$cp['is_active']) fail("Coupon '$code' is currently disabled");
    if ($cp['expires_at'] && strtotime($cp['expires_at']) < strtotime(date('Y-m-d'))) {
      fail("Coupon '$code' expired on " . $cp['expires_at']);
    }
    if ($cp['usage_limit'] > 0 && $cp['used_count'] >= $cp['usage_limit']) {
      fail("Coupon '$code' usage limit of {$cp['usage_limit']} uses has been reached");
    }
    out(['ok' => 1, 'code' => $cp['code'], 'coupon' => [
      'id' => (int)$cp['id'],
      'code' => $cp['code'],
      'type' => $cp['type'],
      'val' => (float)$cp['val'],
      'min_order' => (float)$cp['min_order'],
      'label' => $cp['label']
    ]]);

  /* ==================== REWARD POINTS & CONVERSION ==================== */
  case 'reward_points_data':
    $pointVal = (float)get_setting($BR, 'loyalty_value', 1);
    if ($pointVal <= 0) $pointVal = 1;
    $loyaltyRate = (float)get_setting($BR, 'loyalty_rate', 50);
    if ($loyaltyRate <= 0) $loyaltyRate = 50;

    $custs = rows("SELECT id, name, phone, email, address, loyalty_points, due_balance,
      (SELECT COUNT(*) FROM sales WHERE customer_id=customers.id) as order_count,
      (SELECT COALESCE(SUM(grand_total), 0) FROM sales WHERE customer_id=customers.id) as total_spent
      FROM customers
      ORDER BY loyalty_points DESC, name ASC");

    $rewardCoupons = rows("SELECT * FROM coupons WHERE code LIKE 'RWD-%' ORDER BY id DESC LIMIT 100");

    $totalLoyaltyCustomers = (int)(one("SELECT COUNT(*) n FROM customers WHERE loyalty_points > 0")['n'] ?? 0);
    $totalPoints = (int)(one("SELECT COALESCE(SUM(loyalty_points), 0) s FROM customers")['s'] ?? 0);
    $totalRewardCoupons = (int)(one("SELECT COUNT(*) n FROM coupons WHERE code LIKE 'RWD-%'")['n'] ?? 0);
    $totalRewardDiscount = (float)(one("SELECT COALESCE(SUM(val), 0) s FROM coupons WHERE code LIKE 'RWD-%'")['s'] ?? 0);

    out([
      'customers' => $custs,
      'reward_coupons' => $rewardCoupons,
      'stats' => [
        'total_loyalty_customers' => $totalLoyaltyCustomers,
        'total_points' => $totalPoints,
        'total_points_value' => round($totalPoints * $pointVal, 2),
        'total_reward_coupons' => $totalRewardCoupons,
        'total_reward_discount' => round($totalRewardDiscount, 2)
      ],
      'loyalty_value' => $pointVal,
      'loyalty_rate' => $loyaltyRate
    ]);

  case 'reward_points_convert':
    $cid = (int)($in['customer_id'] ?? 0);
    $pts = (int)($in['points'] ?? 0);
    $expiryDays = max(1, min(365, (int)($in['expiry_days'] ?? 30)));
    $minOrder = max(0, (float)($in['min_order'] ?? 0));

    if ($cid <= 0) fail('Please select a valid customer');
    if ($pts <= 0) fail('Points to convert must be greater than 0');

    $db->beginTransaction();
    $cust = one("SELECT id, name, phone, loyalty_points FROM customers WHERE id=? FOR UPDATE", [$cid]);
    if (!$cust) fail('Customer not found');

    $availPts = (int)($cust['loyalty_points'] ?? 0);
    if ($availPts < $pts) {
      fail("Customer only has {$availPts} points. Cannot convert {$pts} points.");
    }

    $pointVal = (float)get_setting($BR, 'loyalty_value', 1);
    if ($pointVal <= 0) $pointVal = 1;
    $discountTk = round($pts * $pointVal, 2);
    if ($discountTk <= 0) fail('Coupon discount value must be greater than ৳0');

    // Generate unique code: RWD-XXXX-YYYY
    $phoneClean = preg_replace('/\D/', '', (string)$cust['phone']);
    $phonePart = strlen($phoneClean) >= 4 ? substr($phoneClean, -4) : strtoupper(bin2hex(random_bytes(2)));
    do {
      $code = 'RWD-' . $phonePart . '-' . strtoupper(bin2hex(random_bytes(2)));
      $exists = one("SELECT id FROM coupons WHERE UPPER(code)=?", [$code]);
    } while ($exists);

    $label = 'Reward Voucher: ' . $cust['name'] . ' (' . $pts . ' pts)';
    $expiresAt = date('Y-m-d', strtotime("+{$expiryDays} days"));

    $couponId = run("INSERT INTO coupons(code, label, type, val, min_order, usage_limit, used_count, expires_at, is_active, created_at) VALUES(?,?, 'fixed', ?, ?, 1, 0, ?, 1, NOW())",
      [$code, $label, $discountTk, $minOrder, $expiresAt]);

    $remainingPts = $availPts - $pts;
    run("UPDATE customers SET loyalty_points = loyalty_points - ?, updated_at = NOW() WHERE id=?", [$pts, $cid]);

    audit('customer.points_to_coupon', 'coupon', (int)$couponId, [
      'customer_id' => $cid,
      'customer_name' => $cust['name'],
      'customer_phone' => $cust['phone'],
      'points_converted' => $pts,
      'remaining_points' => $remainingPts,
      'coupon_code' => $code,
      'discount_tk' => $discountTk,
      'expires_at' => $expiresAt
    ]);

    $db->commit();

    out([
      'ok' => 1,
      'coupon_id' => (int)$couponId,
      'coupon_code' => $code,
      'discount_tk' => $discountTk,
      'points_converted' => $pts,
      'remaining_points' => $remainingPts,
      'customer_name' => $cust['name'],
      'customer_phone' => $cust['phone'],
      'expires_at' => $expiresAt,
      'min_order' => $minOrder
    ]);

  default:
    out(['error' => 'Unknown action'], 404);
  }
} catch (RuntimeException $e) {
  if (isset($db) && $db->inTransaction()) $db->rollBack();
  out(['error' => $e->getMessage()], 422);
} catch (PDOException $e) {
  if (isset($db) && $db->inTransaction()) $db->rollBack();
  out(['error' => $e->getCode() === '23000' ? 'SKU, barcode, phone, or code already exists' : ('Database error: ' . $e->getMessage())], 500);
}
