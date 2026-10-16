<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A confirmed stretch of work: from arriving to leaving. Breaks in between
     * are folded in. The session belongs to the day it started.
     */
    public function up(): void
    {
        Schema::create('duty_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('duty_date');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->foreignId('in_punch_id')->nullable()->constrained('attendance_punches')->nullOnDelete();
            $table->foreignId('out_punch_id')->nullable()->constrained('attendance_punches')->nullOnDelete();
            $table->unsignedSmallInteger('break_minutes')->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->index(['duty_date', 'employee_id']);
            $table->index(['employee_id', 'starts_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('duty_sessions');
    }
};
