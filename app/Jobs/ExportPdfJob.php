<?php

namespace App\Jobs;

use App\Http\Controllers\Api\AdminExportController;
use App\Models\ExportJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ExportPdfJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 1800; // 30 minutes for large PDF exports
    public $tries = 1;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public string $exportJobId,
        public array $selectedFields,
        public string $direction,
        public ?string $search = null,
        public ?string $status = null
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $exportJob = ExportJob::where('job_id', $this->exportJobId)->first();
        
        if (!$exportJob) {
            Log::error('Export job not found', ['job_id' => $this->exportJobId]);
            return;
        }

        try {
            $exportJob->update([
                'status' => 'processing',
                'started_at' => now(),
            ]);

            // Execute PDF export using controller method
            $controller = new AdminExportController();
            $result = $controller->executeExportPdf(
                $this->selectedFields,
                $this->direction,
                $this->search,
                $this->status,
                $this->exportJobId
            );

            // Update export job with results
            $exportJob->update([
                'status' => 'completed',
                'completed_at' => now(),
                'processed_rows' => $result['total_rows'],
                'file_path' => $result['file_path'],
                'file_url' => $result['file_url'],
                'message' => $result['message'],
            ]);

        } catch (\Exception $e) {
            Log::error('Export PDF job failed', [
                'job_id' => $this->exportJobId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $exportJob->update([
                'status' => 'failed',
                'completed_at' => now(),
                'error_message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}

