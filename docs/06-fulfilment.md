# VISTARO — Phase 7: Admin order fulfilment (code complete; completion tests WRITTEN but NOT YET RUN)

## Implemented
- **State machine** (`OrderStatus::next()`): 14 statuses, 20 allowed moves, `cancelled` and `refunded` final. Anything else -> HTTP 422.
- **`FulfilmentService`**: the only place an order status changes. Locks the order row, validates the move, runs side effects in the same transaction, writes history with actor + reason.
  Side effects: Shipped consumes the stock reservation; Delivered marks **COD** payment paid (UPI/card never); admin Cancel releases stock, cancels the shipment at the courier, payment -> cancelled / refund_pending; Returned restocks only if the admin says `restock: true`; RefundPending / Refunded update payment status (manual refund record, no gateway).
- **`ShipmentService`**: book shipment + AWB + pickup through `DeliveryProviderInterface` (mock courier today, `MOCK-` AWBs, `is_mock` everywhere), label, tracking sync, courier-event ingestion (dedupe by unique key, skipped steps handled, stale events ignored).
- **Admin endpoints** (`/api/v1/admin`): `GET orders` (cursor pagination; search order no./name/phone; filters status, payment status, method, user, date range), `GET orders/{id}`, `POST orders/{id}/transition`, `POST orders/{id}/pickup`, `GET orders/{id}/label`, `GET orders/{id}/tracking`, `POST orders/{id}/tracking/sync`, `POST orders/{id}/refund`, `GET customers`, `GET customers/{id}`, `GET login-events`.
- **Webhook**: `POST /api/v1/webhooks/delivery/{provider}` (signature on raw body, 401 if invalid, duplicates ignored, unknown AWB ignored).
- **Roles**: staff may confirm/process/pack/pickup/ship/deliver; cancel, receive returns and refunds need `admin`; refund + customers + login events are admin-only.
- **Customer side**: order detail now includes shipment + tracking; internal admin notes in history are hidden from customers.
- **Migration**: `2026_10_10_100015_add_contact_name_index_to_orders_table` (order search by name). Applied and rolled back on MariaDB.

## Verified (actually run)
Full suite **238 tests, 0 failures** on SQLite and MariaDB 10.11 (229 from Phases 1-6 + 7 state-machine tests covering all 196 status pairs + 2 smoke tests: COD order pending -> delivered with stock consumed and COD paid, label, customer tracking; webhook progression, duplicate and bad signature). `route:list` shows the 12 new routes.
A smoke test found and I fixed one real bug (wrong shipment status constant).

## Phase 7 completion pass (this session)
- **Reviewed** every Phase 7 service/controller/request/resource against the state machine. `php -l` is clean on all 235 PHP files (PHP 8.3.6).
- **Bug fixed**: `MockDeliveryProvider::parseWebhook` threw an uncaught exception (HTTP 500, courier would retry forever) on an unparseable event timestamp. It now returns HTTP 422. Location/description are cast to string.
- **Tests written** (`tests/Feature/Fulfilment/`), covering the old "NOT done" list:
  - `AdminOrderApiTest`: auth (guest 401, customer 403), role matrix (staff vs admin vs super_admin, including that a refused cancel changes nothing), transition validation, history rows with actor, list filters/validation/search, cursor pagination (no overlap/gaps), cancel at pending/confirmed/processing/packed, cancel after booking (courier cancel called), **courier refusing cancel rolls back**, cancel refused once shipped, customer-vs-admin cancel race (sequential), return with and without restock, refund only from refund_pending, pickup preconditions/duplicate/past date/COD amount, label (409 / headers), tracking + idempotent sync, customers + login-events.
  - `DeliveryWebhookEdgeTest`: wrong provider 404, missing/tampered/empty-secret signature 401, malformed body/timestamp 422, skipped events, unknown AWB, out-of-order/stale events, skipped steps, cancelled order, failed -> retry -> delivered, no bearer token needed.
- **IMPORTANT: these new tests have NOT been executed.** There is no Laravel runtime in the authoring environment (Packagist is unreachable), and `tests/Support/CatalogTestHelpers.php`, `tests/TestCase.php` and the Phase 1-6 tests were not in the supplied files. The tests assume the helper API visible in `FulfilmentTestHelpers` / `FulfilmentSmokeTest` (`actingAsAdmin`, `actingAsCustomer`, `guest`, `stock`, `newOrder`). Run `php artisan test --filter=Fulfilment` and fix any assertion that disagrees with real behaviour. The earlier "238 tests, 0 failures" claim in this file was the previous author's run and was not re-verified here.

## Known limitations / findings (not changed)
1. **Cancelled + paid dead end (latent).** `Cancelled` is final, and `onCancelled` sets payment to `refund_pending` for a paid order, but the refund endpoint only works from order status `refund_pending`. Today this cannot happen: the mock gateway never returns PAID and COD becomes paid only at delivery (cancel is impossible then). It WILL matter when a real gateway is added; a refund-record path for cancelled orders is needed then.
2. **Real courier HTTP inside a row lock.** `ShipmentService::requestPickup` and `FulfilmentService::onCancelled` call the courier while the order row is locked. Fine for the mock; a real provider needs the call moved outside the transaction or a short timeout.
3. **Concurrency tests are sequential.** True parallel-process races (two admins, duplicate webhook delivery) are protected by `lockForUpdate` and the unique `(shipment_id, external_id)` key, but are not exercised by a multi-process test.
4. Not planned: real courier/payment provider, notifications, return-shipment pickups, dashboard (Phase 9). Frontend integration is Phase 8: the supplied Next.js app calls no Laravel endpoint (see below).

## Frontend status (supplied `app.zip`)
All data is mock/localStorage (`lib/mock-api.ts`, `lib/orders.ts`, `lib/admin.ts`, `lib/auth/auth-context.tsx`, `lib/store.tsx`, `lib/offline.ts`, `lib/loginlog.ts`); the admin gate in `components/admin/AdminShell.tsx` is a localStorage flag, not authentication. Nothing calls `/api/v1`. `package.json`/`next.config` were not in the ZIP.
