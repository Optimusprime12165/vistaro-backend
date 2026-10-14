# VISTARO — Phase 3: Database

Verified against **real Laravel 12.69.3 / PHP 8.3.6 / Sanctum 4.3.3 / PHPUnit 11.5.57** on **SQLite** and **MariaDB 10.11**. Not verified on MySQL 8 / Aurora (see Unresolved).

## Commands
```bash
cd backend
composer install            # (or composer update if you have no lock file yet)
cp .env.example .env && php artisan key:generate
# put your DB credentials in .env (DB_DATABASE/USERNAME/PASSWORD), create an empty database, then:
php artisan migrate                 # schema only, safe
php artisan db:seed                 # local: categories + dummy admin + 32 demo products. Production: categories only
php artisan migrate:fresh --seed    # LOCAL ONLY, destroys data
php artisan test                    # uses sqlite :memory: by default (see phpunit.xml)
# run the same tests on MySQL/MariaDB: DB_CONNECTION=mysql DB_DATABASE=vistaro_test ... php artisan test
```
Dev admin (local/testing only): `admin@vistaro.local` / `ChangeMe@123`.

## Tables (24 application + framework tables)
| Table | Purpose / key constraints |
|---|---|
| `users` | customers; **no password**; unique `mobile` (E.164), unique nullable `email`; `status` |
| `admins` | unique `email`, hashed `password`, `role`, `is_active` |
| `otp_verifications` | one row per hashed OTP; expiry, `failed_attempts`, `invalidated_at` |
| `otp_throttles` | **unique (mobile,purpose)**: issuance counter + `next_allowed_at`, so new OTP rows cannot reset daily limits |
| `categories`, `sub_categories` | Topwear/Bottomwear/Outerwear → t-shirts…sweatshirts; unique slugs; sub→category `RESTRICT` |
| `products` | unique `slug`,`sku`; decimal `price`,`mrp`; derived `discount_percent`; JSON `attributes`; `status`; **composite FK (sub_category_id, category_id) → sub_categories** so category/sub-category can never disagree |
| `product_images` | `disk`+`path` (`external` = absolute URL), `is_primary`, `sort_order`; cascade |
| `product_colors` | unique (product,name); cascade |
| `product_sizes` | **online stock**; ENUM XS…4XL; unique (product,size); unsigned `quantity`,`reserved` |
| `offline_stocks` | **offline stock**; unique (product,size); composite FK → `product_sizes(product_id,size)` (cascade) |
| `inventory_movements` | append-only ledger (online+offline), signed changes, polymorphic reference/actor |
| `addresses` | per user; cascade with user |
| `carts`, `cart_items` | one cart per user (unique); line unique (cart,size,colour); **no price columns** |
| `orders` | unique `order_number`; `status` and `payment_status` separate; decimal(12,2) money; user `RESTRICT` |
| `order_items` | immutable snapshot (name, sku, size, colour, image, prices); product/size FK `SET NULL` |
| `order_addresses` | immutable snapshot; **unique `order_id`** |
| `order_status_histories` | append-only (from, to, actor, reason) |
| `payments` | method/gateway/status/amount, `is_mock`; unique (gateway,reference); no card data |
| `shipments` | `is_mock`, unique `awb`, unique (order,type) |
| `shipment_events` | unique (shipment,external_id) → webhook de-duplication |
| `idempotency_keys` | unique (user,scope,key), request hash, stored response |
| `login_events` | append-only customer logins; user `SET NULL` |
| framework | `personal_access_tokens` (Sanctum), `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `migrations` |

## Relationships
Category 1─* SubCategory 1─* Product · Product 1─* Image / Color / ProductSize · ProductSize 1─1 OfflineStock (same product+size) · User 1─* Address, LoginEvent, Order · User 1─1 Cart 1─* CartItem(→ProductSize, →Color) · Order 1─* OrderItem, 1─1 OrderAddress, 1─* StatusHistory, 1─* Payment, 0..1 forward Shipment 1─* ShipmentEvent · ProductSize 1─* InventoryMovement.

## Delete behaviour (read from the live MariaDB catalogue)
Cascade: product→images/colors/sizes/offline; user→addresses/cart/idempotency; order→items/address/history/payments/shipments; cart→items.
Restrict: category→sub-categories, sub-category→products, user→orders.
Set NULL: order_items→product/size, inventory_movements→size, login_events→user.
**Policy:** orders and customers with orders are never hard-deleted (archive products instead).

## Inventory consistency rules
- Sizes: ENUM `XS,S,M,L,XL,XXL,3XL,4XL` (DB-level on both engines).
- A `product_sizes` row always has an `offline_stocks` row (model observer creates a zero row; composite FK forbids offline rows for sizes the product lacks).
- `quantity` = sellable now; `reserved` = held by open orders. Decrements must use `UPDATE … WHERE quantity >= n` (tested); on MySQL/MariaDB UNSIGNED additionally errors instead of going negative.

## Migrations (run order)
`0001_01_01_000001_create_cache_table` · `0001_01_01_000002_create_jobs_table` · `2026_10_10_100000_create_personal_access_tokens_table` · `…100001_create_users_table` · `…100002_create_admins_table` · `…100003_create_otp_tables` · `…100004_create_category_tables` · `…100005_create_products_table` · `…100006_create_product_detail_tables` · `…100007_create_inventory_tables` · `…100008_create_addresses_table` · `…100009_create_cart_tables` · `…100010_create_order_tables` · `…100011_create_payments_table` · `…100012_create_shipment_tables` · `…100013_create_idempotency_keys_table` · `…100014_create_login_events_table`.
The skeleton's `create_users_table` (users/password_reset_tokens/sessions) was removed and replaced; none of these migrations had been applied anywhere outside my test databases.

## Seeders
`DatabaseSeeder` → `CategorySeeder` (all envs; `firstOrCreate`, never overwrites admin edits) → local/testing only: `AdminSeeder` (`firstOrCreate` by email, hashed, never overwrites), `DevProductSeeder` (32 products). **No seeder creates customers, orders, payments, shipments or login events.** `database/seeders/data_catalog.php` is a plain-data port of `data/*.ts`.
**Parity check actually performed:** the frontend's own `products.ts`/`categories.ts` were executed in Node and compared field by field with the seeded database: 32 products + 10 sub-categories, ids, slugs, prices, MRPs, discounts, images, colours, attributes, flags, online and offline stock → **0 differences**.
**Correction to the Phase 1 audit:** the catalogue has **32** products (4+4+8×3), not 33.

## Test results (actually executed)
`vendor/bin/phpunit` → SQLite in-memory: **35 tests, 132 assertions, 0 failures, 1 skipped** (the UNSIGNED test is MySQL-family only). MariaDB 10.11: **35 tests, 133 assertions, all passed**. Also run: `migrate:fresh --seed` on SQLite and MariaDB, and `migrate:reset` (all `down()` methods) on MariaDB.
Defects found and fixed during verification: `->constrained()->unique()` silently created **no** unique index on `carts.user_id` and `order_addresses.order_id` (now explicit `unique()`, covered by tests).

## Unresolved / not verified
1. **MySQL 8 / Aurora MySQL not tested** (MariaDB 10.11 was). ENUM columns + composite FKs are standard but please run `php artisan test` against your MySQL.
2. **No `composer.lock`**: dependencies were installed from a GitHub-built package mirror because Packagist was unreachable here. Versions resolved: Laravel 12.69.3, Sanctum 4.3.3, PHPUnit 11.5.57. Run `composer install`/`update` yourself; commit your own lock.
3. One default address per user and one primary image per product are enforced by services (Phase 5/6), not the DB.
4. `order_number` generation (VS + sequence) is defined in Phase 6; the column is unique.
5. Admin login auditing: only `admins.last_login_*`; no admin audit table yet.
6. Index set is a first design from query patterns; must be re-checked with `EXPLAIN` on volume data (Phase 9).
7. Seeded images are external Unsplash URLs (`disk='external'`); API resources must pass absolute URLs through. Category `image_path` likewise.
8. `OrderStatus` transition map and inventory service arrive in Phases 6–7.
9. Colour-specific stock is not modelled (future migration: nullable `product_color_id` on `product_sizes`).
10. Frontend untouched in this phase.
