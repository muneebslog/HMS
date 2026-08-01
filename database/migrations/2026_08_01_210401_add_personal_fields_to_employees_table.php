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
        Schema::table('employees', function (Blueprint $table) {
            $table->string('father_name')->nullable()->after('name');
            $table->string('cnic')->nullable()->unique()->after('father_name');
            $table->date('date_of_birth')->nullable()->after('cnic');
            $table->string('sex')->nullable()->after('date_of_birth');
            $table->string('religion_sect')->nullable()->after('sex');
            $table->string('caste')->nullable()->after('religion_sect');
            $table->string('marital_status')->nullable()->after('caste');
            $table->text('current_address')->nullable()->after('phone');
            $table->text('permanent_address')->nullable()->after('current_address');
            $table->string('emergency_contact')->nullable()->after('permanent_address');
            $table->string('languages')->nullable()->after('emergency_contact');
            $table->string('distance_time_from_hospital')->nullable()->after('languages');
            $table->boolean('undertaking_accepted')->default(false)->after('notes');
            $table->timestamp('undertaking_accepted_at')->nullable()->after('undertaking_accepted');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropUnique(['cnic']);
            $table->dropColumn([
                'father_name',
                'cnic',
                'date_of_birth',
                'sex',
                'religion_sect',
                'caste',
                'marital_status',
                'current_address',
                'permanent_address',
                'emergency_contact',
                'languages',
                'distance_time_from_hospital',
                'undertaking_accepted',
                'undertaking_accepted_at',
            ]);
        });
    }
};
