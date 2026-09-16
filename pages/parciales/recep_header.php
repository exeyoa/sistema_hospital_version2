<?php
/**
 * pages/parciales/recep_header.php
 *
 * Cabecera común a las 3 páginas del panel de recepcionista.
 * Espera que quien lo incluye haya decidido qué archivo es (no
 * hace de router). Después de incluir:
 *   - $csrfToken está disponible
 *   - $conexion está disponible (PDO)
 *   - $seccionActiva está disponible ('pacientes', 'citas')
 *   - Las funciones compartidas de recep_funciones.php están cargadas
 *
 * Tras la inclusión se emite el HTML desde <!DOCTYPE> hasta el
 * final del `<aside>` del sidebar y la apertura del `<main>`.
 */
require_once __DIR__ . '/../../config/sesion.php';
verificarSesion(['recepcionista']);

$csrfToken = generarTokenCSRF();

require_once __DIR__ . '/../../config/conexion.php';
require_once __DIR__ . '/recep_funciones.php';

if (!isset($seccionActiva)) {
    $seccionActiva = seccionActivaDesdeURL();
}

// Datos del usuario actual para el avatar del topbar (iniciales + foto si ya subió)
// Se hace con try/catch para no romper el panel si la migración de foto_perfil
// aún no se aplicó en la base de datos local.
$inicialesRecep = 'U';
$fotoPerfilRecep = null;
try {
    $stmtUsuario = $conexion->prepare(
        'SELECT nombre, apellido, foto_perfil FROM usuarios WHERE id_usuario = :id LIMIT 1'
    );
    $stmtUsuario->execute([':id' => $_SESSION['id_usuario']]);
    $datosUsuario = $stmtUsuario->fetch(PDO::FETCH_ASSOC);
    if ($datosUsuario !== false) {
        $partesNombre = explode(' ', trim(($datosUsuario['nombre'] ?? '') . ' ' . ($datosUsuario['apellido'] ?? '')));
        $inicialesRecep = mb_strtoupper(
            mb_substr($partesNombre[0] ?? '', 0, 1)
            . (isset($partesNombre[1]) && $partesNombre[1] !== '' ? mb_substr($partesNombre[1], 0, 1) : '')
        );
        if ($inicialesRecep === '') { $inicialesRecep = 'U'; }
        $fotoPerfilRecep = $datosUsuario['foto_perfil'] ?? null;
    }
} catch (PDOException $e) {
    error_log('No se pudieron obtener datos del usuario para el topbar: ' . $e->getMessage());
}

$diasSemana = ['Sunday' => 'Domingo', 'Monday' => 'Lunes', 'Tuesday' => 'Martes', 'Wednesday' => 'Miércoles', 'Thursday' => 'Jueves', 'Friday' => 'Viernes', 'Saturday' => 'Sábado'];
$meses      = ['January' => 'enero', 'February' => 'febrero', 'March' => 'marzo', 'April' => 'abril', 'May' => 'mayo', 'June' => 'junio', 'July' => 'julio', 'August' => 'agosto', 'September' => 'septiembre', 'October' => 'octubre', 'November' => 'noviembre', 'December' => 'diciembre'];
$fechaHoyHeader = sprintf('%s, %d de %s de %s',
    $diasSemana[date('l')],
    date('j'),
    $meses[date('F')],
    date('Y')
);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Panel recepcionista - Hospital Raúl Dávila Mena</title>
    <link rel="stylesheet" href="../css/estilo.css">
    <link rel="stylesheet" href="../css/recepcionista.css">
    <?php if (!empty($extraHead)) { echo $extraHead; } ?>
</head>
<body>
    <div class="barra-superior">
        <button type="button" class="recepcionista-topbar__menu-btn"
                aria-controls="sidebar" aria-expanded="false"
                aria-label="Abrir menú">
            ☰
        </button>
        <span class="marca">Hospital Raúl Dávila Mena</span>

        <div class="recepcionista-topbar__usuario">
            <a href="recepcionista_perfil.php" class="recepcionista-topbar__perfil-btn" aria-label="Ver y editar mi perfil">
                <?php if ($fotoPerfilRecep !== null && $fotoPerfilRecep !== ''): ?>
                    <img src="../imagenes/perfiles/<?= htmlspecialchars($fotoPerfilRecep, ENT_QUOTES, 'UTF-8') ?>"
                         alt="" class="recepcionista-topbar__avatar-img">
                <?php else: ?>
                    <span class="recepcionista-topbar__avatar" aria-hidden="true"><?= htmlspecialchars($inicialesRecep, ENT_QUOTES, 'UTF-8') ?></span>
                <?php endif; ?>
                <span class="recepcionista-topbar__nombre">
                    <?= htmlspecialchars($_SESSION['nombre'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                    <small>Recepcionista · <?= htmlspecialchars($fechaHoyHeader, ENT_QUOTES, 'UTF-8') ?></small>
                </span>
            </a>
        </div>
    </div>

    <?php include __DIR__ . '/sidebar_recepcionista.php'; ?>

    <div class="recepcionista-main contenido-panel contenido-panel--ancho">
