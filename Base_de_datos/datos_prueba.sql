-- ============================================================
-- DATOS DE PRUEBA — Sistema de Consultas Médicas
-- Este script SOLO agrega filas nuevas (INSERT). No modifica
-- ni borra ninguna tabla ni estructura existente.
-- Ejecútalo desde phpMyAdmin > tu base de datos > pestaña "SQL"
-- ============================================================

-- ------------------------------------------------------------
-- 1) PACIENTES DE PRUEBA
-- ------------------------------------------------------------
INSERT INTO pacientes (nombre, apellido, cedula, fecha_nacimiento, sexo, telefono, direccion, correo)
VALUES ('María', 'González', '8-123-4560', '1990-03-14', 'F', '6600-1111', 'Calle 1, Panamá', 'maria.gonzalez@correo.com');
SET @id_p1 = LAST_INSERT_ID();

INSERT INTO pacientes (nombre, apellido, cedula, fecha_nacimiento, sexo, telefono, direccion, correo)
VALUES ('José', 'Herrera', '8-234-5671', '1979-07-22', 'M', '6600-2222', 'Calle 2, Panamá', 'jose.herrera@correo.com');
SET @id_p2 = LAST_INSERT_ID();

INSERT INTO pacientes (nombre, apellido, cedula, fecha_nacimiento, sexo, telefono, direccion, correo)
VALUES ('Ana', 'Rodríguez', '8-345-6782', '1996-11-02', 'F', '6600-3333', 'Calle 3, Panamá', 'ana.rodriguez@correo.com');
SET @id_p3 = LAST_INSERT_ID();

INSERT INTO pacientes (nombre, apellido, cedula, fecha_nacimiento, sexo, telefono, direccion, correo)
VALUES ('Luis', 'Martínez', '8-456-7893', '1972-01-30', 'M', '6600-4444', 'Calle 4, Panamá', 'luis.martinez@correo.com');
SET @id_p4 = LAST_INSERT_ID();

INSERT INTO pacientes (nombre, apellido, cedula, fecha_nacimiento, sexo, telefono, direccion, correo)
VALUES ('Carmen', 'López', '8-567-8904', '1963-09-18', 'F', '6600-5555', 'Calle 5, Panamá', 'carmen.lopez@correo.com');
SET @id_p5 = LAST_INSERT_ID();

INSERT INTO pacientes (nombre, apellido, cedula, fecha_nacimiento, sexo, telefono, direccion, correo)
VALUES ('Ricardo', 'Sánchez', '8-678-9015', '2001-05-09', 'M', '6600-6666', 'Calle 6, Panamá', 'ricardo.sanchez@correo.com');
SET @id_p6 = LAST_INSERT_ID();

INSERT INTO pacientes (nombre, apellido, cedula, fecha_nacimiento, sexo, telefono, direccion, correo)
VALUES ('Patricia', 'Vega', '8-789-0126', '1985-12-25', 'F', '6600-7777', 'Calle 7, Panamá', 'patricia.vega@correo.com');
SET @id_p7 = LAST_INSERT_ID();

-- ------------------------------------------------------------
-- 2) TURNOS DE HOY (para ver la "Cola de pacientes")
--    tipo: con_cita / espontaneo
--    estado: en_espera / en_consulta / atendido
-- ------------------------------------------------------------
INSERT INTO turnos (id_paciente, id_cita, numero_turno, tipo, estado, fecha)
VALUES (@id_p1, NULL, 1, 'con_cita', 'en_espera', NOW());

INSERT INTO turnos (id_paciente, id_cita, numero_turno, tipo, estado, fecha)
VALUES (@id_p2, NULL, 2, 'espontaneo', 'en_espera', NOW());

INSERT INTO turnos (id_paciente, id_cita, numero_turno, tipo, estado, fecha)
VALUES (@id_p3, NULL, 3, 'con_cita', 'en_consulta', NOW());

INSERT INTO turnos (id_paciente, id_cita, numero_turno, tipo, estado, fecha)
VALUES (@id_p4, NULL, 4, 'espontaneo', 'en_espera', NOW());

INSERT INTO turnos (id_paciente, id_cita, numero_turno, tipo, estado, fecha)
VALUES (@id_p5, NULL, 5, 'con_cita', 'en_espera', NOW());
SET @id_turno_hoy_p5 = LAST_INSERT_ID();

-- Un paciente ya atendido hoy, para que "Consultas atendidas" no salga en 0
INSERT INTO turnos (id_paciente, id_cita, numero_turno, tipo, estado, fecha)
VALUES (@id_p6, NULL, 6, 'espontaneo', 'atendido', NOW());
SET @id_turno_hoy_p6 = LAST_INSERT_ID();

-- ------------------------------------------------------------
-- 3) CONSULTA + RECETA DE EJEMPLO (para ver "Mis consultas")
--    Se enlaza automáticamente a tu único médico registrado.
-- ------------------------------------------------------------
INSERT INTO consultas (id_turno, id_paciente, id_medico, fecha_consulta, motivo, diagnostico, observaciones)
VALUES (
    @id_turno_hoy_p6,
    @id_p6,
    (SELECT id_medico FROM medicos LIMIT 1),
    NOW(),
    'Dolor de cabeza y fiebre leve desde hace 2 días',
    'Cuadro viral común (resfriado)',
    'Se recomienda reposo e hidratación. Reevaluar si persiste más de 5 días.'
);
SET @id_consulta1 = LAST_INSERT_ID();

-- Medicamentos de catálogo (los vas a necesitar para el formulario de receta)
INSERT INTO medicamentos (nombre_medicamento, presentacion) VALUES ('Acetaminofén', 'Tabletas 500mg');
SET @id_med1 = LAST_INSERT_ID();

INSERT INTO medicamentos (nombre_medicamento, presentacion) VALUES ('Ibuprofeno', 'Tabletas 400mg');
SET @id_med2 = LAST_INSERT_ID();

INSERT INTO medicamentos (nombre_medicamento, presentacion) VALUES ('Amoxicilina', 'Cápsulas 500mg');
INSERT INTO medicamentos (nombre_medicamento, presentacion) VALUES ('Loratadina', 'Tabletas 10mg');
INSERT INTO medicamentos (nombre_medicamento, presentacion) VALUES ('Omeprazol', 'Cápsulas 20mg');
INSERT INTO medicamentos (nombre_medicamento, presentacion) VALUES ('Suero oral', 'Sobres');

-- Receta para la consulta de ejemplo
INSERT INTO recetas (id_consulta, fecha_emision) VALUES (@id_consulta1, NOW());
SET @id_receta1 = LAST_INSERT_ID();

INSERT INTO receta_detalle (id_receta, id_medicamento, dosis, frecuencia, duracion)
VALUES (@id_receta1, @id_med1, '1 tableta', 'Cada 8 horas', '3 días');

INSERT INTO receta_detalle (id_receta, id_medicamento, dosis, frecuencia, duracion)
VALUES (@id_receta1, @id_med2, '1 tableta', 'Cada 12 horas', '3 días');

-- ------------------------------------------------------------
-- 4) CONSULTA DE AYER (para probar el filtro "Todas" en Mis consultas)
-- ------------------------------------------------------------
INSERT INTO turnos (id_paciente, id_cita, numero_turno, tipo, estado, fecha)
VALUES (@id_p7, NULL, 1, 'con_cita', 'atendido', DATE_SUB(NOW(), INTERVAL 1 DAY));
SET @id_turno_ayer = LAST_INSERT_ID();

INSERT INTO consultas (id_turno, id_paciente, id_medico, fecha_consulta, motivo, diagnostico, observaciones)
VALUES (
    @id_turno_ayer,
    @id_p7,
    (SELECT id_medico FROM medicos LIMIT 1),
    DATE_SUB(NOW(), INTERVAL 1 DAY),
    'Control de presión arterial',
    'Presión arterial dentro de rango normal',
    'Continuar con dieta baja en sodio.'
);

-- ============================================================
-- FIN DEL SCRIPT
-- ============================================================
