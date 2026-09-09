<?php

namespace Tests\Feature;

use App\Models\FoodItem;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FoodItemTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_create_update_and_delete_deactivate_without_erasing_the_item(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $payload = ['name' => 'Biryani', 'unit' => 'portion', 'minimum_stock' => '20.125'];
        $id = $this->postJson('/api/v1/items', $payload)->assertCreated()->assertJsonPath('data.active', true)->json('data.id');
        $this->putJson('/api/v1/items/'.$id, [...$payload, 'name' => 'Chicken Biryani'])->assertOk()->assertJsonPath('data.name', 'Chicken Biryani');
        $this->deleteJson('/api/v1/items/'.$id)->assertNoContent();
        $this->getJson('/api/v1/items/'.$id)->assertOk()->assertJsonPath('data.active', false);
        $this->assertDatabaseHas('food_items', ['id' => $id, 'user_id' => $user->id, 'active' => false, 'minimum_stock' => '20.125']);
    }

    public function test_foreign_items_are_hidden_from_all_routes_and_policies(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();
        $item = $owner->items()->create(['name' => 'Private', 'unit' => 'kg', 'minimum_stock' => '0']);
        Sanctum::actingAs($attacker);
        $this->getJson('/api/v1/items')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/items/'.$item->id)->assertNotFound();
        $this->putJson('/api/v1/items/'.$item->id, ['name' => 'Stolen', 'unit' => 'kg', 'minimum_stock' => '0'])->assertNotFound();
        $this->deleteJson('/api/v1/items/'.$item->id)->assertNotFound();
        foreach (['view', 'update', 'delete'] as $ability) {
            $this->assertTrue(Gate::forUser($owner)->allows($ability, $item));
            $this->assertTrue(Gate::forUser($attacker)->denies($ability, $item));
        }
        $this->assertSame('Private', $item->refresh()->name);
        $this->assertTrue($item->active);
    }

    public function test_invalid_fields_and_owner_injection_return_422(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/v1/items', [])->assertUnprocessable()->assertJsonValidationErrors(['name', 'unit', 'minimum_stock']);
        $this->postJson('/api/v1/items', ['name' => 'Rice', 'unit' => 'invalid', 'minimum_stock' => '-1.001', 'active' => 'maybe', 'user_id' => 99])
            ->assertUnprocessable()->assertJsonValidationErrors(['unit', 'minimum_stock', 'active', 'user_id']);
        $this->postJson('/api/v1/items', ['name' => 'Rice', 'unit' => 'kg', 'minimum_stock' => '0.0001'])->assertUnprocessable();
        $this->assertDatabaseCount('food_items', 0);
    }

    public function test_item_pagination_is_bounded_and_item_identifiers_reject_sql(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        foreach (['Rice', 'Flour', 'Milk'] as $name) {
            $user->items()->create(['name' => $name, 'unit' => 'kg', 'minimum_stock' => '0']);
        }
        $this->getJson('/api/v1/items?per_page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 3);
        $this->getJson('/api/v1/items?per_page=101')->assertUnprocessable();
        $this->getJson('/api/v1/items/1%20OR%201=1')->assertNotFound();
        $this->assertSame(3, FoodItem::count());
    }
}
