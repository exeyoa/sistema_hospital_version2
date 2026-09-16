<?php
/**
 * pages/ajax/buscar_cedula.php
 * -----------------------------------------------------------------
 * Primer endpoint AJAX del proyecto. Recibe por POST una cédula,
 * valida CSRF y responde en JSON los datos básicos del paciente
 * (nombre, apellido, correo) para autorrellenar el formulario de
 * crear_usuario.php. Solo accesible para administradores autenticados.
 * -----------------------------------------------------------------
 */

require_once __DIR__ . '/../../config/sesion.php';

// Solo administradores autenticados (misma protección que el panel).
verificarSesion(['admin']);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/conexion.php';

/**
 * Envía una respuesta JSON y detiene la ejecución.
 */
function responderJson(array $datos, int $codigo = 200): void
{
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

// Solo POST (evita búsquedas por GET y navegación directa).
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    responderJson(['error' => 'Método no permitido.'], 405);
}

// Validación CSRF (misma que el resto de POST del proyecto).
if (!validarTokenCSRF($_POST['csrf_token'] ?? null)) {
    responderJson(['error' => 'Solicitud no autorizada.'], 403);
}

$cedula = trim($_POST['cedula'] ?? '');

// Validación server-side del formato de cédula (mismo patrón que editar_paciente.php).
$patronCedula = '/^(?:[A-Z]{1,2}-)?\d{1,4}-\d{1,4}(?:-\d{1,4})?$/';
if ($cedula === '' || !preg_match($patronCedula, $cedula)) {
    responderJson(['encontrado' => false]);
}

try {
    $stmt = $conexion->prepare('SELECT nombre, apellido, correo FROM pacientes WHERE cedula = :cedula');
    $stmt->execute([':cedula' => $cedula]);
    $paciente = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$paciente) {
        responderJson(['encontrado' => false]);
    }

    responderJson([
        'encontrado' => true,
        'nombre'     => $paciente['nombre'],
        'apellido'   => $paciente['apellido'],
        'correo'     => $paciente['correo'],
    ]);
} catch (PDOException $e) {
    // No exponemos el mensaje crudo de la excepción (RNF-08).
    error_log('Error al buscar paciente por cédula: ' . $e->getMessage());
    responderJson(['error' => 'No se pudo completar la búsqueda. Intenta nuevamente.'], 500);
}
