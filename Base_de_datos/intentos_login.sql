-- =====================================================================
-- Tabla: intentos_login
-- Propósito: registrar cada intento de inicio de sesión (exitoso o
-- fallido) para poder bloquear temporalmente una cuenta tras 3 intentos
-- fallidos consecutivos en una ventana de tiempo, y como bitácora de
-- auditoría de accesos al sistema.
-- =====================================================================

-- Comentada para importar directo en la base ya seleccionada (evita error 1044)
-- USE hospital_db;

CREATE TABLE IF NOT EXISTS intentos_login (
    id_intento   INT AUTO_INCREMENT PRIMARY KEY,
    id_usuario   INT NOT NULL,
    fecha_hora   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    exitoso      TINYINT(1) NOT NULL,           -- 1 = login correcto, 0 = fallido
    ip           VARCHAR(45) NULL,              -- soporta IPv4 e IPv6

    CONSTRAINT fk_intento_usuario
        FOREIGN KEY (id_usuario) REFERENCES usuarios(id_usuario)
        ON DELETE CASCADE,

    -- Índice compuesto: acelera la consulta típica
    -- "cuántos intentos fallidos tiene este usuario en los últimos X minutos"
    INDEX idx_usuario_fecha (id_usuario, fecha_hora)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
