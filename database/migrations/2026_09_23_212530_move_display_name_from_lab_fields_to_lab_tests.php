<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The display name belongs on the test (printed as the report heading),
     * not on individual fields.
     */
    public function up(): void
    {
        if (Schema::hasColumn('lab_fields', 'display_name')) {
            Schema::table('lab_fields', function (Blueprint $table) {
                $table->dropColumn('display_name');
            });
        }

        Schema::table('lab_tests', function (Blueprint $table) {
            $table->string('display_name')->nullable()->after('test_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lab_tests', function (Blueprint $table) {
            $table->dropColumn('display_name');
        });

        Schema::table('lab_fields', function (Blueprint $table) {
            $table->string('display_name')->nullable()->after('name');
        });
    }
};
