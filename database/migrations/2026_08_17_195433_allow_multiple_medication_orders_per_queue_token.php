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
            $table->dropUnique(['queue_token_id']);
            $table->index('queue_token_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('medication_orders', function (Blueprint $table) {
            $table->dropIndex(['queue_token_id']);
            $table->unique('queue_token_id');
        });
    }
};
