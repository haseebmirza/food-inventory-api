<?php

namespace Tests\Feature;

use App\Models\InventoryMovement;
use App\Models\User;
use App\Services\Inventory;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class InventoryConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_competing_sales_wait_for_the_lock_and_cannot_oversell(): void
    {
        $user = User::factory()->create();
        $item = $user->items()->create(['name' => 'Rice', 'unit' => 'kg', 'minimum_stock' => '0']);
        $day = app(Inventory::class)->open($user, '2026-09-09', [['item_id' => $item->id, 'quantity' => '100']]);
        $path = '/api/v1/inventory-days/2026-09-09/movements';
        $responses = $this->race($user, [
            [$path, ['item_id' => $item->id, 'type' => 'out', 'quantity' => '80'], (string) Str::uuid()],
            [$path, ['item_id' => $item->id, 'type' => 'out', 'quantity' => '40'], (string) Str::uuid()],
        ]);
        $statuses = array_column($responses, 'status');
        sort($statuses);
        $this->assertSame([201, 409], $statuses);
        $this->assertSame(1, InventoryMovement::where('type', 'out')->count());
        $this->assertContains(app(Inventory::class)->totals($day)->first()['closing_stock'], ['20.000', '60.000']);
    }

    public function test_simultaneous_retries_create_only_one_movement(): void
    {
        $user = User::factory()->create();
        $item = $user->items()->create(['name' => 'Rice', 'unit' => 'kg', 'minimum_stock' => '0']);
        $day = app(Inventory::class)->open($user, '2026-09-09', [['item_id' => $item->id, 'quantity' => '100']]);
        $request = ['/api/v1/inventory-days/2026-09-09/movements', ['item_id' => $item->id, 'type' => 'out', 'quantity' => '80'], (string) Str::uuid()];
        $responses = $this->race($user, [$request, $request]);
        $statuses = array_column($responses, 'status');
        sort($statuses);
        $this->assertSame([200, 201], $statuses);
        $this->assertSame($responses[0]['body']['data']['id'], $responses[1]['body']['data']['id']);
        $this->assertSame('20.000', app(Inventory::class)->totals($day)->first()['closing_stock']);
        $this->assertDatabaseCount('inventory_movements', 2);
    }

    public function test_competing_day_creation_produces_one_day_and_one_opening(): void
    {
        $user = User::factory()->create();
        $user->items()->create(['name' => 'Rice', 'unit' => 'kg', 'minimum_stock' => '0']);
        $request = ['/api/v1/inventory-days', ['date' => '2026-09-09'], (string) Str::uuid()];
        $statuses = array_column($this->race($user, [$request, $request]), 'status');
        sort($statuses);
        $this->assertSame([201, 409], $statuses);
        $this->assertDatabaseCount('inventory_days', 1);
        $this->assertDatabaseCount('inventory_movements', 1);
    }

    public function test_closing_and_selling_are_serialized_without_changing_closed_history(): void
    {
        $user = User::factory()->create();
        $item = $user->items()->create(['name' => 'Rice', 'unit' => 'kg', 'minimum_stock' => '0']);
        $day = app(Inventory::class)->open($user, '2026-09-09', [['item_id' => $item->id, 'quantity' => '100']]);
        $responses = $this->race($user, [
            ['/api/v1/inventory-days/2026-09-09/close', [], (string) Str::uuid()],
            ['/api/v1/inventory-days/2026-09-09/movements', ['item_id' => $item->id, 'type' => 'out', 'quantity' => '80'], (string) Str::uuid()],
        ]);
        $this->assertSame(200, $responses[0]['status']);
        $this->assertContains($responses[1]['status'], [201, 409]);
        $this->assertNotNull($day->refresh()->closed_at);
        $expected = $responses[1]['status'] === 201 ? '20.000' : '100.000';
        $this->assertSame($expected, app(Inventory::class)->totals($day)->first()['closing_stock']);
    }

    /** Start independent HTTP kernels and prove both are waiting in MySQL before releasing the lock. */
    private function race(User $user, array $requests): array
    {
        $token = $user->createToken('concurrency')->plainTextToken;
        $db = config('database.connections.mysql');
        $environment = [
            'APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'APP_DEBUG' => 'false',
            'DB_CONNECTION' => 'mysql', 'DB_HOST' => $db['host'], 'DB_PORT' => (string) $db['port'],
            'DB_DATABASE' => $db['database'], 'DB_USERNAME' => $db['username'], 'DB_PASSWORD' => $db['password'], 'DB_URL' => '',
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'LOG_CHANNEL' => 'stderr',
        ];
        $processes = [];
        DB::beginTransaction();
        try {
            app(Inventory::class)->lock($user);
            foreach ($requests as [$path, $body, $key]) {
                $process = new Process([PHP_BINARY, base_path('tests/Fixtures/inventory-request.php')], base_path(), $environment);
                $process->setInput(json_encode(compact('path', 'body', 'key', 'token'), JSON_THROW_ON_ERROR));
                $process->setTimeout(20);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 10;
            $waiting = 0;
            do {
                $waiting = collect(DB::select('SHOW PROCESSLIST'))->filter(fn ($row) => $row->db === 'daily_food_inventory_test' && str_contains(strtolower($row->Info ?? ''), 'for update'))->count();
                if ($waiting >= 2) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            $this->assertSame(2, $waiting, 'Both independent writers must reach the database lock before it is released.');
            DB::commit();
            $responses = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
                $responses[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }

            return $responses;
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
        }
    }
}
