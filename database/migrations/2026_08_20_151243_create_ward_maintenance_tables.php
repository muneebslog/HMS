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
        Schema::create('ward_maintenance_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('checklist_date');
            $table->string('shift');
            $table->string('checked_by_name');
            $table->string('supervisor_name')->nullable();
            $table->string('checked_by_time')->nullable();
            $table->string('supervisor_time')->nullable();
            $table->string('patient_safety_fault')->nullable();
            $table->string('patient_safety_reported')->nullable();
            $table->string('room_unavailable')->nullable();
            $table->text('beds_out_of_service')->nullable();
            $table->text('reason_remarks')->nullable();
            $table->text('supervisor_remarks')->nullable();
            $table->timestamp('submitted_at');
            $table->timestamps();

            $table->unique(['checklist_date', 'shift'], 'ward_maintenance_entries_unique_shift');
        });

        Schema::create('ward_maintenance_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entry_id')->constrained('ward_maintenance_entries')->cascadeOnDelete();
            $table->string('section');
            $table->string('item_key');
            $table->string('location_key')->default('');
            $table->string('status')->nullable();
            $table->boolean('available')->nullable();
            $table->boolean('functional')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->unique(['entry_id', 'section', 'item_key', 'location_key'], 'ward_maintenance_answers_unique');
        });

        Schema::create('ward_maintenance_faults', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entry_id')->constrained('ward_maintenance_entries')->cascadeOnDelete();
            $table->string('fault_time')->nullable();
            $table->string('bed_room')->nullable();
            $table->text('description')->nullable();
            $table->string('priority')->nullable();
            $table->string('reported_to')->nullable();
            $table->text('action_taken')->nullable();
            $table->boolean('resolved')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ward_maintenance_faults');
        Schema::dropIfExists('ward_maintenance_answers');
        Schema::dropIfExists('ward_maintenance_entries');
    }
};
