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
        Schema::create('gyne_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('queue_token_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('gravida')->nullable();
            $table->unsignedTinyInteger('para')->nullable();
            $table->unsignedTinyInteger('living_children')->nullable();
            $table->unsignedTinyInteger('miscarriages')->nullable();
            $table->date('married_since')->nullable();
            $table->date('last_pregnancy_at')->nullable();
            $table->date('lmp')->nullable();
            $table->json('problems')->nullable();
            $table->text('problems_other')->nullable();
            $table->json('operations')->nullable();
            $table->text('operations_other')->nullable();
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
        Schema::dropIfExists('gyne_histories');
    }
};
