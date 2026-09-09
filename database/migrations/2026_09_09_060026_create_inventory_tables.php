<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('food_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->enum('unit', ['piece', 'kg', 'liter', 'portion']);
            $table->decimal('minimum_stock', 12, 3)->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['id', 'user_id']);
            $table->index(['user_id', 'active', 'id']);
        });
        Schema::create('inventory_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->date('date');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'date']);
            $table->unique(['id', 'user_id']);
            $table->unsignedBigInteger('open_user_id')->nullable()->storedAs('CASE WHEN closed_at IS NULL THEN user_id ELSE NULL END');
            $table->unique('open_user_id');
        });
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('food_item_id');
            $table->unsignedBigInteger('inventory_day_id');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->enum('type', ['opening', 'in', 'out', 'waste', 'adjustment']);
            $table->decimal('quantity', 12, 3);
            $table->string('note', 500)->nullable();
            $table->uuid('idempotency_key');
            $table->timestamps();
            $table->foreign(['food_item_id', 'user_id'])->references(['id', 'user_id'])->on('food_items')->restrictOnDelete();
            $table->foreign(['inventory_day_id', 'user_id'])->references(['id', 'user_id'])->on('inventory_days')->restrictOnDelete();
            $table->unique(['user_id', 'idempotency_key']);
            $table->unsignedBigInteger('opening_item_id')->nullable()->storedAs("CASE WHEN type = 'opening' THEN food_item_id ELSE NULL END");
            $table->unique(['inventory_day_id', 'opening_item_id']);
            $table->index(['inventory_day_id', 'food_item_id', 'type']);
            $table->index(['user_id', 'food_item_id', 'id']);
            $table->index(['user_id', 'type', 'id']);
        });
        DB::statement('ALTER TABLE food_items ADD CONSTRAINT minimum_stock_nonnegative CHECK (minimum_stock >= 0)');
        DB::statement("ALTER TABLE inventory_movements ADD CONSTRAINT movement_quantity_valid CHECK ((type = 'opening' AND quantity >= 0) OR (type IN ('in', 'out', 'waste') AND quantity > 0) OR (type = 'adjustment' AND quantity <> 0))");
        DB::statement('ALTER TABLE inventory_movements ADD CONSTRAINT movement_creator_owner CHECK (created_by = user_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
        Schema::dropIfExists('inventory_days');
        Schema::dropIfExists('food_items');
    }
};
