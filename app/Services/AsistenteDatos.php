<?php

namespace App\Services;

use App\Models\NetworkQuote;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Herramientas de solo lectura del asistente de ayuda con IA.
 *
 * Seguridad: TODO parte de TrabajoFinalizadoRed::propiedadesDe($user) (propias, de su empresa y
 * compartidas) y se consulta con DB::table, nunca con TenantScope ni por tenant_id del usuario
 * (con tenant NULL esos filtros devuelven todo).
 *
 * Nunca regresa teléfonos, correos, CURP, GPS, calle, CLABE, RFC, INE, pagos ni chats.
 */
class AsistenteDatos
{
    private const LIMITE_MAX = 10;

    private const NOMBRES_ROL = [
        2 => 'Técnico',
        6 => 'Técnico independiente',
        7 => 'Contratista',
        8 => 'Técnico de cuadrilla',
        9 => 'Técnico de cuadrilla',
    ];

    // Estados de publicaciones abiertas en el Mercado (igual que /mercado-trabajos only_mine)
    private const MERCADO_ABIERTOS = ['Por Hacer', 'Asignado', 'En Progreso'];

    /** Definiciones para la API de Claude (claves camelCase del SDK de PHP). */
    public static function herramientas(): array
    {
        $propiedad = ['type' => 'integer', 'description' => 'id de la propiedad (de mis_propiedades). Omítelo para todas.'];

        return [
            [
                'name' => 'mis_propiedades',
                'description' => 'Lista las propiedades del usuario (propias y compartidas con él): id, nombre, tipo, colonia y municipio. Úsala para saber el id de "mi casa", "el depa", etc.',
                'inputSchema' => ['type' => 'object'],
            ],
            [
                'name' => 'trabajos',
                'description' => 'Trabajos (órdenes de servicio) del usuario, del más reciente al más viejo: título, especialidad, estado, fechas, técnico y precio (monto en pesos MXN). Úsala para "mi último trabajo", "qué me hicieron", "cuánto pagué", "cómo va mi servicio".',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'propiedad_id' => $propiedad,
                        'estado' => ['type' => 'string', 'enum' => ['activos', 'terminados', 'todos'], 'description' => 'Por defecto todos'],
                        'limite' => ['type' => 'integer', 'description' => 'Cuántos regresar (1 a 10, por defecto 5)'],
                    ],
                ],
            ],
            [
                'name' => 'tecnicos',
                'description' => 'Técnicos que le han trabajado al usuario: nombre, tipo, cuántos trabajos, y su último trabajo con fecha. Ordenados por el más reciente.',
                'inputSchema' => ['type' => 'object', 'properties' => ['propiedad_id' => $propiedad]],
            ],
            [
                'name' => 'citas',
                'description' => 'Citas (trabajos con fecha y hora programada) del usuario, en hora de Mérida.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'cuando' => ['type' => 'string', 'enum' => ['proximas', 'pasadas'], 'description' => 'Por defecto proximas'],
                    ],
                ],
            ],
            [
                'name' => 'gastos',
                'description' => 'Suma lo que el usuario ha gastado en trabajos (precio acordado o cotizado), en total, por propiedad y por tipo de servicio (Mantenimiento, Problema, Instalación, Inspección, SOS). Úsala para "cuánto he gastado", "cuánto llevo en mantenimiento este año".',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'propiedad_id' => $propiedad,
                        'tipo' => ['type' => 'string', 'enum' => ['Mantenimiento', 'Problema', 'Instalacion', 'Inspeccion', 'SOS'], 'description' => 'Omítelo para todos los tipos'],
                        'desde' => ['type' => 'string', 'description' => 'Fecha inicial YYYY-MM-DD (por fecha en que se pidió el trabajo)'],
                        'hasta' => ['type' => 'string', 'description' => 'Fecha final YYYY-MM-DD'],
                    ],
                ],
            ],
            [
                'name' => 'equipos',
                'description' => 'Inventario registrado de la propiedad (levantamiento): equipos e instalaciones por área (aire acondicionado, gas, plomería, eléctrico, electrodomésticos...) con marca, modelo, capacidad, cantidad, estado, especificaciones, observaciones, mantenimiento y foto. Úsala cuando hable de un equipo o instalación de su propiedad ("mi clima", "el tubo del gas", "el boiler").',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'propiedad_id' => $propiedad,
                        'buscar' => ['type' => 'string', 'description' => 'Palabras para filtrar (cualquiera coincide), con sinónimos: por ejemplo "aire clima minisplit" o "gas tanque estacionario tubería". Omítelo para ver todo.'],
                    ],
                ],
            ],
            [
                'name' => 'mercado',
                'description' => 'Publicaciones abiertas del usuario en el Mercado y sus cotizaciones vigentes: precio, horario propuesto y técnico.',
                'inputSchema' => ['type' => 'object'],
            ],
        ];
    }

    /** Ejecuta una herramienta. Lanza \InvalidArgumentException si el nombre no existe. */
    public static function ejecutar(User $user, string $nombre, array $entrada): array
    {
        $propiedades = TrabajoFinalizadoRed::propiedadesDe($user);

        return match ($nombre) {
            'mis_propiedades' => self::misPropiedades($user, $propiedades),
            'trabajos' => self::trabajos($propiedades, $entrada),
            'tecnicos' => self::tecnicos($propiedades, $entrada),
            'citas' => self::citas($propiedades, $entrada),
            'equipos' => self::equipos($propiedades, $entrada),
            'gastos' => self::gastos($propiedades, $entrada),
            'mercado' => self::mercado($propiedades),
            default => throw new \InvalidArgumentException("Herramienta desconocida: {$nombre}"),
        };
    }

    private static function misPropiedades(User $user, array $propiedades): array
    {
        if (!$propiedades) return ['propiedades' => []];
        $compartidas = AccesoPropiedad::compartidasCon($user);

        $filas = DB::table('properties')->whereIn('id', $propiedades)
            ->orderBy('property_name')
            ->get(['id', 'property_name', 'type', 'colonia', 'municipio']);

        return ['propiedades' => $filas->map(fn ($p) => [
            'id' => (int) $p->id,
            'nombre' => $p->property_name,
            'tipo' => $p->type,
            'colonia' => $p->colonia,
            'municipio' => $p->municipio,
            'compartida_conmigo' => in_array((int) $p->id, $compartidas, true),
        ])->values()->all()];
    }

    /** Lista de propiedades a consultar: todas, o solo una si es del usuario. */
    private static function filtrarPropiedad(array $propiedades, array $entrada): ?array
    {
        if (!isset($entrada['propiedad_id'])) return $propiedades;
        $id = (int) $entrada['propiedad_id'];
        return in_array($id, $propiedades, true) ? [$id] : null;
    }

    private static function ordenes(array $propiedades)
    {
        return DB::table('work_orders as wo')
            ->leftJoin('properties as p', 'p.id', '=', 'wo.property_id')
            ->leftJoin('specialties as s', 's.id', '=', 'wo.specialty_id')
            ->leftJoin('users as t', 't.id', '=', 'wo.tecnico_id')
            ->whereIn('wo.property_id', $propiedades)
            ->select([
                'wo.id', 'wo.zone', 'wo.equipment', 'wo.status', 'wo.publish_network', 'wo.created_at',
                'wo.scheduled_at', 'wo.closed_at', 'wo.client_confirmed_at', 'wo.tecnico_id',
                'p.property_name', 's.name as especialidad',
                't.first_name as t_nombre', 't.last_name as t_apellido', 't.role_id as t_rol',
            ]);
    }

    private static function titulo($o): string
    {
        return trim(implode(' - ', array_filter([trim((string) $o->zone), trim((string) $o->equipment)]))) ?: 'Trabajo';
    }

    private static function tecnico($nombre, $apellido, $rol): ?array
    {
        $completo = trim("{$nombre} {$apellido}");
        return $completo === '' ? null : ['nombre' => $completo, 'tipo' => self::NOMBRES_ROL[(int) $rol] ?? 'Técnico'];
    }

    /** Horas del Mercado (scheduled_at, horario_propuesto): ya se guardan en hora de Mérida sin zona. */
    private static function fecha($valor, bool $conHora = false): ?string
    {
        if (!$valor) return null;
        $c = HorarioMercado::leer($valor);
        return $c?->format($conHora ? 'Y-m-d H:i' : 'Y-m-d');
    }

    /** Marcas de la app (created_at, closed_at...): están en la zona de la app; se pasan a Mérida. */
    private static function fechaApp($valor): ?string
    {
        if (!$valor) return null;
        try {
            return \Carbon\Carbon::parse($valor, config('app.timezone'))->setTimezone(HorarioMercado::ZONA)->format('Y-m-d');
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Técnico de la cotización aceptada (trabajos del Mercado sin tecnico_id). */
    private static function tecnicosAceptados(array $ordenIds): array
    {
        if (!$ordenIds) return [];
        return DB::table('network_quotes as q')
            ->join('users as t', 't.id', '=', 'q.technician_id')
            ->whereIn('q.work_order_id', $ordenIds)
            ->where('q.status', 'accepted')
            ->get(['q.work_order_id', 'q.technician_id', 't.first_name', 't.last_name', 't.role_id'])
            ->keyBy('work_order_id')->all();
    }

    private static function trabajos(array $propiedades, array $entrada): array
    {
        $propiedades = self::filtrarPropiedad($propiedades, $entrada);
        if ($propiedades === null) return ['error' => 'Esa propiedad no es del usuario. Usa mis_propiedades para ver sus ids.'];
        if (!$propiedades) return ['trabajos' => []];

        $estado = in_array($entrada['estado'] ?? null, ['activos', 'terminados', 'todos'], true) ? $entrada['estado'] : 'todos';
        $limite = max(1, min(self::LIMITE_MAX, (int) ($entrada['limite'] ?? 5)));

        $consulta = self::ordenes($propiedades)->orderByDesc('wo.created_at')->orderByDesc('wo.id');
        if ($estado === 'terminados') {
            $consulta->whereIn('wo.status', CalificacionCliente::ESTADOS_TERMINADO);
        }
        // "activos" se filtra en PHP con AccesoQr::cerrado (los estados vienen con mayúsculas mezcladas)
        $filas = $estado === 'activos'
            ? $consulta->limit(200)->get()->reject(fn ($o) => AccesoQr::cerrado($o->status))->take($limite)
            : $consulta->limit($limite)->get();

        $aceptados = self::tecnicosAceptados($filas->pluck('id')->all());
        $precios = self::precios($filas->pluck('id')->all());

        return ['trabajos' => $filas->map(function ($o) use ($aceptados, $precios) {
            $tec = self::tecnico($o->t_nombre, $o->t_apellido, $o->t_rol);
            if (!$tec && isset($aceptados[$o->id])) {
                $a = $aceptados[$o->id];
                $tec = self::tecnico($a->first_name, $a->last_name, $a->role_id);
            }
            return [
                'titulo' => self::titulo($o),
                'propiedad' => $o->property_name,
                'especialidad' => $o->especialidad,
                'estado' => $o->status,
                'del_mercado' => (int) $o->publish_network === 1,
                'creado' => self::fechaApp($o->created_at),
                'programado' => self::fecha($o->scheduled_at, true),
                'terminado' => self::fechaApp($o->client_confirmed_at ?: $o->closed_at),
                'tecnico' => $tec,
                'precio' => $precios[$o->id] ?? null,
            ];
        })->values()->all()];
    }

    /**
     * Precio de cada orden (en pesos), sin datos de pago (tarjeta, MercadoPago):
     * - Mercado: cotización aceptada (su price ya trae el ajuste aceptado, si hubo).
     * - Solutions: la cotización más reciente que no fue reemplazada, con su estado.
     */
    private static function precios(array $ordenIds): array
    {
        if (!$ordenIds) return [];
        $precios = [];

        $mercado = DB::table('network_quotes')->whereIn('work_order_id', $ordenIds)
            ->where('status', 'accepted')->get(['id', 'work_order_id', 'price']);
        $ajustadas = $mercado->isEmpty() ? [] : DB::table('network_quote_ajustes')
            ->whereIn('network_quote_id', $mercado->pluck('id')->all())
            ->where('status', 'accepted')->pluck('network_quote_id')->map(fn ($id) => (int) $id)->all();
        foreach ($mercado as $q) {
            if ($q->price === null) continue;
            $precios[$q->work_order_id] = [
                'monto' => (float) $q->price,
                'tipo' => 'precio acordado con el técnico en el Mercado',
                'ajustado' => in_array((int) $q->id, $ajustadas, true),
            ];
        }

        $cotizaciones = DB::table('quotes')->whereIn('work_order_id', $ordenIds)
            ->where('status', '!=', 'Recotizada')
            ->orderByDesc('created_at')->orderByDesc('id')
            ->get(['work_order_id', 'estimated_amount', 'status', 'concept']);
        foreach ($cotizaciones as $q) {
            if (isset($precios[$q->work_order_id]) || $q->estimated_amount === null) continue;
            $precios[$q->work_order_id] = [
                'monto' => (float) $q->estimated_amount,
                'tipo' => 'cotización',
                'concepto' => $q->concept,
                'estado' => $q->status,
                'pagada' => str_starts_with((string) $q->status, 'Pagado'),
            ];
        }

        return $precios;
    }

    private static function tecnicos(array $propiedades, array $entrada): array
    {
        $propiedades = self::filtrarPropiedad($propiedades, $entrada);
        if ($propiedades === null) return ['error' => 'Esa propiedad no es del usuario. Usa mis_propiedades para ver sus ids.'];
        if (!$propiedades) return ['tecnicos' => []];

        $ordenes = DB::table('work_orders as wo')
            ->leftJoin('properties as p', 'p.id', '=', 'wo.property_id')
            ->whereIn('wo.property_id', $propiedades)
            ->get(['wo.id', 'wo.zone', 'wo.equipment', 'wo.tecnico_id', 'wo.created_at', 'wo.closed_at', 'wo.client_confirmed_at', 'p.property_name'])
            ->keyBy('id');
        if ($ordenes->isEmpty()) return ['tecnicos' => []];
        $ids = $ordenes->keys()->all();

        // Pares (orden, técnico): asignado, cuadrilla de la orden y cotización aceptada
        $pares = [];
        foreach ($ordenes as $o) {
            if ($o->tecnico_id) $pares[] = [$o->id, (int) $o->tecnico_id];
        }
        foreach (DB::table('work_order_technician')->whereIn('work_order_id', $ids)->get(['work_order_id', 'technician_id']) as $f) {
            $pares[] = [(int) $f->work_order_id, (int) $f->technician_id];
        }
        foreach (DB::table('network_quotes')->whereIn('work_order_id', $ids)->where('status', 'accepted')->get(['work_order_id', 'technician_id']) as $f) {
            $pares[] = [(int) $f->work_order_id, (int) $f->technician_id];
        }
        if (!$pares) return ['tecnicos' => []];

        $usuarios = DB::table('users')->whereIn('id', array_unique(array_column($pares, 1)))
            ->get(['id', 'first_name', 'last_name', 'role_id'])->keyBy('id');

        $porTecnico = [];
        foreach ($pares as [$ordenId, $tecId]) {
            $u = $usuarios[$tecId] ?? null;
            $o = $ordenes[$ordenId] ?? null;
            if (!$u || !$o) continue;
            $fecha = $o->client_confirmed_at ?: ($o->closed_at ?: $o->created_at);
            $actual = $porTecnico[$tecId] ?? ['ordenes' => [], 'ultima' => null, 'fecha' => null];
            $actual['ordenes'][$ordenId] = true;
            if ($actual['fecha'] === null || (string) $fecha > (string) $actual['fecha']) {
                $actual['ultima'] = $o;
                $actual['fecha'] = $fecha;
            }
            $porTecnico[$tecId] = $actual;
        }

        uasort($porTecnico, fn ($a, $b) => strcmp((string) $b['fecha'], (string) $a['fecha']));

        $lista = [];
        foreach (array_slice($porTecnico, 0, self::LIMITE_MAX, true) as $tecId => $d) {
            $u = $usuarios[$tecId];
            $lista[] = array_merge(self::tecnico($u->first_name, $u->last_name, $u->role_id) ?? ['nombre' => 'Técnico', 'tipo' => 'Técnico'], [
                'trabajos_conmigo' => count($d['ordenes']),
                'ultimo_trabajo' => self::titulo($d['ultima']),
                'propiedad' => $d['ultima']->property_name,
                'fecha_ultimo' => self::fechaApp($d['fecha']),
            ]);
        }

        return ['tecnicos' => $lista];
    }

    private static function citas(array $propiedades, array $entrada): array
    {
        if (!$propiedades) return ['citas' => []];
        $pasadas = ($entrada['cuando'] ?? 'proximas') === 'pasadas';
        $ahora = HorarioMercado::ahora()->format('Y-m-d H:i:s');

        $consulta = self::ordenes($propiedades)->whereNotNull('wo.scheduled_at');
        $filas = $pasadas
            ? $consulta->where('wo.scheduled_at', '<', $ahora)->orderByDesc('wo.scheduled_at')->limit(self::LIMITE_MAX)->get()
            : $consulta->where('wo.scheduled_at', '>=', $ahora)->orderBy('wo.scheduled_at')->limit(100)->get()
                ->reject(fn ($o) => AccesoQr::cerrado($o->status))->take(self::LIMITE_MAX);

        $aceptados = self::tecnicosAceptados($filas->pluck('id')->all());

        return ['citas' => $filas->map(function ($o) use ($aceptados) {
            $tec = self::tecnico($o->t_nombre, $o->t_apellido, $o->t_rol);
            if (!$tec && isset($aceptados[$o->id])) {
                $a = $aceptados[$o->id];
                $tec = self::tecnico($a->first_name, $a->last_name, $a->role_id);
            }
            return [
                'fecha_hora' => self::fecha($o->scheduled_at, true),
                'trabajo' => self::titulo($o),
                'propiedad' => $o->property_name,
                'estado' => $o->status,
                'tecnico' => $tec,
            ];
        })->values()->all()];
    }

    /**
     * Gasto en trabajos: "gastado" = terminados con precio; "comprometido" = en curso con precio
     * acordado. Los cancelados no cuentan. Sin datos de tarjetas ni MercadoPago.
     */
    private static function gastos(array $propiedades, array $entrada): array
    {
        $propiedades = self::filtrarPropiedad($propiedades, $entrada);
        if ($propiedades === null) return ['error' => 'Esa propiedad no es del usuario. Usa mis_propiedades para ver sus ids.'];
        if (!$propiedades) return ['total_gastado' => 0, 'trabajos_con_precio' => 0];

        $tipos = ['Mantenimiento', 'Problema', 'Instalacion', 'Inspeccion', 'SOS'];
        $fecha = fn ($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;

        $consulta = DB::table('work_orders as wo')
            ->leftJoin('properties as p', 'p.id', '=', 'wo.property_id')
            ->whereIn('wo.property_id', $propiedades);
        if (in_array($entrada['tipo'] ?? null, $tipos, true)) {
            $consulta->where('wo.type', $entrada['tipo']);
        }
        if ($d = $fecha($entrada['desde'] ?? null)) $consulta->where('wo.created_at', '>=', "{$d} 00:00:00");
        if ($h = $fecha($entrada['hasta'] ?? null)) $consulta->where('wo.created_at', '<=', "{$h} 23:59:59");
        $ordenes = $consulta->get(['wo.id', 'wo.type', 'wo.status', 'p.property_name']);

        $precios = self::precios($ordenes->pluck('id')->all());
        $terminado = fn ($s) => in_array(mb_strtolower((string) $s), array_map('mb_strtolower', CalificacionCliente::ESTADOS_TERMINADO), true);
        $cancelado = fn ($s) => str_starts_with(mb_strtolower((string) $s), 'cancelad') || mb_strtolower((string) $s) === 'rechazado';

        $gastado = 0.0; $comprometido = 0.0; $conPrecio = 0; $sinPrecio = 0;
        $porPropiedad = []; $porTipo = [];
        foreach ($ordenes as $o) {
            if ($cancelado($o->status)) continue;
            $monto = $precios[$o->id]['monto'] ?? null;
            if ($monto === null) {
                if ($terminado($o->status)) $sinPrecio++;
                continue;
            }
            if ($terminado($o->status)) {
                $gastado += $monto;
                $conPrecio++;
                $porPropiedad[$o->property_name ?? 'Sin nombre'] = ($porPropiedad[$o->property_name ?? 'Sin nombre'] ?? 0) + $monto;
                $porTipo[$o->type ?: 'Sin tipo'] = ($porTipo[$o->type ?: 'Sin tipo'] ?? 0) + $monto;
            } else {
                $comprometido += $monto;
            }
        }
        arsort($porPropiedad);
        arsort($porTipo);

        return [
            'moneda' => 'MXN',
            'total_gastado' => round($gastado, 2),
            'trabajos_con_precio' => $conPrecio,
            'por_propiedad' => $porPropiedad,
            'por_tipo' => $porTipo,
            'en_curso_con_precio_acordado' => round($comprometido, 2),
            'terminados_sin_precio_registrado' => $sinPrecio,
            'nota' => 'Precios acordados o cotizados, no recibos de pago. Los cancelados no cuentan.',
        ];
    }

    /** Fotos de Cloudinary (las únicas que el chat puede mostrar). */
    public static function esFoto(?string $url): bool
    {
        return is_string($url) && str_starts_with($url, 'https://res.cloudinary.com/');
    }

    private static function equipos(array $propiedades, array $entrada): array
    {
        $propiedades = self::filtrarPropiedad($propiedades, $entrada);
        if ($propiedades === null) return ['error' => 'Esa propiedad no es del usuario. Usa mis_propiedades para ver sus ids.'];
        if (!$propiedades) return ['equipos' => []];

        // Palabras de búsqueda (la intercalación de MySQL ignora acentos y mayúsculas)
        $palabras = array_slice(array_values(array_filter(
            preg_split('/[\s,]+/u', mb_substr(trim((string) ($entrada['buscar'] ?? '')), 0, 120)),
            fn ($p) => mb_strlen($p) >= 2
        )), 0, 8);
        $coincide = function ($q, array $columnas) use ($palabras) {
            if (!$palabras) return;
            $q->where(function ($w) use ($palabras, $columnas) {
                foreach ($palabras as $p) {
                    foreach ($columnas as $c) {
                        $w->orWhere($c, 'like', '%' . addcslashes($p, '%_\\') . '%');
                    }
                }
            });
        };

        $areas = DB::table('property_areas')->whereIn('property_id', $propiedades)
            ->get(['id', 'parent_id', 'name', 'property_id'])->keyBy('id');
        $nombreArea = function ($id) use ($areas) {
            $a = $areas[$id] ?? null;
            if (!$a) return null;
            $padre = $a->parent_id ? ($areas[$a->parent_id]->name ?? null) : null;
            return $padre ? "{$padre} › {$a->name}" : $a->name;
        };
        $nombresProp = DB::table('properties')->whereIn('id', $propiedades)->pluck('property_name', 'id');

        $consulta = DB::table('property_components as c')
            ->join('property_areas as a', 'a.id', '=', 'c.property_area_id')
            ->whereIn('a.property_id', $propiedades);
        $coincide($consulta, ['c.category', 'c.sub_category', 'c.brand', 'c.model_or_color', 'c.observations', 'a.name']);
        $componentes = $consulta->orderBy('a.property_id')->orderBy('a.name')->limit(60)->get([
            'c.id', 'c.property_area_id', 'a.property_id', 'c.category', 'c.sub_category', 'c.brand', 'c.model_or_color',
            'c.quantity', 'c.unit', 'c.status', 'c.specs', 'c.observations', 'c.image_path',
        ]);

        $camposMant = ['property_id', 'property_component_id', 'tipo', 'detalle', 'cantidad', 'frecuencia_meses', 'ultimo_mantenimiento', 'foto_equipo_url'];
        // Mantenimiento de los componentes encontrados (aunque su tipo no coincida con la búsqueda)
        $mantPorComponente = $componentes->isEmpty() ? collect() : DB::table('maintenance_equipment')
            ->whereIn('property_id', $propiedades)->where('activo', 1)
            ->whereIn('property_component_id', $componentes->pluck('id')->all())
            ->get($camposMant)->keyBy('property_component_id');
        // Equipos dados de alta solo en Mantenimiento (sin componente del levantamiento)
        $mantenimiento = DB::table('maintenance_equipment')->whereIn('property_id', $propiedades)
            ->where('activo', 1)->whereNull('property_component_id');
        $coincide($mantenimiento, ['tipo', 'detalle']);
        $mantenimiento = $mantenimiento->limit(30)->get($camposMant);

        $texto = fn ($v) => ($v = trim((string) $v)) === '' ? null : $v;
        $infoMant = fn ($m) => $m ? [
            'cada_meses' => $m->frecuencia_meses ? (int) $m->frecuencia_meses : null,
            'ultimo' => self::fechaApp($m->ultimo_mantenimiento),
        ] : null;

        $lista = $componentes->map(function ($c) use ($nombreArea, $nombresProp, $mantPorComponente, $texto, $infoMant) {
            $specs = is_string($c->specs) ? json_decode($c->specs, true) : null;
            return array_filter([
                'propiedad' => $nombresProp[$c->property_id] ?? null,
                'area' => $nombreArea($c->property_area_id),
                'categoria' => $texto($c->category),
                'tipo' => $texto($c->sub_category),
                'marca' => $texto($c->brand),
                'modelo' => $texto($c->model_or_color),
                'cantidad' => $c->quantity !== null ? rtrim(rtrim((string) $c->quantity, '0'), '.') . ($c->unit ? " {$c->unit}" : '') : null,
                'estado' => $texto($c->status),
                'especificaciones' => $specs ?: null,
                'observaciones' => $texto($c->observations),
                'mantenimiento' => $infoMant($mantPorComponente[$c->id] ?? null),
                'foto' => self::esFoto($c->image_path) ? $c->image_path : null,
            ], fn ($v) => $v !== null);
        })->values()->all();

        foreach ($mantenimiento as $m) {
            $lista[] = array_filter([
                'propiedad' => $nombresProp[$m->property_id] ?? null,
                'tipo' => $texto($m->tipo),
                'detalle' => $texto($m->detalle),
                'cantidad' => $m->cantidad ? (string) $m->cantidad : null,
                'mantenimiento' => $infoMant($m),
                'foto' => self::esFoto($m->foto_equipo_url) ? $m->foto_equipo_url : null,
            ], fn ($v) => $v !== null);
        }

        return ['equipos' => $lista, 'nota' => $lista ? null : 'No hay equipos registrados que coincidan. Puede que la propiedad no tenga levantamiento.'];
    }

    private static function mercado(array $propiedades): array
    {
        if (!$propiedades) return ['publicaciones' => []];

        $publicaciones = self::ordenes($propiedades)
            ->where('wo.publish_network', 1)
            ->whereIn('wo.status', self::MERCADO_ABIERTOS)
            ->orderByDesc('wo.created_at')
            ->limit(self::LIMITE_MAX)
            ->get();
        if ($publicaciones->isEmpty()) return ['publicaciones' => []];

        $cotizaciones = NetworkQuote::query()
            ->whereIn('work_order_id', $publicaciones->pluck('id')->all())
            ->whereIn('status', ['pending', 'accepted'])
            ->whereNull('retirada_at')
            ->get()
            ->reject(fn ($q) => HorarioMercado::estaVencida($q))
            ->groupBy('work_order_id');

        $tecnicos = DB::table('users')
            ->whereIn('id', $cotizaciones->flatten()->pluck('technician_id')->unique()->all())
            ->get(['id', 'first_name', 'last_name', 'role_id'])->keyBy('id');

        return ['publicaciones' => $publicaciones->map(fn ($o) => [
            'titulo' => self::titulo($o),
            'propiedad' => $o->property_name,
            'estado' => $o->status,
            'publicado' => self::fechaApp($o->created_at),
            'cotizaciones' => ($cotizaciones[$o->id] ?? collect())->map(function ($q) use ($tecnicos) {
                $u = $tecnicos[$q->technician_id] ?? null;
                return [
                    'estado' => $q->status === 'accepted' ? 'aceptada' : 'pendiente',
                    'precio' => $q->price !== null ? (float) $q->price : null,
                    'horario_propuesto' => self::fecha($q->getAttributes()['horario_propuesto'] ?? null, true),
                    'tecnico' => $u ? self::tecnico($u->first_name, $u->last_name, $u->role_id) : null,
                ];
            })->values()->all(),
        ])->values()->all()];
    }
}
