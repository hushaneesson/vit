<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommodityType extends Model
{
    protected $fillable = ['name', 'approved'];

    protected function casts(): array
    {
        return ['approved' => 'boolean'];
    }
}
