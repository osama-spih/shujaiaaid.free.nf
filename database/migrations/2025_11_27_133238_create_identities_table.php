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
        Schema::create('identities', function (Blueprint $table) {
            $table->id();
            $table->string('national_id', 20)->unique();
            $table->string('full_name', 120);
            $table->string('phone', 30);
            $table->string('marital_status', 40)->nullable();
            $table->unsignedTinyInteger('family_members_count')->default(0);
            $table->string('spouse_name', 120)->nullable();
            $table->string('spouse_phone', 30)->nullable();
            $table->string('primary_address', 190)->nullable();
            $table->string('job_title', 120)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('needs_review')->default(false);
            $table->timestamp('entered_at')->useCurrent();
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('identities');
    }
};
