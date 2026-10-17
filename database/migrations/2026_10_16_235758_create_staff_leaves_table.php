<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One row per person per day off. Informed leaves are paid until the
     * monthly allowance is used up; uninformed absences are never paid.
     */
    public function up(): void
    {
        Schema::create('staff_leaves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('leave_date');
            $table->string('kind');
            $table->decimal('days', 3, 1)->default(1);
            $table->decimal('paid_days', 3, 1)->default(0);
            $table->boolean('force_paid')->default(false);
            $table->string('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['employee_id', 'leave_date']);
            $table->index('leave_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('staff_leaves');
    }
};
