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
        Schema::create('equipment_inspection_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('area');
            $table->date('checklist_date');
            $table->string('shift');
            $table->string('checked_by_name');
            $table->string('supervisor_name')->nullable();
            $table->json('sign_off')->nullable();
            $table->timestamp('submitted_at');
            $table->timestamps();

            $table->unique(['area', 'checklist_date', 'shift'], 'equipment_inspection_entries_unique_shift');
        });

        Schema::create('equipment_inspection_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entry_id')->constrained('equipment_inspection_entries')->cascadeOnDelete();
            $table->string('section');
            $table->string('item_key');
            $table->boolean('present')->nullable();
            $table->boolean('functional')->nullable();
            $table->boolean('clean')->nullable();
            $table->boolean('maint_req')->nullable();
            $table->boolean('checked')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->unique(['entry_id', 'section', 'item_key'], 'equipment_inspection_answers_unique');
        });

        Schema::create('equipment_inspection_register_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entry_id')->constrained('equipment_inspection_entries')->cascadeOnDelete();
            $table->date('item_date')->nullable();
            $table->string('department')->nullable();
            $table->string('equipment')->nullable();
            $table->text('problem')->nullable();
            $table->text('action_taken')->nullable();
            $table->string('technician')->nullable();
            $table->date('completed_date')->nullable();
            $table->string('signed')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('equipment_inspection_register_rows');
        Schema::dropIfExists('equipment_inspection_answers');
        Schema::dropIfExists('equipment_inspection_entries');
    }
};
