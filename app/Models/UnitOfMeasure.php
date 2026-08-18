<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UnitOfMeasure extends Model
{
    protected $table = 'units_of_measure';

    protected $fillable = ['code', 'description', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
