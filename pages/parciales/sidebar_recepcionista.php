<?php
// Sidebar de la recepcionista. La navegación entre secciones vive aquí;
// el barra-superior solo conserva la marca y el cierre de sesión.
//
// Espera una variable $seccionActiva con uno de: 'pacientes', 'citas'
// (marcará el ítem activo).
if (!isset($seccionActiva)) {
    $seccionActiva = '';
}

function claseActivaRecep(string $seccionActual, string $seccion): string {
    return $seccionActual === $seccion ? 'activo' : '';
}

$diasSemana = ['Sunday' => 'Domingo', 'Monday' => 'Lunes', 'Tuesday' => 'Martes', 'Wednesday' => 'Miércoles', 'Thursday' => 'Jueves', 'Friday' => 'Viernes', 'Saturday' => 'Sábado'];
$meses = ['January' => 'enero', 'February' => 'febrero', 'March' => 'marzo', 'April' => 'abril', 'May' => 'mayo', 'June' => 'junio', 'July' => 'julio', 'August' => 'agosto', 'September' => 'septiembre', 'October' => 'octubre', 'November' => 'noviembre', 'December' => 'diciembre'];
$fechaHoySidebar = sprintf('%s, %d de %s de %s', $diasSemana[date('l')], date('j'), $meses[date('F')], date('Y'));

// Detectar la sección activa por la URL (fallback si no se pasa
// $seccionActiva). Las páginas son independientes: pacientes y citas.
if ($seccionActiva === '' && isset($_SERVER['REQUEST_URI'])) {
    $uri = $_SERVER['REQUEST_URI'];
    if (strpos($uri, 'recepcionista_citas') !== false) {
        $seccionActiva = 'citas';
    } elseif (strpos($uri, 'recepcionista_pacientes') !== false) {
        $seccionActiva = 'pacientes';
    }
}
?>
<aside class="recepcionista-sidebar" id="sidebar">
    <div>
        <h2 class="recepcionista-sidebar__encabezado">Recepción</h2>

        <p class="recepcionista-sidebar__titulo">MENÚ PRINCIPAL</p>
        <ul class="recepcionista-sidebar__nav">
            <li><a href="recepcionista_pacientes.php" class="<?= claseActivaRecep($seccionActiva, 'pacientes') ?>">👤 Pacientes</a></li>
            <li><a href="recepcionista_citas.php" class="<?= claseActivaRecep($seccionActiva, 'citas') ?>">📅 Citas</a></li>
            <li><a href="logout.php">↪️ Cerrar sesión</a></li>
        </ul>
    </div>

    <div class="recepcionista-sidebar__fecha">
        <div class="recepcionista-sidebar__fecha-icono">📅</div>
        <p><strong>Fecha de hoy</strong></p>
        <p><?= htmlspecialchars($fechaHoySidebar, ENT_QUOTES, 'UTF-8') ?></p>
    </div>
</aside>
