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
        'planning_scenario_journey_id',
        'assigned_driver_id',
        'suggested_sequence',
        'assignment_reason',
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

    public function planningScenarioJourney(): BelongsTo
    {
        return $this->belongsTo(PlanningScenarioJourney::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function assignedDriver(): BelongsTo
    {
        return $this->belongsTo(Driver::class, 'assigned_driver_id');
    }
}
