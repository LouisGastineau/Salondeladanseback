<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdminHistory extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['avant' => 'array', 'apres' => 'array', 'created_at' => 'datetime'];
    }
}
