# Food Inventory API

Production-grade REST API for daily food inventory management. Built with **Laravel 13**, **PHP 8.3**, **Sanctum 4**, and **MySQL 8.0**.

> This is a portfolio project demonstrating production-level architecture, security, and testing practices — not a tutorial.

## Highlights

- **Service layer architecture** — thin controllers, business logic in dedicated services
- **Integer-based decimal math** — no float bugs via `Quantity` value object
- **Idempotent writes** — UUID-keyed movements with `FOR UPDATE` locking
- **46 feature tests** (387 assertions) — including real concurrency scenarios
- **Sparse fieldsets** — `?fields=id,name,unit` and `?include=item,day` for payload control
- **Config-driven** — no magic numbers; all tunables in `config/api.php`
- **Full security stack** — HSTS, CORS, rate limiting, request tracing, N+1 prevention

## Tech Stack

| | |
|---|---|
| **Framework** | Laravel 13 (PHP 8.3+) |
| **Auth** | Sanctum 4 (bearer tokens, hashed, expiring) |
| **Database** | MySQL 8.0 (CHECK constraints, InnoDB locking) |
| **Docs** | OpenAPI 3.0 + Swagger UI |
| **CI/CD** | GitHub Actions (PHP 8.3 + 8.4, MySQL, Pint) |
| **Container** | Docker Compose (one-command setup) |

---

## Quick Start

### Docker (recommended)

```sh
cp .env.example .env
php artisan key:generate
docker compose up -d
```

App → `http://localhost:8000` · MySQL → port `3306` · Demo data seeded automatically.

```sh
docker compose down -v   # tear down
```

### Local Setup

```sh
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

Requires PHP 8.3+, MySQL 8.0+, Composer.

### Demo Account

| | |
|---|---|
| **Email** | `demo@example.com` |
| **Password** | `password123A` |

Includes 8 food items, 2 inventory days (1 closed, 1 open), and 52 realistic movements.

```sh
php artisan migrate:fresh --seed     # full reset
php artisan db:seed --class=DemoSeeder   # add demo data only
```

---

## API Overview

**Base URL:** `/api/v1` · **Auth:** `Authorization: Bearer <token>` · **Format:** JSON

### Endpoints

| Method | Path | Description |
|---|---|---|
| POST | `/auth/register` | Create account → token |
| POST | `/auth/login` | Authenticate → token |
| POST | `/auth/logout` | Revoke current token |
| GET | `/auth/me` | Current user profile |
| GET | `/items` | List food items (paginated) |
| POST | `/items` | Create food item |
| GET | `/items/{id}` | Get food item |
| PUT | `/items/{id}` | Update food item |
| DELETE | `/items/{id}` | Deactivate food item |
| POST | `/inventory-days` | Open inventory day |
| POST | `/inventory-days/{date}/movements` | Record movement |
| POST | `/inventory-days/{date}/close` | Close inventory day |
| GET | `/inventory/history` | Movement history (paginated) |
| GET | `/inventory/daily-summary` | Daily inventory report |
| GET | `/health` | Health check (DB + cache) |

### Query Parameters

| Param | Endpoints | Example |
|---|---|---|
| `per_page` | List endpoints | `?per_page=10` (max 100) |
| `fields` | Items, History | `?fields=id,name,unit` |
| `include` | History | `?include=item,day` |
| `item_id` | History | `?item_id=3` |
| `date` | History, Summary | `?date=2026-09-09` |
| `type` | History | `?type=out` |

### Response Format

```json
// Success
{"data": { ... }, "links": { ... }, "meta": { ... }}

// Error
{"message": "Validation failed.", "errors": {"name": ["The name field is required."]}}
```

### Interactive Docs

Full OpenAPI 3.0 spec with Swagger UI:

```sh
php artisan serve   # then visit /docs/index.html
```

Or standalone:

```sh
python3 -m http.server 8787 --directory docs
```

---

## Architecture

```
Request → Middleware → Controller → Service → Model/DB
                         ↓
                     FormRequest (validation)
                     Resource (response shaping)
                     Policy (authorization)
```

### Directory Structure

```
app/
├── Http/
│   ├── Controllers/Api/V1/   # Thin, versioned controllers
│   ├── Middleware/             # Security, CORS, tracing
│   ├── Requests/              # Input validation
│   └── Resources/             # JSON response shaping
├── Models/                    # Eloquent models
├── Policies/                  # Authorization (user-scoped)
├── Services/                  # Business logic layer
│   ├── AuthService.php
│   ├── FoodItemService.php
│   └── Inventory.php
└── Support/
    └── Quantity.php            # Integer-based decimal math

config/api.php                  # All API constants
docs/openapi.yaml               # OpenAPI 3.0 spec
```

### Database Schema

| Table | Purpose |
|---|---|
| `users` | Accounts + per-user inventory lock row |
| `food_items` | Items with unit, minimum stock, active flag |
| `inventory_days` | Daily inventory periods (open/closed) |
| `inventory_movements` | Immutable ledger entries (opening, in, out, waste, adjustment) |
| `personal_access_tokens` | Sanctum hashed tokens |

**Constraints:** user/date uniqueness, one opening per item/day, idempotency keys, composite foreign keys ensuring same owner, valid movement signs, restrictive deletes.

### Key Design Decisions

| Decision | Why |
|---|---|
| **Service layer** (not repository) | Services own business rules + transactions; models stay as data mappers |
| **Integer math** (`Quantity`) | `12.500` → `12500` internally; no IEEE 754 rounding in financial-style quantities |
| **Idempotency keys** | Safe retries on network failures; same key + same content = original response |
| **FOR UPDATE locking** | Serializes writes per-user; deadlock retry up to 3× |
| **Immutable movements** | Model-level `updating`/`deleting` blocked; ledger is append-only |
| **Sparse fieldsets** | Clients fetch only what they need — DB-level `select()` + resource filtering |
| **Config-driven constants** | All rate limits, pagination, password policy in `config/api.php` |

---

## Security & Production Features

### Middleware Stack

| Middleware | Purpose |
|---|---|
| `TrackRequest` | UUID `X-Request-Id`, `X-Response-Time`, structured log correlation |
| `SecurityHeaders` | HSTS, `nosniff`, `DENY`, referrer policy, permissions policy |
| `ForceJsonResponse` | API always returns JSON (even errors) |
| `auth:sanctum` | Bearer token authentication |
| `throttle:api` | Rate limiting per user |

### Rate Limiting

| Scope | Limit | Config Key |
|---|---|---|
| Auth (per IP) | 20/min | `RATE_LIMIT_AUTH_IP` |
| Auth (per account) | 5/min | `RATE_LIMIT_AUTH_ACCOUNT` |
| API (per user) | 120/min | `RATE_LIMIT_API_USER` |

### Other Production Features

- **CORS** — env-driven via `CORS_ALLOWED_ORIGINS`, locked-down headers/methods, 2hr preflight cache
- **Trusted proxies** — configured for load balancer deployments
- **Structured logging** — daily rotation (14 days), JSON channel for production (`daily-json`)
- **Token pruning** — expired Sanctum tokens purged daily at 02:00
- **N+1 prevention** — global `preventLazyLoading()`; logs warning in production, throws in dev
- **Health check** — `GET /health` with DB + cache latency checks (200 healthy / 503 degraded)

---

## Inventory Rules

1. **Balance formula:** `closing = opening + in - out - waste + adjustment`
2. **Chronological days:** close the previous day before opening the next
3. **Carry-forward:** opening a day auto-creates opening movements from the last closed day's balances
4. **Immutable history:** closed days reject all writes; corrections go on a new open day
5. **Idempotent retries:** same UUID + same content = original response (200); different content = 409
6. **Soft delete:** `DELETE` deactivates items; history and balances persist
7. **Low stock:** flagged when `closing_stock < minimum_stock`
8. **Unit safety:** unit changes rejected after first ledger entry; different units never summed

---

## Testing

```sh
php artisan test --compact
```

**46 tests · 387 assertions** covering:

- Auth flow (register, login, logout, token expiry, rate limiting)
- CRUD with ownership isolation (user A can't see user B's data)
- Full inventory workflow (open → record → close → carry forward)
- Edge cases (negative stock, overflow, duplicate days, skipped dates)
- Idempotency (replay same key, conflict on different content)
- Concurrency (real parallel transactions with `FOR UPDATE` verification)
- Sparse fieldsets (`?fields=` filtering, `?include=` control)
- Security (SQL injection shapes, mass assignment, missing auth)

---

## CI/CD

GitHub Actions pipeline (`.github/workflows/ci.yml`):

- **Test matrix:** PHP 8.3 + 8.4 against MySQL 8.0
- **Lint:** Laravel Pint code style check
- **Runs on:** push and pull request

---

## Documentation

| Document | Description |
|---|---|
| [OpenAPI Spec](docs/openapi.yaml) | Full API specification (13 endpoints) |
| [Swagger UI](docs/index.html) | Interactive API explorer |
| [Developer Guide](docs/DEVELOPER_GUIDE.md) | Architecture patterns, feature template, coding rules |
| [API Versioning](docs/specs/api-versioning.md) | URI-based versioning strategy + deprecation policy |
| [RBAC Spec](docs/specs/rbac.md) | Role-based access control design (not yet implemented) |
| [Production Env](.env.production.example) | Production environment template |

---

## Production Deployment

See `.env.production.example` for the production environment template. Key settings:

```env
APP_ENV=production
APP_DEBUG=false
LOG_STACK=daily-json
CORS_ALLOWED_ORIGINS=https://yourdomain.com
```

**Requirements:** PHP 8.3+, MySQL 8.0+, HTTPS, separate migration credentials, shared cache for rate limits across instances.

---

## License

This project is open-source for portfolio and educational purposes.
