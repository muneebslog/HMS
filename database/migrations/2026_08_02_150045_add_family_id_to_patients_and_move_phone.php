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
        Schema::table('patients', function (Blueprint $table) {
            $table->foreignId('family_id')
                ->nullable()
                ->after('id')
                ->constrained('families')
                ->nullOnDelete();
        });

        $phones = DB::table('patients')
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->distinct()
            ->pluck('phone');

        foreach ($phones as $phone) {
            $familyId = DB::table('families')->insertGetId([
                'phone' => $phone,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('patients')
                ->where('phone', $phone)
                ->update(['family_id' => $familyId]);
        }

        Schema::table('patients', function (Blueprint $table) {
            $table->dropColumn('phone');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->string('phone')->nullable()->after('cnic');
        });

        $families = DB::table('families')->whereNotNull('phone')->get();

        foreach ($families as $family) {
            DB::table('patients')
                ->where('family_id', $family->id)
                ->update(['phone' => $family->phone]);
        }

        Schema::table('patients', function (Blueprint $table) {
            $table->dropConstrainedForeignId('family_id');
        });
    }
};
