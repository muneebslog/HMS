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
            $table->string('report_layout')->nullable()->after('is_active');
            $table->text('report_note')->nullable()->after('report_layout');
            $table->boolean('report_show_ranges')->default(true)->after('report_note');
            $table->string('report_custom_template')->nullable()->after('report_show_ranges');
        });

        Schema::table('lab_test_field', function (Blueprint $table) {
            $table->string('section')->nullable()->after('display_order');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lab_test_field', function (Blueprint $table) {
            $table->dropColumn('section');
        });

        Schema::table('lab_tests', function (Blueprint $table) {
            $table->dropColumn([
                'report_layout',
                'report_note',
                'report_show_ranges',
                'report_custom_template',
            ]);
        });
    }
};
