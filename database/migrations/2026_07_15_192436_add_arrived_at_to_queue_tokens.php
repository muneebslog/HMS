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
        Schema::table('queue_tokens', function (Blueprint $table) {
            $table->timestamp('arrived_at')->nullable()->after('origin');
        });

        DB::table('queue_tokens')
            ->where(function ($query) {
                $query->where('origin', 'walk_in')
                    ->orWhere('status', 'waiting');
            })
            ->update(['arrived_at' => DB::raw('created_at')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('queue_tokens', function (Blueprint $table) {
            $table->dropColumn('arrived_at');
        });
    }
};
