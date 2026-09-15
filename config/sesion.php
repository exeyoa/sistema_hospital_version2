<?php
/**
 * config/sesion.php
 * -----------------------------------------------------------------
 * Configuración centralizada de sesión y seguridad para todo el
 * sistema. Reemplaza los "session_start()" sueltos que había en
 * cada página, para no repetir la misma lógica once por módulo.
 *
 * CÓMO USARLO:
 *
 *   Páginas públicas (login, procesar_login, logout, recuperar):
 *       require_once __DIR__ . '/../config/sesion.php';
 *       iniciarSesionSegura();
 *
 *   Páginas protegidas (admin.php, medico.php, recepcionista.php...):
 *       require_once __DIR__ . '/../config/sesion.php';
 *       verificarSesion(['admin']); // el o los roles permitidos aquí
 * -----------------------------------------------------------------
 */

// No mostrar errores de PHP al usuario final (buena práctica de
// producción + RNF-08). Los errores quedan en el log del servidor,
// nunca en pantalla, para no filtrar rutas, consultas SQL, etc.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// Tiempo máximo de inactividad permitido, en segundos (RNF-07).
const TIEMPO_INACTIVIDAD_SEGUNDOS = 5 * 60; // 5 minutos

/**
 * Arranca la sesión con cookies configuradas de forma segura.
 * Es seguro llamarla varias veces por request (no la reinicia si
 * ya estaba activa).
 */
function iniciarSesionSegura(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $esHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

    session_set_cookie_params([
        'lifetime' => 0,        // la cookie muere al cerrar el navegador
        'path'     => '/',
        'domain'   => '',
        'secure'   => $esHttps, // true en producción con HTTPS; false en XAMPP local (http)
        'httponly' => true,     // JavaScript no puede leer la cookie (mitiga robo por XSS)
        'samesite' => 'Lax',    // mitiga CSRF en la mayoría de escenarios
    ]);

    session_start();
}

/**
 * Protege una página completa: exige sesión activa, controla el
 * cierre por inactividad y valida que el rol de la sesión esté
 * en la lista de roles permitidos. Si algo falla, redirige al
 * login y detiene la ejecución del script (exit).
 *
 * @param string[] $rolesPermitidos Ej: ['admin'] o ['medico','admin']
 */
function verificarSesion(array $rolesPermitidos): void
{
    iniciarSesionSegura();

    // 1) ¿Hay una sesión iniciada?
    if (!isset($_SESSION['id_usuario'])) {
        header('Location: login.php');
        exit;
    }

    // 2) Cierre automático por inactividad (RNF-07)
    if (isset($_SESSION['ultima_actividad'])
        && (time() - $_SESSION['ultima_actividad']) > TIEMPO_INACTIVIDAD_SEGUNDOS
    ) {
        cerrarSesionCompleta();
        header('Location: login.php?expirada=1');
        exit;
    }
    $_SESSION['ultima_actividad'] = time();

    // 3) Control de acceso por rol (RF-11 y control de acceso por acción)
    if (!in_array($_SESSION['rol'], $rolesPermitidos, true)) {
        header('Location: login.php');
        exit;
    }
}

/**
 * Destruye la sesión por completo: borra los datos del servidor Y
 * la cookie física en el navegador (session_destroy solo no borra
 * la cookie del cliente).
 */
function cerrarSesionCompleta(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $parametrosCookie = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $parametrosCookie['path'],
            $parametrosCookie['domain'],
            $parametrosCookie['secure'],
            $parametrosCookie['httponly']
        );
    }

    session_destroy();
}

/* =====================================================================
   PROTECCIÓN CSRF (RNF-08)
   ===================================================================== */

/**
 * Devuelve el token CSRF de la sesión actual, generándolo si aún no
 * existe. Llamar DESPUÉS de iniciarSesionSegura() o verificarSesion().
 */
function generarTokenCSRF(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Valida un token CSRF recibido (normalmente desde $_POST) contra el
 * guardado en sesión. Usa hash_equals para evitar ataques de timing.
 */
function validarTokenCSRF(?string $tokenRecibido): bool
{
    return isset($_SESSION['csrf_token'])
        && is_string($tokenRecibido)
        && hash_equals($_SESSION['csrf_token'], $tokenRecibido);
}
