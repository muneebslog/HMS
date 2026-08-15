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
        Schema::table('injections', function (Blueprint $table) {
            $table->string('default_administration_type')->default('im')->after('short_form');
            $table->dropColumn('default_volume_ml');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('injections', function (Blueprint $table) {
            $table->decimal('default_volume_ml', 8, 2)->nullable()->after('short_form');
            $table->dropColumn('default_administration_type');
        });
    }
};
