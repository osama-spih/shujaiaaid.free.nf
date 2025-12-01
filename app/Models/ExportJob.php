<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExportJob extends Model
{
    protected $fillable = [
        'job_id',
        'file_path',
        'file_name',
        'selected_fields',
        'direction',
        'search',
        'status',
        'total_rows',
        'processed_rows',
        'file_url',
        'message',
        'error_message',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'selected_fields' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function getProgressPercentageAttribute(): float
    {
        if ($this->total_rows === 0) {
            return 0;
        }
        return min(100, ($this->processed_rows / $this->total_rows) * 100);
    }

    public function getEstimatedTimeRemainingAttribute(): ?int
    {
        if ($this->processed_rows === 0 || !$this->started_at || $this->status !== 'processing') {
            return null;
        }

        $elapsed = now()->diffInSeconds($this->started_at);
        if ($elapsed === 0) {
            return null;
        }
        $rate = $this->processed_rows / $elapsed; // rows per second
        if ($rate === 0) {
            return null;
        }
        $remaining = ($this->total_rows - $this->processed_rows) / $rate;

        return (int) $remaining;
    }
}
