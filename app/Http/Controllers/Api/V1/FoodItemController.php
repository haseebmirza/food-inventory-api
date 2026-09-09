<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\FoodItemRequest;
use App\Http\Requests\InventoryQueryRequest;
use App\Http\Resources\FoodItemResource;
use App\Services\FoodItemService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class FoodItemController extends Controller
{
    public function __construct(private FoodItemService $foodItems) {}

    public function index(InventoryQueryRequest $request): AnonymousResourceCollection
    {
        return FoodItemResource::collection(
            $this->foodItems->list(
                $request->user(),
                $request->integer('per_page'),
                FoodItemResource::requestedFields($request),
            )
        );
    }

    public function store(FoodItemRequest $request): FoodItemResource
    {
        return new FoodItemResource(
            $this->foodItems->create($request->user(), $request->validated())
        );
    }

    public function show(Request $request, int $item): FoodItemResource
    {
        return new FoodItemResource(
            $this->foodItems->find($request->user(), $item)
        );
    }

    public function update(FoodItemRequest $request, int $item): FoodItemResource
    {
        return new FoodItemResource(
            $this->foodItems->update($request->user(), $item, $request->validated())
        );
    }

    public function destroy(Request $request, int $item): Response
    {
        $this->foodItems->deactivate($request->user(), $item);

        return response()->noContent();
    }
}
