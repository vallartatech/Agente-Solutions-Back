<?php

namespace App\Services;

use Cloudinary\Cloudinary;
use Illuminate\Http\UploadedFile;

class FotosMantenimiento
{
    public function subir(UploadedFile $archivo): string
    {
        $cloudinary = new Cloudinary(env('CLOUDINARY_URL') ?: config('cloudinary.cloud_url'));
        $respuesta = $cloudinary->uploadApi()->upload($archivo->getRealPath(), ['folder' => 'agente_mantenimiento']);

        return $respuesta['secure_url'];
    }
}
