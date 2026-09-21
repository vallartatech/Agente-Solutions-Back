<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
// IMPORTANTE: Cambiamos el import para usar el motor puro de Cloudinary, no el Facade de Laravel
use Cloudinary\Cloudinary;

class ImageController extends Controller
{
    public function uploadProfilePicture(Request $request)
    {
        // 1. Validamos
        $request->validate([
            'image' => 'required|image|mimes:jpeg,png,jpg,webp|max:10240',
        ]);

        try {
            // 2. LA OPCIÓN NUCLEAR: Instanciamos Cloudinary directamente
            // Esto se salta TODO el caché de Railway y Laravel
// En app/Http/Controllers/ImageController.php

            $cloudinary = new Cloudinary(env('CLOUDINARY_URL') ?: config('cloudinary.cloud_url'));
            // 3. Subimos la imagen usando la API directa
            $respuestaNube = $cloudinary->uploadApi()->upload($request->file('image')->getRealPath(), [
                'folder' => 'agente_perfiles'
            ]);

            // 4. Extraemos la URL mágica
            $uploadedFileUrl = $respuestaNube['secure_url'];

            // 5. Detectamos qué estamos subiendo (perfil o portada) gracias a tu React
            $tipoCampo = $request->input('type', 'profile_picture');

            // 6. Guardamos la URL en la columna correcta del usuario
            $userId = auth()->id();

            // Trampa de seguridad: Si no hay ID, lanzamos error
            if (!$userId) {
                throw new \Exception('No se detectó el ID del usuario. Laravel no sabe quién eres.');
            }

            // Usamos el modelo Eloquent para guardar
            $user = \App\Models\User::find($userId);
            if ($user) {
                $user->$tipoCampo = $uploadedFileUrl;
                $user->save();
            } else {
                throw new \Exception('Usuario no encontrado en la base de datos.');
            }

            // 7. Retornamos éxito a React
            return response()->json([
                'message' => '¡Victoria contra Railway!',
                'url' => $uploadedFileUrl
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Error interno: ' . $e->getMessage(),
                'archivo' => $e->getFile(),
                'linea' => $e->getLine()
            ], 500);
        }
    }
}