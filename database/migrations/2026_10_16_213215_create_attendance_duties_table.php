<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Each row is one duty (counts as 1) inside a confirmed session. A session
     * that covered two shifts has two rows. No shift template = custom timing.
     */
    public function up(): void
    {
        Schema::create('attendance_duties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('duty_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shift_template_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('scheduled_start');
            $table->dateTime('scheduled_end');
            $table->unsignedSmallInteger('late_minutes')->default(0);
            $table->unsignedSmallInteger('early_leave_minutes')->default(0);
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_duties');
    }
};
