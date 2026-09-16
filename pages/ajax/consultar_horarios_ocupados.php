<?php
/**
 * pages/ajax/consultar_horarios_ocupados.php
 *
 * Devuelve las horas ya ocupadas (citas en estado pendiente o confirmada)
 * para un médico y una fecha concretos. Es un endpoint GET idempotente,
 * sin efectos secundarios: solo refleja lo que ya está en la BD.
 *
 * NO usa verificarSesion() porque esa función redirige con
 * header('Location: login.php') cuando no hay sesión, lo cual desde
 * este subdirectorio se resuelve como pages/ajax/login.php (404).
 * config/sesion.php está marcado como archivo sensible por el equipo
 * (03_INSTRUCCIONES_PARA_IA.md), así que replicamos aquí SOLO la
 * parte necesaria de la verificación, sin redirigir nunca: si la
 * sesión no es válida, devolvemos 401 JSON.
 *
 * NO requiere token CSRF porque:
 *   - Es GET, sin INSERT/UPDATE/DELETE.
 *   - Same-Origin Policy impide a un sitio atacante leer la respuesta.
 *   - La única protección es la sesión: sin sesión de recepcionista
 *     no se devuelve nada (HTTP 401).
 *
 * La validación de duplicados real al agendar la cita (en
 * recepcionista.php) sigue siendo la autoridad final. Este endpoint
 * es solo una capa de UX para no hacerle perder tiempo a la
 * recepcionista eligiendo horarios ya ocupados.
 *
 * Respuesta (éxito): {"ocupadas": ["07:00", "08:30", ...]}
 * Respuesta (error): {"error": "<mensaje>"} con HTTP 4xx apropiado.
 */

require_once __DIR__ . '/../../config/sesion.php';

header('Content-Type: application/json; charset=utf-8');

if (!function_exists('iniciarSesionSegura')) {
    http_response_code(500);
    echo json_encode(['error' => 'configuración de sesión no disponible']);
    exit;
}

iniciarSesionSegura();

// 0) Autenticación y control de acceso (mismo criterio que
//    verificarSesion(['recepcionista']) pero devolviendo JSON 401 en
//    vez de redirigir, porque los endpoints AJAX no deben redirigir).
$autenticado = isset($_SESSION['id_usuario']);
$rolOk       = $autenticado
    && isset($_SESSION['rol'])
    && in_array($_SESSION['rol'], ['recepcionista'], true);

// Cierre por inactividad (RNF-07): mismo umbral que config/sesion.php
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
// Renovar la marca de actividad (como hace verificarSesion())
$_SESSION['ultima_actividad'] = time();

require_once __DIR__ . '/../../config/conexion.php';

// 1) Validación de parámetros
$idMedico = filter_input(INPUT_GET, 'id_medico', FILTER_VALIDATE_INT);
$fechaRaw = trim($_GET['fecha'] ?? '');

if (!$idMedico || $idMedico <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'id_medico inválido']);
    exit;
}

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

// 2) Verificar que el médico existe y su cuenta de usuario está activa.
//    Evita enumerar IDs y filtra médicos no vigentes.
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

// 3) Consulta: horas ocupadas (citas vivas del médico en esa fecha).
//    Las canceladas NO cuentan, igual que en la validación de duplicados.
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
    error_log('Error al consultar horarios ocupados: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'No se pudo consultar la disponibilidad']);
    exit;
}

echo json_encode(['ocupadas' => $ocupadas]);
