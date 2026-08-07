<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CountryCode extends Model
{
    protected $fillable = ['code', 'name', 'id'];

    protected $keyType = 'string';

    protected $casts = [
        'id' => 'string',
    ];
}
