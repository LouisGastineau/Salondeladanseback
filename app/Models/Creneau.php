<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Creneau extends Model
{
    use HasFactory;

    protected $table = 'creneaux';

    public $timestamps = false;

    protected $fillable = [
        'mission_id',
        'jour',
        'heure_debut',
        'heure_fin',
        'capacite_max',
    ];

    protected function casts(): array
    {
        return [
            'jour' => 'date:Y-m-d',
            'capacite_max' => 'integer',
        ];
    }

    public function mission(): BelongsTo
    {
        return $this->belongsTo(Mission::class, 'mission_id');
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class, 'creneau_id');
    }
}
