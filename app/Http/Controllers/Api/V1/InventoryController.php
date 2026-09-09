<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\InventoryDayRequest;
use App\Http\Requests\InventoryQueryRequest;
use App\Http\Requests\MovementRequest;
use App\Http\Resources\InventoryDayResource;
use App\Http\Resources\MovementResource;
use App\Services\Inventory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class InventoryController extends Controller
{
    public function __construct(private Inventory $inventory) {}

    public function open(InventoryDayRequest $request): JsonResponse
    {
        $day = $this->inventory->open($request->user(), $request->validated('date'), $request->validated('openings', []));

        return (new InventoryDayResource($day))->response()->setStatusCode(201);
    }

    public function movement(MovementRequest $request): JsonResponse
    {
        [$movement, $created] = $this->inventory->record($request->user(), $request->validated());

        return (new MovementResource($movement))->response()->setStatusCode($created ? 201 : 200);
    }

    public function close(InventoryDayRequest $request): InventoryDayResource
    {
        return new InventoryDayResource(
            $this->inventory->close($request->user(), $request->validated('date'))
        );
    }

    public function history(InventoryQueryRequest $request): AnonymousResourceCollection
    {
        $filters = $request->safe()->only(['item_id', 'date', 'type']);
        $includes = $request->includes() ?: ['item', 'day'];

        return MovementResource::collection(
            $this->inventory->history($request->user(), $filters, $request->integer('per_page'), $includes)
        );
    }

    public function summary(InventoryQueryRequest $request): JsonResponse
    {
        $summary = $this->inventory->dailySummary($request->user(), $request->validated('date'));

        return response()->json(['data' => $summary]);
    }
}
