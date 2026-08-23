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
        Schema::create('attendance_work_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('health_aide_id')->constrained()->cascadeOnDelete();
            $table->foreignId('in_punch_id')->constrained('attendance_punches')->cascadeOnDelete();
            $table->foreignId('out_punch_id')->nullable()->constrained('attendance_punches')->nullOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at')->nullable();
            $table->string('status');
            $table->foreignId('duty_assignment_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('worked_minutes')->default(0);
            $table->unsignedInteger('late_minutes')->default(0);
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->unique('in_punch_id');
            $table->index(['health_aide_id', 'starts_at']);
            $table->index(['status', 'starts_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_work_sessions');
    }
};
