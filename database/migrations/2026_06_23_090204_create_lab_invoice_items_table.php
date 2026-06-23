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
        Schema::create('lab_invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lab_invoice_id')->constrained();
            $table->foreignId('lab_test_id')->constrained();
            $table->string('test_name');
            $table->string('test_code');
            $table->string('time_required');
            $table->boolean('is_in_house')->default(true);
            $table->decimal('price', 10, 2);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lab_invoice_items');
    }
};
