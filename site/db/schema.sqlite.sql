-- PixelTopUp — SQLite (zero-config local development)
-- Same logical schema as schema.mysql.sql; types adapted for SQLite.

CREATE TABLE IF NOT EXISTS users (
  id             INTEGER PRIMARY KEY AUTOINCREMENT,
  email          TEXT NOT NULL UNIQUE,
  password_hash  TEXT NULL,
  name           TEXT NOT NULL,
  avatar_url     TEXT NULL,
  role           TEXT NOT NULL DEFAULT 'customer',
  google_id      TEXT NULL UNIQUE,
  wallet_balance INTEGER NOT NULL DEFAULT 0,
  lifetime_spend INTEGER NOT NULL DEFAULT 0,
  created_at     TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS products (
  id                INTEGER PRIMARY KEY AUTOINCREMENT,
  type              TEXT NOT NULL,
  slug              TEXT NULL,
  title             TEXT NOT NULL,
  subtitle          TEXT NULL,
  image_path        TEXT NULL,
  rules_html        TEXT NULL,
  uid_label         TEXT NOT NULL DEFAULT 'Game UID',
  min_lifetime_spend INTEGER NOT NULL DEFAULT 0,
  is_featured       INTEGER NOT NULL DEFAULT 0,
  is_active         INTEGER NOT NULL DEFAULT 1,
  sort_order        INTEGER NOT NULL DEFAULT 0,
  created_at        TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_products_type ON products(type, is_active);

CREATE TABLE IF NOT EXISTS packages (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  product_id INTEGER NOT NULL,
  label      TEXT NOT NULL,
  price_taka INTEGER NOT NULL,
  stock      INTEGER NOT NULL DEFAULT 0,
  is_active  INTEGER NOT NULL DEFAULT 1,
  sort_order INTEGER NOT NULL DEFAULT 0,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_packages_product ON packages(product_id, is_active);

CREATE TABLE IF NOT EXISTS orders (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id         INTEGER NOT NULL,
  product_id      INTEGER NOT NULL,
  package_id      INTEGER NOT NULL,
  package_label   TEXT NOT NULL,
  unit_price      INTEGER NOT NULL,
  quantity        INTEGER NOT NULL DEFAULT 1,
  amount          INTEGER NOT NULL,
  game_uid        TEXT NULL,
  pay_method      TEXT NOT NULL,
  status          TEXT NOT NULL DEFAULT 'pending',
  idempotency_key TEXT NOT NULL UNIQUE,
  created_at      TEXT NOT NULL,
  paid_at         TEXT NULL,
  delivered_at    TEXT NULL
);
CREATE INDEX IF NOT EXISTS idx_orders_user ON orders(user_id, created_at);
CREATE INDEX IF NOT EXISTS idx_orders_status ON orders(status, created_at);

CREATE TABLE IF NOT EXISTS wallet_ledger (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id    INTEGER NOT NULL,
  delta      INTEGER NOT NULL,
  reason     TEXT NOT NULL,
  order_id   INTEGER NULL,
  created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_ledger_user ON wallet_ledger(user_id, created_at);

CREATE TABLE IF NOT EXISTS settings (
  k TEXT PRIMARY KEY,
  v TEXT NULL
);
