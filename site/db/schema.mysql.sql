-- PixelTopUp — MySQL 8 / MariaDB 10.4+
-- All money is stored as whole Bangladeshi Taka in INT columns.

CREATE TABLE IF NOT EXISTS users (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  email          VARCHAR(190) NOT NULL,
  password_hash  VARCHAR(255) NULL,
  name           VARCHAR(120) NOT NULL,
  avatar_url     VARCHAR(255) NULL,
  role           VARCHAR(16) NOT NULL DEFAULT 'customer',
  google_id      VARCHAR(64) NULL,
  wallet_balance INT NOT NULL DEFAULT 0,
  lifetime_spend INT NOT NULL DEFAULT 0,
  created_at     DATETIME NOT NULL,
  UNIQUE KEY uniq_users_email (email),
  UNIQUE KEY uniq_users_google (google_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS products (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  type              VARCHAR(16) NOT NULL,          -- topup | voucher
  slug              VARCHAR(96) NULL,              -- cosmetic only
  title             VARCHAR(128) NOT NULL,
  subtitle          VARCHAR(190) NULL,
  image_path        VARCHAR(255) NULL,
  rules_html        MEDIUMTEXT NULL,
  uid_label         VARCHAR(64) NOT NULL DEFAULT 'Game UID',
  min_lifetime_spend INT NOT NULL DEFAULT 0,
  is_featured       TINYINT NOT NULL DEFAULT 0,
  is_active         TINYINT NOT NULL DEFAULT 1,
  sort_order        INT NOT NULL DEFAULT 0,
  created_at        DATETIME NOT NULL,
  KEY idx_products_type (type, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS packages (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  product_id INT NOT NULL,
  label      VARCHAR(96) NOT NULL,
  price_taka INT NOT NULL,
  stock      INT NOT NULL DEFAULT 0,
  is_active  TINYINT NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  KEY idx_packages_product (product_id, is_active),
  CONSTRAINT fk_packages_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS orders (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  user_id        INT NOT NULL,
  product_id     INT NOT NULL,
  package_id     INT NOT NULL,
  package_label  VARCHAR(96) NOT NULL,   -- snapshot at purchase time
  unit_price     INT NOT NULL,           -- snapshot at purchase time
  quantity       INT NOT NULL DEFAULT 1,
  amount         INT NOT NULL,
  game_uid       VARCHAR(64) NULL,
  pay_method     VARCHAR(16) NOT NULL,   -- wallet | instant
  status         VARCHAR(16) NOT NULL DEFAULT 'pending',
  idempotency_key VARCHAR(64) NOT NULL,
  created_at     DATETIME NOT NULL,
  paid_at        DATETIME NULL,
  delivered_at   DATETIME NULL,
  UNIQUE KEY uniq_orders_idem (idempotency_key),
  KEY idx_orders_user (user_id, created_at),
  KEY idx_orders_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS wallet_ledger (
  id         BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id    INT NOT NULL,
  delta      INT NOT NULL,               -- positive credit, negative debit
  reason     VARCHAR(32) NOT NULL,       -- topup | order | refund | admin
  order_id   INT NULL,
  created_at DATETIME NOT NULL,
  KEY idx_ledger_user (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS settings (
  k VARCHAR(64) PRIMARY KEY,
  v TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
