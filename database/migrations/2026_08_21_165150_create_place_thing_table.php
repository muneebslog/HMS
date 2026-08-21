<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('place_thing', function (Blueprint $table) {
            $table->id();
            $table->foreignId('place_id')->constrained('places')->cascadeOnDelete();
            $table->foreignId('thing_id')->constrained('things')->cascadeOnDelete();
            $table->unsignedInteger('stock_point');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['place_id', 'thing_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('place_thing');
    }
};
