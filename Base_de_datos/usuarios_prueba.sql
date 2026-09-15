-- Usuarios de prueba para probar el login (contraseña real: prueba123)
-- Ejecuta esto en phpMyAdmin, pestaña SQL, después de haber importado hospital_db.sql

-- Comentada para importar directo en la base ya seleccionada (evita error 1044)
-- USE hospital_db;

INSERT INTO usuarios (nombre, apellido, correo, usuario, password_hash, id_rol) VALUES
('Ana', 'Administradora', 'admin@hospital.com', 'admin', '$2y$10$ZfFWMlq3ch8QZdfbR7ZZhOC7dpS8BdbRD6ikwMjs7VCDrZrbpS0fi', 1),
('Carlos', 'Pérez', 'medico@hospital.com', 'medico1', '$2y$10$ZfFWMlq3ch8QZdfbR7ZZhOC7dpS8BdbRD6ikwMjs7VCDrZrbpS0fi', 2),
('Laura', 'Gómez', 'recepcion@hospital.com', 'recepcion1', '$2y$10$ZfFWMlq3ch8QZdfbR7ZZhOC7dpS8BdbRD6ikwMjs7VCDrZrbpS0fi', 3);

-- Vincula al médico de prueba con una especialidad (medicina general = id 1)
INSERT INTO medicos (id_usuario, id_especialidad, numero_colegiado)
VALUES ((SELECT id_usuario FROM usuarios WHERE usuario = 'medico1'), 1, 'COL-0001');
