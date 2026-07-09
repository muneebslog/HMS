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
        Schema::table('lab_tests', function (Blueprint $table) {
            $table->string('sample')->nullable()->after('test_price');
            $table->string('test_code')->nullable()->change();
            $table->string('time_required')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lab_tests', function (Blueprint $table) {
            $table->dropColumn('sample');
            $table->string('test_code')->nullable(false)->change();
            $table->string('time_required')->nullable(false)->change();
        });
    }
};
