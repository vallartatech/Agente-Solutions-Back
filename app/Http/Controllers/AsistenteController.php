<?php

namespace App\Http\Controllers;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\RateLimitException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Asistente de ayuda con IA (Claude) para clientes nuevos.
 * - Solo explica cómo usar la app con la guía de resources/asistente; no lee ni cambia datos.
 * - La conversación la guarda el frontend y llega completa en cada pregunta (máx. MAX_MENSAJES).
 * - A la IA solo le llega el texto de la conversación y la pantalla actual; nada del usuario
 *   (ni nombre, ni correo, ni datos de sus propiedades).
 * - Límite de preguntas por usuario al día (services.anthropic.limite_diario).
 */
class AsistenteController extends Controller
{
    // Roles que ven el asistente: por ahora solo el Cliente particular de la red
    private const ROLES = [4];
    private const GUIA = 'asistente/guia-cliente-particular.md';
    private const MAX_MENSAJES = 20;
    private const MAX_LETRAS = 1000;

    // Botones que la IA puede señalar con [[señalar:id]]. El frontend los ilumina con
    // data-ayuda="<id>" (src/components/Shared/senalarBoton.js): agregar en los dos lados.
    private const BOTONES = [
        'solicitar-servicio' => 'Botón SOLICITAR SERVICIO de Inicio (en celular: "+ SERVICIO" junto a PERFIL DE LA PROPIEDAD)',
        'agregar-propiedad' => 'Botón para registrar una propiedad nueva (en Inicio)',
        'editar-propiedad' => 'Botón EDITAR de la propiedad (en Inicio)',
        'info-propiedad' => 'Flecha para ver la información de la propiedad: dirección, CURP, GPS (en Inicio)',
        'tablero-control' => 'Tablero de Control con SOS, POR HACER, PROCESO y LISTOS (en Inicio)',
        'menu-mercado' => 'Botón Mercado / Red del menú',
        'menu-propiedades' => 'Botón Detalles de propiedad del menú',
        'menu-perfil' => 'Botón del perfil (ahí están Mi Perfil, Mis técnicos y Cerrar Sesión)',
        'calendario' => 'Botón del Calendario (citas)',
        'publicar-problema' => 'Botón Publicar problema de Mercado / Red (otra forma de solicitar un servicio)',
        'pestana-en-red' => 'Pestaña En red de Mercado / Red (solicitudes esperando cotizaciones)',
        'pestana-aceptados' => 'Pestaña Aceptados de Mercado / Red (trabajos con técnico asignado)',
        'ver-ofertas' => 'Botón Ver ofertas / Coordinar de una publicación en Mercado / Red (ahí se aceptan cotizaciones, se chatea y se confirma la llegada)',
        'agregar-favorito' => 'Botón Agregar a favoritos de un técnico en Mis técnicos',
    ];

    // Ruta del frontend → nombre que la guía usa para esa pantalla
    private const PANTALLAS = [
        '/vistamarket' => 'Inicio',
        '/propiedades' => 'Detalles de propiedad',
        '/red-autonomos' => 'Mercado / Red (Mis Publicaciones)',
        '/mis-tecnicos' => 'Mis técnicos',
        '/mi-perfil' => 'Mi Perfil',
        '/profile' => 'Mi Perfil',
        '/notificaciones' => 'Notificaciones',
        '/calendario' => 'Calendario',
        '/calendario-cliente' => 'Calendario',
        '/levantamientos' => 'Levantamientos',
        '/reportes-globales' => 'Reportes',
        '/vista-cotizaciones' => 'Cotizaciones',
    ];

    public function ayuda(Request $request)
    {
        $user = $request->user();
        if (!in_array((int) $user->role_id, self::ROLES, true)) {
            return response()->json(['error' => 'El asistente no está disponible para tu cuenta.'], 403);
        }

        $datos = $request->validate([
            'mensajes' => 'required|array|min:1|max:' . self::MAX_MENSAJES,
            'mensajes.*.rol' => 'required|in:user,assistant',
            'mensajes.*.texto' => 'required|string|max:' . self::MAX_LETRAS,
            'pantalla' => 'nullable|string|max:200',
        ]);

        $mensajes = array_map(fn ($m) => ['role' => $m['rol'], 'content' => trim($m['texto'])], $datos['mensajes']);
        if ($mensajes[0]['role'] !== 'user' || end($mensajes)['role'] !== 'user') {
            return response()->json(['error' => 'La conversación debe empezar y terminar con una pregunta.'], 422);
        }

        $apiKey = config('services.anthropic.key');
        if (!$apiKey) {
            return response()->json(['error' => 'El asistente todavía no está configurado.'], 503);
        }

        // Límite diario por usuario (se cuenta antes de llamar a la IA)
        $limite = (int) config('services.anthropic.limite_diario', 40);
        $clave = 'asistente:' . $user->id . ':' . now()->toDateString();
        $usadas = (int) Cache::get($clave, 0);
        if ($usadas >= $limite) {
            return response()->json([
                'error' => 'Llegaste al límite de preguntas de hoy. Vuelve mañana o contacta a soporte.',
            ], 429);
        }
        Cache::put($clave, $usadas + 1, now()->endOfDay());

        // La pantalla va en un mensaje de sistema al final para no romper la caché del prompt fijo
        $mensajes[] = ['role' => 'system', 'content' => 'El usuario está ahora en la pantalla: ' . $this->nombrePantalla($datos['pantalla'] ?? null) . '.'];

        try {
            $client = new Client(apiKey: $apiKey);
            $respuesta = $client->beta->messages->create(
                model: config('services.anthropic.modelo'),
                maxTokens: 2048,
                system: [
                    ['type' => 'text', 'text' => $this->instrucciones(), 'cacheControl' => ['type' => 'ephemeral']],
                ],
                messages: $mensajes,
                // Respuestas de ayuda cortas: poco razonamiento basta
                outputConfig: ['effort' => 'low'],
                // Si el modelo declina por política, el servidor reintenta con su modelo de respaldo
                fallbacks: 'default',
                betas: ['server-side-fallback-2026-07-01'],
            );
        } catch (AuthenticationException $e) {
            Log::error('Asistente IA: API key inválida', ['msg' => $e->getMessage()]);
            return $this->noDisponible();
        } catch (RateLimitException $e) {
            Log::warning('Asistente IA: límite de la API', ['msg' => $e->getMessage()]);
            return response()->json(['error' => 'El asistente está muy ocupado. Intenta en un momento.'], 503);
        } catch (APIStatusException $e) {
            Log::error('Asistente IA: error de la API', ['status' => $e->status ?? null, 'msg' => $e->getMessage()]);
            return $this->noDisponible();
        } catch (APIConnectionException $e) {
            Log::error('Asistente IA: sin conexión', ['msg' => $e->getMessage()]);
            return $this->noDisponible();
        }

        if ($respuesta->stopReason === 'refusal') {
            return response()->json(['respuesta' => 'No puedo ayudarte con eso. Si tienes una duda sobre cómo usar la app, con gusto te explico.']);
        }

        $texto = '';
        foreach ($respuesta->content as $bloque) {
            if ($bloque->type === 'text') {
                $texto .= $bloque->text;
            }
        }

        return response()->json([
            'respuesta' => trim($texto) ?: 'No tengo una respuesta para eso. Intenta preguntarlo de otra forma.',
            'restantes' => max(0, $limite - $usadas - 1),
        ]);
    }

    private function noDisponible()
    {
        return response()->json(['error' => 'El asistente no está disponible en este momento. Intenta más tarde.'], 503);
    }

    private function nombrePantalla(?string $ruta): string
    {
        $ruta = strtolower(rtrim((string) $ruta, '/'));
        if (isset(self::PANTALLAS[$ruta])) {
            return self::PANTALLAS[$ruta];
        }
        if (str_starts_with($ruta, '/detalle-propiedad') || str_starts_with($ruta, '/propiedad')) {
            return 'Detalle de una propiedad';
        }
        if (str_starts_with($ruta, '/tecnico-perfil')) {
            return 'Perfil de un técnico';
        }
        return 'otra pantalla de la app';
    }

    // Texto fijo (sin datos que cambien por petición) para que se aproveche la caché del prompt
    private function instrucciones(): string
    {
        $guia = file_get_contents(resource_path(self::GUIA));
        $botones = implode("\n", array_map(
            fn ($id, $desc) => "- {$id}: {$desc}",
            array_keys(self::BOTONES),
            self::BOTONES,
        ));

        return <<<TXT
Eres el asistente de ayuda de la app Agente Market. Hablas con un cliente particular que
quizás está empezando a usar la app. Tu trabajo es explicarle cómo usarla, paso a paso.

Reglas:
- Responde siempre en español de México, con trato de "tú", claro y amable.
- Sé breve: de 1 a 5 frases o una lista corta de pasos. Nada de textos largos.
- Usa los nombres de botones y pantallas tal como aparecen en la guía, en **negritas**.
- Responde solo con lo que dice la guía. Si algo no está en la guía, no lo inventes:
  di que no tienes esa información y sugiere contactar a soporte de Agente Solutions.
- No tienes acceso a la cuenta del usuario ni a sus datos. No puedes ver sus propiedades,
  solicitudes ni pagos, ni hacer cambios por él. Si te lo pide, explícale cómo hacerlo él.
- Si pregunta por pagos, cobros, precios del plan o problemas con su cuenta, mándalo a soporte.
- Si pregunta algo que no tiene que ver con la app, dile amablemente que solo ayudas con la app.
- Usa la pantalla donde está el usuario (te la indica el sistema) para orientar tu respuesta.

Botón "Muéstrame":
- La app puede llevar al usuario a la pantalla correcta e iluminar un botón. Para eso, escribe
  al final de tu respuesta, en su propia línea, la etiqueta [[señalar:ID]] con uno de los ID
  de la lista de abajo. La app la convierte en un botón "Muéstrame"; el usuario no ve la etiqueta.
- Úsala cuando tu respuesta diga que hay que tocar uno de esos botones: señala el primer botón
  que debe tocar. Máximo una etiqueta por respuesta (dos solo si de verdad hacen falta).
- Usa solo los ID de la lista, escritos exactamente igual. Si ninguno aplica, no pongas etiqueta.
- No menciones la etiqueta ni digas "te señalo"; basta con explicar los pasos.

ID de botones que se pueden señalar:
{$botones}

<guia>
{$guia}
</guia>
TXT;
    }
}
