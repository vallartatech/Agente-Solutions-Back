<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\TenantScoped;

class Property extends Model
{
    use HasFactory, TenantScoped;

    protected $fillable = [
        'tenant_id',
        'folder_id',
        'client_id', 
        'type', 
        'state', 
        'custom_curp', 
        'address', 
        'coordinates',
        'property_name',       // 👈 Campo nuevo para el nombre
        'facade_photo_path',    // 👈 Campo nuevo para la foto
        'levantamiento_realizado',
        // Partes de la dirección (address guarda el texto completo)
        'municipio',
        'colonia',
        'calle',
        'numero',
        'cruzamientos',
    ];

    public function services()
    {
        return $this->hasMany(Service::class, 'property_id');
    }

    public function client()
    {
        return $this->belongsTo(Client::class, 'client_id');
    }
    
    public function folder()
    {
        return $this->belongsTo(PropertyFolder::class, 'folder_id');
    }

    public function areas()
    {
        return $this->hasMany(PropertyArea::class);
    }
}