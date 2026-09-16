SET NAMES utf8mb4;

UPDATE pacientes SET
  nombre = 'María',
  apellido = 'González'
WHERE id_paciente = 1;
UPDATE pacientes SET nombre = 'José' WHERE id_paciente = 2;
UPDATE pacientes SET apellido = 'Rodríguez' WHERE id_paciente = 3;
UPDATE pacientes SET apellido = 'Martínez' WHERE id_paciente = 4;
UPDATE pacientes SET apellido = 'López' WHERE id_paciente = 5;
UPDATE pacientes SET apellido = 'Sánchez' WHERE id_paciente = 6;
UPDATE pacientes SET direccion = 'Calle 1, Panamá' WHERE id_paciente = 1;
UPDATE pacientes SET direccion = 'Calle 2, Panamá' WHERE id_paciente = 2;
UPDATE pacientes SET direccion = 'Calle 3, Panamá' WHERE id_paciente = 3;
UPDATE pacientes SET direccion = 'Calle 4, Panamá' WHERE id_paciente = 4;
UPDATE pacientes SET direccion = 'Calle 5, Panamá' WHERE id_paciente = 5;
UPDATE pacientes SET direccion = 'Calle 6, Panamá' WHERE id_paciente = 6;
UPDATE pacientes SET direccion = 'Calle 7, Panamá' WHERE id_paciente = 7;

UPDATE consultas SET
  motivo = 'Dolor de cabeza y fiebre leve desde hace 2 días',
  diagnostico = 'Cuadro viral común (resfriado)',
  observaciones = 'Se recomienda reposo e hidratación. Reevaluar si persiste más de 5 días.'
WHERE id_consulta = 1;
UPDATE consultas SET
  motivo = 'Control de presión arterial',
  diagnostico = 'Presión arterial dentro de rango normal'
WHERE id_consulta = 2;

UPDATE medicamentos SET nombre_medicamento = 'Acetaminofén' WHERE id_medicamento = 1;
UPDATE medicamentos SET presentacion = 'Cápsulas 500mg' WHERE id_medicamento = 3;
UPDATE medicamentos SET presentacion = 'Cápsulas 20mg' WHERE id_medicamento = 5;

UPDATE receta_detalle SET duracion = '3 días' WHERE id_detalle IN (1, 2);