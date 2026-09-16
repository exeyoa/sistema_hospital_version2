-- Migración: panel del paciente (rol, password_hash, codigos_recuperacion polimórfico)
-- ------------------------------------------------------------------------------
-- Sistema de Consultas - Hospital Raúl Dávila Mena
--
-- Contexto:
--   Se agrega el rol `paciente` para que los pacientes puedan iniciar sesión
--   en su propio panel y ver su historial de citas / consultas / recetas.
--   La tabla `pacientes` recibe una columna `password_hash` (bcrypt).
--   La tabla `codigos_recuperacion` se hace polimórfica: el código de
--   recuperación puede apuntar a un usuario (admin/medico/recepcionista) O
--   a un paciente. La capa de aplicación se encarga de que EXACTAMENTE uno
--   de los dos esté establecido.
--
-- Cómo aplicarla (idempotente, se puede correr varias veces sin romper):
--   mysql -u root hospital_db < Base_de_datos/migracion_panel_paciente.sql
--
-- No introduce cambios en la integración con el Tribunal Electoral ni agrega
-- la columna `cedula` a `usuarios` (regla del doc 03_INSTRUCCIONES_PARA_IA.md).

-- =====================================================================
-- 1) Rol `paciente` en la tabla `roles`
-- =====================================================================
INSERT INTO roles (nombre_rol) VALUES ('paciente')
ON DUPLICATE KEY UPDATE nombre_rol = nombre_rol;

-- =====================================================================
-- 2) Columna `password_hash` en la tabla `pacientes`
-- =====================================================================
SET @col_existe := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'pacientes'
      AND COLUMN_NAME = 'password_hash'
);

SET @sql := IF(
    @col_existe = 0,
    'ALTER TABLE pacientes ADD COLUMN password_hash VARCHAR(255) NULL AFTER correo',
    'SELECT "La columna password_hash en pacientes ya existe, no se hace nada" AS mensaje'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- =====================================================================
-- 3) `codigos_recuperacion` polimórfica: usuario O paciente
-- =====================================================================

-- 3a) Drop FK existente en id_usuario (idempotente: solo si existe)
SET @fk_existe := (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'codigos_recuperacion'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
      AND CONSTRAINT_NAME = 'codigos_recuperacion_ibfk_1'
);

SET @sql := IF(
    @fk_existe > 0,
    'ALTER TABLE codigos_recuperacion DROP FOREIGN KEY codigos_recuperacion_ibfk_1',
    'SELECT "FK codigos_recuperacion_ibfk_1 ya no existe, no se hace nada" AS mensaje'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 3b) Permitir NULL en id_usuario (idempotente)
SET @col_existe := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'codigos_recuperacion'
      AND COLUMN_NAME = 'id_usuario'
      AND IS_NULLABLE = 'NO'
);

SET @sql := IF(
    @col_existe > 0,
    'ALTER TABLE codigos_recuperacion MODIFY COLUMN id_usuario INT NULL',
    'SELECT "id_usuario ya permite NULL, no se hace nada" AS mensaje'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 3c) Agregar columna id_paciente (idempotente)
SET @col_existe := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'codigos_recuperacion'
      AND COLUMN_NAME = 'id_paciente'
);

SET @sql := IF(
    @col_existe = 0,
    'ALTER TABLE codigos_recuperacion ADD COLUMN id_paciente INT NULL AFTER id_usuario',
    'SELECT "La columna id_paciente en codigos_recuperacion ya existe, no se hace nada" AS mensaje'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 3d) FK en id_paciente → pacientes(id_paciente) (idempotente)
SET @fk_existe := (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'codigos_recuperacion'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
      AND CONSTRAINT_NAME = 'codigos_recuperacion_ibfk_paciente'
);

SET @sql := IF(
    @fk_existe = 0,
    'ALTER TABLE codigos_recuperacion ADD CONSTRAINT codigos_recuperacion_ibfk_paciente FOREIGN KEY (id_paciente) REFERENCES pacientes(id_paciente) ON DELETE CASCADE',
    'SELECT "FK codigos_recuperacion_ibfk_paciente ya existe, no se hace nada" AS mensaje'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
