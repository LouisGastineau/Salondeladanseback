<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Edition extends Model
{
    use HasFactory;

    protected $table = 'editions';

    public $timestamps = false;

    protected $fillable = [
        'nom',
        'date_debut',
        'date_fin',
        'isActive',
    ];

    protected function casts(): array
    {
        return [
            'date_debut' => 'date:Y-m-d',
            'date_fin' => 'date:Y-m-d',
            'isActive' => 'boolean',
        ];
    }

    public function missions(): HasMany
    {
        return $this->hasMany(Mission::class, 'edition_id');
    }

    public function creneaux(): HasManyThrough
    {
        return $this->hasManyThrough(Creneau::class, Mission::class, 'edition_id', 'mission_id');
    }
}
