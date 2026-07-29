<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $numbers = DB::table('procedures')
            ->whereNotNull('room_number')
            ->where('room_number', '!=', '')
            ->distinct()
            ->orderBy('room_number')
            ->pluck('room_number');
        $now = now();

        foreach ($numbers as $number) {
            $roomId = DB::table('rooms')->insertGetId([
                'number' => $number,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('procedures')
                ->where('room_number', $number)
                ->update(['room_id' => $roomId]);
        }
    }

    public function down(): void
    {
        DB::table('procedures')->update(['room_id' => null]);
        DB::table('rooms')->delete();
    }
};
