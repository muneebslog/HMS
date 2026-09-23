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
        Schema::table('lab_fields', function (Blueprint $table) {
            $table->string('type')->default('numeric')->after('unit');
            $table->json('options')->nullable()->after('type');
        });

        DB::table('lab_fields')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('lab_field_ranges')
                    ->whereColumn('lab_field_ranges.lab_field_id', 'lab_fields.id');
            })
            ->update(['type' => 'text']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lab_fields', function (Blueprint $table) {
            $table->dropColumn(['type', 'options']);
        });
    }
};
