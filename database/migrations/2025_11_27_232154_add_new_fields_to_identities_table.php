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
        Schema::table('identities', function (Blueprint $table) {
            $table->string('spouse_national_id', 20)->nullable()->after('spouse_phone');
            $table->string('previous_address', 190)->nullable()->after('primary_address');
            $table->string('housing_type', 60)->nullable()->after('previous_address');
            $table->string('health_status', 30)->nullable()->after('job_title');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('identities', function (Blueprint $table) {
            $table->dropColumn(['spouse_national_id', 'previous_address', 'housing_type', 'health_status']);
        });
    }
};
