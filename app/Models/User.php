<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens;
    use HasFactory;

    protected $table = 'users';

    public $timestamps = false;

    protected $fillable = [
        'nom',
        'prenom',
        'email',
        'telephone',
        'password',
        'photo_path',
        'isMineur',
    ];

    public const ROLE_BENEVOLE = 'benevole';

    public const ROLE_ADMIN = 'admin';

    public const PLANNING_BROUILLON = 'brouillon';

    public const PLANNING_VALIDE = 'valide';

    protected $hidden = ['password', 'invitation_code_id', 'invitationCode'];

    // Authentication is token-based: the schema has no remember_token column.
    protected $rememberTokenName = null;

    protected function casts(): array
    {
        return [
            'isMineur' => 'boolean',
            'password' => 'hashed',
        ];
    }

    public function invitationCode(): BelongsTo
    {
        return $this->belongsTo(InvitationCode::class, 'invitation_code_id');
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class, 'user_id');
    }
}
