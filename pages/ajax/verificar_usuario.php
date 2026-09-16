<?php
/**
 * pages/ajax/verificar_usuario.php
 *
 * GET idempotente que recibe un nombre de usuario y devuelve si está
 * disponible (no existe en la tabla `usuarios`). Misma política que
 * `pages/ajax/verificar_paciente.php`:
 *
 *   - verificarSesion() NO se usa (redirige con Location, no aplica
 *     aquí desde pages/ajax/). Se replica la verificación sin
 *     redirigir.
 *   - No requiere CSRF (GET, sin efectos secundarios).
 *   - El `id_usuario` (opcional) sirve para EXCLUIR al propio usuario
 *     de la verificación, para que un usuario pueda mantener su
 *     mismo `usuario` sin que la API le diga "no disponible".
 *
 * Respuestas:
 *   {"disponible": true}
 *   {"disponible": false}
 *   {"disponible": false, "motivo": "longitud"}  (3 ≤ len ≤ 40)
 *   {"error": "..."}  (4xx / 5xx)
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
// consultar_disponibilidad.php / verificar_paciente.php).
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
$usuario = trim($_GET['usuario'] ?? '');
if ($usuario === '') {
    http_response_code(400);
    echo json_encode(['error' => 'usuario vacío']);
    exit;
}

// Mismo tamaño que la columna `usuarios.usuario` (VARCHAR(40)).
if (strlen($usuario) < 3 || strlen($usuario) > 40) {
    echo json_encode(['disponible' => false, 'motivo' => 'longitud']);
    exit;
}

$idUsuarioActualRaw = filter_input(INPUT_GET, 'id_usuario', FILTER_VALIDATE_INT);
$idUsuarioActual    = ($idUsuarioActualRaw !== null && $idUsuarioActualRaw !== false && $idUsuarioActualRaw > 0)
    ? $idUsuarioActualRaw
    : null;

// 2) Lookup local en tabla `usuarios` (columna usuario es UNIQUE → 0 o 1 fila)
try {
    if ($idUsuarioActual !== null) {
        $stmt = $conexion->prepare(
            'SELECT COUNT(*) FROM usuarios
             WHERE usuario = :usuario AND id_usuario != :id_actual
             LIMIT 1'
        );
        $stmt->execute([
            ':usuario'   => $usuario,
            ':id_actual' => $idUsuarioActual,
        ]);
    } else {
        $stmt = $conexion->prepare(
            'SELECT COUNT(*) FROM usuarios WHERE usuario = :usuario LIMIT 1'
        );
        $stmt->execute([':usuario' => $usuario]);
    }
    $existe = (int) $stmt->fetchColumn() > 0;
} catch (PDOException $e) {
    error_log('Error al verificar usuario: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'No se pudo verificar el usuario']);
    exit;
}

echo json_encode(['disponible' => !$existe]);
