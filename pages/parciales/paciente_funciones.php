<?php
/**
 * pages/parciales/paciente_funciones.php
 *
 * Funciones PHP compartidas entre las páginas del panel del paciente.
 * Este archivo NO emite HTML; solo define funciones. Cada página
 * lo incluye con require_once ANTES de usarlas.
 *
 * Espera que ya estén cargados:
 *   - config/sesion.php (para verificarSesionPaciente, CSRF, flashYRedirigir)
 *   - config/conexion.php (para $conexion PDO)
 */

if (!function_exists('obtenerPacienteActual')) {
    /**
     * Devuelve los datos del paciente actualmente logueado, o null
     * si la sesión no es válida (esto no debería pasar porque las
     * páginas llaman verificarSesionPaciente() antes de incluir este
     * archivo, pero la verificación por las dudas).
     */
    function obtenerPacienteActual(PDO $conexion): ?array
    {
        if (!isset($_SESSION['id_paciente'])) {
            return null;
        }
        // La tabla `pacientes` no tiene fecha_creacion (solo `usuarios` la tiene).
        $stmt = $conexion->prepare(
            'SELECT id_paciente, nombre, apellido, cedula, fecha_nacimiento, sexo,
                    telefono, direccion, correo
             FROM pacientes
             WHERE id_paciente = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => (int) $_SESSION['id_paciente']]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);
        return $fila === false ? null : $fila;
    }
}

if (!function_exists('listarCitasPaciente')) {
    /**
     * Lista las citas del paciente logueado, ordenadas por fecha
     * descendente. Útil para "Mis citas".
     */
    function listarCitasPaciente(PDO $conexion, int $idPaciente): array
    {
        $stmt = $conexion->prepare(
            'SELECT c.id_cita, c.fecha_cita, c.hora_cita, c.estado,
                    m.id_medico,
                    u.nombre AS medico_nombre, u.apellido AS medico_apellido,
                    e.nombre_especialidad
             FROM citas c
             INNER JOIN medicos m ON m.id_medico = c.id_medico
             INNER JOIN usuarios u ON u.id_usuario = m.id_usuario
             INNER JOIN especialidades e ON e.id_especialidad = m.id_especialidad
             WHERE c.id_paciente = :id
             ORDER BY c.fecha_cita DESC, c.hora_cita DESC'
        );
        $stmt->execute([':id' => $idPaciente]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('listarConsultasPaciente')) {
    /**
     * Lista las consultas del paciente logueado, ordenadas por fecha
     * descendente. Cada fila incluye si tiene receta asociada.
     */
    function listarConsultasPaciente(PDO $conexion, int $idPaciente): array
    {
        $stmt = $conexion->prepare(
            'SELECT con.id_consulta, con.fecha_consulta, con.motivo,
                    con.diagnostico, con.observaciones,
                    u.nombre AS medico_nombre, u.apellido AS medico_apellido,
                    e.nombre_especialidad,
                    r.id_receta,
                    r.fecha_emision
             FROM consultas con
             INNER JOIN medicos m ON m.id_medico = con.id_medico
             INNER JOIN usuarios u ON u.id_usuario = m.id_usuario
             INNER JOIN especialidades e ON e.id_especialidad = m.id_especialidad
             LEFT JOIN recetas r ON r.id_consulta = con.id_consulta
             WHERE con.id_paciente = :id
             ORDER BY con.fecha_consulta DESC'
        );
        $stmt->execute([':id' => $idPaciente]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('obtenerRecetasPaciente')) {
    /**
     * Lista las recetas del paciente con sus medicamentos.
     */
    function obtenerRecetasPaciente(PDO $conexion, int $idPaciente): array
    {
        $stmt = $conexion->prepare(
            'SELECT r.id_receta, r.fecha_emision,
                    con.id_consulta, con.fecha_consulta, con.motivo, con.diagnostico,
                    u.nombre AS medico_nombre, u.apellido AS medico_apellido,
                    e.nombre_especialidad
             FROM recetas r
             INNER JOIN consultas con ON con.id_consulta = r.id_consulta
             INNER JOIN medicos m ON m.id_medico = con.id_medico
             INNER JOIN usuarios u ON u.id_usuario = m.id_usuario
             INNER JOIN especialidades e ON e.id_especialidad = m.id_especialidad
             WHERE con.id_paciente = :id
             ORDER BY r.fecha_emision DESC'
        );
        $stmt->execute([':id' => $idPaciente]);
        $recetas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($recetas) === 0) {
            return [];
        }

        // Traer los medicamentos de todas las recetas en una sola consulta
        $idsRecetas = array_column($recetas, 'id_receta');
        $placeholders = implode(',', array_fill(0, count($idsRecetas), '?'));
        $stmtDet = $conexion->prepare(
            "SELECT rd.id_receta, rd.dosis, rd.frecuencia, rd.duracion,
                    med.nombre_medicamento, med.presentacion
             FROM receta_detalle rd
             INNER JOIN medicamentos med ON med.id_medicamento = rd.id_medicamento
             WHERE rd.id_receta IN ($placeholders)
             ORDER BY med.nombre_medicamento"
        );
        $stmtDet->execute($idsRecetas);
        $detallesPorReceta = [];
        foreach ($stmtDet->fetchAll(PDO::FETCH_ASSOC) as $det) {
            $detallesPorReceta[(int) $det['id_receta']][] = $det;
        }

        foreach ($recetas as &$receta) {
            $receta['medicamentos'] = $detallesPorReceta[(int) $receta['id_receta']] ?? [];
        }

        return $recetas;
    }
}

if (!function_exists('seccionActivaPacienteDesdeURL')) {
    function seccionActivaPacienteDesdeURL(): string
    {
        $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
        return match ($script) {
            'paciente.php'              => 'dashboard',
            'paciente_citas.php'        => 'citas',
            'paciente_consultas.php'    => 'consultas',
            'paciente_recetas.php'      => 'recetas',
            'paciente_perfil.php'       => 'perfil',
            default                     => '',
        };
    }
}
