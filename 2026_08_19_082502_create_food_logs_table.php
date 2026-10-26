<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Records every action on a food item — powers the Report Module.
     */
    public function up(): void
    {
        Schema::create('food_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('food_item_id')->constrained()->cascadeOnDelete();

            // What happened to the food item
            $table->enum('action', ['added', 'updated', 'used', 'expired', 'wasted', 'deleted']);

            // Quantity snapshot before and after the action
            $table->decimal('quantity_before', 10, 2)->nullable();
            $table->decimal('quantity_after', 10, 2)->nullable();

            $table->text('notes')->nullable();
            $table->timestamp('logged_at')->useCurrent();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('food_logs');
    }
};
