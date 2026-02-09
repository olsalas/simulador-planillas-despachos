<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IngestionBatch extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'original_filename',
        'uploaded_by',
        'status',
        'total_rows',
        'valid_rows',
        'invalid_rows',
        'summary',
    ];

    protected function casts(): array
    {
        return [
            'summary' => 'array',
        ];
    }

    public function rows(): HasMany
    {
        return $this->hasMany(IngestionRow::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
