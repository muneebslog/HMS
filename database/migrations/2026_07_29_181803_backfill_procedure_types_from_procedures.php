<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $names = DB::table('procedures')
            ->whereNotNull('name')
            ->where('name', '!=', '')
            ->distinct()
            ->orderBy('name')
            ->pluck('name');

        $now = now();

        foreach ($names as $name) {
            $typeId = DB::table('procedure_types')->insertGetId([
                'name' => $name,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('procedures')
                ->where('name', $name)
                ->update(['procedure_type_id' => $typeId]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('procedures')->update(['procedure_type_id' => null]);
        DB::table('procedure_types')->delete();
    }
};
