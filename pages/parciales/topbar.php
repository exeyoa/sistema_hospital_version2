<?php
// Este archivo espera que la página que lo incluye ya haya hecho:
// session_start(); y validado que $_SESSION['rol'] === 'medico';

$nombreMedico = $_SESSION['nombre'];
$partesNombre = explode(' ', trim($nombreMedico));
$inicialesTopbar = strtoupper(mb_substr($partesNombre[0], 0, 1) . (isset($partesNombre[1]) ? mb_substr($partesNombre[1], 0, 1) : ''));
?>
<header class="medico-topbar">
    <button type="button" class="medico-topbar__menu-btn" id="btnMenu" aria-label="Abrir menú">☰</button>

    <div></div>

    <div class="medico-topbar__user">
        <span class="medico-topbar__campana">
            🔔
            <span class="badge-notif">3</span>
        </span>

        <div class="medico-topbar__nombre">
            <strong><?= htmlspecialchars($nombreMedico) ?></strong>
            <span>Médico</span>
        </div>

        <div class="medico-topbar__avatar">
            <div class="avatar-inicial"><?= htmlspecialchars($inicialesTopbar) ?></div>
            <span class="estado-online"></span>
        </div>
    </div>
</header>
