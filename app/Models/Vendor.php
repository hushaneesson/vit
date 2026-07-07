<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * General vendor (company) data — created and managed only by the admin.
 * Holds the registered/activated marketplace vendor name that the Excel
 * generator pulls for the vendor column. No auth/password fields here.
 */
class Vendor extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'legal_company_name',
        'address_line_1',
        'address_line_2',
        'city',
        'state',
        'postal_code',
        'country',
        'primary_contact_name',
        'primary_contact_email',
        'primary_contact_phone',
        'status',
        'notes',
    ];

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    public function catalogItems(): HasMany
    {
        return $this->hasMany(CatalogItem::class);
    }

    public function fieldMappings(): HasMany
    {
        return $this->hasMany(FieldMapping::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(Submission::class);
    }
}
