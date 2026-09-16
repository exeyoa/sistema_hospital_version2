<?php
require_once __DIR__ . '/../config/sesion.php';

// Cambio de estado (activo/inactivo). Se acepta SOLO por POST con token
// CSRF (RNF-08): nunca por GET, para no desactivar cuentas con un enlace.
verificarSesion(['admin']);

require_once __DIR__ . '/../config/conexion.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: admin.php');
    exit;
}

if (!validarTokenCSRF($_POST['csrf_token'] ?? null)) {
    header('Location: admin.php');
    exit;
}

$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
// A qué página regresar después: admin.php (usuarios) o admin_medicos.php
$volver = ($_POST['volver'] ?? '') === 'medicos' ? 'admin_medicos.php' : 'admin.php';

if ($id > 0) {

    // No dejamos que el admin se desactive a sí mismo por accidente
    if ($id === (int) $_SESSION['id_usuario']) {
        header("Location: $volver?error=noPuedesDesactivarte");
        exit;
    }

    // Traemos el estado actual para invertirlo
    $stmt = $conexion->prepare("SELECT activo FROM usuarios WHERE id_usuario = :id");
    $stmt->execute([':id' => $id]);
    $estadoActual = $stmt->fetchColumn();

    if ($estadoActual !== false) {
        $nuevoEstado = $estadoActual ? 0 : 1;

        $stmt = $conexion->prepare("UPDATE usuarios SET activo = :activo WHERE id_usuario = :id");
        $stmt->execute([':activo' => $nuevoEstado, ':id' => $id]);
    }
}

header("Location: $volver?estadoActualizado=1");
exit;