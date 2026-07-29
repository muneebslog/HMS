<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('lab_tests', function (Blueprint $table) {
            $table->dropUnique(['test_code']);
        });

        Schema::table('lab_invoice_items', function (Blueprint $table) {
            $table->string('sample')->nullable()->after('test_code');
        });

        DB::table('lab_invoice_items')
            ->orderBy('id')
            ->chunkById(100, function ($items): void {
                foreach ($items as $item) {
                    $sample = DB::table('lab_tests')
                        ->where('id', $item->lab_test_id)
                        ->value('sample');

                    if ($sample !== null) {
                        DB::table('lab_invoice_items')
                            ->where('id', $item->id)
                            ->update(['sample' => $sample]);
                    }
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lab_invoice_items', function (Blueprint $table) {
            $table->dropColumn('sample');
        });

        Schema::table('lab_tests', function (Blueprint $table) {
            $table->unique('test_code');
        });
    }
};
