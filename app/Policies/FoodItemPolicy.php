<?php

namespace App\Policies;

use App\Models\FoodItem;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class FoodItemPolicy
{
    public function view(User $user, FoodItem $item): Response
    {
        return $user->id === $item->user_id ? Response::allow() : Response::denyAsNotFound();
    }

    public function update(User $user, FoodItem $item): Response
    {
        return $this->view($user, $item);
    }

    public function delete(User $user, FoodItem $item): Response
    {
        return $this->view($user, $item);
    }
}
