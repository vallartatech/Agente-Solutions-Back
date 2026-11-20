<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class NotificationController extends Controller
{
    // Fetch unread notifications for the logged-in user
    public function getUnread(Request $request)
    {
        $user = $request->user();
        
        return response()->json([
            'success' => true,
            'notifications' => $user->unreadNotifications
        ], 200);
    }

    public function markAsRead(Request $request, $id)
    {
        $notification = $request->user()->notifications()->find($id);

        if ($notification) {
            $notification->markAsRead();
            return response()->json(['success' => true]);
        }

        return response()->json(['success' => false, 'message' => 'Notification not found'], 404);
    }

    // ¿Tiene el usuario algún dispositivo con push ligado a su cuenta en OneSignal?
    public function pushStatus(Request $request)
    {
        return response()->json(\App\Services\OneSignalPush::estadoUsuario((string) $request->user()->id), 200);
    }

    // App de iPhone: registra el token APNs del dispositivo en OneSignal a nombre del usuario
    public function registrarPushIOS(Request $request)
    {
        $request->validate([
            // Token APNs: 64 caracteres hexadecimales (se admite algo más largo por si Apple lo cambia)
            'token' => ['required', 'string', 'regex:/^[0-9a-fA-F]{64,200}$/'],
        ]);

        $resultado = \App\Services\OneSignalPush::registrarIOS((string) $request->user()->id, strtolower($request->token));
        return response()->json($resultado, $resultado['ok'] ? 200 : 502);
    }

    // App de iPhone: al cerrar sesión, el dispositivo deja de recibir los avisos de esa cuenta
    public function bajaPushIOS(Request $request)
    {
        $request->validate([
            'subscription_id' => 'required|string|max:64',
        ]);

        $ok = \App\Services\OneSignalPush::bajaSuscripcion((string) $request->user()->id, $request->subscription_id);
        return response()->json(['ok' => $ok], 200);
    }

    ///Listar todas las Notificaciones
    public function getAll(Request $request)
    {
        $user = $request->user();
        
        return response()->json([
            'success' => true,
            'notifications' => $user->notifications 
        ], 200);
    }
}