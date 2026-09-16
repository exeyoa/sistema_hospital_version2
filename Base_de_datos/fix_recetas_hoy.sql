SET NAMES utf8mb4;

-- ============================================================
-- RECETA DE PRUEBA PARA HOY — Vista admin "Recetas Médicas"
-- Una receta emitida hoy (fecha_emision = NOW()) ligada a la
-- consulta de hoy (id_consulta = 3, Ricardo Sánchez) más 2
-- medicamentos de ejemplo reutilizando el catálogo existente.
-- ============================================================

INSERT INTO recetas (id_consulta, fecha_emision)
VALUES (3, NOW());

SET @id_receta_hoy = LAST_INSERT_ID();

-- Antibiótico + analgésico (ejemplos para la consulta de lumbalgia)
INSERT INTO receta_detalle (id_receta, id_medicamento, dosis, frecuencia, duracion)
SELECT @id_receta_hoy, id_medicamento, '1 cápsula', 'Cada 8 horas', '7 días'
FROM medicamentos
WHERE nombre_medicamento = 'Amoxicilina'
LIMIT 1;

INSERT INTO receta_detalle (id_receta, id_medicamento, dosis, frecuencia, duracion)
SELECT @id_receta_hoy, id_medicamento, '1 tableta', 'Cada 6 horas', '3 días'
FROM medicamentos
WHERE nombre_medicamento = 'Acetaminofén'
LIMIT 1;

-- ============================================================
-- FIN DEL SCRIPT
-- ============================================================