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
        // Recover from a failed earlier run where the table was created without the FK.
        Schema::dropIfExists('procedure_apparent_invoice_items');

        Schema::create('procedure_apparent_invoice_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('procedure_apparent_invoice_id');
            $table->string('name');
            $table->decimal('amount', 12, 2);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            // Custom name — MySQL limits identifiers to 64 characters.
            $table->foreign('procedure_apparent_invoice_id', 'pai_items_invoice_id_foreign')
                ->references('id')
                ->on('procedure_apparent_invoices')
                ->cascadeOnDelete();
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
