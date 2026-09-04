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
        Schema::create('procedure_birth_certificate_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('procedure_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('father_name');
            $table->string('mother_name');
            $table->string('grandfather_name')->nullable();
            $table->string('maternal_grandfather_name')->nullable();
            $table->unsignedTinyInteger('father_age')->nullable();
            $table->unsignedTinyInteger('mother_age')->nullable();
            $table->string('father_cnic')->nullable();
            $table->string('mother_cnic')->nullable();
            $table->text('home_address')->nullable();
            $table->dateTime('born_at');
            $table->string('sex');
            $table->string('status')->default('living');
            $table->string('baby_name')->nullable();
            $table->string('multiplicity')->default('single');
            $table->unsignedTinyInteger('child_order')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('procedure_birth_certificate_details');
    }
};
