<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Schema; 
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
use App\Services\PushForDatabaseNotifications;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Schema::defaultStringLength(191);

        // Toda notificación de la campanita también se envía como push (OneSignal)
        Event::listen(NotificationSent::class, [PushForDatabaseNotifications::class, 'handle']);

        // Enlace para verificar correo
        VerifyEmail::createUrlUsing(function ($notifiable) {
            $frontendUrl = env('FRONTEND_URL', 'http://localhost:5173');
            $id = $notifiable->getKey();
            $hash = sha1($notifiable->getEmailForVerification());

            return "{$frontendUrl}/email/verify/{$id}/{$hash}";
        });

        // Enlace para restablecer contraseña
        ResetPassword::createUrlUsing(function ($user, string $token) {
            $frontendUrl = env('FRONTEND_URL', 'http://localhost:5173');
            return "{$frontendUrl}/recuperar-password?token={$token}&email={$user->getEmailForPasswordReset()}";
        });
    }
}