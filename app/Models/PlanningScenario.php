<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlanningScenario extends Model
{
    use HasFactory;

    protected $fillable = [
        'depot_id',
        'created_by',
        'service_date',
        'name',
        'status',
        'configuration',
        'summary',
        'last_generated_at',
    ];

    protected function casts(): array
    {
        return [
            'service_date' => 'date',
            'configuration' => 'array',
            'summary' => 'array',
            'last_generated_at' => 'datetime',
        ];
    }

    public function depot(): BelongsTo
    {
        return $this->belongsTo(Depot::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function stops(): HasMany
    {
        return $this->hasMany(PlanningScenarioStop::class);
    }

    public function journeys(): HasMany
    {
        return $this->hasMany(PlanningScenarioJourney::class);
    }
}
