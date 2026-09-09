<?php

namespace Tests\Feature;

use App\Models\InventoryMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InventoryTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function inventory(string $opening = '100.000'): array
    {
        $this->travelTo(now()->setDate(2026, 9, 10)->startOfDay());
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $item = $user->items()->create(['name' => 'Biryani', 'unit' => 'portion', 'minimum_stock' => '20']);
        $this->postJson('/api/v1/inventory-days', ['date' => '2026-09-09', 'openings' => [['item_id' => $item->id, 'quantity' => $opening]]])->assertCreated();

        return [$user, $item];
    }

    private function movement(int $item, string $type, string $quantity, string $date = '2026-09-09', ?string $key = null, ?string $note = null): TestResponse
    {
        return $this->postJson('/api/v1/inventory-days/'.$date.'/movements', ['item_id' => $item, 'type' => $type, 'quantity' => $quantity, 'note' => $note], ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    public function test_daily_workflow_carries_closing_stock_forward_without_duplicating_incoming(): void
    {
        [$user, $item] = $this->inventory();
        $this->movement($item->id, 'in', '30')->assertCreated();
        $this->movement($item->id, 'out', '80')->assertCreated();
        $this->movement($item->id, 'waste', '10')->assertCreated();
        $this->getJson('/api/v1/inventory/daily-summary?date=2026-09-09')->assertOk()
            ->assertJsonPath('data.items.0.opening_stock', '100.000')->assertJsonPath('data.items.0.total_incoming', '30.000')
            ->assertJsonPath('data.items.0.total_outgoing', '80.000')->assertJsonPath('data.items.0.total_waste', '10.000')->assertJsonPath('data.items.0.closing_stock', '40.000');
        $this->postJson('/api/v1/inventory-days/2026-09-09/close')->assertOk();
        $this->postJson('/api/v1/inventory-days/2026-09-09/close')->assertOk();
        $this->postJson('/api/v1/inventory-days', ['date' => '2026-09-10'])->assertCreated();
        $this->movement($item->id, 'in', '50', '2026-09-10')->assertCreated();
        $this->movement($item->id, 'out', '60', '2026-09-10')->assertCreated();
        $this->movement($item->id, 'waste', '5', '2026-09-10')->assertCreated();
        $this->getJson('/api/v1/inventory/daily-summary?date=2026-09-10')->assertOk()
            ->assertJsonPath('data.items.0.opening_stock', '40.000')->assertJsonPath('data.items.0.total_incoming', '50.000')->assertJsonPath('data.items.0.closing_stock', '25.000');
        $this->assertSame(2, $user->days()->count());
        $this->assertDatabaseCount('inventory_movements', 8);
    }

    public function test_negative_stock_is_rejected_and_transaction_leaves_no_movement(): void
    {
        [, $item] = $this->inventory();
        $this->movement($item->id, 'out', '80')->assertCreated();
        $this->movement($item->id, 'out', '40')->assertConflict()->assertJsonPath('message', 'Insufficient stock.');
        $this->movement($item->id, 'waste', '21')->assertConflict();
        $this->movement($item->id, 'adjustment', '-21', note: 'Count correction')->assertConflict();
        $this->assertDatabaseCount('inventory_movements', 2);
        $this->getJson('/api/v1/inventory/daily-summary?date=2026-09-09')->assertJsonPath('data.items.0.closing_stock', '20.000');
    }

    public function test_duplicates_and_unclosed_previous_day_return_409(): void
    {
        [$user] = $this->inventory();
        $this->postJson('/api/v1/inventory-days', ['date' => '2026-09-09'])->assertConflict();
        $this->postJson('/api/v1/inventory-days', ['date' => '2026-09-10'])->assertConflict();
        $this->postJson('/api/v1/inventory-days/2026-09-09/close')->assertOk();
        $this->postJson('/api/v1/inventory-days', ['date' => '2026-09-08'])->assertConflict();
        $this->assertSame(1, $user->days()->count());
    }

    public function test_closed_history_is_unchanged_and_corrections_use_current_day_adjustments(): void
    {
        [, $item] = $this->inventory();
        $this->postJson('/api/v1/inventory-days/2026-09-09/close')->assertOk();
        $this->movement($item->id, 'in', '10')->assertConflict();
        $this->movement($item->id, 'adjustment', '-1', note: 'Correction')->assertConflict();
        $this->postJson('/api/v1/inventory-days', ['date' => '2026-09-10'])->assertCreated();
        $this->movement($item->id, 'adjustment', '-5.125', '2026-09-10', note: 'Correct September 9 count')->assertCreated();
        $this->getJson('/api/v1/inventory/daily-summary?date=2026-09-10')->assertJsonPath('data.items.0.total_adjustments', '-5.125')->assertJsonPath('data.items.0.closing_stock', '94.875');
        $this->getJson('/api/v1/inventory/daily-summary?date=2026-09-09')->assertJsonPath('data.items.0.closing_stock', '100.000');
        $this->assertDatabaseCount('inventory_movements', 3);
    }

    public function test_idempotent_retries_return_original_movement_even_after_closing(): void
    {
        [, $item] = $this->inventory();
        $key = (string) Str::uuid();
        $id = $this->movement($item->id, 'in', '1.1', key: $key)->assertCreated()->json('data.id');
        $this->movement($item->id, 'in', '1.100', key: $key)->assertOk()->assertJsonPath('data.id', $id);
        $this->movement($item->id, 'in', '2', key: $key)->assertConflict();
        $this->movement($item->id, 'in', '1.1', key: $key, note: 'different')->assertConflict();
        $this->postJson('/api/v1/inventory-days/2026-09-09/close')->assertOk();
        $this->movement($item->id, 'in', '1.1', key: $key)->assertOk();
        $this->assertDatabaseCount('inventory_movements', 2);
    }

    public function test_exact_decimals_and_low_stock_threshold(): void
    {
        [, $item] = $this->inventory('20.000');
        $this->getJson('/api/v1/inventory/daily-summary?date=2026-09-09')->assertJsonCount(0, 'data.low_stock_items');
        $this->movement($item->id, 'out', '0.001')->assertCreated();
        $this->getJson('/api/v1/inventory/daily-summary?date=2026-09-09')->assertJsonPath('data.items.0.closing_stock', '19.999')->assertJsonPath('data.low_stock_items.0.item_id', $item->id);
        $this->movement($item->id, 'in', '0.001')->assertCreated();
        $this->getJson('/api/v1/inventory/daily-summary?date=2026-09-09')->assertJsonPath('data.items.0.closing_stock', '20.000')->assertJsonCount(0, 'data.low_stock_items');
    }

    public static function invalidQuantities(): array
    {
        return [['in', '0'], ['out', '-1'], ['opening', '-1'], ['adjustment', '0'], ['in', '0.0001'], ['in', '1e2'], ['in', '1000000000'], ['in', 0.1], ['in', 'NaN']];
    }

    #[DataProvider('invalidQuantities')]
    public function test_invalid_quantities_return_422_without_writes(string $type, mixed $quantity): void
    {
        [, $item] = $this->inventory();
        $this->postJson('/api/v1/inventory-days/2026-09-09/movements', ['item_id' => $item->id, 'type' => $type, 'quantity' => $quantity, 'note' => 'Test'], ['Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->assertDatabaseCount('inventory_movements', 1);
    }

    public function test_openings_are_unique_and_cannot_override_carried_stock(): void
    {
        [, $item] = $this->inventory();
        $this->movement($item->id, 'opening', '10')->assertConflict();
        $this->postJson('/api/v1/inventory-days/2026-09-09/close')->assertOk();
        $this->postJson('/api/v1/inventory-days', ['date' => '2026-09-10', 'openings' => [['item_id' => $item->id, 'quantity' => '10']]])->assertConflict();
        $this->assertDatabaseCount('inventory_days', 1);
        $this->assertDatabaseCount('inventory_movements', 1);
    }

    public function test_deleted_items_preserve_history_and_balance_but_reject_new_movements(): void
    {
        [, $item] = $this->inventory();
        $this->deleteJson('/api/v1/items/'.$item->id)->assertNoContent();
        $this->movement($item->id, 'out', '1')->assertConflict();
        $this->postJson('/api/v1/inventory-days/2026-09-09/close')->assertOk();
        $this->postJson('/api/v1/inventory-days', ['date' => '2026-09-10'])->assertCreated();
        $this->getJson('/api/v1/inventory/daily-summary?date=2026-09-10')->assertJsonPath('data.items.0.closing_stock', '100.000')->assertJsonPath('data.items.0.active', false);
        $this->getJson('/api/v1/inventory/history')->assertJsonPath('meta.total', 2);
        $this->putJson('/api/v1/items/'.$item->id, ['name' => 'Biryani', 'unit' => 'kg', 'minimum_stock' => '20', 'active' => true])->assertConflict();
        $this->putJson('/api/v1/items/'.$item->id, ['name' => 'Biryani', 'unit' => 'portion', 'minimum_stock' => '20', 'active' => true])->assertOk();
        $this->movement($item->id, 'out', '1', '2026-09-10')->assertCreated();
    }

    public function test_new_items_require_opening_and_unit_totals_are_separate(): void
    {
        [$user] = $this->inventory();
        $milk = $user->items()->create(['name' => 'Milk', 'unit' => 'liter', 'minimum_stock' => '1']);
        $this->movement($milk->id, 'in', '2')->assertConflict();
        $this->movement($milk->id, 'opening', '0')->assertCreated();
        $this->movement($milk->id, 'in', '2')->assertCreated();
        $this->getJson('/api/v1/inventory/daily-summary?date=2026-09-09')->assertJsonPath('data.totals_by_unit.liter.closing_stock', '2.000')->assertJsonPath('data.totals_by_unit.portion.closing_stock', '100.000');
    }

    public function test_history_filters_pagination_and_cross_user_isolation(): void
    {
        [$owner, $item] = $this->inventory();
        $this->movement($item->id, 'in', '1')->assertCreated();
        $this->movement($item->id, 'out', '1')->assertCreated();
        $this->getJson('/api/v1/inventory/history?item_id='.$item->id.'&date=2026-09-09&type=out&per_page=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.quantity', '1.000')->assertJsonPath('data.0.created_by', $owner->id);
        $this->getJson('/api/v1/inventory/history?per_page=2')->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 3);
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/inventory/history')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/inventory/history?item_id='.$item->id)->assertNotFound();
        $this->getJson('/api/v1/inventory/daily-summary?date=2026-09-09')->assertNotFound();
        $this->postJson('/api/v1/inventory-days/2026-09-09/close')->assertNotFound();
        $this->movement($item->id, 'in', '1')->assertNotFound();
        $this->postJson('/api/v1/inventory-days', ['date' => '2026-09-09', 'openings' => [['item_id' => $item->id, 'quantity' => '1']]])->assertNotFound();
        $this->postJson('/api/v1/inventory-days', ['date' => '2026-09-09'])->assertCreated();
        $this->movement($item->id, 'in', '1')->assertNotFound();
        $this->getJson('/api/v1/inventory/daily-summary?date=2026-09-09')->assertJsonCount(0, 'data.items');
        $this->assertDatabaseCount('inventory_movements', 3);
        $this->assertNull($owner->days()->first()->closed_at);
    }

    public function test_movement_validation_rejects_missing_keys_spoofed_ownership_and_invalid_dates(): void
    {
        [, $item] = $this->inventory();
        $this->postJson('/api/v1/inventory-days/2026-09-09/movements', ['item_id' => $item->id, 'type' => 'adjustment', 'quantity' => '1', 'created_by' => 123, 'user_id' => 123])->assertUnprocessable()->assertJsonValidationErrors(['idempotency_key', 'note', 'created_by', 'user_id']);
        foreach (['2026-02-30', '2026-09-11', 'nonsense'] as $date) {
            $this->postJson('/api/v1/inventory-days', ['date' => $date])->assertUnprocessable()->assertJsonValidationErrors('date');
        }
        $this->getJson('/api/v1/inventory/history?type=out%27%20OR%201=1')->assertUnprocessable();
        $this->assertDatabaseCount('inventory_movements', 1);
    }

    public function test_failed_day_creation_rolls_back_partial_openings(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 10));
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        foreach (['Rice', 'Flour'] as $name) {
            $user->items()->create(['name' => $name, 'unit' => 'kg', 'minimum_stock' => '0']);
        }
        $count = 0;
        Event::listen('eloquent.creating: '.InventoryMovement::class, function () use (&$count): void {
            if (++$count === 2) {
                throw new \RuntimeException('Simulated storage failure');
            }
        });
        try {
            $this->postJson('/api/v1/inventory-days', ['date' => '2026-09-09'])->assertInternalServerError()->assertJsonPath('message', 'An internal error occurred.');
            $this->assertDatabaseCount('inventory_days', 0);
            $this->assertDatabaseCount('inventory_movements', 0);
        } finally {
            Event::forget('eloquent.creating: '.InventoryMovement::class);
        }
    }

    public function test_stock_maximum_and_missing_days_are_rejected(): void
    {
        [, $item] = $this->inventory('999999999.999');
        $this->movement($item->id, 'in', '0.001')->assertConflict();
        $this->movement($item->id, 'out', '1', '2026-09-10')->assertNotFound();
        $this->postJson('/api/v1/inventory-days/2026-09-10/close')->assertNotFound();
        $this->assertDatabaseCount('inventory_movements', 1);
    }

    public function test_skipped_dates_carry_the_last_closed_balance(): void
    {
        [, $item] = $this->inventory('5.125');
        $this->postJson('/api/v1/inventory-days/2026-09-09/close')->assertOk();
        $this->travelTo(now()->setDate(2026, 9, 15));
        $this->postJson('/api/v1/inventory-days', ['date' => '2026-09-15'])->assertCreated();
        $this->getJson('/api/v1/inventory/daily-summary?date=2026-09-15')->assertJsonPath('data.items.0.opening_stock', '5.125');
        $this->assertDatabaseCount('inventory_days', 2);
    }

    public function test_history_eager_loading_query_count_does_not_grow_with_items(): void
    {
        [$user] = $this->inventory();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson('/api/v1/inventory/history')->assertOk()->assertJsonCount(1, 'data');
        $singleQueries = count(DB::getQueryLog());
        for ($i = 0; $i < 10; $i++) {
            $item = $user->items()->create(['name' => 'Item '.$i, 'unit' => 'kg', 'minimum_stock' => '0']);
            $this->movement($item->id, 'opening', '1')->assertCreated();
        }
        DB::flushQueryLog();
        $this->getJson('/api/v1/inventory/history')->assertOk()->assertJsonCount(11, 'data');
        $this->assertSame($singleQueries, count(DB::getQueryLog()));
        DB::flushQueryLog();
        $this->getJson('/api/v1/inventory/daily-summary?date=2026-09-09')->assertOk()->assertJsonCount(11, 'data.items');
        $this->assertLessThanOrEqual(3, count(DB::getQueryLog()));
        DB::disableQueryLog();
    }

    public function test_direct_model_edits_cannot_modify_or_delete_history(): void
    {
        $this->inventory();
        $movement = InventoryMovement::firstOrFail();
        try {
            $movement->quantity = '999';
            $movement->save();
            $this->fail('A historical movement was modified.');
        } catch (\LogicException $error) {
            $this->assertSame('Inventory movements are immutable.', $error->getMessage());
        }
        try {
            $movement->delete();
            $this->fail('A historical movement was deleted.');
        } catch (\LogicException $error) {
            $this->assertSame('Inventory movements are immutable.', $error->getMessage());
        }
        $this->assertSame('100.000', $movement->refresh()->quantity);
        $this->assertDatabaseCount('inventory_movements', 1);
    }
}
