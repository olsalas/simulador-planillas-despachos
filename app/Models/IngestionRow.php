<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IngestionRow extends Model
{
    use HasFactory;

    protected $fillable = [
        'ingestion_batch_id',
        'row_number',
        'status',
        'raw_payload',
        'validation_errors',
    ];

    protected function casts(): array
    {
        return [
            'raw_payload' => 'array',
            'validation_errors' => 'array',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(IngestionBatch::class, 'ingestion_batch_id');
    }
}
