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
        Schema::table('supervisor_checklist_options', function (Blueprint $table) {
            $table->boolean('is_no')->default(false)->after('option_text');
        });

        DB::table('supervisor_checklist_options')
            ->whereRaw('LOWER(option_text) = ?', ['no'])
            ->update(['is_no' => true]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('supervisor_checklist_options', function (Blueprint $table) {
            $table->dropColumn('is_no');
        });
    }
};
