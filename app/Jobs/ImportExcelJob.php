<?php

namespace App\Jobs;

use App\Http\Controllers\Api\AdminExportController;
use App\Models\ImportJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ImportExcelJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 1800; // 30 minutes للملفات الكبيرة (18,000+ صف)
    public $tries = 1;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public string $importJobId,
        public string $filePath,
        public string $fileName,
        public array $selectedFields,
        public string $direction
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $importJob = ImportJob::where('job_id', $this->importJobId)->first();
        
        if (!$importJob) {
            Log::error('Import job not found', ['job_id' => $this->importJobId]);
            return;
        }

        try {
            // Verify file exists before processing
            if (!file_exists($this->filePath)) {
                throw new \Exception('الملف غير موجود: ' . $this->filePath);
            }
            
            Log::info('Starting import job', [
                'job_id' => $this->importJobId,
                'file_path' => $this->filePath,
                'file_exists' => file_exists($this->filePath),
                'file_size' => file_exists($this->filePath) ? filesize($this->filePath) : 0,
            ]);
            
            $importJob->update([
                'status' => 'processing',
                'started_at' => now(),
            ]);

            // Execute import using controller method
            $controller = new AdminExportController();
            $result = $controller->executeImport(
                $this->filePath,
                $this->selectedFields,
                $this->direction,
                $this->importJobId
            );

            // Update import job with results
            $importJob->update([
                'status' => 'completed',
                'completed_at' => now(),
                'processed_rows' => $result['total_rows'],
                'imported' => $result['imported'],
                'created' => $result['created'],
                'updated' => $result['updated'],
                'errors_count' => $result['errors_count'],
                'errors' => $result['errors'],
                'message' => $result['message'],
            ]);

            // Clean up uploaded file
            if (file_exists($this->filePath)) {
                @unlink($this->filePath);
            }

        } catch (\Exception $e) {
            Log::error('Import job failed', [
                'job_id' => $this->importJobId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $importJob->update([
                'status' => 'failed',
                'completed_at' => now(),
                'error_message' => $e->getMessage(),
            ]);

            // Clean up uploaded file
            if (file_exists($this->filePath)) {
                @unlink($this->filePath);
            }

            throw $e;
        }
    }
}
