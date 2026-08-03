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
        Schema::create('medication_order_drip_additives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medication_order_drip_id')->constrained()->cascadeOnDelete();
            $table->foreignId('injection_id')->constrained()->restrictOnDelete();
            $table->decimal('volume_ml', 8, 2);
            $table->string('name');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('medication_order_drip_additives');
    }
};
