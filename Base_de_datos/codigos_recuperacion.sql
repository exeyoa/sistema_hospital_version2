-- Sistema de consultas médicas - recuperación de contraseña
-- Tabla para códigos de recuperación de 6 dígitos (expiran en 5 minutos).
-- No elimina físicamente los códigos: se marcan con usado = 1.

-- Comentada para importar directo en la base ya seleccionada (evita error 1044)
-- USE hospital_db;

CREATE TABLE IF NOT EXISTS codigos_recuperacion (
    id_codigo INT AUTO_INCREMENT PRIMARY KEY,
    id_usuario INT NOT NULL,
    codigo CHAR(6) NOT NULL,
    fecha_creacion DATETIME DEFAULT CURRENT_TIMESTAMP,
    fecha_expiracion DATETIME NOT NULL,
    usado TINYINT(1) NOT NULL DEFAULT 0,
    FOREIGN KEY (id_usuario) REFERENCES usuarios(id_usuario)
);