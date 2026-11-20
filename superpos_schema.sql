-- Cartivon POS by Codevix Technologies (MySQL 8, InnoDB, utf8mb4)
-- Money: DECIMAL(14,2). Quantities: DECIMAL(14,3) (supports kg/litre).
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ========== Organisation ==========
CREATE TABLE branches (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(20) NOT NULL UNIQUE,
  name VARCHAR(120) NOT NULL,
  address TEXT, phone VARCHAR(30), email VARCHAR(120),
  bin_number VARCHAR(30) NULL COMMENT 'Bangladesh VAT BIN',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL
) ENGINE=InnoDB;

CREATE TABLE settings (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  branch_id BIGINT UNSIGNED NULL,
  `key` VARCHAR(100) NOT NULL,
  value JSON NULL,
  UNIQUE KEY uq_settings (branch_id, `key`),
  FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ========== Auth / RBAC ==========
CREATE TABLE roles (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(50) NOT NULL UNIQUE,
  description VARCHAR(255) NULL,
  is_system TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE permissions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL UNIQUE COMMENT 'e.g. sales.refund, products.delete',
  module VARCHAR(40) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE role_permissions (
  role_id BIGINT UNSIGNED NOT NULL,
  permission_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (role_id, permission_id),
  FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
  FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  role_id BIGINT UNSIGNED NOT NULL,
  branch_id BIGINT UNSIGNED NULL,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(120) NOT NULL UNIQUE,
  phone VARCHAR(30) NULL,
  password VARCHAR(255) NOT NULL COMMENT 'bcrypt/argon2',
  pin_hash VARCHAR(255) NULL COMMENT 'quick cashier unlock',
  locale ENUM('en','bn') NOT NULL DEFAULT 'en',
  salary DECIMAL(14,2) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_login_at TIMESTAMP NULL,
  remember_token VARCHAR(100) NULL,
  created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  FOREIGN KEY (role_id) REFERENCES roles(id),
  FOREIGN KEY (branch_id) REFERENCES branches(id)
) ENGINE=InnoDB;

CREATE TABLE login_history (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NULL,
  email VARCHAR(120) NOT NULL,
  ip_address VARCHAR(45), user_agent VARCHAR(255),
  success TINYINT(1) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (user_id, created_at),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NULL,
  branch_id BIGINT UNSIGNED NULL,
  action VARCHAR(60) NOT NULL,
  auditable_type VARCHAR(60) NOT NULL,
  auditable_id BIGINT UNSIGNED NULL,
  old_values JSON NULL, new_values JSON NULL,
  ip_address VARCHAR(45),
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (auditable_type, auditable_id),
  INDEX (user_id, created_at),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ========== Catalogue ==========
CREATE TABLE categories (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  parent_id BIGINT UNSIGNED NULL,
  name VARCHAR(100) NOT NULL, name_bn VARCHAR(100) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  FOREIGN KEY (parent_id) REFERENCES categories(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE brands (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL UNIQUE
) ENGINE=InnoDB;

CREATE TABLE units (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(40) NOT NULL, short_name VARCHAR(10) NOT NULL,
  allow_decimal TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE products (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category_id BIGINT UNSIGNED NULL,
  brand_id BIGINT UNSIGNED NULL,
  unit_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(180) NOT NULL, name_bn VARCHAR(180) NULL,
  sku VARCHAR(60) NOT NULL UNIQUE,
  barcode VARCHAR(64) NULL UNIQUE,
  image_path VARCHAR(255) NULL,
  purchase_price DECIMAL(14,2) NOT NULL DEFAULT 0,
  selling_price DECIMAL(14,2) NOT NULL DEFAULT 0,
  wholesale_price DECIMAL(14,2) NULL,
  vat_rate DECIMAL(5,2) NOT NULL DEFAULT 0 COMMENT 'percent',
  vat_inclusive TINYINT(1) NOT NULL DEFAULT 1,
  min_stock DECIMAL(14,3) NOT NULL DEFAULT 0,
  track_expiry TINYINT(1) NOT NULL DEFAULT 0,
  has_variants TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL, deleted_at TIMESTAMP NULL,
  FULLTEXT KEY ft_products (name, sku),
  INDEX (category_id), INDEX (brand_id),
  FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
  FOREIGN KEY (brand_id) REFERENCES brands(id) ON DELETE SET NULL,
  FOREIGN KEY (unit_id) REFERENCES units(id)
) ENGINE=InnoDB;

CREATE TABLE product_variants (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(100) NOT NULL COMMENT 'e.g. 500g, Red/XL',
  sku VARCHAR(60) NOT NULL UNIQUE,
  barcode VARCHAR(64) NULL UNIQUE,
  purchase_price DECIMAL(14,2) NULL, selling_price DECIMAL(14,2) NULL,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ========== Inventory ==========
-- One row per product/variant/branch/batch. Source of truth = stock_movements; this is the cached balance.
CREATE TABLE inventory (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  branch_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  variant_id BIGINT UNSIGNED NULL,
  batch_no VARCHAR(60) NOT NULL DEFAULT '',
  expiry_date DATE NULL,
  quantity DECIMAL(14,3) NOT NULL DEFAULT 0,
  unit_cost DECIMAL(14,2) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_inv (branch_id, product_id, variant_id, batch_no),
  INDEX (expiry_date),
  CONSTRAINT chk_inv_qty CHECK (quantity >= 0),
  FOREIGN KEY (branch_id) REFERENCES branches(id),
  FOREIGN KEY (product_id) REFERENCES products(id),
  FOREIGN KEY (variant_id) REFERENCES product_variants(id)
) ENGINE=InnoDB;

CREATE TABLE stock_movements (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  branch_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  variant_id BIGINT UNSIGNED NULL,
  batch_no VARCHAR(60) NOT NULL DEFAULT '',
  type ENUM('purchase','sale','sale_return','purchase_return','adjustment_in','adjustment_out',
            'transfer_in','transfer_out','damage','opening') NOT NULL,
  quantity DECIMAL(14,3) NOT NULL COMMENT 'signed: + in, - out',
  unit_cost DECIMAL(14,2) NOT NULL DEFAULT 0,
  reference_type VARCHAR(40) NULL, reference_id BIGINT UNSIGNED NULL,
  note VARCHAR(255) NULL,
  user_id BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (product_id, branch_id, created_at),
  INDEX (reference_type, reference_id),
  FOREIGN KEY (branch_id) REFERENCES branches(id),
  FOREIGN KEY (product_id) REFERENCES products(id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE stock_transfers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reference_no VARCHAR(30) NOT NULL UNIQUE,
  from_branch_id BIGINT UNSIGNED NOT NULL,
  to_branch_id BIGINT UNSIGNED NOT NULL,
  status ENUM('pending','in_transit','received','cancelled') NOT NULL DEFAULT 'pending',
  created_by BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NULL, received_at TIMESTAMP NULL,
  FOREIGN KEY (from_branch_id) REFERENCES branches(id),
  FOREIGN KEY (to_branch_id) REFERENCES branches(id),
  FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE stock_transfer_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  transfer_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  variant_id BIGINT UNSIGNED NULL,
  batch_no VARCHAR(60) NOT NULL DEFAULT '',
  quantity DECIMAL(14,3) NOT NULL,
  FOREIGN KEY (transfer_id) REFERENCES stock_transfers(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB;

CREATE TABLE damaged_stock (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  branch_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  batch_no VARCHAR(60) NOT NULL DEFAULT '',
  quantity DECIMAL(14,3) NOT NULL,
  reason ENUM('damaged','expired','lost','other') NOT NULL,
  note VARCHAR(255) NULL,
  user_id BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NULL,
  FOREIGN KEY (branch_id) REFERENCES branches(id),
  FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB;

-- ========== Suppliers & Purchasing ==========
CREATE TABLE suppliers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL, contact_person VARCHAR(120) NULL,
  phone VARCHAR(30), email VARCHAR(120), address TEXT,
  opening_balance DECIMAL(14,2) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL
) ENGINE=InnoDB;

CREATE TABLE purchases (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  branch_id BIGINT UNSIGNED NOT NULL,
  supplier_id BIGINT UNSIGNED NOT NULL,
  reference_no VARCHAR(30) NOT NULL UNIQUE,
  supplier_invoice_no VARCHAR(60) NULL,
  status ENUM('ordered','partial','received','cancelled') NOT NULL DEFAULT 'ordered',
  order_date DATE NOT NULL, received_date DATE NULL,
  subtotal DECIMAL(14,2) NOT NULL DEFAULT 0,
  discount DECIMAL(14,2) NOT NULL DEFAULT 0,
  tax_total DECIMAL(14,2) NOT NULL DEFAULT 0,
  shipping DECIMAL(14,2) NOT NULL DEFAULT 0,
  grand_total DECIMAL(14,2) NOT NULL DEFAULT 0,
  paid_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  INDEX (supplier_id, order_date),
  FOREIGN KEY (branch_id) REFERENCES branches(id),
  FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
  FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE purchase_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  purchase_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  variant_id BIGINT UNSIGNED NULL,
  batch_no VARCHAR(60) NOT NULL DEFAULT '',
  expiry_date DATE NULL,
  qty_ordered DECIMAL(14,3) NOT NULL,
  qty_received DECIMAL(14,3) NOT NULL DEFAULT 0,
  unit_cost DECIMAL(14,2) NOT NULL,
  tax_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
  line_total DECIMAL(14,2) NOT NULL,
  FOREIGN KEY (purchase_id) REFERENCES purchases(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB;

CREATE TABLE purchase_returns (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  purchase_id BIGINT UNSIGNED NOT NULL,
  reference_no VARCHAR(30) NOT NULL UNIQUE,
  total DECIMAL(14,2) NOT NULL,
  reason VARCHAR(255) NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NULL,
  FOREIGN KEY (purchase_id) REFERENCES purchases(id),
  FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE purchase_return_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  purchase_return_id BIGINT UNSIGNED NOT NULL,
  purchase_item_id BIGINT UNSIGNED NOT NULL,
  quantity DECIMAL(14,3) NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  FOREIGN KEY (purchase_return_id) REFERENCES purchase_returns(id) ON DELETE CASCADE,
  FOREIGN KEY (purchase_item_id) REFERENCES purchase_items(id)
) ENGINE=InnoDB;

-- ========== Customers ==========
CREATE TABLE customer_groups (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL UNIQUE,
  discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0 COMMENT 'membership discount',
  points_multiplier DECIMAL(4,2) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE customers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  group_id BIGINT UNSIGNED NULL,
  name VARCHAR(120) NOT NULL,
  phone VARCHAR(30) NOT NULL UNIQUE,
  email VARCHAR(120) NULL, address TEXT NULL,
  loyalty_points INT NOT NULL DEFAULT 0,
  credit_limit DECIMAL(14,2) NOT NULL DEFAULT 0,
  due_balance DECIMAL(14,2) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  FOREIGN KEY (group_id) REFERENCES customer_groups(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ========== Registers, Shifts ==========
CREATE TABLE cash_registers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  branch_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(60) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY (branch_id, name),
  FOREIGN KEY (branch_id) REFERENCES branches(id)
) ENGINE=InnoDB;

CREATE TABLE shifts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  register_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  opened_at TIMESTAMP NOT NULL, closed_at TIMESTAMP NULL,
  opening_cash DECIMAL(14,2) NOT NULL DEFAULT 0,
  expected_cash DECIMAL(14,2) NULL,
  counted_cash DECIMAL(14,2) NULL,
  difference DECIMAL(14,2) NULL,
  status ENUM('open','closed') NOT NULL DEFAULT 'open',
  note VARCHAR(255) NULL,
  INDEX (user_id, status),
  FOREIGN KEY (register_id) REFERENCES cash_registers(id),
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- ========== Promo Coupons & Vouchers ==========
CREATE TABLE IF NOT EXISTS coupons (
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
) ENGINE=InnoDB;

-- ========== Sales ==========
CREATE TABLE sales (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  branch_id BIGINT UNSIGNED NOT NULL,
  register_id BIGINT UNSIGNED NULL,
  shift_id BIGINT UNSIGNED NULL,
  customer_id BIGINT UNSIGNED NULL,
  cashier_id BIGINT UNSIGNED NOT NULL,
  invoice_no VARCHAR(30) NOT NULL UNIQUE,
  client_uuid CHAR(36) NULL UNIQUE COMMENT 'idempotency key for offline sync',
  status ENUM('draft','held','completed','partially_refunded','refunded','void') NOT NULL DEFAULT 'draft',
  subtotal DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'sum of line totals before cart discount',
  item_discount_total DECIMAL(14,2) NOT NULL DEFAULT 0,
  cart_discount_type ENUM('none','percent','fixed') NOT NULL DEFAULT 'none',
  cart_discount_value DECIMAL(14,2) NOT NULL DEFAULT 0,
  cart_discount_amount DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'resolved, capped at subtotal',
  tax_total DECIMAL(14,2) NOT NULL DEFAULT 0,
  rounding DECIMAL(6,2) NOT NULL DEFAULT 0,
  grand_total DECIMAL(14,2) NOT NULL DEFAULT 0,
  paid_total DECIMAL(14,2) NOT NULL DEFAULT 0,
  change_due DECIMAL(14,2) NOT NULL DEFAULT 0,
  due_amount DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'credit sale balance',
  points_earned INT NOT NULL DEFAULT 0, points_redeemed INT NOT NULL DEFAULT 0,
  note VARCHAR(255) NULL,
  sold_at TIMESTAMP NULL,
  created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  INDEX (branch_id, sold_at), INDEX (cashier_id, sold_at), INDEX (customer_id), INDEX (status),
  FOREIGN KEY (branch_id) REFERENCES branches(id),
  FOREIGN KEY (register_id) REFERENCES cash_registers(id),
  FOREIGN KEY (shift_id) REFERENCES shifts(id),
  FOREIGN KEY (customer_id) REFERENCES customers(id),
  FOREIGN KEY (cashier_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE sale_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sale_id BIGINT UNSIGNED NOT NULL,
  product_id BIGINT UNSIGNED NOT NULL,
  variant_id BIGINT UNSIGNED NULL,
  batch_no VARCHAR(60) NOT NULL DEFAULT '',
  name_snapshot VARCHAR(180) NOT NULL,
  quantity DECIMAL(14,3) NOT NULL,
  unit_price DECIMAL(14,2) NOT NULL,
  unit_cost DECIMAL(14,2) NOT NULL COMMENT 'snapshot for profit',
  discount_type ENUM('none','percent','fixed') NOT NULL DEFAULT 'none',
  discount_value DECIMAL(14,2) NOT NULL DEFAULT 0,
  discount_amount DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'resolved, capped at qty*unit_price',
  vat_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
  vat_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  line_total DECIMAL(14,2) NOT NULL,
  returned_qty DECIMAL(14,3) NOT NULL DEFAULT 0,
  INDEX (product_id),
  FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB;

CREATE TABLE payments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sale_id BIGINT UNSIGNED NULL,
  customer_id BIGINT UNSIGNED NULL COMMENT 'due collection',
  purchase_id BIGINT UNSIGNED NULL COMMENT 'supplier payment',
  supplier_id BIGINT UNSIGNED NULL,
  direction ENUM('in','out') NOT NULL,
  method ENUM('cash','card','bkash','nagad','rocket','bank','credit','other') NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  reference VARCHAR(80) NULL COMMENT 'txn id / card last4',
  shift_id BIGINT UNSIGNED NULL,
  user_id BIGINT UNSIGNED NULL,
  paid_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (sale_id), INDEX (purchase_id), INDEX (method, paid_at),
  FOREIGN KEY (sale_id) REFERENCES sales(id),
  FOREIGN KEY (customer_id) REFERENCES customers(id),
  FOREIGN KEY (purchase_id) REFERENCES purchases(id),
  FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
  FOREIGN KEY (shift_id) REFERENCES shifts(id)
) ENGINE=InnoDB;

CREATE TABLE returns (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sale_id BIGINT UNSIGNED NOT NULL,
  reference_no VARCHAR(30) NOT NULL UNIQUE,
  refund_total DECIMAL(14,2) NOT NULL,
  refund_method ENUM('cash','card','bkash','nagad','rocket','bank','store_credit') NOT NULL,
  reason VARCHAR(255) NULL,
  approved_by BIGINT UNSIGNED NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NULL,
  FOREIGN KEY (sale_id) REFERENCES sales(id),
  FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE return_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  return_id BIGINT UNSIGNED NOT NULL,
  sale_item_id BIGINT UNSIGNED NOT NULL,
  quantity DECIMAL(14,3) NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  restock TINYINT(1) NOT NULL DEFAULT 1,
  FOREIGN KEY (return_id) REFERENCES returns(id) ON DELETE CASCADE,
  FOREIGN KEY (sale_item_id) REFERENCES sale_items(id)
) ENGINE=InnoDB;

-- ========== Expenses ==========
CREATE TABLE expense_categories (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL UNIQUE  -- Salary, Rent, Utility, Other ...
) ENGINE=InnoDB;

CREATE TABLE expenses (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  branch_id BIGINT UNSIGNED NOT NULL,
  category_id BIGINT UNSIGNED NOT NULL,
  employee_id BIGINT UNSIGNED NULL COMMENT 'for salary expenses',
  amount DECIMAL(14,2) NOT NULL,
  expense_date DATE NOT NULL,
  method ENUM('cash','bank','bkash','nagad','other') NOT NULL DEFAULT 'cash',
  note VARCHAR(255) NULL, attachment_path VARCHAR(255) NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NULL,
  INDEX (branch_id, expense_date),
  FOREIGN KEY (branch_id) REFERENCES branches(id),
  FOREIGN KEY (category_id) REFERENCES expense_categories(id),
  FOREIGN KEY (employee_id) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- ========== Notifications ==========
CREATE TABLE notifications (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NULL, branch_id BIGINT UNSIGNED NULL,
  type VARCHAR(40) NOT NULL COMMENT 'low_stock, out_of_stock, expiry, backup_failed ...',
  title VARCHAR(150) NOT NULL, body VARCHAR(255) NULL, data JSON NULL,
  read_at TIMESTAMP NULL, created_at TIMESTAMP NULL,
  INDEX (user_id, read_at),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

SET FOREIGN_KEY_CHECKS = 1;
