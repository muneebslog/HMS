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
        Schema::create('supervisor_checklist_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entry_id')->constrained('supervisor_checklist_entries')->cascadeOnDelete();
            $table->foreignId('question_id')->constrained('supervisor_checklist_questions')->cascadeOnDelete();
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->unique(['entry_id', 'question_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supervisor_checklist_responses');
    }
};
