# Horario en la cotización (Agente Market)

Solo aplica a solicitudes nuevas del Mercado (`work_orders.flujo_horario = 1`). Las que ya existían terminan con el flujo anterior.

## Cómo funciona

| Paso | Qué pasa | Dónde |
|---|---|---|
| El cliente publica | Fecha y hora (o "a cualquier hora"); urgente = hoy. Se guardan en `fecha_deseada` / `hora_deseada`. | `POST /work-orders/cliente` |
| El técnico cotiza | Precio + mensaje + **hora exacta** (`horario`). Cada hora distinta a la del cliente cuenta 1 cambio, máximo 2. No cuenta: la del cliente, reenviar la misma, o una nueva después de que la anterior venció. | `POST /mercado-trabajos/{id}/cotizar` |
| El cliente acepta | Precio y horario juntos: `scheduled_at` = hora de la cotización y la visita queda confirmada. | `POST /network-quotes/{id}/accept` |
| Rechazo | Con motivo; el aviso abre la edición de la cotización (`/mercado-trabajos?trabajo=ID&editar=1`) y sale en el mapa del técnico. | `POST /network-quotes/{id}/reject` |
| Vencimiento | 1 h antes del horario (si se cotizó con menos de 2 h, a la hora). Ya no se puede aceptar; se avisa al técnico. | Tarea automática |
| Recordatorios | Al técnico 20 y 10 min antes (el de 10 no sale si ya presionó "Comenzar ruta"). | Tarea automática |
| Tardanza | 15 min después de la hora sin "Llegué": aviso al cliente cada 15 min ("va en camino" si ya va). | Tarea automática |
| Seguir esperando | El siguiente aviso sale en 15 min. | `POST /mercado-trabajos/{id}/seguir-esperando` |
| Regresar al Mercado | Se le quita el trabajo, se registra en `tecnicos_no_llegaron`, sus avisos quedan solo informativos, pierde el acceso (lista, chat, cotizar) y se borra el QR. Las otras cotizaciones vigentes piden confirmación al técnico. | `POST /mercado-trabajos/{id}/tecnico-no-llego` |
| Nadie cotizó | Urgente sin cotizaciones vigentes a las 8 pm, o programada con la fecha pasada: el cliente programa otro día o cancela. | Tarea automática + `POST /mercado-trabajos/{id}/reprogramar-solicitud` |
| Re-programación | Después de aceptar, 1 sola vez; la visita no cambia hasta que el cliente la aprueba. | `/programar-visita` y `/responder-visita-cliente` |
| Llegada | Igual que antes (Llegué → el cliente confirma). Si el cliente no confirma en 15 min, el técnico puede comenzar. | Frontend |

Reglas y tiempos: `App\Services\HorarioMercado`. Todas las horas son de Mérida, sin zona (igual que `scheduled_at`).

## Producción (Railway)

1. **Migración:** `2026_11_25_130000_horario_en_cotizacion_mercado` (el Pre-deploy Command `php artisan migrate --force` la corre).
   Sin ella todo sigue funcionando como antes.
2. **Tareas automáticas cada minuto.** En Railway el scheduler no corre. Usa el mismo `CRON_TOKEN` de la purga de cuentas
   (docs/eliminacion-cuentas.md):
   - **cron-job.org** (permite cada minuto): petición `POST https://agente-solutions-back-production.up.railway.app/api/interno/mercado/tareas`
     con el header `X-Cron-Token: <CRON_TOKEN>`, cada 1 minuto.
   - **Railway Cron** (mínimo cada 5 min, los avisos saldrían hasta 5 min tarde): servicio con imagen `curlimages/curl`,
     Cron Schedule `*/5 * * * *` y Start Command
     ```
     sh -c "curl -fsS -X POST -H \"X-Cron-Token: $CRON_TOKEN\" https://agente-solutions-back-production.up.railway.app/api/interno/mercado/tareas"
     ```
   - Responde JSON con lo que hizo: `{"vencidas":0,"recordatorios":0,"tardanzas":0,"sin_tecnico":0}`.
3. Fuera de Railway basta con `php artisan schedule:run` cada minuto (está en `routes/console.php`).
   A mano: `php artisan mercado:tareas-horario`.
