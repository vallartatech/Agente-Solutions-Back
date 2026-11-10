-- ============================================================================
-- Eliminación de cuentas (Google Play) y consentimientos
-- Equivale a database/migrations/2026_11_10_100000_add_account_deletion_fields.php
-- Usa UNO de los dos (o la migración de Laravel, o este SQL), no ambos.
-- Probar primero en una copia local. MySQL 8.
-- Nota: en MySQL los ALTER TABLE se confirman solos (no entran en transacción).
-- ============================================================================

ALTER TABLE `users`
  ADD COLUMN `deletion_requested_at` TIMESTAMP NULL DEFAULT NULL,
  ADD COLUMN `anonymized_at`         TIMESTAMP NULL DEFAULT NULL,
  ADD COLUMN `privacy_accepted_at`   TIMESTAMP NULL DEFAULT NULL,
  ADD INDEX `users_deletion_requested_at_index` (`deletion_requested_at`);

ALTER TABLE `technician_profiles`
  ADD COLUMN `consentimiento_datos_at` TIMESTAMP NULL DEFAULT NULL;

-- Cuentas que ya pidieron eliminarse con el botón anterior (solo se desactivaban):
-- pasan a eliminación pendiente con 30 días contados desde hoy.
UPDATE `users`
   SET `approval_status` = 'pending_deletion',
       `deletion_requested_at` = NOW()
 WHERE `approval_status` = 'deleted_by_user';

-- Registrar la migración para que `php artisan migrate` no intente repetirla
INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_11_10_100000_add_account_deletion_fields', COALESCE(MAX(`batch`), 0) + 1 FROM `migrations`;

-- ---------------------------------------------------------------------------
-- Reversa (solo si hace falta deshacer):
-- UPDATE users SET approval_status = 'deleted_by_user' WHERE approval_status = 'pending_deletion';
-- ALTER TABLE technician_profiles DROP COLUMN consentimiento_datos_at;
-- ALTER TABLE users DROP INDEX users_deletion_requested_at_index,
--   DROP COLUMN deletion_requested_at, DROP COLUMN anonymized_at, DROP COLUMN privacy_accepted_at;
-- DELETE FROM migrations WHERE migration = '2026_11_10_100000_add_account_deletion_fields';
-- ---------------------------------------------------------------------------
