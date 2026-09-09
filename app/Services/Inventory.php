<?php

namespace App\Services;

use App\Models\FoodItem;
use App\Models\InventoryDay;
use App\Models\InventoryMovement;
use App\Models\User;
use App\Support\Quantity;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Inventory
{
    /** All writers acquire this lock before reading inventory or changing item state. */
    public function lock(User $user): void
    {
        User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
    }

    public function open(User $user, string $date, array $openings): InventoryDay
    {
        return DB::transaction(function () use ($user, $date, $openings) {
            $this->lock($user);
            abort_if($user->days()->where('date', $date)->exists(), 409, 'This inventory day already exists.');
            $previous = $user->days()->orderByDesc('date')->first();
            abort_if($previous && ! $previous->closed_at, 409, 'Close the previous inventory day first.');
            abort_if($previous && $date <= $previous->date->format('Y-m-d'), 409, 'Inventory days must advance chronologically.');

            $initial = [];
            $openingItems = $user->items()->whereIn('id', array_column($openings, 'item_id'))->withExists('movements')->get()->keyBy('id');
            foreach ($openings as $opening) {
                $item = $openingItems->get($opening['item_id']);
                abort_unless($item, 404);
                abort_unless($item->active, 409, 'Inactive items cannot receive opening stock.');
                abort_if($item->movements_exists, 409, 'Existing stock must be carried forward automatically.');
                $initial[$item->id] = $opening['quantity'];
            }
            $balances = $previous ? $this->totals($previous)->keyBy('item_id') : collect();
            $day = new InventoryDay;
            $day->user_id = $user->id;
            $day->date = $date;
            $day->save();

            foreach ($user->items()->orderBy('id')->lazyById(config('api.inventory.chunk_size')) as $item) {
                $balance = $balances->get($item->id);
                if (! $item->active && $balance === null) {
                    continue;
                }
                $quantity = $balance ? $balance['closing_stock'] : ($initial[$item->id] ?? '0.000');
                $this->insert($user, $day, $item, 'opening', Quantity::format(Quantity::parse($quantity)),
                    $balance ? 'Carried forward from '.$previous->date->format('Y-m-d') : 'Initial opening stock', (string) Str::uuid());
            }

            return $day;
        }, 3);
    }

    /** @return array{0: InventoryMovement, 1: bool} */
    public function record(User $user, array $data): array
    {
        return DB::transaction(function () use ($user, $data) {
            $this->lock($user);
            $day = $user->days()->where('date', $data['date'])->firstOrFail();
            $item = $user->items()->findOrFail($data['item_id']);
            Gate::forUser($user)->authorize('update', $item);
            $quantity = Quantity::parse($data['quantity']);
            $normalized = Quantity::format($quantity);
            $existing = InventoryMovement::query()->where('user_id', $user->id)->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                abort_unless($existing->inventory_day_id === $day->id && $existing->food_item_id === $item->id
                    && $existing->type === $data['type'] && $existing->quantity === $normalized
                    && $existing->note === ($data['note'] ?? null), 409, 'Idempotency key was already used with different content.');

                return [$existing->load(['item', 'day']), false];
            }
            abort_if($day->closed_at !== null, 409, 'Closed days are immutable. Record a correction on an open day.');
            abort_unless($item->active, 409, 'Reactivate the item before recording movements.');
            if (($data['type'] === 'opening' && $quantity < 0)
                || (in_array($data['type'], ['in', 'out', 'waste'], true) && $quantity <= 0)
                || ($data['type'] === 'adjustment' && $quantity === 0)) {
                throw ValidationException::withMessages(['quantity' => 'Quantity must be positive; only adjustments may be negative and openings may be zero.']);
            }
            abort_if($data['type'] === 'opening' && $item->movements()->exists(), 409, 'Opening stock can only be entered once, before other movements.');
            abort_if($data['type'] !== 'opening' && ! $day->movements()->where('food_item_id', $item->id)->where('type', 'opening')->exists(), 409, 'Record opening stock for this new item first.');
            $balance = Quantity::parse($this->totals($day, $item->id)->first()['closing_stock'] ?? '0.000');
            $change = in_array($data['type'], ['out', 'waste'], true) ? -$quantity : $quantity;
            abort_if($balance + $change < 0, 409, 'Insufficient stock.');
            abort_if($balance + $change > Quantity::MAX, 409, 'Stock exceeds the supported maximum.');

            return [$this->insert($user, $day, $item, $data['type'], $normalized, $data['note'] ?? null, $data['idempotency_key'])->load(['item', 'day']), true];
        }, 3);
    }

    public function close(User $user, string $date): InventoryDay
    {
        return DB::transaction(function () use ($user, $date) {
            $this->lock($user);
            $day = $user->days()->where('date', $date)->firstOrFail();
            if ($day->closed_at !== null) {
                return $day;
            }
            foreach ($this->totals($day) as $row) {
                $balance = Quantity::parse($row['closing_stock']);
                abort_if($balance < 0 || $balance > Quantity::MAX, 409, 'The inventory contains an invalid closing balance.');
            }
            $day->closed_at = now();
            $day->save();

            return $day;
        }, 3);
    }

    /** SQL aggregates only the requested day; no historical ledger is hydrated. */
    public function totals(InventoryDay $day, ?int $itemId = null): Collection
    {
        $query = DB::table('inventory_movements as m')->join('food_items as i', 'i.id', '=', 'm.food_item_id')
            ->where('m.inventory_day_id', $day->id)->where('m.user_id', $day->user_id)
            ->select(['i.id as item_id', 'i.name', 'i.unit', 'i.minimum_stock', 'i.active'])
            ->groupBy('i.id', 'i.name', 'i.unit', 'i.minimum_stock', 'i.active')->orderBy('i.id');
        if ($itemId !== null) {
            $query->where('i.id', $itemId);
        }
        foreach (['opening' => 'opening_stock', 'in' => 'total_incoming', 'out' => 'total_outgoing', 'waste' => 'total_waste', 'adjustment' => 'total_adjustments'] as $type => $field) {
            $query->selectRaw("SUM(CASE WHEN m.type = ? THEN m.quantity ELSE 0 END) AS {$field}", [$type]);
        }

        return $query->get()->map(function (object $row): array {
            $result = (array) $row;
            foreach (['opening_stock', 'total_incoming', 'total_outgoing', 'total_waste', 'total_adjustments', 'minimum_stock'] as $field) {
                $result[$field] = Quantity::format(Quantity::parse((string) $result[$field]));
            }
            $closing = Quantity::parse($result['opening_stock']) + Quantity::parse($result['total_incoming'])
                - Quantity::parse($result['total_outgoing']) - Quantity::parse($result['total_waste']) + Quantity::parse($result['total_adjustments']);
            $result['closing_stock'] = Quantity::format($closing);
            $result['active'] = (bool) $result['active'];
            $result['low_stock'] = $closing < Quantity::parse($result['minimum_stock']);

            return $result;
        });
    }

    /**
     * @param  string[]  $includes  Relations to eager-load (e.g. ['item', 'day'])
     * @return LengthAwarePaginator<int, InventoryMovement>
     */
    public function history(User $user, array $filters = [], ?int $perPage = null, array $includes = ['item', 'day']): LengthAwarePaginator
    {
        $query = InventoryMovement::query()
            ->where('user_id', $user->id);

        if ($includes !== []) {
            $query->with($includes);
        }

        if (isset($filters['item_id'])) {
            $item = $user->items()->findOrFail($filters['item_id']);
            $query->where('food_item_id', $item->id);
        }

        if (isset($filters['date'])) {
            $query->whereHas('day', fn ($day) => $day->where('date', $filters['date']));
        }

        if (isset($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        return $query->orderByDesc('id')->paginate($perPage ?? config('api.pagination.default'))->withQueryString();
    }

    /**
     * @return array{date: string, closed_at: mixed, items: Collection, totals_by_unit: Collection, low_stock_items: Collection}
     */
    public function dailySummary(User $user, string $date): array
    {
        return DB::transaction(function () use ($user, $date) {
            $day = $user->days()->where('date', $date)->firstOrFail();
            $items = $this->totals($day);
            $totals = $items->groupBy('unit')->map(function ($rows): array {
                $result = [];
                foreach (['opening_stock', 'total_incoming', 'total_outgoing', 'total_waste', 'total_adjustments', 'closing_stock'] as $field) {
                    $result[$field] = Quantity::format($rows->sum(fn (array $row): int => Quantity::parse($row[$field])));
                }

                return $result;
            });

            return [
                'date' => $day->date->format('Y-m-d'),
                'closed_at' => $day->closed_at,
                'items' => $items,
                'totals_by_unit' => $totals,
                'low_stock_items' => $items->where('low_stock', true)->values(),
            ];
        });
    }

    private function insert(User $user, InventoryDay $day, FoodItem $item, string $type, string $quantity, ?string $note, string $key): InventoryMovement
    {
        $movement = new InventoryMovement;
        $movement->user_id = $user->id;
        $movement->created_by = $user->id;
        $movement->inventory_day_id = $day->id;
        $movement->food_item_id = $item->id;
        $movement->type = $type;
        $movement->quantity = $quantity;
        $movement->note = $note;
        $movement->idempotency_key = $key;
        $movement->save();

        return $movement;
    }
}
