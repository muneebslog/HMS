<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Random, unguessable code used in the patient's QR link to their results page.
     * (Receipt numbers are sequential, so they must not be used in a public link.)
     */
    public function up(): void
    {
        Schema::table('lab_invoices', function (Blueprint $table) {
            $table->string('public_token', 32)->nullable()->unique()->after('invoice_number');
        });

        DB::table('lab_invoices')->whereNull('public_token')->orderBy('id')->each(function ($invoice) {
            DB::table('lab_invoices')->where('id', $invoice->id)->update(['public_token' => Str::random(20)]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lab_invoices', function (Blueprint $table) {
            $table->dropUnique(['public_token']);
            $table->dropColumn('public_token');
        });
    }
};
