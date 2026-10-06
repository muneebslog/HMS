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
        Schema::table('doctors', function (Blueprint $table) {
            $table->boolean('has_medication_page')
                ->default(false)
                ->after('is_gynecologist')
                ->comment('Grants access to the doctor medication page.');
        });

        DB::table('doctors')
            ->whereIn('user_id', DB::table('medication_orders')->whereNotNull('prescribed_by')->select('prescribed_by'))
            ->update(['has_medication_page' => true]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('doctors', function (Blueprint $table) {
            $table->dropColumn('has_medication_page');
        });
    }
};
