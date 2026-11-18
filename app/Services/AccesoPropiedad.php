<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Propiedades compartidas (property_shares): el invitado usa la propiedad como si fuera suya.
 * Ve y opera órdenes, cotizaciones, servicios, pagos, cancelaciones, QR de acceso y mantenimiento,
 * y recibe las mismas notificaciones que el dueño. Solo el dueño real borra, edita o comparte la propiedad.
 */
class AccesoPropiedad
{
    /** Llave de la cache en los atributos de la petición (no estática: no se arrastra entre peticiones) */
    private const CACHE = 'acceso_propiedad.compartidas';

    /** ¿Existen las tablas? (en pruebas con esquema parcial puede no haber property_shares). Cache por petición. */
    private static function hayTablas(): bool
    {
        $attrs = request()->attributes;
        if (!$attrs->has('acceso_propiedad.tablas')) {
            $attrs->set('acceso_propiedad.tablas', \Illuminate\Support\Facades\Schema::hasTable('property_shares')
                && \Illuminate\Support\Facades\Schema::hasTable('clients'));
        }
        return (bool) $attrs->get('acceso_propiedad.tablas');
    }

    /** Perfiles de cliente (tabla clients) del usuario */
    public static function clienteIds(?User $user): array
    {
        if (!$user) return [];
        return DB::table('clients')
            ->where(function ($q) use ($user) {
                $q->where('user_id', $user->id);
                if (!empty($user->email)) {
                    $q->orWhere('email', $user->email);
                }
            })
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** Ids de las propiedades que otros le compartieron al usuario */
    public static function compartidasCon(?User $user): array
    {
        if (!$user || !self::hayTablas()) return [];
        // Se llama en cada consulta con TenantScope: una sola consulta por usuario y por petición
        $cache = request()->attributes->get(self::CACHE, []);
        if (!array_key_exists($user->id, $cache)) {
            $clienteIds = self::clienteIds($user);
            $cache[$user->id] = empty($clienteIds) ? [] : DB::table('property_shares')
                ->whereIn('client_id', $clienteIds)
                ->pluck('property_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
            request()->attributes->set(self::CACHE, $cache);
        }
        return $cache[$user->id];
    }

    /** ¿La propiedad está compartida con este usuario? */
    public static function esInvitado(?User $user, $propertyId): bool
    {
        if (!$user || !$propertyId) return false;
        return in_array((int) $propertyId, self::compartidasCon($user), true);
    }

    /**
     * ¿El usuario solo es invitado (no el dueño real)? Para lo que solo hace el dueño:
     * borrar o editar la propiedad, compartirla y quitar invitados.
     */
    public static function esSoloInvitado(?User $user, $property): bool
    {
        if (!$user || !$property) return false;
        if (!self::esInvitado($user, $property->id)) return false;
        $duenoCliente = DB::table('clients')->where('id', $property->client_id)->first(['user_id', 'email']);
        $esDueno = $duenoCliente && (
            (int) $duenoCliente->user_id === (int) $user->id
            || (!empty($duenoCliente->email) && $duenoCliente->email === $user->email)
        );
        return !$esDueno;
    }

    /** ¿La orden es de una propiedad compartida con este usuario? */
    public static function esInvitadoDeOrden(?User $user, $workOrderId): bool
    {
        if (!$user || !$workOrderId) return false;
        $propertyId = DB::table('work_orders')->where('id', $workOrderId)->value('property_id');
        return self::esInvitado($user, $propertyId);
    }

    /** Usuarios invitados a la propiedad (para avisarles igual que al dueño) */
    public static function invitadosDe($propertyId): array
    {
        if (!$propertyId || !self::hayTablas()) return [];
        return DB::table('property_shares')
            ->join('clients', 'clients.id', '=', 'property_shares.client_id')
            ->where('property_shares.property_id', $propertyId)
            ->whereNotNull('clients.user_id')
            ->pluck('clients.user_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /** Invitados de la propiedad de una orden */
    public static function invitadosDeOrden($workOrderId): array
    {
        if (!$workOrderId) return [];
        $propertyId = DB::table('work_orders')->where('id', $workOrderId)->value('property_id');
        return self::invitadosDe($propertyId);
    }

    /**
     * Destinatarios de los avisos de una orden: el dueño (si se conoce) más los invitados de la propiedad,
     * sin repetir y sin quien hizo la acción.
     */
    public static function destinatariosDeOrden($workOrderId, ?int $duenoId = null, ?int $excluirUserId = null): array
    {
        $ids = array_merge($duenoId ? [(int) $duenoId] : [], self::invitadosDeOrden($workOrderId));
        $ids = array_values(array_unique($ids));
        if ($excluirUserId) {
            $ids = array_values(array_filter($ids, fn ($id) => $id !== (int) $excluirUserId));
        }
        return $ids;
    }

    /**
     * Atajo para avisos de la red: dueño de la orden (duenoDeOrden) + invitados, sin el usuario que hizo la acción.
     */
    public static function interesadosDeOrden($workOrderId, ?int $excluirUserId = null): array
    {
        if (!$workOrderId) return [];
        $duenoId = \App\Http\Controllers\MisClientesController::duenoDeOrden((int) $workOrderId);
        return self::destinatariosDeOrden($workOrderId, $duenoId, $excluirUserId ?? auth('sanctum')->id());
    }

    /**
     * El usuario dueño (si lo hay) más los invitados de la propiedad, como colección de User sin repetir.
     * Sirve para Notification::send(...) en los avisos al cliente.
     */
    public static function conInvitados(?User $dueno, $propertyId, ?int $excluirUserId = null): \Illuminate\Support\Collection
    {
        $usuarios = collect($dueno ? [$dueno] : []);
        $ids = self::invitadosDe($propertyId);
        if (!empty($ids)) {
            $usuarios = $usuarios->merge(User::withoutGlobalScopes()->whereIn('id', $ids)->get());
        }
        return $usuarios
            ->unique('id')
            ->reject(fn ($u) => $excluirUserId && (int) $u->id === (int) $excluirUserId)
            ->values();
    }

    /**
     * ¿Puede leer o escribir el chat de una cotización de la red? Solo el técnico que cotizó y
     * el lado del cliente (dueño, invitados, su equipo, Root/Admin) según puedeResponderPorDueno.
     */
    public static function puedeUsarChatRed(?User $user, $networkQuote): bool
    {
        if (!$user || !$networkQuote) return false;
        if ((int) $networkQuote->technician_id === (int) $user->id) return true;
        $orden = \App\Models\WorkOrder::withoutGlobalScopes()->find($networkQuote->work_order_id);
        return \App\Http\Controllers\AjusteCotizacionController::puedeResponderPorDueno($user, $orden);
    }

    /**
     * ¿Puede pedir trabajos para esta propiedad? Root, Admin y técnico interno como antes; los demás solo
     * si es suya (su perfil de cliente), se la compartieron o es de su tenant (gestor, contratista, equipo).
     */
    public static function puedeOperarPropiedad(?User $user, $propertyId): bool
    {
        if (!$user || !$propertyId) return false;
        if (in_array((int) $user->role_id, [0, 1, 2], true)) return true;

        $propiedad = DB::table('properties')->leftJoin('clients', 'clients.id', '=', 'properties.client_id')
            ->where('properties.id', $propertyId)
            ->first(['properties.client_id', 'properties.tenant_id', 'clients.tenant_id as client_tenant_id']);
        if (!$propiedad) return false;

        if ($propiedad->client_id && in_array((int) $propiedad->client_id, self::clienteIds($user), true)) return true;
        if (self::esInvitado($user, $propertyId)) return true;

        $tenant = (int) ($user->tenant_id ?? 0);
        return $tenant > 0 && ((int) $propiedad->tenant_id === $tenant || (int) $propiedad->client_tenant_id === $tenant);
    }

    /** Usuario del cliente dueño de la propiedad (por user_id o, si no tiene, por su correo) */
    public static function duenoDe($propertyId): ?User
    {
        if (!$propertyId) return null;
        $cliente = DB::table('properties')->join('clients', 'clients.id', '=', 'properties.client_id')
            ->where('properties.id', $propertyId)->first(['clients.user_id', 'clients.email']);
        if (!$cliente) return null;
        if ($cliente->user_id) {
            $u = User::withoutGlobalScopes()->find($cliente->user_id);
            if ($u) return $u;
        }
        return !empty($cliente->email) ? User::withoutGlobalScopes()->where('email', $cliente->email)->first() : null;
    }

    /** Dueño e invitados de la propiedad: los destinatarios de los avisos al cliente */
    public static function duenoEInvitados($propertyId, ?int $excluirUserId = null): \Illuminate\Support\Collection
    {
        return self::conInvitados(self::duenoDe($propertyId), $propertyId, $excluirUserId);
    }

    /** Propiedad de una cotización de Solutions (por su servicio u orden) */
    public static function propiedadDeCotizacion($quote): ?int
    {
        if (!$quote) return null;
        if (!empty($quote->service_id)) {
            $id = DB::table('services')->where('id', $quote->service_id)->value('property_id');
            if ($id) return (int) $id;
        }
        if (!empty($quote->work_order_id)) {
            $id = DB::table('work_orders')->where('id', $quote->work_order_id)->value('property_id');
            if ($id) return (int) $id;
        }
        return null;
    }

    /**
     * Busca una cotización de Solutions para una acción del cliente. Primero con el TenantScope normal;
     * si no aparece (la propiedad compartida es de otro tenant), la devuelve solo si el usuario es
     * invitado de esa propiedad. Con $falla = true lanza 404 como findOrFail.
     */
    public static function cotizacion($id, ?User $user = null, array $with = [], bool $falla = true)
    {
        $user = $user ?: auth('sanctum')->user();
        $quote = \App\Models\Quote::with($with)->find($id);
        if (!$quote && $user) {
            $sinScope = \App\Models\Quote::withoutGlobalScopes()->with($with)->find($id);
            if ($sinScope && self::esInvitado($user, self::propiedadDeCotizacion($sinScope))) {
                $quote = $sinScope;
            }
        }
        if (!$quote && $falla) {
            throw (new \Illuminate\Database\Eloquent\ModelNotFoundException())->setModel(\App\Models\Quote::class, [$id]);
        }
        return $quote;
    }

    /** Para pruebas: limpia la cache de la petición */
    public static function limpiarCache(): void
    {
        request()->attributes->remove(self::CACHE);
        request()->attributes->remove('acceso_propiedad.tablas');
    }
}
