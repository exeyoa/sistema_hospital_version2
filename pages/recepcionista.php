<?php
/**
 * pages/recepcionista.php
 *
 * Página legacy del panel de recepcionista. El panel se divide en
 * 2 subpáginas (recepcionista_pacientes.php, _citas.php) que se
 * navegan desde el sidebar. Este archivo solo redirige a la
 * subpágina por defecto para mantener compatibilidad con marcadores,
 * enlaces antiguos, etc.
 */
require_once __DIR__ . '/../config/sesion.php';

// verificarSesion() redirige y termina con exit si la sesión no es
// válida. Si retorna (es decir, sesión OK), seguimos al redirect.
verificarSesion(['recepcionista']);

header('Location: recepcionista_pacientes.php');
exit;
