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
        Schema::table('drip_bases', function (Blueprint $table) {
            $table->boolean('show_on_er')->default(false)->after('default_volume_ml');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('drip_bases', function (Blueprint $table) {
            $table->dropColumn('show_on_er');
        });
    }
};
