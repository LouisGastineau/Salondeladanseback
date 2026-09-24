<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Reservation extends Model
{
    use HasFactory;

    protected $table = 'reservations';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'creneau_id',
    ];

    public const EN_ATTENTE = 'en_attente';

    public const ACCEPTEE = 'acceptee';

    public const REFUSEE = 'refusee';

    public const STATUT_BROUILLON = 'brouillon';

    public const STATUT_VALIDE = 'valide';

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'user_id' => 'integer',
            'creneau_id' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function creneau(): BelongsTo
    {
        return $this->belongsTo(Creneau::class, 'creneau_id');
    }
}
