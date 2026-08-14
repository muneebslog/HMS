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
        Schema::table('medication_order_injections', function (Blueprint $table) {
            $table->dropForeign(['injection_id']);
        });

        Schema::table('medication_order_injections', function (Blueprint $table) {
            $table->unsignedBigInteger('injection_id')->nullable()->change();
        });

        Schema::table('medication_order_injections', function (Blueprint $table) {
            $table->foreign('injection_id')->references('id')->on('injections')->restrictOnDelete();
        });

        Schema::table('medication_order_drip_additives', function (Blueprint $table) {
            $table->dropForeign(['injection_id']);
        });

        Schema::table('medication_order_drip_additives', function (Blueprint $table) {
            $table->unsignedBigInteger('injection_id')->nullable()->change();
        });

        Schema::table('medication_order_drip_additives', function (Blueprint $table) {
            $table->foreign('injection_id')->references('id')->on('injections')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('medication_order_injections', function (Blueprint $table) {
            $table->dropForeign(['injection_id']);
        });

        Schema::table('medication_order_injections', function (Blueprint $table) {
            $table->unsignedBigInteger('injection_id')->nullable(false)->change();
        });

        Schema::table('medication_order_injections', function (Blueprint $table) {
            $table->foreign('injection_id')->references('id')->on('injections')->restrictOnDelete();
        });

        Schema::table('medication_order_drip_additives', function (Blueprint $table) {
            $table->dropForeign(['injection_id']);
        });

        Schema::table('medication_order_drip_additives', function (Blueprint $table) {
            $table->unsignedBigInteger('injection_id')->nullable(false)->change();
        });

        Schema::table('medication_order_drip_additives', function (Blueprint $table) {
            $table->foreign('injection_id')->references('id')->on('injections')->restrictOnDelete();
        });
    }
};
