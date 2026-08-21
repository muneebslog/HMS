<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_check_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_check_id')->constrained('stock_checks')->cascadeOnDelete();
            $table->foreignId('thing_id')->constrained('things')->cascadeOnDelete();
            $table->unsignedInteger('stock_point');
            $table->unsignedInteger('counted_quantity');
            $table->unsignedInteger('refill_needed');
            $table->timestamps();

            $table->unique(['stock_check_id', 'thing_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_check_items');
    }
};
