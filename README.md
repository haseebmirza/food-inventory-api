# Daily Food Inventory API

A small Laravel 13 REST API using Sanctum bearer tokens and MySQL 8.0.16+ (enforced CHECK constraints). PHP 8.3+ on a 64-bit runtime is required. No frontend is needed.

## Quick start (Docker)

No PHP or MySQL needed on your machine — just Docker:

```sh
cp .env.example .env
php artisan key:generate   # or set APP_KEY manually in .env
docker compose up -d
```

App runs at `http://localhost:8000`. MySQL is on port `3306`. The `migrate` service seeds the demo data automatically.

To tear down:

```sh
docker compose down -v
```

## Local setup (without Docker)

The current workspace has `.env`, an application key, and migrated local database `daily_food_inventory`. Local MySQL is 8.0.33. Root with an empty password is a local-development setting only.

For a new checkout:

```sh
composer install
cp .env.example .env
php artisan key:generate
# Create the MySQL database configured in .env, then:
php artisan migrate --seed
php artisan serve
```

### Demo credentials

Seeding creates a ready-to-use demo account with 8 food items, 2 inventory days, and realistic movements:

| | |
|---|---|
| **Email** | `demo@example.com` |
| **Password** | `password123A` |

To reseed from scratch:

```sh
php artisan migrate:fresh --seed
```

To seed only the demo data (without resetting):

```sh
php artisan db:seed --class=DemoSeeder
```

The demo user has: 8 items (Chicken, Rice, Oil, Eggs, Milk, Tomatoes, Onions, Butter), yesterday's closed day with carried-forward balances, and today's open day with deliveries, usage, waste, and adjustments.

Use HTTPS when deployed. Set `APP_ENV=production`, `APP_DEBUG=false`, your own `APP_KEY`, database credentials and `APP_URL`. Grant the runtime database account only the privileges it needs; migration credentials should be separate. Keep MySQL's default InnoDB REPEATABLE READ isolation (used for consistent report reads). Configure a shared cache for rate limits across application instances; the default database cache supports this. Token lifetime is `SANCTUM_EXPIRATION` minutes, default 1440. Dates use the application's UTC timezone.

### Production middleware

- **Security headers** (`SecurityHeaders`) — adds `Strict-Transport-Security`, `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy`, `Permissions-Policy`; strips `X-Powered-By` and `Server`.
- **Force JSON** (`ForceJsonResponse`) — sets `Accept: application/json` on all API requests so Laravel always returns JSON errors.
- **Trusted proxies** — accepts forwarded headers from any proxy (`*`); tighten to specific IPs in production if needed.
- **CORS** — configured via `CORS_ALLOWED_ORIGINS` env var (comma-separated). Defaults to `*` for local development. Exposes rate-limit headers. Preflight cache: 2 hours.

### Interactive API docs

Swagger UI served from `docs/index.html` backed by `docs/openapi.yaml`:

```sh
python3 -m http.server 8787 --directory docs
```

## Developer Guide

New to this codebase? Read **[docs/DEVELOPER_GUIDE.md](docs/DEVELOPER_GUIDE.md)** — it covers the architecture patterns, step-by-step feature template, coding rules, and a merge checklist.

## Architecture and database

Controllers handle HTTP concerns, Form Requests validate inputs, API Resources explicitly select output fields, and `FoodItemPolicy` enforces ownership. The single `Inventory` service exists to keep transaction/locking rules and ledger arithmetic together. `Quantity` converts decimal strings to integer thousandths; calculations never use binary floating-point quantities.

| Table | Purpose and relationships |
| --- | --- |
| `users` | Account and per-account inventory lock |
| `food_items` | Belongs to a user; name, unit, minimum stock, active, timestamps |
| `inventory_days` | Belongs to a user; date and closed timestamp |
| `inventory_movements` | Belongs to an item, day and creator; type, decimal quantity, note, retry key, timestamps |
| `personal_access_tokens` | Sanctum's hashed, expiring credentials |

Movement date is normalized through `inventory_day_id` and is included in API responses. A mutable `current_stock` field is not used. Opening movements are checkpoints; do not add openings across multiple days to calculate lifetime receipts.

Existing Laravel starter tables (`password_reset_tokens`, `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`) were preserved. They are not additional inventory abstractions.

Database constraints enforce:

- One day per user/date, and one open day per user via a nullable generated unique key.
- One opening movement per item/day via a nullable generated unique key.
- One idempotency key per user.
- Composite foreign keys ensuring the movement's item and day have the same owner; creator must equal owner.
- Valid signs for each movement type and nonnegative minimum stock.
- Restrictive deletion of referenced users, items and days.

Indexes support user/date lookup, ordered user lists, item/type history, and day/item aggregation. The full ledger is never loaded for balance calculation; only the requested day's movements are aggregated in SQL. History eager loads items and days and is paginated.

## API contract

Prefix: `/api/v1`. Send `Content-Type: application/json`. Protected endpoints require `Authorization: Bearer <token>`.

Success uses `{"data": ...}`. Paginated resources also include `links` and `meta`. Errors use `{"message":"...","errors":{...}}`. `204` responses have no body. Expected errors: `401` authentication, `404` missing or foreign record, `409` inventory/state conflict, `422` validation, `429` throttling. Unexpected `500` messages do not expose exceptions or SQL.

| Method | Path | Body / behavior |
| --- | --- | --- |
| POST | `/auth/register` | `name`, `email`, `password`, `password_confirmation`; returns user and token, 201 |
| POST | `/auth/login` | `email`, `password`; returns user and token |
| POST | `/auth/logout` | Revokes current token only, 204 |
| GET | `/auth/me` | Current user |
| GET | `/items` | Paginated; includes inactive items |
| POST | `/items` | `name`, `unit`, `minimum_stock`, optional `active`; 201 |
| GET | `/items/{item}` | Owned item |
| PUT | `/items/{item}` | Required `name`, `unit`, `minimum_stock`; optional `active` |
| DELETE | `/items/{item}` | Deactivates, preserves record; 204 |
| POST | `/inventory-days` | `date`, optional `openings: [{item_id, quantity}]`; 201 |
| POST | `/inventory-days/{date}/movements` | `item_id`, `type`, `quantity`, optional `note`; UUID `Idempotency-Key` header required |
| POST | `/inventory-days/{date}/close` | Closes the owned day; repeat is harmless |
| GET | `/inventory/history` | Optional `item_id`, `date`, `type`, `page`, `per_page` |
| GET | `/inventory/daily-summary` | Required `date=YYYY-MM-DD` |

List pagination defaults to 25, maximum 100, ordered by ID (history newest first). History is the public movement-reading endpoint; no movement edit/delete endpoints exist. A day is addressed by the authenticated user's date, never an unscoped client-supplied day ID.

Quantities, including `minimum_stock`, **must be JSON strings**, e.g. `"0"`, `"12.5"`, `"12.500"`. Responses always have three decimal places. Up to `999999999.999` per movement or item balance; exponent notation and greater precision are rejected. Units: `piece`, `kg`, `liter`, `portion`. Fractional quantities are allowed for every unit. Passwords require at least 12 characters including letters and numbers, with a 72-byte bcrypt limit. Emails are trimmed and lowercased.

Example item:

```json
{"name":"Chicken Biryani","unit":"portion","minimum_stock":"20","active":true}
```

Example first day:

```json
{"date":"2026-09-09","openings":[{"item_id":1,"quantity":"100"}]}
```

Example sale body (send a new UUID in `Idempotency-Key`):

```json
{"item_id":1,"type":"out","quantity":"80","note":"Lunch sales"}
```

Example correction on an open day:

```json
{"item_id":1,"type":"adjustment","quantity":"-2.500","note":"Correction to September 9 physical count"}
```

## Inventory rules and assumptions

1. `closing = opening + in - out - waste + adjustment`. Incoming/outgoing/waste must be positive; adjustments are signed and nonzero, with a required note. Initial openings may be zero. Negative balances and overflow return 409 without writing a movement.
2. Open days move forward chronologically. Close the last recorded day before opening another. Future days and insertion into earlier history are rejected. Skipped dates are allowed and use the last recorded closed balance; no synthetic days are created.
3. Opening a day creates one opening movement for each active item, and carries existing balances for inactive items too. Supply initial quantities for items with no ledger history through `openings`; otherwise their initial quantity is zero. Existing balances cannot be overridden by this payload.
4. An item added after a day was opened must receive its first `opening` movement before in/out/waste/adjustment. It enters that day's report when its opening is recorded. Existing opening movements cannot be replaced; use a documented adjustment for a mistaken initial count.
5. Closing marks the day closed after validating ledger balances. Its figures remain reconstructible from immutable movements, without a redundant daily-balance table. Retry of close returns the existing closure. Further writes, including adjustments, are rejected; corrections belong to a subsequent open day.
6. A retry with the same UUID and normalized payload returns the original movement with 200, even after closure or deactivation. First creation returns 201. Reusing a UUID for a different date/item/type/quantity/note returns 409. Different UUIDs represent different business operations; clients must persist and reuse their retry key.
7. DELETE deactivates an item; its history and balance remain visible and carry forward. Reactivation is allowed. Unit changes are rejected after the first ledger entry. Renaming and minimum-stock changes remain allowed.
8. Daily reports contain per-item opening, incoming, outgoing, waste, adjustments, closing, and low-stock flags. Totals are grouped by unit; different units are never summed together. `closing < minimum_stock` is low stock; equality is not. Inactive items with ledger history are included so stock is not hidden.
9. Item name, active status and minimum-stock threshold in historical reports reflect **current item metadata**; historical quantities and units remain stable. Metadata revisions are not separately audited in this v1.
10. Empty days can be opened and closed. A missing summary day returns 404. Reports are read-only and do not create days or stock.

## Concurrency and security review

Every inventory write and item mutation starts a database transaction and locks the owning `users` row with `SELECT ... FOR UPDATE`. Mutable state is read after acquiring that lock. The lock is held through insert/close/update and commit. This serializes writes for one user while other users remain independent. Deadlocks are retried up to three times. Unique constraints are the additional backstop for duplicate days/openings/retry keys. Summary reads use a transaction for a consistent MySQL snapshot.

Explicit review outcomes:

- IDOR/BOLA: every item/day/history lookup is user-scoped; foreign IDs return 404. Policies additionally cover item operations. Cross-user reads, writes, closes, reports and movement filters are tested.
- Mass assignment: only validated fields enter fillable attributes. Ownership, creator, timestamps, closure and token fields are assigned by the server; spoofed ownership inputs are rejected.
- SQL injection: filter values are bound; type/unit allowlists and numeric route constraints apply. Raw aggregation aliases come from server constants, not client input. Injection-shaped identifiers and filters are tested.
- Authentication: bearer-only Sanctum configuration, hashed passwords and stored tokens, expiry, current-token logout, generic invalid-credential responses, and login/register throttling. Missing-token requests return JSON 401 even without an Accept header. User Resources never return password hashes or remember tokens.
- Leakage: queries and reports are scoped, outputs are explicitly selected, and unexpected errors are generic even with debug configuration. Token responses use `Cache-Control: no-store`.
- History: no destructive inventory API; model update/delete guards and restrictive foreign keys protect ordinary application paths. Raw SQL or privileged database operators can bypass model guards. Production access controls and tested backups are still necessary; this is not tamper-evident storage.

## Tests and verification

Create `daily_food_inventory_test` in local MySQL. `phpunit.xml` targets that database. The base test class checks the connection/database and rejects a DB URL override **before database refresh hooks run**. Never point tests at production. Concurrency tests need permission to inspect their MySQL sessions using `SHOW PROCESSLIST` (the local root account has this).

```sh
php artisan test --compact
```

Tests cover auth and expiry, all protected routes, item CRUD/deactivation and ownership policies, exact decimals, carry-forward, skipped dates, low stock, history filters/pagination, invalid quantities/dates, opening uniqueness, retry keys, inactive items, units, negative/overflow balances, unchanged closed history, rollback after partial inserts, safe errors, query counts and four real concurrent scenarios.

The concurrent tests boot two separate PHP HTTP kernels, hold the user lock in the parent, observe both MySQL sessions waiting on `FOR UPDATE`, then release the lock. They test overselling, duplicate retries, duplicate day creation, and closing versus selling. No mocked locks or sequential-only concurrency claims.

See [VERIFICATION.md](VERIFICATION.md) for exact executed setup/check commands, results, changed files and remaining limitations.
