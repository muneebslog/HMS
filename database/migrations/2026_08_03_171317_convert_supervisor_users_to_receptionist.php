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
        DB::table('users')
            ->where('role', 'supervisor')
            ->update(['role' => 'receptionist']);

        DB::table('role_requests')
            ->where('requested_role', 'supervisor')
            ->where('status', 'pending')
            ->update(['requested_role' => 'receptionist']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Supervisors were permanently converted to receptionists; cannot restore safely.
    }
};
