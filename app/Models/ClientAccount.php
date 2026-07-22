<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClientAccount extends Model
{
    protected $fillable = [
        'name',
        'contact_email',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function licenses(): HasMany
    {
        return $this->hasMany(License::class);
    }

    public function dtimerMachines(): HasMany
    {
        return $this->hasMany(DtimerMachine::class);
    }

    public function coinSaleEvents(): HasMany
    {
        return $this->hasMany(CoinSaleEvent::class);
    }

    public function licenseRevocations(): HasMany
    {
        return $this->hasMany(LicenseRevocation::class);
    }
}
