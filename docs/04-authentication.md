# VISTARO — Phase 4: Authentication & Authorization

Verified on Laravel 12.69.3 / PHP 8.3.6 / Sanctum 4.3.3. Tests run on SQLite and MariaDB 10.11 (not MySQL 8/Aurora).

## Design
- **Two identities, two guards.** `auth:customer` (provider `users` → `User`) and `auth:admin` (provider `admins` → `Admin`) are Sanctum guards bound to different providers, so the framework rejects an admin token on customer routes and vice versa (401). `principal:customer|admin` then checks the token **ability** and that the account is **active** (403). `admin.role:...` restricts by role (`super_admin` always passes).
- **Pure bearer tokens.** `config/sanctum.php`: `guard => []` (no cookie/session fallback), `expiration => null` (expiry is set per token). No CSRF/cookies. CORS (`config/cors.php`) allows only `FRONTEND_URL`, no credentials.
- **Customer sign-in = mobile OTP** (no password, no separate register step: a verified OTP creates the account). Email is optional profile data. (The request said "email OTP"; the master prompt, Phase 2 decision #1 and the schema are mobile-based, so mobile is implemented. Delivery is behind `OtpDeliveryInterface`.)
- **Admin sign-in = email + password** (bcrypt via the `hashed` cast, constant-time failure path, rehash-on-login, single active session, password change revokes other sessions).

## OTP rules (all enforced server-side in `OtpService`, state in MySQL)
| Rule | Implementation |
|---|---|
| Max 3 issuances / mobile / 24 h | `otp_throttles` (one row per mobile+purpose); window starts at first issuance; only a fully elapsed window resets it |
| Request counts as #1; resend counts too | both endpoints call the same `issue()` |
| Cooldown 30 s after #1, 60 s after #2 | `next_allowed_at`, stored one second late so it is **never shorter** than configured (timestamps have 1 s resolution; `retry_after` may read 31) |
| 4th issuance refused | 429, `errors.reason=daily_limit`, `retry_after` = seconds to window end |
| New OTP rows never reset counters | counter is not on the OTP row |
| Expiry | 300 s (`OTP_TTL_SECONDS`) |
| Single use, newer OTP invalidates older | `verified_at` / `invalidated_at` |
| Wrong guesses | 5 per OTP (`OTP_MAX_VERIFY_ATTEMPTS`), then OTP burned and HTTP 429 |
| Storage | HMAC-SHA256 keyed with `APP_KEY`; plain OTP exists only in memory and in the SMS |
| Concurrency | row lock inside a transaction; row created beforehand by autocommit `INSERT IGNORE` (avoids gap-lock deadlocks); deadlock retry ×5 |
| Delivery failure | 503, issuance refunded, OTP invalidated, log has masked number and no OTP |
| Burst throttles | `otp-request` 5/min/IP + 3/min/number, `otp-verify` 10/min, `admin-login` 5/min per email+IP and 20/min/IP |

## Tokens
| | Customer | Admin |
|---|---|---|
| Ability | `customer` | `admin` |
| Lifetime | 30 days (`CUSTOMER_TOKEN_TTL_MINUTES`) | 8 h (`ADMIN_TOKEN_TTL_MINUTES`) |
| Max sessions | 5 (oldest revoked) | 1 (login revokes older) |
| Revocation | `POST /auth/logout` (this device), `/auth/logout-all` | `POST /admin/auth/logout`, `/logout-all`, password change |
| Frontend storage (Phase 8) | `localStorage` | `sessionStorage` |

**XSS risk (read before Phase 8):** any script that runs on the page can read a token held in JavaScript, so an XSS bug means account takeover until the token expires. Mitigations planned: no `dangerouslySetInnerHTML` with user data, strict CSP, token sent only to the configured API origin, never in URLs/logs, immediate local logout on any 401, short admin lifetime. Residual risk accepted; switching to httpOnly cookies later is isolated to `lib/api.ts`.

## Endpoints (`/api/v1`)
| Method | Path | Auth | Purpose |
|---|---|---|---|
| POST | `/auth/otp/request` | – | send OTP `{ "mobile": "98765 43210" }` |
| POST | `/auth/otp/resend` | – | same, requires a prior request |
| POST | `/auth/otp/verify` | – | `{ "mobile", "otp" }` → token |
| POST | `/auth/logout`, `/auth/logout-all` | customer | revoke |
| GET / PATCH | `/me` | customer | profile (`name`, `email`; mobile immutable) |
| POST | `/admin/auth/login` | – | `{ "email", "password" }` → token |
| GET | `/admin/me` | admin | profile |
| PATCH | `/admin/auth/password` | admin | `current_password`, `password`, `password_confirmation` |
| POST | `/admin/auth/logout`, `/admin/auth/logout-all` | admin | revoke |

Real responses captured from a running server (HTTP via curl, MariaDB):
```jsonc
// POST /auth/otp/request  -> 200
{"success":true,"message":"OTP sent.","data":{"mobile":"+91******3210","expires_in":300,"resend_available_in":30,"remaining_issuances":2,"max_issuances":3}}
// immediately again -> 429 (also header Retry-After)
{"success":false,"message":"Please wait before requesting another OTP.","errors":{"retry_after":31,"reason":"cooldown","remaining_issuances":2}}
// POST /auth/otp/verify wrong code -> 422
{"success":false,"message":"The OTP is incorrect.","errors":{"otp":["The OTP is incorrect."],"attempts_remaining":4}}
// POST /auth/otp/verify correct -> 200
{"success":true,"message":"Signed in.","data":{"token":"1|…","token_type":"Bearer","expires_at":"2026-11-11T02:46:18+00:00","is_new_user":true,
 "user":{"id":1,"name":null,"email":null,"mobile":"+919876543210","mobile_verified":true,"profile_complete":false,"created_at":"…"}}}
// GET /admin/me with a customer token -> 401
{"success":false,"message":"Unauthenticated.","errors":{}}
// POST /admin/auth/login wrong password -> 401
{"success":false,"message":"The provided credentials are incorrect.","errors":{}}
// POST /admin/auth/login admin@vistaro.local / ChangeMe@123 (dev seed only) -> 200
{"success":true,"message":"Signed in.","data":{"token":"2|…","token_type":"Bearer","expires_at":"…","admin":{"id":1,"name":"Dev Admin","email":"admin@vistaro.local","role":"super_admin","last_login_at":"…"}}}
```
Status codes: 200, 401 (no/invalid/expired/revoked token, wrong guard, bad credentials), 403 (wrong ability, suspended/inactive, role), 422 (validation, wrong/expired OTP), 429 (limits; `Retry-After` + `errors.retry_after`), 503 (OTP could not be sent).

## Commands
```bash
php artisan migrate && php artisan db:seed      # no new migrations in Phase 4
php artisan route:list --path=api
php artisan test                                  # sqlite in-memory; add DB_CONNECTION=mysql DB_DATABASE=… for MySQL
# real parallel-process OTP race check against a MySQL/MariaDB dev database (local env only):
seq 1 16 | xargs -P16 -I{} php tests/tools/otp_race.php issue +919000000001
```

## Not done / needs you
1. **No SMS provider yet.** Only `OTP_DELIVERY=log` exists, which works in `local`/`testing` and refuses elsewhere, so a production deploy returns 503 on OTP requests until a provider class is added to `VistaroServiceProvider`.
2. **Behind API Gateway/ALB/CloudFront** configure trusted proxies, otherwise every user shares the proxy IP and the per-IP throttles (5/min) will block legitimate users.
3. Admin password **recovery by email** is not implemented (needs mail + reset-token table). Password change is.
4. No admin login audit table (only `last_login_at/ip`); customer logins are recorded in `login_events`.
5. Throttle limiters need a **shared cache store** in AWS (`CACHE_STORE=database` or Redis).
6. Not tested on MySQL 8 / Aurora; concurrency tested with 16 local parallel processes on MariaDB, not on Lambda.
