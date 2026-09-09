<?php

namespace Database\Seeders;

use App\Models\FoodItem;
use App\Models\User;
use App\Services\Inventory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DemoSeeder extends Seeder
{
    /**
     * Seed a demo user with items, inventory days, and movements.
     *
     * Demo credentials:
     *   Email:    demo@example.com
     *   Password: password123A
     */
    public function run(Inventory $inventory): void
    {
        $user = User::factory()->create([
            'name' => 'Demo User',
            'email' => 'demo@example.com',
            'password' => 'password123A',
        ]);

        $items = FoodItem::factory()
            ->count(8)
            ->sequence(
                ['name' => 'Chicken Breast', 'unit' => 'kg', 'minimum_stock' => '10.000'],
                ['name' => 'Basmati Rice', 'unit' => 'kg', 'minimum_stock' => '20.000'],
                ['name' => 'Olive Oil', 'unit' => 'liter', 'minimum_stock' => '5.000'],
                ['name' => 'Eggs', 'unit' => 'piece', 'minimum_stock' => '50.000'],
                ['name' => 'Milk', 'unit' => 'liter', 'minimum_stock' => '10.000'],
                ['name' => 'Tomatoes', 'unit' => 'kg', 'minimum_stock' => '5.000'],
                ['name' => 'Onions', 'unit' => 'kg', 'minimum_stock' => '8.000'],
                ['name' => 'Butter', 'unit' => 'kg', 'minimum_stock' => '3.000'],
            )
            ->create(['user_id' => $user->id]);

        $openings = $items->map(fn (FoodItem $item) => [
            'item_id' => $item->id,
            'quantity' => match ($item->unit) {
                'piece' => '100.000',
                'kg' => '25.000',
                'liter' => '15.000',
                default => '50.000',
            },
        ])->all();

        // Day 1: open with stock, record some movements, close
        $yesterday = now()->subDay()->format('Y-m-d');
        $inventory->open($user, $yesterday, $openings);

        $this->recordMovements($inventory, $user, $yesterday, $items);
        $inventory->close($user, $yesterday);

        // Day 2: opens automatically with carried-forward balances
        $today = now()->format('Y-m-d');
        $inventory->open($user, $today, []);

        $this->recordMovements($inventory, $user, $today, $items);

        $this->command->info('Demo user seeded: demo@example.com / password123A');
    }

    /**
     * @param  Collection<int, FoodItem>  $items
     */
    private function recordMovements(Inventory $inventory, User $user, string $date, $items): void
    {
        $movements = [
            // Incoming deliveries
            ['index' => 0, 'type' => 'in', 'quantity' => '15.000', 'note' => 'Morning delivery'],
            ['index' => 1, 'type' => 'in', 'quantity' => '30.000', 'note' => 'Bulk rice order'],
            ['index' => 3, 'type' => 'in', 'quantity' => '60.000', 'note' => 'Egg delivery'],
            // Outgoing (kitchen usage)
            ['index' => 0, 'type' => 'out', 'quantity' => '8.000', 'note' => 'Lunch prep'],
            ['index' => 1, 'type' => 'out', 'quantity' => '12.000', 'note' => 'Biryani batch'],
            ['index' => 2, 'type' => 'out', 'quantity' => '2.000', 'note' => 'Cooking'],
            ['index' => 3, 'type' => 'out', 'quantity' => '24.000', 'note' => 'Breakfast service'],
            ['index' => 4, 'type' => 'out', 'quantity' => '3.000', 'note' => 'Tea and coffee'],
            ['index' => 5, 'type' => 'out', 'quantity' => '4.000', 'note' => 'Salad prep'],
            ['index' => 6, 'type' => 'out', 'quantity' => '3.000', 'note' => 'Base for curries'],
            // Waste
            ['index' => 5, 'type' => 'waste', 'quantity' => '1.500', 'note' => 'Spoiled tomatoes'],
            ['index' => 4, 'type' => 'waste', 'quantity' => '0.500', 'note' => 'Expired milk'],
            // Adjustment
            ['index' => 7, 'type' => 'adjustment', 'quantity' => '-0.200', 'note' => 'Physical count correction'],
        ];

        foreach ($movements as $m) {
            $item = $items[$m['index']];
            $inventory->record($user, [
                'date' => $date,
                'item_id' => $item->id,
                'type' => $m['type'],
                'quantity' => $m['quantity'],
                'note' => $m['note'],
                'idempotency_key' => (string) Str::uuid(),
            ]);
        }
    }
}
