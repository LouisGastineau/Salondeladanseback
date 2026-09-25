<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailNotification extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['contenu' => 'array', 'envoye_at' => 'datetime'];
    }
}
