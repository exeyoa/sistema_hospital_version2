<?php
// Credenciales de conexión leídas desde config/entorno.php (único lugar
// donde se pegan los valores reales del hosting).
require_once __DIR__ . '/entorno.php';

try {
    $conexion = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASSWORD
    );
    $conexion->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    // No mostramos $e->getMessage() al usuario (RNF-08): podría revelar
    // el nombre de la base de datos, usuario, estructura, etc.
    // El detalle real queda en el log del servidor para quien depure.
    error_log('Error de conexión a la base de datos: ' . $e->getMessage());
    die('No se pudo conectar con el sistema. Intenta más tarde o contacta al administrador.');
}
?>