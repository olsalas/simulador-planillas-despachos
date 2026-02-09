<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RouteBatch extends Model
{
    use HasFactory;

    protected $fillable = [
        'driver_id',
        'service_date',
        'source_ingestion_batch_id',
        'total_invoices',
        'total_stops',
        'pending_invoices',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'service_date' => 'date',
        ];
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function sourceIngestionBatch(): BelongsTo
    {
        return $this->belongsTo(IngestionBatch::class, 'source_ingestion_batch_id');
    }
}
