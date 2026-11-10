# Eliminación de cuentas (Google Play)

## Cómo funciona

| Paso | Qué pasa | Dónde |
|---|---|---|
| 1. El usuario toca "Eliminar mi Cuenta" (app o `/eliminar-cuenta`) | `approval_status = pending_deletion`, `deletion_requested_at = now()`, `is_active = 0`, se cierran sus sesiones. El login responde: *"Tu cuenta se eliminará definitivamente en 30 días. Si fue un error, contacta a soporte."* | `CuentaEliminacion::solicitar()` |
| Aviso a ROOT | Notificación. Si la empresa (tenant) se queda sin otro admin activo, lo marca como **ATENCIÓN**. | `UserAccountDeletedNotification` |
| 2. Soporte cancela (≤ 30 días) | Usuarios → "Eliminación pendiente" → reactivar. `POST /api/usuarios/{id}/cancelar-eliminacion` (ROOT, o Admin de su tenant). | `CuentaEliminacion::cancelar()` |
| 3. Día 30 | La purga diaria anonimiza la cuenta. | `php artisan cuentas:purgar-eliminadas` |
| Inmediato (solicitud por correo) | Usuarios → botón eliminar → escribir ELIMINAR. `DELETE /api/usuarios/{id}` ya **no borra la fila**: anonimiza al instante. | `CuentaEliminacion::anonimizar()` |

**Nunca se hace `DELETE` de `users` ni de `clients`:** las llaves foráneas en cascada se llevarían
propiedades → órdenes → cotizaciones/pagos → reportes. Los modelos `User` y `Client` lanzan una
excepción si alguien intenta `->delete()`.

### Qué borra la anonimización
- **Archivos:** carpeta `storage/app/private/kyc/{id}` (INE, comprobante, antecedentes, diplomas),
  foto de perfil y portada (disco o Cloudinary), adjuntos de chat y stickers personales (Cloudinary).
  Una imagen de Cloudinary que use otro usuario no se borra.
- **Datos:** nombre → "Usuario eliminado", correo → `eliminado-{id}@eliminado.invalid` (libera el correo
  y el teléfono para registrarse de nuevo), teléfono, nacimiento, contraseña aleatoria; `technician_profiles`
  (RFC, CLABE, banco…), cursos, experiencias, especialidades; ubicaciones; sesiones, tokens, notificaciones;
  texto y adjuntos de sus mensajes; sus entradas en `chat_history` de cotizaciones; comentarios de reseñas;
  correo/teléfono del titular copiados en `tenants`.
- **Se conserva anonimizado:** órdenes de trabajo, propiedades, reportes, calificaciones numéricas, la empresa (tenant).
- **Pagos:** de `mp_payment_data` / `advance_mp_data` / `remaining_mp_data` solo queda
  `mp_payment_id, status, amount, currency, payment_type, date_approved, date_created`
  (se quitan tarjetahabiente, correo del pagador y últimos 4 dígitos).
- **OneSignal:** `DELETE /apps/{app}/users/by/external_id/{id}` (necesita `ONESIGNAL_REST_API_KEY`).
- **Log:** solo `user_id`, fecha y conteos.

---

## Probar en local (NUNCA contra producción)

`.env` apunta a la base de producción. Todo comando de prueba lleva `--env=testing`, que usa `.env.testing`
(base `agente_prod_copia` en `127.0.0.1`, sin Cloudinary ni OneSignal).

1. Pon tu contraseña de root de MySQL 8.4 local en `.env.testing` → `DB_PASSWORD=`.
2. Crea la base y carga la copia (desde `Desktop/Agente`):
   ```bash
   "/c/Program Files/MySQL/MySQL Server 8.4/bin/mysql.exe" -uroot -p -h127.0.0.1 \
     -e "CREATE DATABASE agente_prod_copia CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
   "/c/Program Files/MySQL/MySQL Server 8.4/bin/mysql.exe" -uroot -p -h127.0.0.1 agente_prod_copia \
     < "Base de Datos/Agente_Railway_antes_mantenimiento_2026-10-30.sql"
   ```
3. Verifica que apunta a local **antes** de migrar:
   ```bash
   php artisan about --env=testing --only=environment   # debe decir testing
   php artisan tinker --env=testing --execute="echo config('database.connections.mysql.host');"   # 127.0.0.1
   ```
4. Migra (la copia es del 30-oct; también corre las migraciones posteriores):
   ```bash
   php artisan migrate --env=testing
   ```
5. Prueba el flujo:
   ```bash
   php artisan serve --env=testing        # API en http://127.0.0.1:8000
   ```
   En el frontend, `VITE_API_BASE_URL=http://127.0.0.1:8000/api` en `.env.local` y `npm run dev`.
   - Registra un cliente nuevo → debe pedir la casilla del Aviso de Privacidad.
   - Entra como técnico de la red (rol 6/8) → "Completar perfil": Cobro e Identidad piden el consentimiento primero.
   - Perfil → "Eliminar mi Cuenta" → mensaje de 30 días; volver a entrar → mismo mensaje.
   - Como ROOT → Usuarios → "Eliminación pendiente" → reactivar.
   - Simula que pasaron 30 días y purga:
     ```bash
     php artisan tinker --env=testing --execute="DB::table('users')->where('id', 123)->update(['deletion_requested_at' => now()->subDays(31)]);"
     php artisan cuentas:purgar-eliminadas --env=testing --dry-run
     php artisan cuentas:purgar-eliminadas --env=testing
     ```
     Con Cloudinary/OneSignal vacíos en `.env.testing`, el reporte muestra `errores_archivos` (los de la nube)
     y `onesignal=sin_configurar`: es lo esperado, no toca nada real.

---

## Producción (Railway)

1. **Migración:** `php artisan migrate` en el servicio del backend, o `database/sql/2026_11_10_account_deletion.sql`
   (uno de los dos). Haz un respaldo antes. Las cuentas que ya habían pedido eliminarse (`deleted_by_user`)
   pasan a `pending_deletion` con 30 días contados desde ese día.
2. **Variables del servicio backend (Agente-Solutions-Back):**
   - `CRON_TOKEN` = un valor largo y aleatorio (`openssl rand -hex 32`).
   - `ACCOUNT_PURGE_REMOTE_DELETE=true` (también borra en Cloudinary y OneSignal). En local va en `false`.
   - `ONESIGNAL_REST_API_KEY` (si falta, la purga reporta `onesignal=sin_configurar`).
   - `RECAPTCHA_SECRET_KEY`, `CLOUDINARY_URL`, `APP_KEY` (sin cambiar: cifra RFC y CLABE).
3. **Pre-deploy Command:** Settings → Deploy → Pre-deploy Command = `php artisan migrate --force`.
   Si una migración falla, el deploy se detiene antes de cambiar el tráfico.
4. **Volume en `/app/storage/app`** (documentos KYC y archivos del disco `public`):
   1. Servicio Agente-Solutions-Back → clic derecho / ⌘K → **Add Volume** (o Settings → Volumes → New Volume).
   2. **Mount Path:** `/app/storage/app` (no `/app/storage`: ocultaría `framework/` y `logs/`).
   3. Railway redespliega. El volumen empieza vacío: los archivos que había en el disco del contenedor no se copian.
   4. Verifica: `railway ssh -s Agente-Solutions-Back -- ls -la /app/storage/app`; si falla al escribir por
      permisos, agrega la variable `RAILWAY_RUN_UID=0` y redespliega.
   5. Si las fotos del disco `public` se sirven por `/storage/...`, confirma que exista el enlace:
      `railway ssh -s Agente-Solutions-Back -- php artisan storage:link`.
5. **Cron diario.** La purga debe correr **dentro del servicio web** (ahí está el disco con los documentos KYC;
   un servicio Cron aparte no lo ve). Crea en Railway un servicio nuevo de tipo Cron:
   - Imagen: `curlimages/curl`.
   - Cron Schedule: `0 9 * * *` (09:00 UTC = 03:00 en Ciudad de México).
   - Variable del servicio Cron: `CRON_TOKEN=${{Agente-Solutions-Back.CRON_TOKEN}}` (referencia).
   - Start Command:
     ```
     sh -c "curl -fsS -X POST -H \"X-Cron-Token: $CRON_TOKEN\" https://agentesolutionsback-production.up.railway.app/api/interno/cuentas/purgar"
     ```
   - Prueba en seco: agrega `?dry_run=1` al final de la URL y córrelo una vez a mano (debe responder 200).
   Alternativa sin Railway Cron: cron-job.org con la misma petición.

---

## Respaldos y copias locales (plazo de 90 días)

La página pública de eliminación promete que los datos eliminados pueden permanecer en respaldos
**hasta 90 días**. Para cumplirlo:

- Todo respaldo con datos reales (`Base de Datos/*.sql`, descargas de Railway, etc.) se **borra a los 90 días**
  de su fecha. Hoy quedan `Agente_Railway_antes_mantenimiento_2026-10-30.sql` y `Agente_Railway_2026-11-09.sql`:
  se conservan hasta pasar el deploy y deben borrarse a más tardar el 28-ene-2027 y el 7-feb-2027 respectivamente.
- Las bases locales con copia de producción (**`agente`** y **`agente_prod_copia`**) se **eliminan después del deploy**
  (`DROP DATABASE`), junto con la carpeta `storage/app/private/kyc` local si se usó en pruebas.
- Los respaldos nunca se suben a git ni se comparten.
