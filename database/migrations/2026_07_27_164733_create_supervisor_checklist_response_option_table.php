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
        Schema::create('supervisor_checklist_response_option', function (Blueprint $table) {
            $table->id();
            $table->foreignId('response_id')->constrained('supervisor_checklist_responses')->cascadeOnDelete();
            $table->foreignId('option_id')->constrained('supervisor_checklist_options')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['response_id', 'option_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supervisor_checklist_response_option');
    }
};
