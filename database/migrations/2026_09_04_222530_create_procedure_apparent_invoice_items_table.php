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
        Schema::create('procedure_apparent_invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('procedure_apparent_invoice_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('amount', 12, 2);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('procedure_apparent_invoice_items');
    }
};
