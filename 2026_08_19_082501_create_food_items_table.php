<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('food_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();

            $table->string('name', 150);                  // e.g. "Whole Milk"
            $table->string('brand', 100)->nullable();     // e.g. "Dutch Lady"

            // Quantity tracking
            $table->decimal('quantity', 10, 2)->default(1);
            $table->enum('unit', [
                'kg', 'g', 'lb', 'oz',
                'L', 'mL',
                'pcs', 'bottle', 'can', 'box', 'bag', 'pack',
            ])->default('pcs');

            // Date tracking
            $table->date('purchase_date')->nullable();
            $table->date('expiry_date');

            // Storage
            $table->enum('storage_location', [
                'fridge', 'freezer', 'pantry', 'shelf', 'cupboard', 'other',
            ])->default('pantry');

            // Status: active = still good, used = consumed, expired = past date, wasted = thrown away
            $table->enum('status', ['active', 'used', 'expired', 'wasted'])->default('active');

            $table->text('notes')->nullable();
            $table->string('image')->nullable();          // stored file path

            $table->timestamps();
            $table->softDeletes();                        // safe deletion — keeps history
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('food_items');
    }
};
