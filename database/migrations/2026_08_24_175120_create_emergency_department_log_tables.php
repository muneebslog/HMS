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
        Schema::create('emergency_department_log_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('checklist_date');
            $table->string('shift');
            $table->string('completed_by_name');
            $table->string('supervisor_name')->nullable();
            $table->text('equipment_issues_log')->nullable();
            $table->timestamp('submitted_at');
            $table->timestamps();

            $table->unique(['checklist_date', 'shift'], 'ed_log_entries_unique_shift');
        });

        Schema::create('emergency_department_log_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entry_id')->constrained('emergency_department_log_entries')->cascadeOnDelete();
            $table->string('section');
            $table->string('item_key');
            $table->unsignedInteger('count')->nullable();
            $table->string('status')->nullable();
            $table->boolean('adequate')->nullable();
            $table->boolean('checked')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->unique(['entry_id', 'section', 'item_key'], 'ed_log_answers_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('emergency_department_log_answers');
        Schema::dropIfExists('emergency_department_log_entries');
    }
};
