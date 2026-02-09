<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Depot extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'address',
        'latitude',
        'longitude',
        'is_active',
    ];

    public function drivers(): HasMany
    {
        return $this->hasMany(Driver::class);
    }
}
