# API Versioning Strategy

## Current State

All endpoints live under `/api/v1`. Authentication, food items, inventory, and reporting share this single version namespace.

## Versioning Method

**URI-based prefixing:** `/api/v1/...`, `/api/v2/...`

Chosen over header-based versioning because:
- Explicit and visible in logs, docs, and debugging.
- Cache-friendly — different URIs, no `Vary` header complexity.
- Easy to route in Laravel with `prefix('v1')` / `prefix('v2')` groups.

## Response Headers

Every API response includes:

| Header | Value | Purpose |
|--------|-------|---------|
| `API-Version` | `v1` | Confirms which version handled the request |
| `Sunset` | RFC 7231 date | Present only on deprecated versions — signals retirement date |

## What Triggers a New Version

A new version (`v2`) is created **only** for breaking changes:

- Removing or renaming a field in a response
- Changing a field's type (e.g. integer → string)
- Changing validation rules that reject previously valid input
- Removing an endpoint
- Changing error codes or response structure

## What Does NOT Trigger a New Version

These go into the current version directly:

- Adding new optional fields to responses
- Adding new endpoints
- Adding new optional request parameters
- Relaxing validation (accepting more input)
- Performance improvements
- Bug fixes

## Deprecation Policy

1. **Announce** — Add `Sunset` header with retirement date to every response on the old version. Minimum 6 months notice.
2. **Document** — Add deprecation notice to OpenAPI spec and README.
3. **Migration guide** — Publish `docs/specs/v1-to-v2-migration.md` with every breaking change and the fix.
4. **Monitor** — Track request counts on deprecated version. Notify active consumers.
5. **Retire** — After sunset date, return `410 Gone` with a JSON body pointing to the new version.

## Route Structure

```
routes/
├── api.php          # Loads versioned route files
├── api/v1.php       # Current: all v1 routes
└── api/v2.php       # Future: v2 routes (when needed)
```

Currently all routes are in `routes/api.php`. When v2 is needed, split into `routes/api/v1.php` and `routes/api/v2.php` with shared middleware.

## Consumer Contract

- Clients **must** include the version in the URL (`/api/v1/items`, not `/api/items`).
- Unversioned `/api/items` returns `404` — no implicit "latest" version.
- Clients should monitor the `Sunset` header and migrate before the date.
