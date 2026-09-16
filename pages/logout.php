<?php
require_once __DIR__ . '/../config/sesion.php';
iniciarSesionSegura();

// Redirige al login que corresponde según el rol de la sesión
$redirect = (($_SESSION['rol'] ?? '') === 'paciente')
    ? 'login_paciente.php'
    : 'login.php';
cerrarSesionCompleta();
header('Location: ' . $redirect);
exit;
