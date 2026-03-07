<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanningScenarioStop extends Model
{
    use HasFactory;

    protected $fillable = [
        'planning_scenario_id',
        'branch_id',
        'stop_key',
        'status',
        'exclusion_reason',
        'branch_code',
        'branch_name',
        'branch_address',
        'latitude',
        'longitude',
        'invoice_count',
        'historical_sequence_min',
        'invoice_ids',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'invoice_ids' => 'array',
            'metadata' => 'array',
        ];
    }

    public function planningScenario(): BelongsTo
    {
        return $this->belongsTo(PlanningScenario::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
