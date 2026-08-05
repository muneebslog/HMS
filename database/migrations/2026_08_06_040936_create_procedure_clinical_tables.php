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
        Schema::create('procedure_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('procedure_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type')->nullable();
            $table->foreignId('uploaded_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['procedure_id', 'type']);
        });

        Schema::create('procedure_vitals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('procedure_id')->constrained()->cascadeOnDelete();
            $table->timestamp('recorded_at');
            $table->unsignedSmallInteger('pulse')->nullable();
            $table->unsignedSmallInteger('bp_systolic')->nullable();
            $table->unsignedSmallInteger('bp_diastolic')->nullable();
            $table->unsignedSmallInteger('resp_rate')->nullable();
            $table->decimal('temp', 4, 1)->nullable();
            $table->string('cvp')->nullable();
            $table->string('iv_fluid')->nullable();
            $table->string('oral_ng')->nullable();
            $table->string('urine')->nullable();
            $table->string('stool')->nullable();
            $table->string('aspirate')->nullable();
            $table->string('drain')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['procedure_id', 'recorded_at']);
        });

        Schema::create('procedure_fetal_hearts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('procedure_id')->constrained()->cascadeOnDelete();
            $table->timestamp('recorded_at');
            $table->unsignedSmallInteger('fhr');
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['procedure_id', 'recorded_at']);
        });

        Schema::create('procedure_pre_op_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('procedure_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('give_bath')->default(false);
            $table->boolean('provide_hospital_dress')->default(false);
            $table->timestamp('npo_from')->nullable();
            $table->boolean('mark_operation_site')->default(false);
            $table->boolean('shave_and_prepare')->default(false);
            $table->unsignedTinyInteger('blood_pints')->nullable();
            $table->text('investigations')->nullable();
            $table->text('pre_medication')->nullable();
            $table->timestamp('send_to_ot_at')->nullable();
            $table->text('other_orders')->nullable();
            $table->string('operation_site')->nullable();
            $table->string('done_by')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('procedure_operation_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('procedure_id')->unique()->constrained()->cascadeOnDelete();
            $table->date('operated_on')->nullable();
            $table->time('started_at')->nullable();
            $table->time('ended_at')->nullable();
            $table->string('operation')->nullable();
            $table->string('surgeon')->nullable();
            $table->string('nurse')->nullable();
            $table->string('anaesthesia')->nullable();
            $table->text('findings')->nullable();
            $table->text('procedure_text')->nullable();
            $table->text('closure')->nullable();
            $table->text('drain')->nullable();
            $table->text('biopsy')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('procedure_delivery_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('procedure_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('labour_type')->nullable();
            $table->string('procedure_name')->nullable();
            $table->string('obstetrician')->nullable();
            $table->string('assistant')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->string('analgesia')->nullable();
            $table->text('delivery_details')->nullable();
            $table->string('labour_first_stage')->nullable();
            $table->string('labour_second_stage')->nullable();
            $table->string('labour_third_stage')->nullable();
            $table->text('complications')->nullable();
            $table->string('baby_sex')->nullable();
            $table->string('baby_weight')->nullable();
            $table->string('apgar_score')->nullable();
            $table->string('resuscitated_by')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('procedure_post_op_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('procedure_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('maintain_intake_output')->default(false);
            $table->timestamp('npo_till')->nullable();
            $table->text('antibiotics')->nullable();
            $table->text('iv_fluids')->nullable();
            $table->text('analgesics')->nullable();
            $table->text('antiemetics')->nullable();
            $table->text('biopsy')->nullable();
            $table->text('others')->nullable();
            $table->string('done_by')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('procedure_progress_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('procedure_id')->constrained()->cascadeOnDelete();
            $table->timestamp('noted_at');
            $table->text('note');
            $table->foreignId('doctor_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['procedure_id', 'noted_at']);
        });

        Schema::create('procedure_medications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('procedure_id')->constrained()->cascadeOnDelete();
            $table->string('form');
            $table->foreignId('medicine_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('injection_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('drip_base_id')->nullable()->constrained()->nullOnDelete();
            $table->string('custom_name')->nullable();
            $table->string('dose')->nullable();
            $table->string('route')->nullable();
            $table->text('notes')->nullable();
            $table->string('schedule_type');
            $table->json('schedule_times')->nullable();
            $table->unsignedSmallInteger('interval_hours')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->string('status')->default('active');
            $table->foreignId('prescribed_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['procedure_id', 'status']);
        });

        Schema::create('procedure_medication_doses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('procedure_medication_id')->constrained()->cascadeOnDelete();
            $table->timestamp('due_at');
            $table->string('status')->default('pending');
            $table->timestamp('given_at')->nullable();
            $table->foreignId('given_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['procedure_medication_id', 'due_at']);
            $table->index(['status', 'due_at']);
        });

        Schema::create('procedure_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('procedure_id')->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->timestamp('generated_at')->nullable();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('printed_at')->nullable();
            $table->foreignId('printed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('path')->nullable();
            $table->timestamps();

            $table->unique(['procedure_id', 'kind']);
        });

        Schema::create('procedure_discharge_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('procedure_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('blood_group')->nullable();
            $table->string('indication')->nullable();
            $table->timestamp('procedure_time')->nullable();
            $table->string('parity')->nullable();
            $table->string('baby_sex')->nullable();
            $table->string('baby_weight')->nullable();
            $table->string('baby_condition')->nullable();
            $table->text('rx_text')->nullable();
            $table->date('stitch_removal_date')->nullable();
            $table->text('outcome_summary')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('procedure_discharge_details');
        Schema::dropIfExists('procedure_documents');
        Schema::dropIfExists('procedure_medication_doses');
        Schema::dropIfExists('procedure_medications');
        Schema::dropIfExists('procedure_progress_notes');
        Schema::dropIfExists('procedure_post_op_orders');
        Schema::dropIfExists('procedure_delivery_notes');
        Schema::dropIfExists('procedure_operation_notes');
        Schema::dropIfExists('procedure_pre_op_orders');
        Schema::dropIfExists('procedure_fetal_hearts');
        Schema::dropIfExists('procedure_vitals');
        Schema::dropIfExists('procedure_attachments');
    }
};
