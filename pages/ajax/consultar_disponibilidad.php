<?php
/**
 * pages/ajax/consultar_disponibilidad.php
 *
 * Endpoint de SOLO LECTURA que responde dos piezas de información
 * complementarias para el formulario de agendar cita:
 *
 *   1. ocupadas           — horas del día que ya tienen cita viva
 *                            (pendiente/confirmada) para ese médico.
 *                            Se omite si no se pasa fecha.
 *
 *   2. conflicto_paciente — próxima cita activa (pendiente/confirmada,
 *                            fecha >= hoy) que tenga el mismo paciente
 *                            con el mismo médico. Es el aviso
 *                            proactivo que el frontend muestra apenas
 *                            la recepcionista elige médico, sin
 *                            esperar a que elija fecha.
 *
 * GET idempotente, sin efectos secundarios. NO usa
 * verificarSesion() porque esa función redirige con
 * header('Location: login.php') y desde este subdirectorio se
 * resuelve como pages/ajax/login.php (404). Replica la verificación
 * aquí mismo sin redirigir: si la sesión no es válida, devuelve
 * 401 JSON. config/sesion.php está marcado como archivo sensible.
 *
 * NO requiere token CSRF (GET sin efectos secundarios; SOP impide
 * leer la respuesta cross-origin; sesión es la única protección).
 *
 * Matriz de respuestas:
 *   id_medico solo                              → 200 {ocupadas:[], conflicto_paciente:null}
 *   id_medico + fecha                           → 200 {ocupadas:[...], conflicto_paciente:null}
 *   id_medico + id_paciente (sin fecha)         → 200 {ocupadas:[], conflicto_paciente:{...}|null}
 *   id_medico + fecha + id_paciente             → 200 {ocupadas:[...], conflicto_paciente:{...}|null}
 *   id_paciente sin id_medico                   → 400
 *   id_medico inválido / inactivo / inexistente → 400 / 404
 *   fecha presente pero malformada              → 400
 *   id_paciente presente pero inválido          → 200 (silencioso, conflicto_paciente:null)
 *   sin sesión                                  → 401
 */

require_once __DIR__ . '/../../config/sesion.php';

header('Content-Type: application/json; charset=utf-8');

if (!function_exists('iniciarSesionSegura')) {
    http_response_code(500);
    echo json_encode(['error' => 'configuración de sesión no disponible']);
    exit;
}

iniciarSesionSegura();

// 0) Autenticación y control de acceso (replica verificarSesion sin
//    redirigir — ver justificación en la cabecera).
$autenticado = isset($_SESSION['id_usuario']);
$rolOk       = $autenticado
    && isset($_SESSION['rol'])
    && in_array($_SESSION['rol'], ['recepcionista'], true);

$sesionExpirada = $autenticado
    && isset($_SESSION['ultima_actividad'])
    && (time() - $_SESSION['ultima_actividad']) > TIEMPO_INACTIVIDAD_SEGUNDOS;

if ($sesionExpirada) {
    cerrarSesionCompleta();
    http_response_code(401);
    echo json_encode(['error' => 'Sesión expirada']);
    exit;
}
if (!$autenticado || !$rolOk) {
    http_response_code(401);
    echo json_encode(['error' => 'No autorizado']);
    exit;
}
$_SESSION['ultima_actividad'] = time();

require_once __DIR__ . '/../../config/conexion.php';

// 1) Validación de parámetros

// 1a) id_medico — obligatorio. Sin él no hay nada útil que responder.
$idMedico = filter_input(INPUT_GET, 'id_medico', FILTER_VALIDATE_INT);
if (!$idMedico || $idMedico <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'id_medico inválido']);
    exit;
}

// 1b) id_paciente — OPCIONAL. Si está presente y es válido (entero > 0),
//     se calcula conflicto_paciente. Si está ausente o inválido, el
//     campo se devuelve como null sin error (precheck del médico).
$idPacienteRaw = filter_input(INPUT_GET, 'id_paciente', FILTER_VALIDATE_INT);
$idPaciente    = ($idPacienteRaw !== null && $idPacienteRaw !== false && $idPacienteRaw > 0)
    ? $idPacienteRaw
    : null;

// 1c) fecha — OPCIONAL. Si está presente y malformada → 400. Si está
//     ausente o vacía → no se calcula ocupadas (sigue siendo 200).
$fechaRaw = trim($_GET['fecha'] ?? '');
$fechaNorm = null;
if ($fechaRaw !== '') {
    $dt = DateTime::createFromFormat('Y-m-d', $fechaRaw);
    $erroresFecha = DateTime::getLastErrors();
    $fechaValida =
        $dt !== false
        && ($erroresFecha === false || ($erroresFecha['warning_count'] + $erroresFecha['error_count']) === 0);
    if (!$fechaValida) {
        http_response_code(400);
        echo json_encode(['error' => 'fecha inválida']);
        exit;
    }
    $fechaNorm = $dt->format('Y-m-d');
}

// 2) Verificar que el médico existe y su cuenta de usuario está activa.
//    Evita enumerar IDs y filtra médicos no vigentes.
try {
    $stmt = $conexion->prepare(
        'SELECT COUNT(*) FROM medicos m
         INNER JOIN usuarios u ON u.id_usuario = m.id_usuario
         WHERE m.id_medico = :id AND u.activo = 1'
    );
    $stmt->execute([':id' => $idMedico]);
    if ((int) $stmt->fetchColumn() === 0) {
        http_response_code(404);
        echo json_encode(['error' => 'médico no encontrado o inactivo']);
        exit;
    }
} catch (PDOException $e) {
    error_log('Error al verificar médico: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'No se pudo verificar el médico']);
    exit;
}

// 3) ocupadas — solo si se pasó una fecha válida.
$ocupadas = [];
if ($fechaNorm !== null) {
    try {
        $stmt = $conexion->prepare(
            "SELECT hora_cita FROM citas
             WHERE id_medico = :id_medico
               AND fecha_cita = :fecha
               AND estado IN ('pendiente', 'confirmada')"
        );
        $stmt->execute([
            ':id_medico' => $idMedico,
            ':fecha'     => $fechaNorm,
        ]);
        $ocupadas = array_map(
            static fn(array $r): string => substr($r['hora_cita'], 0, 5),
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    } catch (PDOException $e) {
        error_log('Error al consultar horas ocupadas: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'No se pudo consultar la disponibilidad']);
        exit;
    }
}

// 4) conflicto_paciente — solo si se pasó id_paciente válido.
//    Busca la cita activa más próxima (fecha >= hoy) con ese médico.
//    No depende del parámetro fecha del request.
$conflictoPaciente = null;
if ($idPaciente !== null) {
    try {
        $stmt = $conexion->prepare(
            "SELECT fecha_cita, hora_cita
             FROM citas
             WHERE id_paciente = :id_paciente
               AND id_medico   = :id_medico
               AND fecha_cita >= CURDATE()
               AND estado IN ('pendiente', 'confirmada')
             ORDER BY fecha_cita ASC, hora_cita ASC
             LIMIT 1"
        );
        $stmt->execute([
            ':id_paciente' => $idPaciente,
            ':id_medico'   => $idMedico,
        ]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($fila !== false) {
            $conflictoPaciente = [
                'fecha' => $fila['fecha_cita'],     // YYYY-MM-DD desde MySQL DATE
                'hora'  => substr($fila['hora_cita'], 0, 5), // HH:MM
            ];
        }
    } catch (PDOException $e) {
        error_log('Error al consultar conflicto paciente+médico: ' . $e->getMessage());
        // Para conflicto_paciente no abortamos: si falla, lo dejamos
        // en null y el frontend no muestra aviso (degradación suave).
        $conflictoPaciente = null;
    }
}

echo json_encode([
    'ocupadas'           => $ocupadas,
    'conflicto_paciente' => $conflictoPaciente,
]);
