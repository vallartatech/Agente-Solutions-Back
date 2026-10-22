<?php

namespace App\Services;

use App\Models\Property;
use App\Models\User;
use App\Models\UserPlaceVisit;
use App\Notifications\PlaceVisitNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Detecta por GPS cuándo alguien llega a una propiedad registrada o se va de ella,
 * y avisa (push + campanita) a quienes tienen acceso al mapa en vivo de su equipo.
 *
 * Para evitar avisos repetidos por los saltos del GPS:
 * - entra a 90 m o menos, pero solo "sale" al alejarse más de 150 m;
 * - si vuelve al mismo lugar antes de 30 min de haber salido, se retoma la visita sin avisar;
 * - las lecturas con precisión peor a 100 m no cuentan.
 */
class PlaceVisitService
{
    public const RADIO_ENTRADA_M = 90;
    public const RADIO_SALIDA_M = 150;
    private const PRECISION_MAX_M = 100;
    private const REINGRESO_MIN = 30;
    // Si alguien deja de enviar ubicación estando en un lugar, la visita se cierra en silencio
    private const SIN_SENAL_HORAS = 2;
    private const ZONA_HORARIA = 'America/Merida';

    public function procesar(User $user, float $lat, float $lng, ?float $precision): void
    {
        if ($precision !== null && $precision > self::PRECISION_MAX_M) {
            return;
        }

        try {
            $abierta = UserPlaceVisit::where('user_id', $user->id)->whereNull('left_at')->latest('id')->first();

            // Sin señal por mucho tiempo: no sabemos cuándo se fue, se cierra sin avisar
            if ($abierta && $abierta->last_seen_at->lt(now()->subHours(self::SIN_SENAL_HORAS))) {
                $abierta->update(['left_at' => $abierta->last_seen_at]);
                $abierta = null;
            }

            $lugares = $this->lugares($user);
            if (!$lugares) {
                return;
            }

            if ($abierta) {
                $lugar = $lugares[$abierta->property_id] ?? null;
                $distancia = $lugar ? $this->distancia($lat, $lng, $lugar['lat'], $lugar['lng']) : INF;
                if ($distancia <= self::RADIO_SALIDA_M) {
                    $abierta->update(['last_seen_at' => now()]);
                    return; // sigue en el lugar
                }
                // Se fue
                $abierta->update(['left_at' => now()]);
                $this->avisar($user, $abierta, 'salida', $lugar['nombre'] ?? 'una propiedad');
            }

            // ¿Llegó a algún lugar? (el más cercano dentro del radio de entrada)
            $cercano = null;
            $minima = INF;
            foreach ($lugares as $id => $l) {
                $d = $this->distancia($lat, $lng, $l['lat'], $l['lng']);
                if ($d < $minima) {
                    $minima = $d;
                    $cercano = $id;
                }
            }
            if ($cercano === null || $minima > self::RADIO_ENTRADA_M) {
                return;
            }

            // Volvió al mismo lugar poco después de salir: se retoma la visita sin avisar
            $reciente = UserPlaceVisit::where('user_id', $user->id)
                ->where('property_id', $cercano)
                ->whereNotNull('left_at')
                ->where('left_at', '>=', now()->subMinutes(self::REINGRESO_MIN))
                ->latest('id')->first();
            if ($reciente) {
                $reciente->update(['left_at' => null, 'last_seen_at' => now()]);
                return;
            }

            $visita = UserPlaceVisit::create([
                'user_id' => $user->id,
                'property_id' => $cercano,
                'tenant_id' => $user->tenant_id,
                'entered_at' => now(),
                'last_seen_at' => now(),
            ]);
            $this->avisar($user, $visita, 'llegada', $lugares[$cercano]['nombre']);
        } catch (\Throwable $e) {
            // Nunca debe impedir que se guarde la ubicación
            Log::error('[Visitas GPS] ' . $e->getMessage());
        }
    }

    // Al pausar la ubicación: se cierra la visita abierta sin avisar
    public function cerrarSinAviso(User $user): void
    {
        UserPlaceVisit::where('user_id', $user->id)->whereNull('left_at')->update(['left_at' => now()]);
    }

    // Visitas abiertas y recientes por usuario: [user_id => ['nombre', 'desde']] (para el mapa)
    public function lugaresActuales(array $userIds): array
    {
        return UserPlaceVisit::with('property:id,property_name,address')
            ->whereIn('user_id', $userIds)
            ->whereNull('left_at')
            ->where('last_seen_at', '>=', now()->subHours(self::SIN_SENAL_HORAS))
            ->get()
            ->mapWithKeys(fn ($v) => [$v->user_id => [
                'nombre' => $this->nombreLugar($v->property),
                'desde' => $v->entered_at->toIso8601String(),
            ]])
            ->all();
    }

    // Propiedades con coordenadas del equipo del usuario (se guardan 5 min en caché)
    private function lugares(User $user): array
    {
        $equipo = (!empty($user->tenant_id) && (int) $user->tenant_id !== 1) ? (int) $user->tenant_id : 'agente';

        return Cache::remember("visitas_gps_lugares_{$equipo}", 300, function () use ($equipo) {
            $q = Property::withoutGlobalScopes()->whereNotNull('coordinates')->where('coordinates', '!=', '');
            if ($equipo === 'agente') {
                $q->where(fn ($w) => $w->whereNull('tenant_id')->orWhere('tenant_id', 1));
            } else {
                $q->where('tenant_id', $equipo);
            }

            $lugares = [];
            foreach ($q->get(['id', 'property_name', 'address', 'coordinates']) as $p) {
                $partes = array_map('trim', explode(',', (string) $p->coordinates));
                if (count($partes) !== 2 || !is_numeric($partes[0]) || !is_numeric($partes[1])) {
                    continue;
                }
                $lugares[$p->id] = ['lat' => (float) $partes[0], 'lng' => (float) $partes[1], 'nombre' => $this->nombreLugar($p)];
            }
            return $lugares;
        });
    }

    private function nombreLugar($p): string
    {
        return $p ? (trim((string) $p->property_name) ?: (trim((string) $p->address) ?: 'una propiedad')) : 'una propiedad';
    }

    // Push + campanita a quienes ven el mapa en vivo del mismo equipo (menos la propia persona)
    private function avisar(User $user, UserPlaceVisit $visita, string $tipo, string $lugar): void
    {
        $q = User::withoutGlobalScopes()->where('is_active', 1)->where('can_view_live_map', true)->where('id', '!=', $user->id);
        if (!empty($user->tenant_id) && (int) $user->tenant_id !== 1) {
            $q->where('tenant_id', $user->tenant_id);
        } else {
            $q->where(fn ($w) => $w->whereNull('tenant_id')->orWhere('tenant_id', 1));
        }
        $destinatarios = $q->get();
        if ($destinatarios->isEmpty()) {
            return;
        }

        $nombre = trim("{$user->first_name} {$user->last_name}") ?: 'Alguien del equipo';
        // Horas en hora local (el servidor puede estar en UTC)
        $hora = fn ($t) => $t->copy()->setTimezone(self::ZONA_HORARIA)->format('H:i');
        if ($tipo === 'llegada') {
            $titulo = "📍 {$nombre} llegó a {$lugar}";
            $mensaje = 'Detectado por GPS a las ' . $hora(now()) . '.';
        } else {
            $titulo = "🚶 {$nombre} salió de {$lugar}";
            $mensaje = 'Estuvo ' . $this->duracion($visita->entered_at, now()) . ' (llegó a las ' . $hora($visita->entered_at) . ').';
        }

        $notificacion = new PlaceVisitNotification($visita, $titulo, $mensaje, $tipo);
        Notification::send($destinatarios, $notificacion);
        OneSignalPush::sendToUsers(
            $destinatarios->pluck('id')->all(),
            $titulo,
            $mensaje,
            '/mapa-equipo',
            ['alert_type' => 'place_visit', 'user_id' => $user->id, 'property_id' => $visita->property_id]
        );
    }

    private function duracion($desde, $hasta): string
    {
        $min = max(1, (int) round(abs($desde->diffInSeconds($hasta)) / 60));
        if ($min < 60) {
            return "{$min} min";
        }
        $h = intdiv($min, 60);
        $resto = $min % 60;
        return $resto ? "{$h} h {$resto} min" : "{$h} h";
    }

    // Distancia en metros entre dos coordenadas (haversine)
    private function distancia(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return 2 * $r * asin(min(1, sqrt($a)));
    }
}
