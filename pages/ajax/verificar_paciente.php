<?php
/**
 * pages/ajax/verificar_paciente.php
 *
 * GET idempotente que recibe una cédula y devuelve si el paciente ya
 * existe en la BD local del hospital. Sin efectos secundarios; misma
 * política de seguridad que consultar_disponibilidad.php:
 *
 *   - verificarSesion() NO se usa (redirige con Location, no aplica
 *     aquí desde pages/ajax/). Se replica la verificación sin
 *     redirigir.
 *   - No requiere CSRF (GET, sin efectos secundarios).
 *   - La cédula que llega por GET es SOLO para consulta local
 *     (SELECT en tabla `pacientes`). NO se hace ninguna llamada
 *     a APIs externas: si el equipo aprueba reintroducir la
 *     integración con el Tribunal Electoral en el futuro, ese
 *     código va aquí con revisión explícita aparte (ver
 *     documento/03_INSTRUCCIONES_PARA_IA.md líneas 79-89).
 *
 * Respuestas:
 *   {"existe": true,  "paciente": { id_paciente, nombre, apellido,
 *                                   cedula, fecha_nacimiento, sexo }}
 *   {"existe": false}
 */

require_once __DIR__ . '/../../config/sesion.php';

header('Content-Type: application/json; charset=utf-8');

if (!function_exists('iniciarSesionSegura')) {
    http_response_code(500);
    echo json_encode(['error' => 'configuración de sesión no disponible']);
    exit;
}

iniciarSesionSegura();

// Replicar verificarSesion sin redirigir (mismo patrón que
// consultar_disponibilidad.php).
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

// 1) Validación de la cédula
$cedula = trim($_GET['cedula'] ?? '');
if ($cedula === '') {
    http_response_code(400);
    echo json_encode(['error' => 'cedula vacía']);
    exit;
}

// 2) Lookup local en tabla `pacientes` (columna cedula es UNIQUE → 0 o 1 fila)
try {
    $stmt = $conexion->prepare(
        'SELECT id_paciente, nombre, apellido, cedula, fecha_nacimiento, sexo
         FROM pacientes
         WHERE cedula = :cedula
         LIMIT 1'
    );
    $stmt->execute([':cedula' => $cedula]);
    $fila = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Error al verificar paciente: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'No se pudo verificar el paciente']);
    exit;
}

if ($fila === false) {
    echo json_encode(['existe' => false]);
    exit;
}

// 3) Devolver datos del paciente (sin información sensible extra)
//    No se filtra nada: nombre/apellido/cedula/fecha_nacimiento/son los
//    que la recepcionista podría ver igualmente al registrar/agendar.
echo json_encode([
    'existe'   => true,
    'paciente' => [
        'id_paciente'      => (int) $fila['id_paciente'],
        'nombre'           => $fila['nombre'],
        'apellido'         => $fila['apellido'],
        'cedula'           => $fila['cedula'],
        'fecha_nacimiento' => $fila['fecha_nacimiento'],
        'sexo'             => $fila['sexo'],
    ],
]);
