<?php
require_once __DIR__ . '/../config/sesion.php';
cerrarSesionCompleta();
header('Location: login.php');
exit;
