-- Migración: agregar columna foto_perfil a la tabla `usuarios`
-- ---------------------------------------------------------------
-- Sistema de consultas médicas - Sistema de Consultas
--
-- Contexto:
--   El módulo de perfil del recepcionista permite subir una foto
--   de perfil. Se guarda el ARCHIVO en disco (imagenes/perfiles/)
--   y solo el NOMBRE del archivo en esta columna.
--
-- Cómo aplicarla (una sola vez por base de datos):
--   mysql -u root -p hospital_db < Base_de_datos/migracion_foto_perfil.sql
--
-- Esta migración es IDEMPOTENTE: si la columna ya existe, la
-- segunda corrida no hace nada (no rompe). Usa el bloque de
-- INFORMATION_SCHEMA para detectar si la columna ya está.
--
-- No toca la columna `cedula` ni reintroduce la integración con
-- el Tribunal Electoral (regla del documento 03_INSTRUCCIONES_PARA_IA.md).

SET @columna_existe := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'usuarios'
      AND COLUMN_NAME  = 'foto_perfil'
);

SET @sql := IF(
    @columna_existe = 0,
    'ALTER TABLE usuarios ADD COLUMN foto_perfil VARCHAR(255) NULL AFTER password_hash',
    'SELECT "La columna foto_perfil ya existe, no se hace nada" AS mensaje'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
