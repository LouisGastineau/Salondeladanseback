<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Mission extends Model
{
    use HasFactory;

    protected $table = 'missions';

    public $timestamps = false;

    protected $fillable = [
        'edition_id',
        'nom',
        'isSensible',
    ];

    protected function casts(): array
    {
        return [
            'isSensible' => 'boolean',
        ];
    }

    public function edition(): BelongsTo
    {
        return $this->belongsTo(Edition::class, 'edition_id');
    }

    public function creneaux(): HasMany
    {
        return $this->hasMany(Creneau::class, 'mission_id');
    }
}
