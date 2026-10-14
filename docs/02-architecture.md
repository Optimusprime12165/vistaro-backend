# VISTARO — Phase 2: Backend Architecture

Status legend: **Written + `php -l` clean** means syntax-checked only. **Nothing here has been booted, migrated or tested against Laravel** (no Composer/Laravel/MySQL in the authoring environment).

## 0. Assumed versions (unverified, stated not guessed)
PHP ≥ 8.2, Laravel 12 (also valid for 11: slim `bootstrap/app.php`, `bootstrap/providers.php`), Sanctum 4 (installed by `php artisan install:api`), MySQL 8 / Aurora MySQL. If your skeleton differs, only `bootstrap/app.php` and `bootstrap/providers.php` need adapting.

## 1. Scaffolding (run locally, once)
```bash
composer create-project laravel/laravel vistaro-api "^12.0"
cd vistaro-api
php artisan install:api            # installs Sanctum, creates routes/api.php + personal_access_tokens migration
# copy this overlay over the skeleton (app/, bootstrap/, config/vistaro.php, routes/, docs/)
php artisan config:clear && php artisan route:list --path=api/v1
```
Expected `route:list`: `GET api/v1/health`, `GET api/v1/ready`. Phase 3 adds migrations.

## 2. Layering (one direction only)
```
routes → Middleware → FormRequest (validate/authorize) → Controller (thin)
       → Service (business rules, transactions) → Eloquent Models
       → API Resource (output shape) → ApiResponse (envelope)
```
Controllers never contain business logic; services never build HTTP responses (they throw `ApiException` subclasses). No repository layer: Eloquent is already the data-access abstraction, and a repository per model would only add indirection. If a query becomes complex it becomes a named query scope or a dedicated query class (e.g. `ProductCatalogQuery`), not a repository.

## 3. Reuse: where CRUD is shared and where it is not
| File | Role |
|---|---|
| `Contracts/CrudInterface` | `index/show/store/update/destroy` signatures, defined once. `index` returns a database-level paginator. |
| `Abstracts/AbstractCrudService` | Implements it. Subclass declares **whitelists**: `searchable()`, `filterable()`, `sortable()`, `defaultSort()`, `with()`, `paginationMode()` (`paginate` / `simple` / `cursor`). Provides stable ordering (PK tie-breaker, required for cursors), LIKE-escaping, transactional store/update/destroy, lifecycle hooks (`beforeStore`, `afterUpdate`, `beforeDestroy`, …). |
| `DTOs/ListQuery` + `Http/Requests/ListRequest` | One validated shape for `q, filters, sort, direction, per_page, page, cursor`. **Default 10; 20 accepted; max 100 (HTTP 422 above)**; sort field must be in the endpoint's whitelist. |

Will use it (Phase 5): categories, sub-categories, customers (read-only index), login events (cursor). **Will not** use it: OTP, login, cart, checkout, stock adjustments, order transitions, shipping. Products use a specialised service (images, sizes, attributes, cache invalidation) that extends it only for list/show plumbing.

Search is **prefix** (`term%`) by default so B-tree indexes work; `searchContains()` opts in to `%term%` for small tables only.

## 4. Response conventions (`Http/Responses/ApiResponse`)
```jsonc
// success
{ "success": true, "message": null, "data": {...}, "meta": {...}, }
// list
{ "success": true, "data": [...], "meta": { "current_page":1,"per_page":10,"total":33,"last_page":4,"from":1,"to":10 }, "links": {"next":"...","prev":null} }
// simple pagination meta : current_page, per_page, has_more
// cursor pagination meta : per_page, next_cursor, prev_cursor, has_more
// error
{ "success": false, "message": "The given data was invalid.", "errors": { "mobile": ["..."] } }
```
Status codes: 200/201/204, 401 unauthenticated, 403 wrong principal / suspended / policy, 404, 409 conflict (`ConflictException`, `InsufficientStockException`), 422 validation / invalid state transition, 429 with `Retry-After` and `errors.retry_after` (`TooManyAttemptsException`), 503 readiness, 500 generic. `ApiExceptionRenderer` (registered in `bootstrap/app.php`) is the only exception→JSON mapping; unexpected exceptions return a generic 500 with no SQL/trace/class names, and are still logged.

**Pagination strategy per table** (decided now, built in Phase 5–7):
| Listing | Mode | Why |
|---|---|---|
| Admin products, categories, sub-categories | `paginate` | Small/medium, page numbers + total useful |
| Admin orders, login events, shipment events | `cursor` | Append-heavy, newest-first; avoids skipped/duplicated rows and COUNT(*) |
| Customer "my orders" | `simple` | "Load more" button, no total needed |
| Admin customers | `paginate` | Needs total |
| Storefront products | `paginate` (cached facets) | Filter/sort UI shows totals |

## 5. Authentication design (Sanctum, bearer tokens)

### Two identities, never mixed
- `admins` table / `Admin` model; `users` table / `User` model (customers). Different tables ⇒ a token's `tokenable_type` is `App\Models\Admin` or `App\Models\User`.
- Route protection = `auth:sanctum` **and** `principal:admin|customer` (`Http/Middleware/EnsurePrincipal`). It checks the **tokenable class**, the **token ability** (`admin` or `customer`), and that the account is active (`ActiveAccount::isActive()`), so suspension takes effect immediately. A client-supplied role is never trusted.
- Fine-grained admin permissions (later) are additional abilities / policies; the baseline is `admin`.

### Token lifecycle
| | Customer | Admin |
|---|---|---|
| Issued by | `POST /v1/auth/otp/verify` | `POST /v1/admin/auth/login` |
| Ability | `['customer']` | `['admin']` |
| Lifetime | `CUSTOMER_TOKEN_TTL_MINUTES` (default 30 days) | `ADMIN_TOKEN_TTL_MINUTES` (default 8 h) |
| Expiry mechanism | per-token `expires_at` set at `createToken(..., expiresAt)`; Sanctum rejects expired tokens. `config/sanctum.php` `expiration` stays `null`. Prune with `sanctum:prune-expired --hours=24` on a schedule (EventBridge → Lambda artisan). |
| Revocation | logout deletes the **current** token; "log out everywhere" deletes all; admin login revokes the admin's previous tokens (single active session); customer tokens capped at 5 (oldest revoked); deactivating an account blocks it at once via `ActiveAccount`. |
| Rate limits | `otp-request`, `otp-verify`, `admin-login` named limiters (`VistaroServiceProvider`) + DB-backed OTP business limits |

### Where the browser keeps the token — and the risks (decision for Phase 8)
A bearer token held in JavaScript is readable by any script running on the page, so **an XSS bug = account takeover for the token's lifetime**. httpOnly cookies avoid that but need a same-site/CSRF design or a Next.js server proxy, which is excluded by your "no Next.js backend" rule. Therefore:

| Token | Storage | Rationale |
|---|---|---|
| Admin | `sessionStorage` | Cleared when the tab closes; 8 h max; highest privilege ⇒ shortest exposure. Cost: new tab / restart = log in again. |
| Customer | `localStorage` | Shoppers expect to stay signed in; 30-day token with limited blast radius (own orders/addresses only; no payment data stored). |

Mitigations that will be implemented: no `dangerouslySetInnerHTML` with user data (React escapes by default; I will grep for it), strict CSP header from `next.config` (`script-src 'self'`, no inline except the nonce'd Next bootstrap), image URLs only through the API client, token never put in URLs or logs, `Authorization` header only to the configured API origin, explicit CORS allow-list on Laravel (`FRONTEND_URL`), immediate logout on any 401, short admin TTL. Residual risk remains and is accepted in this design; if you later prefer cookies, the swap is isolated to `lib/api.ts`.

## 6. Provider abstractions (composition root = `VistaroServiceProvider`)
| Abstraction | Dev implementation | Production switch |
|---|---|---|
| `OtpDeliveryInterface` | `LogOtpDelivery` — writes OTP to log **only in `local`/`testing`**, throws elsewhere | add SMS class + one `match` arm; `OTP_DELIVERY=...` |
| `DeliveryProviderInterface` | `MockDeliveryProvider` — AWBs `MOCK-…`, `isMock=true`, label states "NOT A REAL COURIER BOOKING", HMAC-verified mock webhook | real courier class; `DELIVERY_PROVIDER=...` |
| `PaymentGatewayInterface` | `MockPaymentGateway` — **never returns `paid`**; UPI/card orders stay `pending`; COD bypasses the gateway | real gateway with hosted/tokenised checkout; `PAYMENT_GATEWAY=...` |
| `ImageStorageInterface` | `DiskImageStorage` on any Laravel disk (`public` locally, `s3` on AWS via `IMAGES_DISK`) | `.env` only |

Raw card numbers and CVVs are not accepted by any request class and no table will have a column for them. The checkout UI's card fields are removed in Phase 8.

## 7. Route organisation (`routes/api.php`, all under `/api/v1`)
`public.php` (health, ready, catalog) · `customer.php` (public OTP endpoints + `auth:sanctum,principal:customer` group) · `admin.php` under `/admin` (public login + `principal:admin` group) · `webhooks.php` under `/webhooks` (no Sanctum; signature-verified). Explicit routes, named (`v1.…`), route-model binding, global `throttle:api` (120/min). No unrestricted destructive endpoints. Stub comments in each file mark which phase fills them.

## 8. Lambda / stateless notes already reflected
No sessions/cookies, bearer auth only; rate limiter and cache must use a **shared** store (Redis/DB) — documented in `VistaroServiceProvider`; images go through the disk abstraction (S3); OTP limits live in the DB, not memory; `/ready` checks DB and cache. Deployment tooling (Bref etc.) and VPC guidance come in Phase 11.

## 9. Environment keys introduced so far
```
PAGINATION_DEFAULT=10   PAGINATION_MAX=100
STOCK_LOW_THRESHOLD=5   STOCK_SHOW_EXACT_UP_TO=10
OTP_DELIVERY=log        OTP_TTL_SECONDS=300   OTP_MAX_VERIFY_ATTEMPTS=5
CUSTOMER_TOKEN_TTL_MINUTES=43200   ADMIN_TOKEN_TTL_MINUTES=480
DELIVERY_PROVIDER=mock  DELIVERY_WEBHOOK_SECRET=   SHIPPING_FREE_ABOVE=1999  SHIPPING_FLAT_FEE=99
PAYMENT_GATEWAY=mock    IMAGES_DISK=public   CATALOG_CACHE_TTL=300
```
(`FRONTEND_URL` for CORS arrives with Phase 4.)

## 10. Assumptions, blockers, open items
- `routes/console.php` exists in the stock skeleton (referenced in `bootstrap/app.php`).
- `App\Models\Admin` / `App\Models\User` do not exist yet (Phase 3); `EnsurePrincipal` references them and routes using it are empty until then, so nothing breaks at boot. **`Admin` and `User` must implement `ActiveAccount` and use `HasApiTokens`.**
- Product `categories` / `sub_categories` mapping from the audit (Topwear/Bottomwear/Outerwear → t-shirts…sweatshirts) is carried into Phase 3 seeders.
- Blockers: none for writing code. Verification blocker: cannot run Composer/Laravel/MySQL here.
