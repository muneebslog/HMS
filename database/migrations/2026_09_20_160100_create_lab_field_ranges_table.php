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
        Schema::create('lab_field_ranges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lab_field_id')->constrained()->cascadeOnDelete();
            $table->string('category');
            $table->decimal('value_low', 10, 2)->nullable();
            $table->decimal('value_high', 10, 2)->nullable();
            $table->unsignedSmallInteger('age_low')->nullable();
            $table->unsignedSmallInteger('age_high')->nullable();
            $table->timestamps();

            $table->index(['lab_field_id', 'category']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lab_field_ranges');
    }
};
