<?php
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/intentos_login.php';
require_once __DIR__ . '/../config/conexion.php';

iniciarSesionSegura();

// --- Validación CSRF (RNF-08) ---
if (!validarTokenCSRF($_POST['csrf_token'] ?? null)) {
    $_SESSION['error_login'] = 'Tu formulario expiró, intenta de nuevo.';
    header('Location: login.php');
    exit;
}

$usuario  = trim($_POST['usuario'] ?? '');
$password = $_POST['password'] ?? '';

if ($usuario === '' || $password === '') {
    $_SESSION['error_login'] = 'Completa usuario y contraseña.';
    header('Location: login.php');
    exit;
}

// Trae el usuario junto con el nombre de su rol
$sql = "SELECT u.id_usuario, u.nombre, u.password_hash, r.nombre_rol
        FROM usuarios u
        JOIN roles r ON u.id_rol = r.id_rol
        WHERE u.usuario = :usuario AND u.activo = 1";

$stmt = $conexion->prepare($sql);
$stmt->bindParam(':usuario', $usuario);
$stmt->execute();
$fila = $stmt->fetch(PDO::FETCH_ASSOC);

// --- Control de fuerza bruta (RNF-08) ---
// Solo se puede contar/bloquear por id_usuario (así está diseñada la
// tabla intentos_login), es decir, cuando el usuario SÍ existe.
// Para un usuario inexistente añadimos un pequeño retraso como
// mitigación básica contra ataques automatizados.
if ($fila) {
    $fallidosRecientes = contarIntentosFallidosRecientes($conexion, (int) $fila['id_usuario']);

    if ($fallidosRecientes >= MAX_INTENTOS_FALLIDOS) {
        $_SESSION['error_login'] = 'Cuenta bloqueada temporalmente por múltiples intentos '
            . 'fallidos. Intenta de nuevo en ' . VENTANA_BLOQUEO_MINUTOS . ' minutos.';
        header('Location: login.php');
        exit;
    }
} else {
    usleep(500000); // 0.5s — dificulta ataques automatizados / enumeración de usuarios
}

if ($fila && password_verify($password, $fila['password_hash'])) {

    // --- Login correcto ---
    registrarIntentoLogin($conexion, (int) $fila['id_usuario'], true);

    // Regenerar el id de sesión: evita session fixation (RNF-08).
    // Debe hacerse ANTES de guardar los nuevos datos en $_SESSION.
    session_regenerate_id(true);

    $_SESSION['id_usuario']       = $fila['id_usuario'];
    $_SESSION['nombre']           = $fila['nombre'];
    $_SESSION['rol']              = $fila['nombre_rol']; // admin | medico | recepcionista
    $_SESSION['ultima_actividad'] = time();
    unset($_SESSION['csrf_token']); // se genera uno nuevo para el próximo formulario

    header('Location: ' . $_SESSION['rol'] . '.php');
    exit;
}

// --- Login incorrecto ---
if ($fila) {
    registrarIntentoLogin($conexion, (int) $fila['id_usuario'], false);
}

$_SESSION['error_login'] = 'Usuario o contraseña incorrectos.';
header('Location: login.php');
exit;
