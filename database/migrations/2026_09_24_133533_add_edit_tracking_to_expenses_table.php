<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reception may edit an expense; the edit goes back for approval, and the values
     * before the edit are kept so the approver can see what changed.
     */
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->string('previous_name')->nullable()->after('review_note');
            $table->decimal('previous_amount', 12, 2)->nullable()->after('previous_name');
            $table->timestamp('edited_at')->nullable()->after('previous_amount');
            $table->foreignId('edited_by')->nullable()->after('edited_at')->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('edited_by');
            $table->dropColumn(['previous_name', 'previous_amount', 'edited_at']);
        });
    }
};
