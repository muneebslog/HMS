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
        Schema::table('medicines', function (Blueprint $table) {
            $table->integer('stock_quantity')->default(0)->after('is_active');
        });

        Schema::table('injections', function (Blueprint $table) {
            $table->integer('stock_quantity')->default(0)->after('is_active');
        });

        Schema::table('drip_bases', function (Blueprint $table) {
            $table->integer('stock_quantity')->default(0)->after('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('medicines', function (Blueprint $table) {
            $table->dropColumn('stock_quantity');
        });

        Schema::table('injections', function (Blueprint $table) {
            $table->dropColumn('stock_quantity');
        });

        Schema::table('drip_bases', function (Blueprint $table) {
            $table->dropColumn('stock_quantity');
        });
    }
};
