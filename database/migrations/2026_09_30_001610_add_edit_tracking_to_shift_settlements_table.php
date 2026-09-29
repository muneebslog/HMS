<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('shift_settlements', function (Blueprint $table) {
            $table->decimal('previous_received_amount', 12, 2)->nullable()->after('notes');
            $table->foreignId('edited_by')->nullable()->after('previous_received_amount')->constrained('users');
            $table->timestamp('edited_at')->nullable()->after('edited_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shift_settlements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('edited_by');
            $table->dropColumn(['previous_received_amount', 'edited_at']);
        });
    }
};
