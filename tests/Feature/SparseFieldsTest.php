<?php

namespace Tests\Feature;

use App\Models\FoodItem;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SparseFieldsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_food_items_index_returns_only_requested_fields(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        FoodItem::factory()->create(['user_id' => $user->id, 'name' => 'Rice', 'unit' => 'kg']);

        $response = $this->getJson('/api/v1/items?fields=name,unit');

        $response->assertOk();
        $item = $response->json('data.0');

        // Requested fields + id (always included)
        $this->assertArrayHasKey('id', $item);
        $this->assertArrayHasKey('name', $item);
        $this->assertArrayHasKey('unit', $item);

        // Excluded fields
        $this->assertArrayNotHasKey('minimum_stock', $item);
        $this->assertArrayNotHasKey('active', $item);
        $this->assertArrayNotHasKey('created_at', $item);
        $this->assertArrayNotHasKey('updated_at', $item);
    }

    public function test_food_items_index_returns_all_fields_when_no_fields_param(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        FoodItem::factory()->create(['user_id' => $user->id]);

        $response = $this->getJson('/api/v1/items');

        $response->assertOk();
        $item = $response->json('data.0');

        $this->assertArrayHasKey('id', $item);
        $this->assertArrayHasKey('name', $item);
        $this->assertArrayHasKey('unit', $item);
        $this->assertArrayHasKey('minimum_stock', $item);
        $this->assertArrayHasKey('active', $item);
    }

    public function test_invalid_field_names_are_ignored(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        FoodItem::factory()->create(['user_id' => $user->id]);

        $response = $this->getJson('/api/v1/items?fields=name,nonexistent,hacker_field');

        $response->assertOk();
        $item = $response->json('data.0');

        // Only valid fields returned (+ id always)
        $this->assertArrayHasKey('id', $item);
        $this->assertArrayHasKey('name', $item);
        $this->assertArrayNotHasKey('nonexistent', $item);
        $this->assertArrayNotHasKey('hacker_field', $item);
    }

    public function test_inventory_history_respects_include_param(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $item = FoodItem::factory()->create(['user_id' => $user->id, 'name' => 'Flour', 'unit' => 'kg']);
        $this->postJson('/api/v1/inventory-days', [
            'date' => '2026-09-01',
            'openings' => [['item_id' => $item->id, 'quantity' => '50.000']],
        ])->assertCreated();

        // Default: both item and day are included.
        $response = $this->getJson('/api/v1/inventory/history');
        $response->assertOk();
        $movement = $response->json('data.0');
        $this->assertArrayHasKey('item', $movement);
        $this->assertArrayHasKey('date', $movement);

        // Explicit include=item: only item loaded.
        $response = $this->getJson('/api/v1/inventory/history?include=item');
        $response->assertOk();
        $movement = $response->json('data.0');
        $this->assertArrayHasKey('item', $movement);
        $this->assertNotNull($movement['item']);
    }
}
