<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use App\Traits\TenantScoped;

use Illuminate\Contracts\Auth\MustVerifyEmail;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, TenantScoped;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'role_id',
        'tenant_id',
        'approval_status',
        'first_name',   // ✅ Reemplazamos 'name'
        'last_name',    // ✅ Agregamos 'last_name'
        'phone_number', // ✅ Agregamos teléfono por si acaso
        'email',
        'password',
        'is_active',
        'profile_picture',
        'cover_picture',
        'subscription_status',
        'subscription_start',
        'subscription_expires_at',
        'subscription_amount',
        'subscription_mp_payment_id',
        'rating_stars_avg',
        'rating_time_avg',
        'total_reviews_count',
    ];


    protected $appends = ['name'];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'rating_stars_avg' => 'float',
            'rating_time_avg' => 'float',
            'total_reviews_count' => 'integer',
        ];
    }


    public function getNameAttribute()
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    public function routeNotificationForOneSignal()
    {
        // Esto le dice a OneSignal: "Envíale el mensaje al dispositivo que esté
        // logueado con este ID de usuario".
        return ['include_external_user_ids' => [(string) $this->id]];
    }

    public function tenant()
    {
        return $this->belongsTo(\App\Models\Tenant::class, 'tenant_id');
    }

    public function specialties()
    {
        return $this->belongsToMany(\App\Models\Specialty::class, 'technician_specialties', 'user_id', 'specialty_id')
                    ->withPivot('custom_name');
    }

    public function reviewsReceived()
    {
        return $this->hasMany(\App\Models\TechnicianReview::class, 'technician_id');
    }

    public function reviewsGiven()
    {
        return $this->hasMany(\App\Models\TechnicianReview::class, 'client_id');
    }

    public function recalculateRatings(): void
    {
        $count = $this->reviewsReceived()->count();
        if ($count > 0) {
            $starsAvg = $this->reviewsReceived()->avg('rating_stars');
            $timeAvg = $this->reviewsReceived()->avg('rating_time');
            $this->rating_stars_avg = round((float)$starsAvg, 2);
            $this->rating_time_avg = round((float)$timeAvg, 2);
            $this->total_reviews_count = $count;
        } else {
            $this->rating_stars_avg = 5.00;
            $this->rating_time_avg = 5.00;
            $this->total_reviews_count = 0;
        }
        $this->saveQuietly();
    }
}
