# Role-Based Access Control (RBAC) — Feature Spec

## Problem

Currently every authenticated user has full access to all API operations on their own data. There is no concept of teams, shared inventories, or restricted roles. In a real food business, multiple people (owner, manager, kitchen staff) need different access levels to the same inventory.

## Goals

1. Users belong to a **team** (restaurant, kitchen, warehouse).
2. Each user has a **role** within the team that restricts what they can do.
3. Existing single-user data stays intact (migrated to a personal team).
4. No external package dependency — built with Laravel's native Gate/Policy system.

## Roles

| Role | Description | Permissions |
|------|-------------|-------------|
| **owner** | Business owner, created the team | Full access. Manage team members, roles, items, inventory, reports. Delete team. |
| **manager** | Shift/inventory manager | Manage items, open/close days, record movements, view reports. Cannot manage team members or delete team. |
| **staff** | Kitchen/floor staff | Record movements (in, out, waste) on open days. View items and own movement history. Cannot create/edit items, open/close days, or view full reports. |
| **viewer** | Read-only auditor | View items, movement history, and reports. Cannot create or modify anything. |

## Data Model

### New tables

#### `teams`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | |
| name | string(120) | Team/business name |
| owner_id | bigint FK → users | Creator of the team |
| created_at | timestamp | |
| updated_at | timestamp | |

#### `team_user` (pivot)
| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | |
| team_id | bigint FK → teams | |
| user_id | bigint FK → users | |
| role | enum: owner, manager, staff, viewer | |
| created_at | timestamp | |
| updated_at | timestamp | |
| **unique** | (team_id, user_id) | One role per team per user |

### Modified tables

#### `food_items`
- Add `team_id` (bigint FK → teams), nullable initially for migration, then NOT NULL.
- Drop reliance on `user_id` as sole ownership — items belong to a **team**.

#### `inventory_days`
- Add `team_id` (bigint FK → teams).
- Days are team-scoped, not user-scoped.

#### `inventory_movements`
- Keep `created_by` / `user_id` for audit trail (who recorded the movement).
- Scoping moves from user → team.

## API Changes

### New endpoints

| Method | Path | Role Required | Description |
|--------|------|---------------|-------------|
| POST | `/v1/teams` | any authenticated | Create a team (caller becomes owner) |
| GET | `/v1/teams` | any authenticated | List user's teams |
| GET | `/v1/teams/{team}` | any team member | Team details |
| PUT | `/v1/teams/{team}` | owner | Update team name |
| DELETE | `/v1/teams/{team}` | owner | Delete team (soft) |
| GET | `/v1/teams/{team}/members` | owner, manager | List members |
| POST | `/v1/teams/{team}/members` | owner | Invite/add member with role |
| PUT | `/v1/teams/{team}/members/{user}` | owner | Change member role |
| DELETE | `/v1/teams/{team}/members/{user}` | owner | Remove member |

### Modified endpoints

All existing item/inventory/report endpoints gain a `team` scope:

| Current Path | New Path | Notes |
|---|---|---|
| `/v1/items` | `/v1/teams/{team}/items` | Team-scoped |
| `/v1/items/{item}` | `/v1/teams/{team}/items/{item}` | Team-scoped |
| `/v1/inventory-days` | `/v1/teams/{team}/inventory-days` | Team-scoped |
| `/v1/inventory-days/{date}/movements` | `/v1/teams/{team}/inventory-days/{date}/movements` | Team-scoped |
| `/v1/inventory-days/{date}/close` | `/v1/teams/{team}/inventory-days/{date}/close` | Team-scoped |
| `/v1/inventory/history` | `/v1/teams/{team}/inventory/history` | Team-scoped |
| `/v1/inventory/daily-summary` | `/v1/teams/{team}/inventory/daily-summary` | Team-scoped |

> **Backward compatibility:** Keep the old `/v1/items` etc. routes working by defaulting to the user's personal team. Deprecate after one version cycle.

## Permission Matrix

| Action | owner | manager | staff | viewer |
|--------|:-----:|:-------:|:-----:|:------:|
| Manage team settings | ✅ | ❌ | ❌ | ❌ |
| Manage members | ✅ | ❌ | ❌ | ❌ |
| Create/edit/deactivate items | ✅ | ✅ | ❌ | ❌ |
| Open/close inventory days | ✅ | ✅ | ❌ | ❌ |
| Record movements | ✅ | ✅ | ✅ | ❌ |
| View items | ✅ | ✅ | ✅ | ✅ |
| View movement history | ✅ | ✅ | own only | ✅ |
| View daily summary/reports | ✅ | ✅ | ❌ | ✅ |

## Implementation Plan

### Phase 1 — Schema & Models
1. Create migration: `teams`, `team_user` pivot, add `team_id` to `food_items` and `inventory_days`.
2. Create `Team` model with relationships.
3. Add `teams()` and `currentTeam()` to `User` model.
4. Data migration: create a personal team for each existing user, assign their items/days to it.

### Phase 2 — Middleware & Authorization
1. Create `EnsureTeamMember` middleware — resolves `{team}` route param, verifies membership, binds role to request.
2. Create `TeamPolicy` — controls team-level actions (update, delete, manage members).
3. Update `FoodItemPolicy` — check team membership + role instead of direct user ownership.
4. Create `InventoryPolicy` — gate open/close/movement by role.

### Phase 3 — Controllers & Routes
1. Create `TeamController` and `TeamMemberController`.
2. Refactor existing controllers to scope queries by `team_id` instead of `user_id`.
3. Add team-scoped route group with `EnsureTeamMember` middleware.
4. Keep legacy user-scoped routes as aliases to personal team.

### Phase 4 — Tests
1. Test each role against every endpoint (permitted and denied).
2. Test cross-team isolation (team A member cannot access team B data).
3. Test personal team migration and backward-compatible routes.
4. Test role changes and member removal revoke access immediately.

## Migration Safety

- `team_id` columns added as **nullable** first, backfilled, then set to NOT NULL in a second migration.
- Existing users get a personal team auto-created via a data migration.
- No data loss — `user_id` columns kept for audit trail.
- Legacy routes remain functional during transition.

## Out of Scope (Future)

- Per-item or per-day granular permissions.
- Team invitations via email/link (for now: owner adds by user ID).
- Multiple roles per user per team.
- Team billing or subscription tiers.
