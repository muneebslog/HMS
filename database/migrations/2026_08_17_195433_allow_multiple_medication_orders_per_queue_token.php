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
        Schema::table('medication_orders', function (Blueprint $table) {
            $table->dropForeign(['queue_token_id']);
        });

        Schema::table('medication_orders', function (Blueprint $table) {
            $table->dropUnique(['queue_token_id']);
        });

        Schema::table('medication_orders', function (Blueprint $table) {
            $table->foreign('queue_token_id')->references('id')->on('queue_tokens')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('medication_orders', function (Blueprint $table) {
            $table->dropForeign(['queue_token_id']);
        });

        Schema::table('medication_orders', function (Blueprint $table) {
            $table->unique('queue_token_id');
        });

        Schema::table('medication_orders', function (Blueprint $table) {
            $table->foreign('queue_token_id')->references('id')->on('queue_tokens')->cascadeOnDelete();
        });
    }
};
