# Developer Guide

How we build features in this project. Read this before writing code.

---

## Architecture at a Glance

```
Request → Middleware → Controller → Service → Model/DB
                         ↓
                     FormRequest (validation)
                     Resource (response shaping)
                     Policy (authorization)
```

**Controllers** are thin — they parse HTTP input, call a service, and return a resource.  
**Services** hold all business logic, transactions, and database queries.  
**Resources** shape the JSON output. Controllers never return raw `response()->json()` for model data.

---

## Directory Map

```
app/
├── Http/
│   ├── Controllers/Api/V1/   # Versioned API controllers (thin)
│   ├── Middleware/             # Global + route middleware
│   ├── Requests/              # FormRequest validation classes
│   └── Resources/             # Eloquent API Resources
│       └── Concerns/          # Shared traits (e.g. FiltersFields)
├── Models/                    # Eloquent models
├── Policies/                  # Authorization policies
├── Services/                  # Business logic (the real work)
└── Support/                   # Value objects & helpers (e.g. Quantity)

config/
└── api.php                    # All API constants (rate limits, pagination, password policy)

routes/
└── api.php                    # All API routes (versioned under /v1)

tests/Feature/                 # Feature tests (one per domain)
docs/
├── openapi.yaml               # OpenAPI 3.0 spec (source of truth)
├── index.html                 # Swagger UI
└── specs/                     # Design specs (RBAC, versioning, etc.)
```

---

## Adding a New Feature (Step by Step)

Example: adding a **Supplier** resource.

### 1. Model + Migration + Factory

```bash
php artisan make:model Supplier -mf --no-interaction
```

This creates:
- `app/Models/Supplier.php`
- `database/migrations/xxxx_create_suppliers_table.php`
- `database/factories/SupplierFactory.php`

Define the table, fillable fields, relationships, and factory states.

### 2. Service

Create `app/Services/SupplierService.php` by hand. Follow the pattern in `FoodItemService`:

```php
class SupplierService
{
    public function __construct(private Inventory $inventory) {}

    public function list(User $user, ?int $perPage = null): LengthAwarePaginator
    {
        return $user->suppliers()->orderBy('id')
            ->paginate($perPage ?? config('api.pagination.default'))
            ->withQueryString();
    }

    public function create(User $user, array $data): Supplier
    {
        return DB::transaction(function () use ($user, $data) {
            return $user->suppliers()->create($data)->refresh();
        }, 3);
    }

    // find(), update(), delete() ...
}
```

**Rules:**
- All DB queries live here, never in the controller
- Wrap writes in `DB::transaction()`
- Use `Gate::authorize()` for authorization checks
- Use `config('api.*')` for any numbers (pagination, limits)

### 3. FormRequest

```bash
php artisan make:request SupplierRequest --no-interaction
```

Validate input. Prohibit `user_id` to prevent mass-assignment:

```php
public function rules(): array
{
    return [
        'name'    => ['required', 'string', 'max:120'],
        'email'   => ['required', 'email', 'max:254'],
        'user_id' => ['prohibited'],
    ];
}
```

### 4. Resource

```bash
php artisan make:class Http/Resources/SupplierResource --no-interaction
```

Extend `JsonResource`, use `FiltersFields` trait, declare `ALLOWED_FIELDS`:

```php
class SupplierResource extends JsonResource
{
    use FiltersFields;

    public const ALLOWED_FIELDS = ['id', 'name', 'email', 'created_at', 'updated_at'];

    public function toArray(Request $request): array
    {
        return $this->filterFields([
            'id'         => $this->id,
            'name'       => $this->name,
            'email'      => $this->email,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ], $request);
    }
}
```

### 5. Policy

```bash
php artisan make:policy SupplierPolicy --model=Supplier --no-interaction
```

All data is user-scoped. Follow `FoodItemPolicy` — deny as 404 if ownership doesn't match:

```php
public function view(User $user, Supplier $supplier): Response
{
    return $user->id === $supplier->user_id
        ? Response::allow()
        : Response::denyAsNotFound();
}
```

### 6. Controller (thin)

```bash
php artisan make:controller Api/V1/SupplierController --no-interaction
```

Controller only does: parse request → call service → return resource.

```php
class SupplierController extends Controller
{
    public function __construct(private SupplierService $suppliers) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return SupplierResource::collection(
            $this->suppliers->list($request->user(), $request->integer('per_page'))
        );
    }

    public function store(SupplierRequest $request): SupplierResource
    {
        return new SupplierResource(
            $this->suppliers->create($request->user(), $request->validated())
        );
    }

    // show(), update(), destroy() ...
}
```

**Never put in a controller:**
- DB queries or Eloquent calls
- Business logic or conditional rules
- `response()->json()` for model data — use Resources

### 7. Routes

Add to `routes/api.php` inside the v1 group:

```php
Route::apiResource('suppliers', SupplierController::class);
```

### 8. Tests

```bash
php artisan make:test --phpunit SupplierTest --no-interaction
```

Write **feature tests** (not unit) for API endpoints. Use factories for setup:

```php
class SupplierTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_create_and_list_suppliers(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/suppliers', [
            'name' => 'Fresh Farms', 'email' => 'farms@example.com',
        ])->assertCreated()->assertJsonPath('data.name', 'Fresh Farms');

        $this->getJson('/api/v1/suppliers')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_other_users_cannot_see_my_suppliers(): void
    {
        // ...
    }
}
```

### 9. OpenAPI Docs

Update `docs/openapi.yaml` with the new endpoints, then publish:

```bash
composer run docs:publish
```

### 10. Format & Verify

```bash
vendor/bin/pint --dirty --format agent    # Fix code style
php artisan test --compact                 # Run all tests
```

---

## Patterns & Rules

### Config Over Magic Numbers

All tunable numbers live in `config/api.php`:

```php
config('api.pagination.default')   // 25
config('api.pagination.max')       // 100
config('api.throttle.api_per_user') // 120
config('api.password.min_length')  // 12
```

Never hardcode these in services, requests, or controllers.

### User-Scoped Data

All data is scoped to the authenticated user. Always query through the user relationship:

```php
// ✅ Correct
$user->items()->findOrFail($id);

// ❌ Wrong — leaks other users' data
FoodItem::findOrFail($id);
```

### Transactions & Locking

Writes that touch shared state use `DB::transaction()` with the user lock:

```php
DB::transaction(function () use ($user, $data) {
    $this->inventory->lock($user);  // SELECT ... FOR UPDATE
    // ... safe writes here
}, 3);  // 3 retries on deadlock
```

### Quantities

Use the `Quantity` value object (`app/Support/Quantity.php`) for all decimal math. It uses integer arithmetic internally to avoid float bugs:

```php
$parsed = Quantity::parse('12.500');   // Returns int (12500)
$formatted = Quantity::format(12500);  // Returns '12.500'
```

### Idempotency

Movement creation requires `idempotency_key` (UUID). Same key + same content = return original (200). Same key + different content = 409.

### Error Responses

Use `abort()` and `abort_if()` for business rule violations. Laravel's exception handler formats them consistently:

```php
abort_if($day->closed_at !== null, 409, 'Closed days are immutable.');
```

### N+1 Prevention

`preventLazyLoading()` is enabled globally. Always eager-load relations:

```php
// ✅
$query->with(['item', 'day']);

// ❌ Throws in dev, logs warning in production
foreach ($movements as $m) {
    $m->item->name;  // Lazy load triggers violation
}
```

---

## Middleware Stack

Every API request passes through (in order):

| Middleware | Purpose |
|---|---|
| `TrackRequest` | UUID `X-Request-Id`, `X-Response-Time`, log correlation |
| `SecurityHeaders` | HSTS, nosniff, DENY, referrer policy |
| `ForceJsonResponse` | Always returns JSON (sets Accept header) |
| `auth:sanctum` | Token authentication (protected routes) |
| `throttle:api` | Rate limiting per user |

---

## Testing Conventions

- **Feature tests** for all endpoints (not unit tests)
- Use `LazilyRefreshDatabase` trait
- Use `Sanctum::actingAs($user)` for auth
- Use factories with states: `FoodItem::factory()->inactive()->create()`
- Test ownership isolation: verify user A cannot see user B's data
- Test idempotency: replay same key, expect same response
- Test concurrency: parallel writes don't corrupt data
- Run narrowest set: `php artisan test --filter=test_method_name`

---

## Checklist Before Merging

- [ ] Service handles all business logic (controller is thin)
- [ ] FormRequest validates all input (including `user_id: prohibited`)
- [ ] Resource uses `FiltersFields` trait with `ALLOWED_FIELDS`
- [ ] Policy enforces user-scoped access
- [ ] Numbers come from `config/api.php`
- [ ] Relations are eager-loaded (no lazy loading)
- [ ] Writes use `DB::transaction()` with locking where needed
- [ ] Feature tests cover happy path + edge cases + auth
- [ ] OpenAPI docs updated (`composer run docs:publish`)
- [ ] `vendor/bin/pint --dirty --format agent` passes
- [ ] `php artisan test --compact` passes
