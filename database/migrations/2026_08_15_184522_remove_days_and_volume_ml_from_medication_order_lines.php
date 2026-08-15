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
            $table->dropColumn('days');
        });

        Schema::table('medication_order_injections', function (Blueprint $table) {
            $table->dropColumn('volume_ml');
        });

        Schema::table('medication_order_drips', function (Blueprint $table) {
            $table->dropColumn('volume_ml');
        });

        Schema::table('medication_order_drip_additives', function (Blueprint $table) {
            $table->dropColumn('volume_ml');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('medication_order_medicines', function (Blueprint $table) {
            $table->unsignedInteger('days')->default(3)->after('dose');
        });

        Schema::table('medication_order_injections', function (Blueprint $table) {
            $table->decimal('volume_ml', 8, 2)->nullable()->after('comment');
        });

        Schema::table('medication_order_drips', function (Blueprint $table) {
            $table->decimal('volume_ml', 8, 2)->default(0)->after('drip_base_id');
        });

        Schema::table('medication_order_drip_additives', function (Blueprint $table) {
            $table->decimal('volume_ml', 8, 2)->default(0)->after('injection_id');
        });
    }
};
