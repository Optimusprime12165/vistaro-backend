# VISTARO backend — PHPUnit tests (INTERIM, NOT YET EXECUTED)

PHP/Composer dependencies could not be installed in the sandbox: tests were only syntax-linted (`php -l`), NOT run.
Run: `php artisan test --filter=<ClassName>`. Note: `tests/TestCase.php` is missing from backend.zip (tests import `Tests\TestCase`).
No application code was changed.

## Helpers (tests/Support)
- `CapturingOtpDelivery` — fake OtpDeliveryInterface that records delivered OTPs.
- `OtpTestHelpers` — binds the fake, forces OTP_FIXED_ENABLED=false, disables the HTTP burst throttle.

## Test classes (tests/Feature/Customer)
- `GuestCartPreviewTest` — no token, server prices/totals, client prices ignored, dropped lines (unknown product,
  bad size/colour, hidden product), item ids, quantity cap on folded duplicates, stock reported (not clamped), 422s (>50 lines, bad body), nothing written to carts/cart_items.
- `CartMergeTest` — 401 without token, new line, max(server, guest) not sum, stock cap incl. other lines of same size, per-line max,
  sold-out skipped, idempotent, vanished/changed product/size/colour skipped, duplicates folded, customer isolation, validation, client prices ignored.

## Still TO DO
Email OTP, profile verification / PATCH /me, GET /me for inactive customers. Mobile OTP is already covered by Phase1To7VerificationTest.

## Observations so far
- Guest preview does not cap by live stock; it flags `insufficient_stock` and `can_checkout:false`. Merge does cap.
- Preview returns 422 for a single line above the per-line max (cap only applies to folded duplicates).
- Merge never reduces an existing server line when stock falls.
