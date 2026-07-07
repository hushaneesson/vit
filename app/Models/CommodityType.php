<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommodityType extends Model
{
    protected $fillable = ['name', 'active', 'sort_order'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }
}
