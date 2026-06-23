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
        Schema::create('queue_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_queue_id')->constrained();
            $table->foreignId('invoice_item_id')->constrained();
            $table->unsignedInteger('token_number');
            $table->string('status')->default('waiting');
            $table->timestamps();

            $table->unique(['service_queue_id', 'token_number']);
            $table->index(['invoice_item_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('queue_tokens');
    }
};
