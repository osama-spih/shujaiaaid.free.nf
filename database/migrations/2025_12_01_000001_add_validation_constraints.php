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
        // إضافة constraints على جدول identities
        Schema::table('identities', function (Blueprint $table) {
            // national_id موجود كـ unique بالفعل من migration سابقة، لا نحتاج لإضافته مرة أخرى
            
            // إضافة check constraints عبر raw SQL (مع التحقق من عدم وجوده)
            try {
                DB::statement('ALTER TABLE identities ADD CONSTRAINT chk_family_members_count CHECK (family_members_count >= 0 AND family_members_count <= 30)');
            } catch (\Exception $e) {
                // Constraint موجود بالفعل، نتجاهل الخطأ
                if (strpos($e->getMessage(), 'Duplicate key name') === false && strpos($e->getMessage(), 'already exists') === false) {
                    throw $e;
                }
            }
        });

        // إضافة constraints على جدول family_members
        Schema::table('family_members', function (Blueprint $table) {
            // التأكد من أن member_name مطلوب (فقط إذا لم يكن مطلوباً بالفعل)
            if (Schema::hasColumn('family_members', 'member_name')) {
                $table->string('member_name', 120)->nullable(false)->change();
            }
            
            // التأكد من أن relation مطلوب (فقط إذا لم يكن مطلوباً بالفعل)
            if (Schema::hasColumn('family_members', 'relation')) {
                $table->string('relation', 60)->nullable(false)->change();
            }
        });

        // إضافة indexes لتحسين الأداء (مع التحقق من عدم وجودها)
        Schema::table('identities', function (Blueprint $table) {
            // national_id له unique constraint بالفعل (يخلق index تلقائياً)، لا نحتاج index إضافي
            // لكن نضيف indexes أخرى
            try {
                $table->index('needs_review');
            } catch (\Exception $e) {
                // Index موجود بالفعل
            }
            try {
                $table->index('created_at');
            } catch (\Exception $e) {
                // Index موجود بالفعل
            }
        });

        Schema::table('family_members', function (Blueprint $table) {
            // identity_id له foreign key (يخلق index تلقائياً في معظم الحالات)
            // لكن نتحقق ونضيف إذا لم يكن موجوداً
            try {
                $table->index('identity_id');
            } catch (\Exception $e) {
                // Index موجود بالفعل (من foreign key)
            }
            try {
                $table->index('national_id');
            } catch (\Exception $e) {
                // Index موجود بالفعل
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('identities', function (Blueprint $table) {
            try {
                DB::statement('ALTER TABLE identities DROP CONSTRAINT IF EXISTS chk_family_members_count');
            } catch (\Exception $e) {
                // Constraint غير موجود
            }
            
            // national_id index هو جزء من unique constraint، لا نحذفه
            try {
                $table->dropIndex(['needs_review']);
            } catch (\Exception $e) {
                // Index غير موجود
            }
            try {
                $table->dropIndex(['created_at']);
            } catch (\Exception $e) {
                // Index غير موجود
            }
        });

        Schema::table('family_members', function (Blueprint $table) {
            try {
                $table->dropIndex(['identity_id']);
            } catch (\Exception $e) {
                // Index غير موجود (قد يكون جزء من foreign key)
            }
            try {
                $table->dropIndex(['national_id']);
            } catch (\Exception $e) {
                // Index غير موجود
            }
        });
    }
};

