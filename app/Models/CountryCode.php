<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CountryCode extends Model
{
    protected $fillable = ['code', 'name', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }
}
