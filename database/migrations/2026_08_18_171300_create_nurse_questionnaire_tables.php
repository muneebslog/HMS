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
        Schema::create('nurse_questionnaires', function (Blueprint $table) {
            $table->id();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedTinyInteger('interval_hours')->default(2);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('nurse_questionnaire_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('questionnaire_id')->constrained('nurse_questionnaires')->cascadeOnDelete();
            $table->text('question_text');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('nurse_questionnaire_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('questionnaire_id')->constrained('nurse_questionnaires')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('block_starts_at');
            $table->timestamp('block_ends_at');
            $table->timestamp('submitted_at');
            $table->timestamps();

            $table->unique(['questionnaire_id', 'user_id', 'block_starts_at'], 'nurse_questionnaire_entries_unique_block');
        });

        Schema::create('nurse_questionnaire_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entry_id')->constrained('nurse_questionnaire_entries')->cascadeOnDelete();
            $table->foreignId('question_id')->constrained('nurse_questionnaire_questions')->cascadeOnDelete();
            $table->string('answer');
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
        Schema::dropIfExists('nurse_questionnaire_responses');
        Schema::dropIfExists('nurse_questionnaire_entries');
        Schema::dropIfExists('nurse_questionnaire_questions');
        Schema::dropIfExists('nurse_questionnaires');
    }
};
