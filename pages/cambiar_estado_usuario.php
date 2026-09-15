6<?php
session_start();

if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] !== 'admin') {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../config/conexion.php';

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
// A qué página regresar después: admin.php (usuarios) o admin_medicos.php
$volver = ($_GET['volver'] ?? '') === 'medicos' ? 'admin_medicos.php' : 'admin.php';

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
