<?php

namespace App\Services;

use App\Models\FoodItem;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class FoodItemService
{
    public function __construct(private Inventory $inventory) {}

    /**
     * @param  string[]|null  $selectFields  DB columns to fetch (null = all)
     * @return LengthAwarePaginator<int, FoodItem>
     */
    public function list(User $user, ?int $perPage = null, ?array $selectFields = null): LengthAwarePaginator
    {
        $query = $user->items()->orderBy('id');

        if ($selectFields !== null) {
            // Always include primary key for pagination to work.
            $dbColumns = $this->mapFieldsToColumns($selectFields);
            $query->select(array_unique(['id', 'user_id', ...$dbColumns]));
        }

        return $query->paginate($perPage ?? config('api.pagination.default'))->withQueryString();
    }

    /**
     * Map API field names to database column names.
     *
     * @param  string[]  $fields
     * @return string[]
     */
    private function mapFieldsToColumns(array $fields): array
    {
        // For FoodItem, field names match column names directly.
        return $fields;
    }

    public function create(User $user, array $data): FoodItem
    {
        return DB::transaction(function () use ($user, $data) {
            $this->inventory->lock($user);

            return $user->items()->create($data)->refresh();
        }, 3);
    }

    public function find(User $user, int $itemId): FoodItem
    {
        $item = $user->items()->findOrFail($itemId);
        Gate::authorize('view', $item);

        return $item;
    }

    public function update(User $user, int $itemId, array $data): FoodItem
    {
        return DB::transaction(function () use ($user, $itemId, $data) {
            $this->inventory->lock($user);
            $item = $user->items()->findOrFail($itemId);
            Gate::authorize('update', $item);
            abort_if(
                $item->unit !== $data['unit'] && $item->movements()->exists(),
                409,
                'Units cannot change after inventory has been recorded.'
            );
            $item->update($data);

            return $item;
        }, 3);
    }

    public function deactivate(User $user, int $itemId): void
    {
        DB::transaction(function () use ($user, $itemId) {
            $this->inventory->lock($user);
            $item = $user->items()->findOrFail($itemId);
            Gate::authorize('delete', $item);
            $item->update(['active' => false]);
        }, 3);
    }
}
