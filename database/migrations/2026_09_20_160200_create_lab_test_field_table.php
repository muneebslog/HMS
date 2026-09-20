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
        Schema::create('lab_test_field', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lab_test_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lab_field_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('display_order')->default(0);
            $table->timestamps();

            $table->unique(['lab_test_id', 'lab_field_id']);
            $table->index(['lab_test_id', 'display_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lab_test_field');
    }
};
