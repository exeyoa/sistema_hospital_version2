<?php
/**
 * pages/parciales/paciente_header.php
 *
 * Cabecera común a las páginas del panel del paciente.
 * Espera que la página que lo incluye ya haya decidido qué archivo es
 * (no hace de router). Tras la inclusión:
 *   - $csrfToken está disponible
 *   - $seccionActiva está disponible ('dashboard', 'citas', 'consultas',
 *     'recetas', 'perfil')
 *   - Las funciones compartidas de paciente_funciones.php están cargadas
 *
 * El paciente NO usa sesión de usuario del sistema, sino `id_paciente`.
 * Por eso este archivo es independiente del de recepcionista.
 */
require_once __DIR__ . '/../../config/sesion.php';
verificarSesionPaciente();

$csrfToken = generarTokenCSRF();

require_once __DIR__ . '/../../config/conexion.php';
require_once __DIR__ . '/paciente_funciones.php';

if (!isset($seccionActiva)) {
    $seccionActiva = '';
}

// Iniciales para el avatar del topbar
$partesNombre = explode(' ', trim(($_SESSION['nombre'] ?? '') . ' ' . ($_SESSION['apellido'] ?? '')));
$inicialesPaciente = mb_strtoupper(
    mb_substr($partesNombre[0] ?? '', 0, 1)
    . (isset($partesNombre[1]) && $partesNombre[1] !== '' ? mb_substr($partesNombre[1], 0, 1) : '')
);
if ($inicialesPaciente === '') { $inicialesPaciente = 'P'; }

$diasSemana = ['Sunday' => 'Domingo', 'Monday' => 'Lunes', 'Tuesday' => 'Martes', 'Wednesday' => 'Miércoles', 'Thursday' => 'Jueves', 'Friday' => 'Viernes', 'Saturday' => 'Sábado'];
$meses = ['January' => 'enero', 'February' => 'febrero', 'March' => 'marzo', 'April' => 'abril', 'May' => 'mayo', 'June' => 'junio', 'July' => 'julio', 'August' => 'agosto', 'September' => 'septiembre', 'October' => 'octubre', 'November' => 'noviembre', 'December' => 'diciembre'];
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
    <title>Mi panel - Hospital Raúl Dávila Mena</title>
    <link rel="stylesheet" href="../css/estilo.css">
    <link rel="stylesheet" href="../css/paciente.css">
    <?php if (!empty($extraHead)) { echo $extraHead; } ?>
</head>
<body>
    <div class="barra-superior paciente-barra">
        <button type="button" class="paciente-topbar__menu-btn"
                aria-controls="sidebar-paciente" aria-expanded="false"
                aria-label="Abrir menú">
            ☰
        </button>
        <span class="marca">Mi panel</span>

        <div class="paciente-topbar__usuario">
            <a href="paciente_perfil.php" class="paciente-topbar__perfil-btn" aria-label="Ver y editar mi perfil">
                <span class="paciente-topbar__avatar" aria-hidden="true"><?= htmlspecialchars($inicialesPaciente, ENT_QUOTES, 'UTF-8') ?></span>
                <span class="paciente-topbar__nombre">
                    <?= htmlspecialchars($_SESSION['nombre'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                    <small>Paciente · <?= htmlspecialchars($fechaHoyHeader, ENT_QUOTES, 'UTF-8') ?></small>
                </span>
            </a>
        </div>
    </div>

    <?php include __DIR__ . '/paciente_sidebar.php'; ?>

    <div class="paciente-main contenido-panel contenido-panel--ancho">
