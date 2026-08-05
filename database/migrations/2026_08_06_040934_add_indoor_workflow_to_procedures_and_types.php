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
        Schema::table('procedure_types', function (Blueprint $table) {
            $table->boolean('requires_birth_certificate')->default(false)->after('is_active');
            $table->boolean('requires_fetal_heart')->default(false)->after('requires_birth_certificate');
            $table->string('note_style')->default('operation')->after('requires_fetal_heart');
        });

        Schema::table('procedures', function (Blueprint $table) {
            $table->timestamp('file_printed_at')->nullable()->after('admitted_at');
            $table->foreignId('file_printed_by')->nullable()->after('file_printed_at')->constrained('users')->nullOnDelete();
            $table->timestamp('consent_completed_at')->nullable()->after('file_printed_by');
            $table->timestamp('pre_op_completed_at')->nullable()->after('consent_completed_at');
            $table->string('pre_op_done_by')->nullable()->after('pre_op_completed_at');
            $table->foreignId('pre_op_completed_by')->nullable()->after('pre_op_done_by')->constrained('users')->nullOnDelete();
            $table->timestamp('operation_started_at')->nullable()->after('pre_op_completed_by');
            $table->timestamp('operation_completed_at')->nullable()->after('operation_started_at');
            $table->timestamp('post_op_completed_at')->nullable()->after('operation_completed_at');
            $table->foreignId('post_op_completed_by')->nullable()->after('post_op_completed_at')->constrained('users')->nullOnDelete();
            $table->timestamp('discharged_at')->nullable()->after('post_op_completed_by');
            $table->foreignId('discharged_by')->nullable()->after('discharged_at')->constrained('users')->nullOnDelete();
        });

        $this->seedProcedureTypeFlags();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('procedures', function (Blueprint $table) {
            $table->dropConstrainedForeignId('file_printed_by');
            $table->dropConstrainedForeignId('pre_op_completed_by');
            $table->dropConstrainedForeignId('post_op_completed_by');
            $table->dropConstrainedForeignId('discharged_by');
            $table->dropColumn([
                'file_printed_at',
                'consent_completed_at',
                'pre_op_completed_at',
                'pre_op_done_by',
                'operation_started_at',
                'operation_completed_at',
                'post_op_completed_at',
                'discharged_at',
            ]);
        });

        Schema::table('procedure_types', function (Blueprint $table) {
            $table->dropColumn([
                'requires_birth_certificate',
                'requires_fetal_heart',
                'note_style',
            ]);
        });
    }

    /**
     * Apply sensible defaults for known procedure type names.
     */
    private function seedProcedureTypeFlags(): void
    {
        $types = DB::table('procedure_types')->select('id', 'name')->get();

        foreach ($types as $type) {
            $name = mb_strtolower((string) $type->name);
            $isDelivery = str_contains($name, 'svd')
                || str_contains($name, 'lscs')
                || str_contains($name, 'delivery')
                || str_contains($name, 'cesarean')
                || str_contains($name, 'caesarean');
            $isSvd = str_contains($name, 'svd') || str_contains($name, 'vaginal');
            $isLscs = str_contains($name, 'lscs') || str_contains($name, 'cesarean') || str_contains($name, 'caesarean');

            DB::table('procedure_types')->where('id', $type->id)->update([
                'requires_birth_certificate' => $isDelivery,
                'requires_fetal_heart' => $isSvd || $isLscs,
                'note_style' => $isSvd ? 'delivery' : 'operation',
            ]);
        }
    }
};
