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
            $table->string('region', 100)->nullable()->after('primary_address')->comment('المنطقة');
            $table->string('locality', 100)->nullable()->after('region')->comment('المحلية');
            $table->string('branch', 100)->nullable()->after('locality')->comment('الشعبة');
            $table->string('mosque', 100)->nullable()->after('branch')->comment('المسجد');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('identities', function (Blueprint $table) {
            $table->dropColumn(['region', 'locality', 'branch', 'mosque']);
        });
    }
};

