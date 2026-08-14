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
        Schema::table('medication_order_medicines', function (Blueprint $table) {
            $table->dropForeign(['medicine_id']);
        });

        Schema::table('medication_order_medicines', function (Blueprint $table) {
            $table->unsignedBigInteger('medicine_id')->nullable()->change();
        });

        Schema::table('medication_order_medicines', function (Blueprint $table) {
            $table->foreign('medicine_id')->references('id')->on('medicines')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('medication_order_medicines', function (Blueprint $table) {
            $table->dropForeign(['medicine_id']);
        });

        Schema::table('medication_order_medicines', function (Blueprint $table) {
            $table->unsignedBigInteger('medicine_id')->nullable(false)->change();
        });

        Schema::table('medication_order_medicines', function (Blueprint $table) {
            $table->foreign('medicine_id')->references('id')->on('medicines')->restrictOnDelete();
        });
    }
};
