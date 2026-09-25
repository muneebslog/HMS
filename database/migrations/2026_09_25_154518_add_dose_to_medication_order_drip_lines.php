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
        Schema::table('medication_order_drips', function (Blueprint $table) {
            $table->string('dose', 50)->nullable()->after('name');
        });

        Schema::table('medication_order_drip_additives', function (Blueprint $table) {
            $table->string('dose', 50)->nullable()->after('name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('medication_order_drip_additives', function (Blueprint $table) {
            $table->dropColumn('dose');
        });

        Schema::table('medication_order_drips', function (Blueprint $table) {
            $table->dropColumn('dose');
        });
    }
};
