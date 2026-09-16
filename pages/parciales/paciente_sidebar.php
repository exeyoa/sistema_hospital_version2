<?php
// Sidebar del paciente. La navegación entre secciones vive aquí.
// Espera $seccionActiva con uno de: 'dashboard', 'citas', 'consultas',
// 'recetas', 'perfil'
if (!isset($seccionActiva)) {
    $seccionActiva = '';
}

function claseActivaPac(string $seccionActual, string $seccion): string {
    return $seccionActual === $seccion ? 'activo' : '';
}
?>
<aside class="paciente-sidebar" id="sidebar-paciente">
    <div>
        <h2 class="paciente-sidebar__encabezado">Mi panel</h2>

        <p class="paciente-sidebar__titulo">MENÚ PRINCIPAL</p>
        <ul class="paciente-sidebar__nav">
            <li><a href="paciente.php" class="<?= claseActivaPac($seccionActiva, 'dashboard') ?>">🏠 Inicio</a></li>
            <li><a href="paciente_citas.php" class="<?= claseActivaPac($seccionActiva, 'citas') ?>">📅 Mis citas</a></li>
            <li><a href="paciente_consultas.php" class="<?= claseActivaPac($seccionActiva, 'consultas') ?>">🩺 Mis consultas</a></li>
            <li><a href="paciente_recetas.php" class="<?= claseActivaPac($seccionActiva, 'recetas') ?>">💊 Mis recetas</a></li>
            <li><a href="paciente_perfil.php" class="<?= claseActivaPac($seccionActiva, 'perfil') ?>">👤 Mi perfil</a></li>
            <li><a href="logout.php">↪️ Cerrar sesión</a></li>
        </ul>
    </div>
</aside>
