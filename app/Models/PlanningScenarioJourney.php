<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlanningScenarioJourney extends Model
{
    use HasFactory;

    protected $fillable = [
        'planning_scenario_id',
        'driver_id',
        'name',
        'status',
        'total_stops',
        'total_invoices',
        'summary',
    ];

    protected function casts(): array
    {
        return [
            'summary' => 'array',
        ];
    }

    public function planningScenario(): BelongsTo
    {
        return $this->belongsTo(PlanningScenario::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function stops(): HasMany
    {
        return $this->hasMany(PlanningScenarioStop::class);
    }
}
