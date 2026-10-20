# PixelTopUp — a game top-up storefront in plain PHP

A complete, working storefront built from the architecture observed on
gamesour.com: server-rendered catalogue, a three-step order wizard, wallet +
instant payment, per-user order limits, a loyalty gate, and an admin panel for
orders, products and stock.

**No framework, no build step, no Composer.** PHP 8.0+ and a database. It runs
on shared hosting as-is.

- **Demo store, not a clone.** All product names, copy and styling are original.
  Nothing is copied from gamesour.com, and no customer data is reproduced.
- **Tested.** `tests/smoke.py` exercises 23 checks against the running app
  including double-submits, price tampering, stock restoration, access control
  and the webhook signature guard. All pass.

---

## Quick start (SQLite, zero setup)

```bash
cd site
php bin/install.php                 # creates the schema and seeds demo data
php -S 0.0.0.0:8080 -t public public/index.php
# open http://localhost:8080
```

Seeded accounts (change these before deploying):

| Role | Email | Password |
| --- | --- | --- |
| Admin | `admin@pixeltopup.test` | `ChangeMe123!` |
| Customer | `demo@example.test` | `DemoPass123!` (wallet ৳2,000) |

## Quick start (MySQL / MariaDB)

```bash
mysql -u root -p -e "CREATE DATABASE pixeltopup CHARACTER SET utf8mb4"
DB_DRIVER=mysql DB_NAME=pixeltopup DB_USER=root DB_PASS=secret \
  php bin/install.php
DB_DRIVER=mysql DB_NAME=pixeltopup DB_USER=root DB_PASS=secret \
  php -S 0.0.0.0:8080 -t public public/index.php
```

`bin/install.php` will also try to create the database for you. Both schemas
live in `db/`: `schema.mysql.sql` and `schema.sqlite.sql`.

---

## What is included

| Route | What it does |
| --- | --- |
| `/` | notice bar, featured + regular product sections, recent deliveries feed |
| `/shop` | search and type filter over active products |
| `/product/{id}/{slug}` | the three-step wizard: package → details → payment |
| `POST /checkout` | validates, prices, reserves stock, creates the order |
| `/order/{id}` | status timeline for one order (owner or admin only) |
| `/account` | wallet balance, lifetime spend, order history, ledger |
| `POST /wallet/topup` | demo-only wallet credit (disabled in production) |
| `/login` `/register` `POST /logout` | email + password auth, optional Google |
| `/pay/{id}` | payment step; demo gateway completes it |
| `POST /webhook/payment` | **HMAC-signed** provider callback (idempotent) |
| `/admin/orders` | filter, count, and advance order status |
| `/admin/products` | create/edit products, add packages, set stock |
| `/sitemap.xml` `/robots.txt` | generated from the catalogue |

### Business rules implemented

- **Server-side pricing.** The browser submits only `package_id` and `quantity`.
  Price is re-read from the database inside the transaction.
- **Idempotency.** Each rendered page carries a 32-hex key; the orders table has
  a unique index on it. A double-tap or a page refresh cannot create two orders.
- **Stock safety.** `UPDATE packages SET stock = stock - ? WHERE id = ? AND
  stock >= ?` — the check and the write are one statement, so two buyers can
  never take the same last code. SQLite uses `BEGIN IMMEDIATE` for the same
  reason MySQL uses row locks.
- **Per-day cap.** 5 orders per rolling 24h, enforced inside the transaction.
- **Loyalty gate.** Products with `min_lifetime_spend > 0` unlock after that
  much completed spend; re-checked on every order, not just in the UI.
- **Wallet as a ledger.** Balance lives in `users.wallet_balance` for atomic
  debits; every movement is also appended to `wallet_ledger` as the audit trail.
  The account page compares the two and warns if they ever disagree.
- **Cancellation.** Restores stock, refunds wallet payments, and reverses the
  lifetime-spend credit. Nothing can cancel a delivered order.
- **Privacy on the feed.** The "recent deliveries" feed shows `gta•••` style
  shortened names, not full names and amounts.

### Security measures

| Area | Implementation |
| --- | --- |
| Passwords | `password_hash()` with `PASSWORD_DEFAULT` (bcrypt/argon) |
| Sessions | `HttpOnly`, `SameSite=Lax`, `Secure` when HTTPS, id regenerated on login |
| CSRF | per-session token, `hash_equals()` on every state-changing POST |
| SQL injection | prepared statements everywhere, no string interpolation |
| XSS | `e()` (htmlspecialchars) on output; admin HTML allowlisted via `strip_tags()` |
| Access control | ownership checks on orders; `role = admin` gate on `/admin` |
| Webhooks | `hash_hmac('sha256', body, secret)` compared with `hash_equals()` |
| Enumeration | identical error for unknown email and wrong password |
| Robots | `/admin`, `/account`, `/order/` disallowed |
| Config | secrets read from env with dev fallbacks; `.htaccess` blocks `app/`, `db/`, `bin/` |

---

## Configuration

Every setting has an env-var override (`app/config.php`):

```bash
DB_DRIVER=mysql            # sqlite | mysql
DB_HOST=127.0.0.1
DB_NAME=pixeltopup
DB_USER=...
DB_PASS=...
APP_URL=https://yourdomain.com     # used for sitemap + OAuth redirect
APP_SECRET=...                     # random 32+ bytes
PAYMENT_SECRET=...                 # shared with your payment provider
DEMO_PAYMENTS=0                    # MUST be 0 in production
GOOGLE_CLIENT_ID=...               # optional Google sign-in
GOOGLE_CLIENT_SECRET=...
SITE_NAME=PixelTopUp
```

Generate secrets:

```bash
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
```

---

## Connecting a real payment gateway

The app expects two things from any provider: a way to send the customer to pay,
and a signed callback. `POST /webhook/payment` is the callback.

Send this from your provider's console (example with cURL):

```bash
BODY='{"order_id":42,"status":"success"}'
SIG=$(printf '%s' "$BODY" | openssl dgst -sha256 -hmac "$PAYMENT_SECRET" -hex | sed 's/^.* //')
curl -X POST https://yourdomain.com/webhook/payment \
     -H "Content-Type: application/json" -H "X-Signature: $SIG" -d "$BODY"
```

For bKash / Nagad / SSLCommerz: create the payment in `/pay/{id}`, store the
provider's `paymentID`, and map their IPN to that payload. Always verify the
signature **and** that the amount matches the order inside the handler. The
handler is idempotent, so repeated callbacks are safe.

Until a provider is connected, `DEMO_PAYMENTS=1` shows a "simulate payment"
button so the whole flow is testable.

---

## Project layout

```
site/
├── app/
│   ├── bootstrap.php        session, helpers, CSRF, auth, order helpers
│   ├── config.php           all settings (env-overridable)
│   ├── Database.php         PDO wrapper + transaction helper
│   └── views/               layout + one file per page
├── bin/install.php          schema + seed (refuses to run over the web)
├── db/
│   ├── schema.mysql.sql
│   └── schema.sqlite.sql
├── public/                  ← document root
│   ├── index.php            front controller and all route handlers
│   ├── .htaccess
│   └── assets/app.css|app.js
└── tests/smoke.py           23 end-to-end checks
```

---

## Deploying

1. Upload the repo; point the domain's document root at `site/public`.
   On cPanel: put `public/` contents in `public_html/` and `app/`, `db/`,
   `bin/` one level above it.
2. Create a MySQL database and user.
3. Set env vars (or edit `app/config.php`) — at minimum `DB_*`, `APP_URL`,
   `APP_SECRET`, `PAYMENT_SECRET`, `DEMO_PAYMENTS=0`.
4. Run `php bin/install.php` once from SSH, then **change the seeded passwords**.
5. Enable HTTPS. Session cookies become `Secure` automatically.
6. Add a cron for backups: `mysqldump pixeltopup | gzip > /backup/$(date +%F).sql.gz`.

### Before taking real money

- [ ] `DEMO_PAYMENTS=0`
- [ ] Both secrets replaced with random values
- [ ] Admin password changed; demo customer deleted
- [ ] Gateway webhook registered and signature verified
- [ ] HTTPS enforced (HSTS)
- [ ] Database backups scheduled and **restore tested**
- [ ] Error display off (`display_errors=0`), logs on
- [ ] Terms, refund and privacy pages added — required for payment providers
- [ ] Rate-limit `/login` at the web-server level (nginx `limit_req`)

---

## Not implemented (on purpose)

Being explicit so nothing is assumed:

- **Real payment provider** — the demo gateway is a stub; wiring a provider is
  your integration work (see above).
- **Email**: no verification, password reset, or receipts. Add an SMTP library.
- **Google sign-in** — code is present but the live round-trip was never tested
  in the authoring sandbox (no browser, no OAuth credentials). Treat it as
  unverified until you try it with your own client ID.
- **Code inventory.** Stock is a counter. If each voucher is a unique code, add
  a `voucher_codes` table and hand out one row per order inside the same
  transaction — the counter then becomes a derived count.
- **Delivery automation.** Marking an order delivered is manual in the admin
  panel. Automating it means calling the game's top-up API from a worker.
- **Refunds** to the original card, partial refunds, tax/VAT, multi-currency,
  and multi-language (the UI is English with ৳ prices).

## Running the tests

```bash
php bin/install.php --fresh        # tests assume the seeded starting balances
php -S 0.0.0.0:8080 -t public public/index.php &
python3 tests/smoke.py
```

## About `tools/preview.mjs`

The authoring sandbox had no PHP binary, so the site was developed and tested by
running PHP 8.3 through WebAssembly (`@php-wasm/node`). That harness lives at
`tools/preview.mjs` and is **not** part of the website — never deploy it. On any
machine with PHP installed, use `php -S` or Apache/nginx instead.

```bash
cd tools && npm install && node preview.mjs 8080    # optional, sandbox only
```
