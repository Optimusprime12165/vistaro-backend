# VISTARO — Phase 5 + 6: Catalogue, Inventory, Addresses, Cart, Checkout, Orders

Verified on Laravel 12.69.3 / PHP 8.3.6, SQLite and MariaDB 10.11 (not MySQL 8 / Aurora). No schema change: **no new migrations**.

## Business rules used (nothing invented; where I added a safeguard it is marked)
| Rule | Source |
|---|---|
| Shipping: free when subtotal is 0 or >= 1999, otherwise 99 | frontend `calc` -> `SHIPPING_FREE_ABOVE`, `SHIPPING_FLAT_FEE` |
| No tax line, no coupons (prices tax-inclusive) | none defined, so none applied |
| Sale badge = discount >= 25 % | frontend rule -> `SALE_MIN_DISCOUNT` |
| Return window 14 days from delivery | frontend policy text -> `RETURN_WINDOW_DAYS` |
| Admin low stock: any size < 5; out: 0. Storefront "N left" when 1..10 | spec -> `vistaro.stock.*` |
| Max 10 per cart line | spec -> `vistaro.cart.max_line_quantity` |
| *Safeguard (mine)*: max 10 saved addresses per customer | `MAX_ADDRESSES` |
| *Safeguard (mine)*: mock UPI/card refused in production | `app()->isProduction()` + mock gateway |
| `trending` badge: **not supported** (needed the fake ratings); returns 422, never fake data | |

## Stock model
`product_sizes.quantity` = sellable now, `.reserved` = held by open orders, `offline_stocks` = shop. Only `InventoryService` changes stock: conditional atomic `UPDATE ... WHERE quantity >= n` (+ UNSIGNED columns on MySQL), one `inventory_movements` row per change, all in transactions with deadlock retry. Placing an order **reserves** (available -> reserved) for COD and online alike; cancelling before dispatch **releases**; fulfilment (Phase 7) will consume the reservation. Colour lines of one size share one stock row and are checked together.

## Endpoints (`/api/v1`)
**Public:** `GET categories`, `categories/{slug}`, `sub-categories?category=`, `products`, `products/facets`, `products/{slug|id}`, `products/{slug}/related`.
Product filters: `q, category, sub_category, size[], price_min, price_max, color[], fit[], fabric[], pattern[], badge=new|best|sale, sort=featured|newest|price_asc|price_desc|best, per_page (10 default, 20, max 100), page`.
Visible = status active + available + active sub-category + active category. Sizes show `available`, and `left`/`label` ("8 left") only for 1..10 units. Facets: fits, fabrics, patterns, colours, in-stock sizes, price range.

**Admin** (`auth:admin`): reads for any admin; stock writes for `admin,staff`; catalogue writes for `admin` (super_admin always passes, staff -> 403).
`categories`, `sub-categories` (CRUD, 409 when they still contain children), `products` (CRUD; delete = archive when it has order history), `products/{id}/images` (multipart `images[]` or `urls[]` https), `images/order`, `images/{id}/primary`, `PUT colors`, `PUT sizes` (bulk), `DELETE sizes/{size}`, `PUT stock` `{location, sizes:{M:12}}`, `POST stock/adjust` `{location,size,delta}`, `GET inventory?location=online|offline&stock_status=in|low|out`, `GET inventory/low-stock`.

**Customer:** `addresses` (CRUD + `PATCH {id}/default`), `cart` (`GET`, `DELETE`, `POST items`, `PATCH/DELETE items/{id}`), `GET checkout/preview?address_id=&payment_method=`, `POST orders` (**`Idempotency-Key` header required**), `GET orders`, `GET orders/{id}`, `POST orders/{id}/cancel`, `POST orders/{id}/return`.

## Examples
```jsonc
POST /api/v1/cart/items            {"product_id":1,"size":"M","color_id":2,"quantity":2}
-> 201 {"data":{"id":5,"items":[{"id":9,"size":"M","unit_price":"1299.00","quantity":2,"line_subtotal":"2598.00","available":true,"issue":null,"stock_left":null,...}],
        "totals":{"subtotal":"2598.00","mrp_total":"3198.00","discount_total":"600.00","shipping_fee":"0.00","total":"2598.00",...},"can_checkout":true}}

POST /api/v1/orders  (header Idempotency-Key: 6b1f...)   {"address_id":3,"payment_method":"upi"}
-> 201 {"success":true,"message":"Order placed. Payment is pending: this is a development order and no money was collected.",
        "data":{"order_number":"VS10007","status":"pending","payment_status":"pending","payment":{"method":"upi","status":"pending","is_mock":true},...}}
retry with the same key and body -> same 201 body + header Idempotent-Replayed: true; same key, different body -> 422
insufficient stock -> 409 {"errors":{"items.9":["Only 1 left in size M."]}}   unavailable item -> 422
```

## Payments
COD: payment row `pending` (cash on delivery). UPI/card: `MockPaymentGateway`, row `pending`, `is_mock=true`, response says so. **No order is ever `paid`**: even if a gateway's initiation result says paid, it is stored as pending (initiation is not confirmation). Raw card data is not accepted anywhere.

## Verification actually run
| Check | Result |
|---|---|
| Full PHPUnit, SQLite | **229 tests, 1987 assertions, 0 failures, 1 skipped** (MySQL-only unsigned test) |
| Full PHPUnit, MariaDB 10.11 | **229 tests, 1988 assertions, 0 failures** |
| Phase 4 auth regression | included above, all pass |
| Phase 5 / 6 tests | 69 catalogue + 72 commerce tests |
| Mutation checks | 11 deliberate breakages (visibility, stock guard, idempotency, release, thresholds, shipping, default address, paid status, 10-cap, cart clearing, MRP rule): every one made tests fail |
| Parallel processes vs MariaDB | oversell race: 3 rounds x 12 buyers for 3 units -> exactly 3 orders each time, stock 0/reserved 3; same-key race: 12 parallel -> 1 order + 11 replays (x3); multi-item opposite-order carts: 16 parallel x3 -> 48 orders, 0 deadlocks |
| `route:list --path=api` | 64 routes, all spec endpoints present |

Run: `php artisan test` (sqlite) or `DB_CONNECTION=mysql DB_DATABASE=... php artisan test`; races: `php tests/tools/order_race.php setup 3 12` then `... place <userId> <key>` in parallel (dev DB only).

## Known limits / not done
1. No admin order endpoints yet (Phase 7): reserved stock stays reserved until fulfilment exists; there is no way to confirm payment or mark delivered, so return/refund paths are tested by setting statuses directly.
2. No real payment provider or payment-confirmation webhook; nothing can become `paid`.
3. No caching yet (Phase 9); admin/storefront name search is `LIKE %term%` (fine for thousands of rows; FULLTEXT later).
4. A retry of the same Idempotency-Key after the address was deleted gets a validation error instead of the replay.
5. Category/sub-category images store a relative path on the default image disk; changing `IMAGES_DISK` later needs a data move. Uploads are validated by content (no GD needed) but not resized or thumbnailed.
6. Guest carts do not exist (login is required, as in the frontend).
7. `LIKE` special characters (`%`, `_`) are escaped for MySQL; SQLite treats them literally.
8. MySQL 8 / Aurora not tested (MariaDB 10.11 was).
9. The Next.js frontend is untouched (integration is Phase 8). It must map: lower-case address types, decimal prices as strings, `left`/`label` for "N left", and the `Idempotency-Key` header.
