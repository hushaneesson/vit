<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClassificationType extends Model
{
    protected $fillable = [
        'key',
        'label',
        'description',
        'is_always_required',
        'active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_always_required' => 'boolean',
            'active' => 'boolean',
        ];
    }
}
