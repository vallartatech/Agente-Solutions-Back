<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Tenant extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'owner_user_id',
        'logo_url',
        'phone',
        'email',
        'status',
        'membership_type',
        'max_properties',
        'max_clients',
        'extra_properties_count',
        'billing_cycle',
        'subscription_status',
        'subscription_start',
        'subscription_expires_at',
        'subscription_amount',
        'subscription_mp_payment_id',
        'max_cuadrilla',
    ];

    public function canAddProperty(): bool
    {
        if ($this->membership_type === 'autonomo_fundador') {
            return true;
        }
        $currentCount = $this->properties()->count();
        $allowed = ($this->max_properties ?? 3) + ($this->extra_properties_count ?? 0);
        return $currentCount < $allowed;
    }

    public function canAddClient(): bool
    {
        if ($this->membership_type === 'autonomo_fundador') {
            return true;
        }
        if ($this->membership_type === 'autonomo_personal') {
            return false;
        }
        $currentCount = \App\Models\Client::where('tenant_id', $this->id)->count();
        $allowed = $this->max_clients ?? 30;
        return $currentCount < $allowed;
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function users()
    {
        return $this->hasMany(User::class, 'tenant_id');
    }

    public function properties()
    {
        return $this->hasMany(Property::class, 'tenant_id');
    }

    public function quotes()
    {
        return $this->hasMany(Quote::class, 'tenant_id');
    }

    public function services()
    {
        return $this->hasMany(Service::class, 'tenant_id');
    }

    public function propertyManager()
    {
        return $this->hasOne(PropertyManager::class, 'tenant_id');
    }
}
