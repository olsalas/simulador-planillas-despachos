<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invoice extends Model
{
    use HasFactory;

    protected $fillable = [
        'ingestion_batch',
        'external_invoice_id',
        'invoice_number',
        'driver_id',
        'branch_id',
        'service_date',
        'historical_sequence',
        'historical_latitude',
        'historical_longitude',
        'status',
        'outlier_reason',
        'raw_payload',
    ];

    protected function casts(): array
    {
        return [
            'service_date' => 'date',
            'raw_payload' => 'array',
        ];
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
