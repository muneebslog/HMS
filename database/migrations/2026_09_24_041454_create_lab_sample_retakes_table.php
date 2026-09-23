<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A retake the lab asked for on an in-house test (sample missing, clotted, not enough...):
     * the lab requests it, reception calls the patient, then prints a no-charge retake slip
     * when the patient comes back.
     */
    public function up(): void
    {
        Schema::create('lab_sample_retakes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lab_invoice_item_id')->constrained()->cascadeOnDelete();
            $table->string('reason');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('patient_contacted_at')->nullable();
            $table->foreignId('patient_contacted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('slip_printed_at')->nullable()->index();
            $table->foreignId('slip_printed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lab_sample_retakes');
    }
};
