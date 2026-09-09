<?php

namespace Database\Factories;

use App\Models\FoodItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FoodItem>
 */
class FoodItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->randomElement([
                'Chicken Breast', 'Basmati Rice', 'Olive Oil', 'Tomatoes',
                'Onions', 'Garlic', 'Butter', 'Milk', 'Eggs', 'Flour',
                'Sugar', 'Salt', 'Black Pepper', 'Cumin', 'Coriander',
                'Yogurt', 'Lemon', 'Ginger', 'Chili Powder', 'Cooking Oil',
            ]),
            'unit' => fake()->randomElement(['piece', 'kg', 'liter', 'portion']),
            'minimum_stock' => fake()->randomElement(['5.000', '10.000', '20.000', '50.000']),
            'active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['active' => false]);
    }
}
