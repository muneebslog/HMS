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
        Schema::create('lab_api_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lab_invoice_id')->constrained()->cascadeOnDelete();
            $table->string('status');
            $table->json('request_payload')->nullable();
            $table->text('response_body')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('lab_case_url')->nullable();
            $table->timestamps();

            $table->index('lab_invoice_id');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lab_api_logs');
    }
};
