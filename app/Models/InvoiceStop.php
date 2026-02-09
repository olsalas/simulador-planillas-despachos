<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceStop extends Model
{
    use HasFactory;

    protected $fillable = [
        'driver_id',
        'branch_id',
        'service_date',
        'invoice_count',
        'planned_sequence',
        'status',
        'distance_from_previous_meters',
        'travel_time_from_previous_seconds',
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

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
