<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per test found on the partner lab's portal, waiting for the lab to
     * attach its report to one of our outsourced tests.
     */
    public function up(): void
    {
        Schema::create('partner_lab_reports', function (Blueprint $table) {
            $table->id();
            $table->string('source', 32);
            $table->string('partner_test_id', 64);
            $table->string('partner_case_no', 64)->nullable()->index();
            $table->string('partner_patient_no', 64)->nullable();
            $table->string('patient_name')->nullable();
            $table->string('patient_age', 32)->nullable();
            $table->string('patient_gender', 16)->nullable();
            $table->timestamp('registered_at')->nullable()->index();
            $table->string('reference')->nullable();
            $table->string('test_code', 32)->nullable();
            $table->string('test_name')->nullable();
            $table->string('status', 64)->nullable();
            $table->string('report_url', 512)->nullable();
            $table->timestamp('ready_at')->nullable()->index();
            $table->timestamp('last_seen_at')->nullable();
            $table->foreignId('lab_invoice_item_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('attached_at')->nullable();
            $table->foreignId('attached_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('ignored_at')->nullable();
            $table->foreignId('ignored_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['source', 'partner_test_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('partner_lab_reports');
    }
};
