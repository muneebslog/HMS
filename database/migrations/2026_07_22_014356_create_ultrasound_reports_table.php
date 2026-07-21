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
        Schema::create('ultrasound_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('queue_token_id')->unique()->constrained();
            $table->foreignId('patient_id')->constrained();
            $table->foreignId('doctor_id')->nullable()->constrained();
            $table->foreignId('service_queue_id')->constrained();
            $table->date('report_date');

            // Header
            $table->string('name');
            $table->unsignedTinyInteger('age')->nullable();
            $table->string('fetus_status')->nullable();

            // Measurements
            $table->string('bpd_meas')->nullable();
            $table->string('bpd_age')->nullable();
            $table->string('femur_meas')->nullable();
            $table->string('femur_age')->nullable();
            $table->string('ac_meas')->nullable();
            $table->string('ac_age')->nullable();
            $table->string('crl_meas')->nullable();
            $table->string('crl_age')->nullable();

            // Clinical details
            $table->string('gest_age')->nullable();
            $table->string('edd')->nullable();
            $table->string('heart_motion')->nullable();
            $table->string('placenta')->nullable();
            $table->string('placenta_grade')->nullable();
            $table->string('amniotic_fluid')->nullable();
            $table->string('presentation')->nullable();

            // Anatomy checkmarks
            $table->boolean('lt_ventricular')->default(false);
            $table->boolean('bpd_level')->default(false);
            $table->boolean('feral_stomach')->default(false);
            $table->boolean('kidneys')->default(false);
            $table->boolean('bladder')->default(false);
            $table->boolean('spine')->default(false);

            // Biophysical profile
            $table->string('bpp')->nullable();

            // Conclusion
            $table->string('conclusion_line1')->nullable();
            $table->string('conclusion_line2')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ultrasound_reports');
    }
};
