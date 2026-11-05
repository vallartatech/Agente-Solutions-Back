<?php

namespace App\Services;

use App\Models\AccessQr;
use App\Models\Service;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * QR de acceso a la propiedad de un trabajo.
 *
 * - Alcance: una orden suelta (work_order_id), un servicio (service_id) o, si la orden pertenece
 *   a un lote, todo el lote (batch_id + property_id): el mismo QR sirve para todas sus órdenes.
 * - Hasta 2 QRs por alcance (slot 1 y 2).
 * - Se borra solo cuando todo el alcance queda cerrado (Listo, Finalizado, Cancelado, Rechazado…).
 *   La segunda visita no lo borra: el trabajo sigue abierto.
 */
class AccesoQr
{
    public const SLOTS = [1, 2];

    // Columnas sin la imagen: para revisar o borrar no hace falta cargarla
    private const META = ['id', 'tenant_id', 'property_id', 'work_order_id', 'service_id', 'batch_id', 'slot', 'label', 'note', 'valid_until', 'mime', 'uploaded_by', 'created_at', 'updated_at'];

    private const CERRADOS = [
        'listo', 'finalizado', 'terminado', 'completado', 'completed',
        'cancelado', 'cancelado_tecnico', 'rechazado',
    ];

    public static function cerrado(?string $status): bool
    {
        return in_array(mb_strtolower(trim((string) $status)), self::CERRADOS, true);
    }

    /** Busca el trabajo respetando el filtro de tenant del usuario conectado. */
    public static function resolverTrabajo(string $tipo, int $id): ?Model
    {
        return $tipo === 'work_order' ? WorkOrder::find($id) : Service::find($id);
    }

    /** Columnas que identifican el alcance del QR para este trabajo. */
    public static function alcance(Model $trabajo): array
    {
        if ($trabajo instanceof WorkOrder) {
            return $trabajo->batch_id
                ? ['batch_id' => $trabajo->batch_id, 'property_id' => $trabajo->property_id]
                : ['work_order_id' => $trabajo->id];
        }
        return ['service_id' => $trabajo->id];
    }

    public static function query(Model $trabajo)
    {
        return AccessQr::where(self::alcance($trabajo));
    }

    /** Todos los trabajos que comparten el QR (el lote completo o solo este trabajo). */
    public static function trabajosDelAlcance(Model $trabajo): Collection
    {
        if ($trabajo instanceof WorkOrder && $trabajo->batch_id) {
            return WorkOrder::withoutGlobalScopes()
                ->where('batch_id', $trabajo->batch_id)
                ->where('property_id', $trabajo->property_id)
                ->get();
        }
        return $trabajo->exists ? collect([$trabajo]) : collect();
    }

    /** Cerrado = no queda ningún trabajo abierto en el alcance (o ya no existe ninguno). */
    public static function alcanceCerrado(Model $trabajo): bool
    {
        return self::trabajosDelAlcance($trabajo)->every(fn ($t) => self::cerrado($t->status));
    }

    public static function esLote(Model $trabajo): bool
    {
        return $trabajo instanceof WorkOrder && (bool) $trabajo->batch_id;
    }

    // ---------------------------------------------------------------- Técnicos

    private static function asignadoA(Model $t, int $userId): bool
    {
        if ($t instanceof WorkOrder) {
            return (int) $t->tecnico_id === $userId
                || DB::table('work_order_technician')->where('work_order_id', $t->id)->where('technician_id', $userId)->exists();
        }
        return (int) $t->assigned_to === $userId
            || DB::table('service_technician')->where('service_id', $t->id)->where('technician_id', $userId)->exists();
    }

    public static function tecnicoAsignado(User $user, Model $trabajo): bool
    {
        return self::trabajosDelAlcance($trabajo)->contains(fn ($t) => self::asignadoA($t, (int) $user->id));
    }

    /** El técnico ya confirmó su llegada (GPS) en algún trabajo del alcance que tiene asignado. */
    public static function tecnicoEnSitio(User $user, Model $trabajo): bool
    {
        return self::trabajosDelAlcance($trabajo)->contains(
            fn ($t) => $t->arrival_status === 'EN_SITIO' && self::asignadoA($t, (int) $user->id)
        );
    }

    // ---------------------------------------------------------------- Imagen

    public static function cifrar(string $binario): string
    {
        return encrypt(base64_encode($binario), false);
    }

    public static function descifrar(AccessQr $qr): string
    {
        return base64_decode(decrypt($qr->image, false));
    }

    // ---------------------------------------------------------------- Borrado

    public static function log(array $alcance, string $accion, ?int $slot = null, ?int $userId = null, ?string $detalle = null, ?int $tenantId = null): void
    {
        try {
            DB::table('access_qr_logs')->insert([
                'tenant_id' => $tenantId,
                'property_id' => $alcance['property_id'] ?? null,
                'work_order_id' => $alcance['work_order_id'] ?? null,
                'service_id' => $alcance['service_id'] ?? null,
                'batch_id' => $alcance['batch_id'] ?? null,
                'slot' => $slot,
                'user_id' => $userId,
                'action' => $accion,
                'detail' => $detalle ? mb_substr($detalle, 0, 255) : null,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Bitácora de QR de acceso: ' . $e->getMessage());
        }
    }

    private static function eliminar(Collection $qrs, string $motivo): int
    {
        foreach ($qrs as $qr) {
            $alcance = array_filter($qr->only(['property_id', 'work_order_id', 'service_id', 'batch_id']));
            $qr->delete();
            self::log($alcance, 'eliminado_auto', $qr->slot, null, $motivo, $qr->tenant_id);
        }
        return $qrs->count();
    }

    /** Si todo el alcance ya cerró, borra sus QRs. Lo llaman los modelos al cambiar de estado. */
    public static function purgarSiCerrado(Model $trabajo, string $motivo = 'Trabajo finalizado'): int
    {
        $qrs = self::query($trabajo)->get(self::META);
        if ($qrs->isEmpty() || !self::alcanceCerrado($trabajo)) {
            return 0;
        }
        return self::eliminar($qrs, $motivo);
    }

    /** Borra los vencidos de un alcance. */
    public static function purgarVencidos(Model $trabajo): void
    {
        $vencidos = self::query($trabajo)->whereNotNull('valid_until')->where('valid_until', '<', now())->get(self::META);
        if ($vencidos->isNotEmpty()) {
            self::eliminar($vencidos, 'Vigencia vencida');
        }
    }

    /** Respaldo periódico (comando acceso-qr:purgar): revisa todos los QRs existentes. */
    public static function purgarTodo(): int
    {
        $total = 0;
        foreach (AccessQr::query()->get(self::META) as $qr) {
            if ($qr->vencido()) {
                $total += self::eliminar(collect([$qr]), 'Vigencia vencida');
                continue;
            }
            $trabajo = match (true) {
                (bool) $qr->batch_id => WorkOrder::withoutGlobalScopes()->where('batch_id', $qr->batch_id)->where('property_id', $qr->property_id)->first(),
                (bool) $qr->work_order_id => WorkOrder::withoutGlobalScopes()->find($qr->work_order_id),
                (bool) $qr->service_id => Service::withoutGlobalScopes()->find($qr->service_id),
                default => null,
            };
            if (!$trabajo || self::alcanceCerrado($trabajo)) {
                $total += self::eliminar(collect([$qr]), 'Limpieza periódica');
            }
        }
        return $total;
    }

    /**
     * Para la lista del técnico: marca qué trabajos tienen QR (sin exponer la imagen).
     * $workOrders y $servicios son filas de DB::table con id (y batch_id/property_id las órdenes).
     */
    public static function marcarListado(Collection $workOrders, Collection $servicios): void
    {
        $woIds = $workOrders->pluck('id')->filter()->all();
        $svIds = $servicios->pluck('id')->filter()->all();
        $lotes = $workOrders->pluck('batch_id')->filter()->unique()->values()->all();

        if (!$woIds && !$svIds && !$lotes) {
            return;
        }

        $qrs = AccessQr::query()
            ->where(function ($q) use ($woIds, $svIds, $lotes) {
                $q->whereIn('work_order_id', $woIds ?: [0])
                    ->orWhereIn('service_id', $svIds ?: [0])
                    ->orWhereIn('batch_id', $lotes ?: ['__ninguno__']);
            })
            ->get(['work_order_id', 'service_id', 'batch_id', 'property_id']);

        $porOrden = $qrs->pluck('work_order_id')->filter()->flip();
        $porServicio = $qrs->pluck('service_id')->filter()->flip();
        $porLote = $qrs->filter(fn ($q) => $q->batch_id)->map(fn ($q) => $q->batch_id . '|' . $q->property_id)->flip();

        $workOrders->each(function ($w) use ($porOrden, $porLote) {
            $w->tiene_qr = $w->batch_id
                ? $porLote->has($w->batch_id . '|' . $w->property_id)
                : $porOrden->has($w->id);
        });
        $servicios->each(fn ($s) => $s->tiene_qr = $porServicio->has($s->id));
    }
}
