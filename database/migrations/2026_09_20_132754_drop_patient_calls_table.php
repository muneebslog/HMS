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
        Schema::dropIfExists('patient_calls');

        if (Schema::hasTable('role_page_permissions')) {
            DB::table('role_page_permissions')
                ->where('route_name', 'reception.patient-calling')
                ->delete();
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::create('patient_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('queue_token_id')->constrained()->cascadeOnDelete();
            $table->foreignId('called_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('called_at');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }
};
