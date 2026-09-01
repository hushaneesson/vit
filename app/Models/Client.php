<?php

namespace App\Models;

use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Notifications\Notifiable;

/**
 * A login account for a person at a vendor company. Logs in via email-OTP
 * only — there is intentionally no password field. Belongs to a vendor via
 * vendor_id; multiple clients can share the same vendor and therefore the
 * same catalog data.
 */
class Client extends Model implements AuthenticatableContract
{
    use Authenticatable, HasFactory, Notifiable;

    protected $fillable = [
        'vendor_id',
        'name',
        'email',
        'phone',
        'title',
        'status',
        'invited_at',
        'activated_at',
        'otp_code',
        'otp_expires_at',
        'last_login_at',
    ];

    protected $hidden = [
        'otp_code',
    ];

    protected $with = [
        'vendor',
    ];

    protected function casts(): array
    {
        return [
            'invited_at' => 'datetime',
            'activated_at' => 'datetime',
            'otp_expires_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function catalogItems(): HasMany
    {
        return $this->hasMany(CatalogItem::class);
    }

    public function catalogSubmissions(): HasMany
    {
        return $this->hasMany(CatalogSubmission::class);
    }

    public function generateOtp(): string
    {
        $code = (string) random_int(100000, 999999);
        $this->forceFill([
            'otp_code' => $code,
            'otp_expires_at' => now()->addMinutes(10),
        ])->save();

        return $code;
    }
}
