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
        Schema::create('medical_certificates', function (Blueprint $table) {
            $table->id();
            $table->string('serial_no')->nullable()->unique();
            $table->string('verification_token', 32)->unique();
            $table->string('type');
            $table->foreignId('patient_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('doctor_id')->nullable()->constrained()->nullOnDelete();
            $table->string('patient_title');
            $table->string('patient_name');
            $table->string('gender');
            $table->string('relation');
            $table->string('guardian_name')->nullable();
            $table->unsignedSmallInteger('age')->nullable();
            $table->string('mrn')->nullable();
            $table->string('cnic')->nullable();
            $table->string('diagnosis')->nullable();
            $table->boolean('show_diagnosis')->default(true);
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->date('resume_date')->nullable();
            $table->string('time_from')->nullable();
            $table->string('time_to')->nullable();
            $table->unsignedTinyInteger('gestation_weeks')->nullable();
            $table->date('expected_delivery_date')->nullable();
            $table->string('doctor_name');
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('issued_at');
            $table->unsignedSmallInteger('print_count')->default(0);
            $table->dateTime('last_printed_at')->nullable();
            $table->dateTime('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason')->nullable();
            $table->timestamps();

            $table->index(['issued_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('medical_certificates');
    }
};
