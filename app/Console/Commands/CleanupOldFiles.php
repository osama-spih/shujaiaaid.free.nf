<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class CleanupOldFiles extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cleanup:old-files {--days=7 : عدد الأيام للاحتفاظ بالملفات}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'تنظيف ملفات التصدير والاستيراد القديمة';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $days = (int) $this->option('days');
        $cutoffDate = Carbon::now()->subDays($days);
        
        $this->info("بدء تنظيف الملفات الأقدم من {$days} يوم...");
        
        $deletedCount = 0;
        $deletedSize = 0;
        
        // تنظيف ملفات التصدير
        $exportsDir = storage_path('app/exports');
        if (is_dir($exportsDir)) {
            $this->info("تنظيف ملفات التصدير...");
            $result = $this->cleanupDirectory($exportsDir, $cutoffDate);
            $deletedCount += $result['count'];
            $deletedSize += $result['size'];
            $this->info("تم حذف {$result['count']} ملف من التصدير ({$this->formatBytes($result['size'])})");
        }
        
        // تنظيف ملفات الاستيراد القديمة (إذا كانت موجودة)
        $importsDir = storage_path('app/imports');
        if (is_dir($importsDir)) {
            $this->info("تنظيف ملفات الاستيراد...");
            $result = $this->cleanupDirectory($importsDir, $cutoffDate);
            $deletedCount += $result['count'];
            $deletedSize += $result['size'];
            $this->info("تم حذف {$result['count']} ملف من الاستيراد ({$this->formatBytes($result['size'])})");
        }
        
        // تنظيف Jobs القديمة من قاعدة البيانات (أقدم من 30 يوم)
        $this->info("تنظيف Jobs القديمة من قاعدة البيانات...");
        $jobsDeleted = $this->cleanupOldJobs($cutoffDate);
        $this->info("تم حذف {$jobsDeleted} job من قاعدة البيانات");
        
        $this->info("✅ اكتمل التنظيف: تم حذف {$deletedCount} ملف ({$this->formatBytes($deletedSize)}) و {$jobsDeleted} job");
        
        Log::info('Cleanup completed', [
            'files_deleted' => $deletedCount,
            'size_freed' => $deletedSize,
            'jobs_deleted' => $jobsDeleted,
        ]);
        
        return Command::SUCCESS;
    }
    
    /**
     * تنظيف ملفات من مجلد معين
     */
    private function cleanupDirectory(string $directory, Carbon $cutoffDate): array
    {
        $count = 0;
        $size = 0;
        
        if (!is_dir($directory)) {
            return ['count' => 0, 'size' => 0];
        }
        
        $files = glob($directory . DIRECTORY_SEPARATOR . '*');
        
        foreach ($files as $file) {
            if (is_file($file)) {
                $fileTime = Carbon::createFromTimestamp(filemtime($file));
                
                if ($fileTime->lt($cutoffDate)) {
                    $fileSize = filesize($file);
                    if (@unlink($file)) {
                        $count++;
                        $size += $fileSize;
                    } else {
                        $this->warn("فشل حذف الملف: {$file}");
                    }
                }
            }
        }
        
        return ['count' => $count, 'size' => $size];
    }
    
    /**
     * تنظيف Jobs القديمة من قاعدة البيانات
     */
    private function cleanupOldJobs(Carbon $cutoffDate): int
    {
        $count = 0;
        
        // تنظيف Export Jobs
        $exportJobsDeleted = \App\Models\ExportJob::where('created_at', '<', $cutoffDate)
            ->whereIn('status', ['completed', 'failed'])
            ->delete();
        $count += $exportJobsDeleted;
        
        // تنظيف Import Jobs
        $importJobsDeleted = \App\Models\ImportJob::where('created_at', '<', $cutoffDate)
            ->whereIn('status', ['completed', 'failed'])
            ->delete();
        $count += $importJobsDeleted;
        
        return $count;
    }
    
    /**
     * تنسيق حجم الملف
     */
    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }
}
