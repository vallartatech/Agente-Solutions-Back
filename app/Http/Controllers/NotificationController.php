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