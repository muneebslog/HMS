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
        Schema::create('policy_journals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->text('incident');
            $table->text('resolution');
            $table->text('policy');
            $table->string('category')->nullable();
            $table->json('tags')->nullable();
            $table->date('effective_date')->nullable();
            $table->date('review_date')->nullable();
            $table->string('status')->default('active');
            $table->json('attachments')->nullable();
            $table->timestamps();

            $table->index('category');
            $table->index('status');
            $table->index('effective_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('policy_journals');
    }
};
