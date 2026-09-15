<?php
// Este archivo espera una variable $paginaActiva definida ANTES del include,
// con uno de estos valores: 'cola', 'mis_consultas', 'historial', 'recetas', 'perfil'
if (!isset($paginaActiva)) {
    $paginaActiva = '';
}

function claseActiva(string $paginaActual, string $pagina): string {
    return $paginaActual === $pagina ? 'activo' : '';
}

$diasSemana = ['Sunday' => 'Domingo', 'Monday' => 'Lunes', 'Tuesday' => 'Martes', 'Wednesday' => 'Miércoles', 'Thursday' => 'Jueves', 'Friday' => 'Viernes', 'Saturday' => 'Sábado'];
$meses = ['January' => 'enero', 'February' => 'febrero', 'March' => 'marzo', 'April' => 'abril', 'May' => 'mayo', 'June' => 'junio', 'July' => 'julio', 'August' => 'agosto', 'September' => 'septiembre', 'October' => 'octubre', 'November' => 'noviembre', 'December' => 'diciembre'];
$fechaHoySidebar = sprintf('%s, %d de %s de %s', $diasSemana[date('l')], date('j'), $meses[date('F')], date('Y'));
?>
<aside class="medico-sidebar" id="sidebar">
    <div>
        <a href="medico.php" class="medico-sidebar__logo">
            <div class="medico-sidebar__logo-icono">🏥</div>
            <div>
                <strong>Hospital</strong>
                <span>Sistema de Consultas</span>
            </div>
        </a>

        <p class="medico-sidebar__titulo">MENÚ PRINCIPAL</p>
        <ul class="medico-sidebar__nav">
            <li><a href="medico.php" class="<?= claseActiva($paginaActiva, 'cola') ?>">👥 Cola de pacientes</a></li>
            <li><a href="mis_consultas.php" class="<?= claseActiva($paginaActiva, 'mis_consultas') ?>">📋 Mis consultas</a></li>
            <li><a href="#" class="<?= claseActiva($paginaActiva, 'historial') ?>">🗂️ Historial clínico</a></li>
            <li><a href="#" class="<?= claseActiva($paginaActiva, 'recetas') ?>">📄 Recetas emitidas</a></li>
            <li><a href="#" class="<?= claseActiva($paginaActiva, 'perfil') ?>">👤 Perfil</a></li>
            <li><a href="logout.php">↪️ Cerrar sesión</a></li>
        </ul>
    </div>

    <div class="medico-sidebar__turno">
        <div class="medico-sidebar__turno-icono">🩺</div>
        <p><strong>Turno actual</strong></p>
        <p><?= htmlspecialchars($fechaHoySidebar) ?></p>
        <p>🕒 <?= date('h:i a') ?></p>
    </div>
</aside>
