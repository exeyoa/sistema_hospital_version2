<?php
/**
 * pages/parciales/recep_funciones.php
 *
 * Funciones PHP compartidas entre las páginas del panel de
 * recepcionista (recepcionista_pacientes.php, _citas.php).
 * Este archivo NO emite HTML; solo define funciones. Cada página
 * lo incluye con require_once ANTES de usarlas.
 *
 * Espera que ya estén cargados:
 *   - config/sesion.php (para CSRF, flashYRedirigir)
 *   - config/conexion.php (para $conexion PDO)
 */

if (!function_exists('buscarPacientes')) {
    /**
     * Busca pacientes por cédula EXACTA o por nombre/apellido PARCIAL.
     * DECISIÓN CONSCIENTE: LIKE '%...%' sin índice (escala actual
     * del hospital lo permite; ver nota en recepcionista.php).
     */
    function buscarPacientes(PDO $conexion, string $texto): array
    {
        $stmt = $conexion->prepare(
            'SELECT id_paciente, nombre, apellido, cedula FROM pacientes WHERE cedula = :texto LIMIT 1'
        );
        $stmt->execute([':texto' => $texto]);
        $exacto = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($exacto) {
            return [$exacto];
        }
        $stmt = $conexion->prepare(
            'SELECT id_paciente, nombre, apellido, cedula FROM pacientes
             WHERE nombre LIKE :parcial OR apellido LIKE :parcial
             ORDER BY apellido, nombre
             LIMIT 20'
        );
        $stmt->execute([':parcial' => '%' . $texto . '%']);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('obtenerPaciente')) {
    function obtenerPaciente(PDO $conexion, int $idPaciente): ?array
    {
        $stmt = $conexion->prepare(
            'SELECT id_paciente, nombre, apellido, cedula FROM pacientes WHERE id_paciente = :id LIMIT 1'
        );
        $stmt->execute([':id' => $idPaciente]);
        $paciente = $stmt->fetch(PDO::FETCH_ASSOC);
        return $paciente === false ? null : $paciente;
    }
}

if (!function_exists('listarMedicosActivos')) {
    function listarMedicosActivos(PDO $conexion): array
    {
        return $conexion->query(
            'SELECT m.id_medico, u.nombre, u.apellido, e.nombre_especialidad
             FROM medicos m
             INNER JOIN usuarios u ON u.id_usuario = m.id_usuario
             INNER JOIN especialidades e ON e.id_especialidad = m.id_especialidad
             WHERE u.activo = 1
             ORDER BY u.apellido, u.nombre'
        )->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('listarCitasPendientes')) {
    function listarCitasPendientes(PDO $conexion): array
    {
        return $conexion->query(
            "SELECT c.id_cita, c.fecha_cita, c.hora_cita, c.estado,
                    p.nombre AS paciente_nombre, p.apellido AS paciente_apellido, p.cedula,
                    u.nombre AS medico_nombre, u.apellido AS medico_apellido,
                    e.nombre_especialidad
             FROM citas c
             INNER JOIN pacientes p ON p.id_paciente = c.id_paciente
             INNER JOIN medicos m ON m.id_medico = c.id_medico
             INNER JOIN usuarios u ON u.id_usuario = m.id_usuario
             INNER JOIN especialidades e ON e.id_especialidad = m.id_especialidad
             WHERE c.fecha_cita >= CURDATE() AND c.estado IN ('pendiente', 'confirmada')
             ORDER BY c.fecha_cita, c.hora_cita"
        )->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('generarTurno')) {
    /**
     * Stub histórico. El concepto de turno/cola fue retirado del
     * panel de recepcionista; esta función se conserva vacía para
     * no romper includes antiguos y para referencia futura.
     * La lógica real ya no se ejecuta en ninguna página.
     */
    function generarTurno(PDO $conexion, int $idPaciente, ?int $idCita, string $tipo, ?string &$mensajeError): bool
    {
        $mensajeError = 'Generación de turnos deshabilitada.';
        return false;
    }
}

if (!function_exists('generarHorasDisponibles')) {
    function generarHorasDisponibles(): array
    {
        $horas  = [];
        $inicio = strtotime(HORA_INICIO_CITAS);
        $fin    = strtotime(HORA_FIN_CITAS);
        for ($t = $inicio; $t <= $fin; $t += INTERVALO_MINUTOS * 60) {
            $horas[] = date('H:i', $t);
        }
        return $horas;
    }
}

if (!function_exists('flashYRedirigir')) {
    function flashYRedirigir(string $seccion, array $errores, string $exito, array $contextoGet = []): void
    {
        $_SESSION['flash_recepcion'] = [
            'seccion' => $seccion,
            'errores' => $errores,
            'exito'   => $exito,
        ];
        $destino = 'recepcionista_' . $seccion . '.php';
        if ($contextoGet !== []) {
            $destino .= '?' . http_build_query($contextoGet);
        }
        header('Location: ' . $destino);
        exit;
    }
}

if (!function_exists('seccionActivaDesdeURL')) {
    /**
     * Detecta la sección activa según el nombre del archivo PHP en
     * ejecución. Usado por el sidebar para marcar el ítem activo.
     */
    function seccionActivaDesdeURL(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        if (strpos($uri, 'recepcionista_citas') !== false) {
            return 'citas';
        } elseif (strpos($uri, 'recepcionista_pacientes') !== false) {
            return 'pacientes';
        }
        return '';
    }
}
