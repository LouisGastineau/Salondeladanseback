<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class InvitationCode extends Model
{
    use HasFactory;

    protected $table = 'invitation_codes';

    public $timestamps = false;

    protected $fillable = [
        'code',
        'isActive',
        'email',
        'statut_envoi',
        'envoye_at',
    ];

    protected $hidden = ['code'];

    protected function casts(): array
    {
        return [
            'isActive' => 'boolean',
            'created_at' => 'datetime',
            'envoye_at' => 'datetime',
        ];
    }

    public function user(): HasOne
    {
        return $this->hasOne(User::class, 'invitation_code_id');
    }
}
