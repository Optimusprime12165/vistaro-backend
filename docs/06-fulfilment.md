# VISTARO — Phase 7: Admin order fulfilment (PARTIAL: code written, test suite incomplete)

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

## NOT done yet
1. Tests for: admin list/detail (search, filters, cursor paging, query count), role matrix, cancel at every stage and courier-cancel failure rollback, returns/refunds, pickup/label/tracking edge cases, webhook edge cases (out of order, unknown AWB, wrong provider, malformed body), customers and login-event lists.
2. Parallel-process race checks (two admins on one order, duplicate webhook delivery, admin vs customer cancel).
3. Not exercised yet: cursor-pagination meta on a real endpoint, mobile-number search normalisation, date filters, label headers.
4. Not planned: real courier/payment provider, notifications, return-shipment pickups, dashboard (Phase 9).
