<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImportJob extends Model
{
    protected $fillable = [
        'job_id',
        'file_path',
        'file_name',
        'selected_fields',
        'direction',
        'status',
        'total_rows',
        'processed_rows',
        'imported',
        'created',
        'updated',
        'errors_count',
        'errors',
        'message',
        'error_message',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'selected_fields' => 'array',
        'errors' => 'array',
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
        if ($this->processed_rows === 0 || !$this->started_at) {
            return null;
        }

        $elapsed = now()->diffInSeconds($this->started_at);
        $rate = $this->processed_rows / $elapsed; // rows per second
        $remaining = ($this->total_rows - $this->processed_rows) / $rate;

        return (int) $remaining;
    }
}
