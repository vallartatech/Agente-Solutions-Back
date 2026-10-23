<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PropertyFolder extends Model
{
    use HasFactory;

    protected $table = 'property_folders';

    protected $fillable = [
        'tenant_id',
        'created_by',
        'name',
        'color',
        'logo_url', // logo de la empresa (catálogo y Mapa de propiedades)
    ];

    public function properties()
    {
        return $this->hasMany(Property::class, 'folder_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
