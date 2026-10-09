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
        Schema::create('gyne_ultrasounds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('queue_token_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->date('scanned_on');
            $table->unsignedTinyInteger('fetus_count')->nullable();
            $table->unsignedTinyInteger('ga_weeks')->nullable();
            $table->unsignedTinyInteger('ga_days')->nullable();
            $table->unsignedSmallInteger('fetal_heart_rate')->nullable();
            $table->string('presentation')->nullable();
            $table->string('placenta')->nullable();
            $table->string('liquor')->nullable();
            $table->decimal('afi', 4, 1)->nullable();
            $table->unsignedSmallInteger('efw_grams')->nullable();
            $table->text('impression')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['patient_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gyne_ultrasounds');
    }
};
