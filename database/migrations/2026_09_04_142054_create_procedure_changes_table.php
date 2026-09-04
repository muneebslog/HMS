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
        Schema::create('procedure_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('procedure_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_procedure_type_id')->nullable()->constrained('procedure_types')->nullOnDelete();
            $table->foreignId('to_procedure_type_id')->nullable()->constrained('procedure_types')->nullOnDelete();
            $table->string('from_name');
            $table->string('to_name');
            $table->decimal('from_amount', 12, 2);
            $table->decimal('to_amount', 12, 2);
            $table->decimal('package_price', 12, 2);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->foreignId('changed_by')->constrained('users');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('procedure_changes');
    }
};
