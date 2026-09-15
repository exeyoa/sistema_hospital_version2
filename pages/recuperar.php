<?php
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/recuperacion.php';
require_once __DIR__ . '/../config/politica_password.php';

iniciarSesionSegura();

// Límite de solicitudes de código por ventana de tiempo (adaptado de
// config/intentos_login.php, implementado a nivel de sesión).
const RECUPERACION_MAX_INTENTOS    = 3;
const RECUPERACION_VENTANA_MINUTOS = 15;

// Ventana corta de sesión para el código verificado entre sub-pasos
// (mismo plazo que la vigencia del código en la base de datos).
const RECUPERACION_VALIDACION_SEGUNDOS = 5 * 60;

/**
 * true si la sesión ya alcanzó el máximo de solicitudes en la ventana.
 */
function recuperacionEnLimite(): bool
{
    $ahora = time();

    if (!isset($_SESSION['recuperacion_primer_intento'])) {
        $_SESSION['recuperacion_primer_intento'] = $ahora;
        $_SESSION['recuperacion_conteo']         = 0;
    }

    if ($ahora - $_SESSION['recuperacion_primer_intento'] > RECUPERACION_VENTANA_MINUTOS * 60) {
        $_SESSION['recuperacion_primer_intento'] = $ahora;
        $_SESSION['recuperacion_conteo']         = 0;
    }

    return $_SESSION['recuperacion_conteo'] >= RECUPERACION_MAX_INTENTOS;
}

/**
 * Registra una solicitud de código en la sesión actual.
 */
function registrarSolicitudRecuperacion(): void
{
    $_SESSION['recuperacion_conteo'] = ($_SESSION['recuperacion_conteo'] ?? 0) + 1;
}

/**
 * Limpia el código validado en sesión (sub-paso B) si existe.
 */
function limpiarCodigoValidadoSesion(): void
{
    unset($_SESSION['recuperacion_codigo_valido']);
    unset($_SESSION['recuperacion_codigo_valido_en']);
}

$error              = '';
$solicitudEnviada   = false;
$contrasenaCambiada = false;

// Token CSRF compartido por los formularios de los sub-pasos (RNF-08)
$csrfToken = generarTokenCSRF();

// ------------------------------------------------------------
// Procesamiento POST
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // --- PANTALLA 1: pedir el código ---
    if (isset($_POST['correo_usuario'])) {

        if (!validarTokenCSRF($_POST['csrf_token'] ?? null)) {
            $error = 'Tu formulario expiró, intenta de nuevo.';
        } elseif (recuperacionEnLimite()) {
            $error = 'Has hecho demasiadas solicitudes. Espera ' . RECUPERACION_VENTANA_MINUTOS . ' minutos e intenta de nuevo.';
        } else {
            registrarSolicitudRecuperacion();

            $correoUsuario = trim($_POST['correo_usuario'] ?? '');

            if ($correoUsuario === '') {
                $error = 'Ingresa tu correo o usuario.';
            } else {
                $stmt = $conexion->prepare(
                    "SELECT id_usuario, nombre, correo
                     FROM usuarios
                     WHERE (correo = :correo OR usuario = :usuario) AND activo = 1
                     LIMIT 1"
                );
                $stmt->execute([
                    ':correo'  => $correoUsuario,
                    ':usuario' => $correoUsuario,
                ]);
                $usuarioRecuperacion = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($usuarioRecuperacion) {
                    $codigo = generarCodigoRecuperacion($conexion, (int) $usuarioRecuperacion['id_usuario']);
                    enviarCorreoCodigo($usuarioRecuperacion['correo'], $usuarioRecuperacion['nombre'], $codigo);

                    // Enlace entre pantalla 1 y pantalla 2 (sin exponer datos)
                    $_SESSION['recuperacion_id_usuario'] = (int) $usuarioRecuperacion['id_usuario'];
                }

                // Mensaje genérico SIEMPRE, exista o no la cuenta (no revelamos cuentas).
                $solicitudEnviada = true;
            }
        }

        // Al iniciar (o reintentar) un flujo, se descarta cualquier código ya validado
        limpiarCodigoValidadoSesion();
    }

    // --- PANTALLA 2 - SUB-PASO A: verificar el código (sin marcarlo usado) ---
    if (isset($_POST['codigo'])) {

        if (!validarTokenCSRF($_POST['csrf_token'] ?? null)) {
            $error = 'Tu formulario expiró, intenta de nuevo.';
        } elseif (!isset($_SESSION['recuperacion_id_usuario'])) {
            header('Location: recuperar.php');
            exit;
        } else {
            $codigoIngresado = trim($_POST['codigo'] ?? '');

            if (verificarCodigoRecuperacion($conexion, (int) $_SESSION['recuperacion_id_usuario'], $codigoIngresado)) {
                // Código válido y vigente: lo recordamos en sesión para pasar
                // al sub-paso B. Se volverá a revalidar contra la base de
                // datos justo antes de cambiar la contraseña.
                $_SESSION['recuperacion_codigo_valido']    = $codigoIngresado;
                $_SESSION['recuperacion_codigo_valido_en'] = time();
            } else {
                $error = 'El código no es válido o ya expiró. Verifica tu correo o solicita un código nuevo.';
                limpiarCodigoValidadoSesion();
            }
        }
    }

    // --- PANTALLA 2 - SUB-PASO B: definir la nueva contraseña ---
    if (isset($_POST['nueva_password']) || isset($_POST['confirmar_password'])) {

        $hayCodigoValidado = isset($_SESSION['recuperacion_codigo_valido'])
            && isset($_SESSION['recuperacion_codigo_valido_en'])
            && (time() - $_SESSION['recuperacion_codigo_valido_en']) <= RECUPERACION_VALIDACION_SEGUNDOS;

        if (!validarTokenCSRF($_POST['csrf_token'] ?? null)) {
            $error = 'Tu formulario expiró, intenta de nuevo.';
        } elseif (!isset($_SESSION['recuperacion_id_usuario']) || !$hayCodigoValidado) {
            header('Location: recuperar.php');
            exit;
        } else {
            $nuevaPassword     = $_POST['nueva_password'] ?? '';
            $confirmarPassword = $_POST['confirmar_password'] ?? '';

            if ($nuevaPassword === '' || $confirmarPassword === '') {
                $error = 'Completa la nueva contraseña y su confirmación.';
            } elseif ($erroresPolitica = validarPoliticaPassword($nuevaPassword)) {
                $error = 'La contraseña debe ' . implode(', ', $erroresPolitica) . '.';
            } elseif ($nuevaPassword !== $confirmarPassword) {
                $error = 'Las contraseñas no coinciden.';
            } else {
                // No se permite reusar la contraseña actual (password_verify,
                // nunca comparación de hashes: cada hash es único por diseño).
                $stmt = $conexion->prepare(
                    "SELECT password_hash FROM usuarios WHERE id_usuario = :id_usuario"
                );
                $stmt->execute([
                    ':id_usuario' => $_SESSION['recuperacion_id_usuario'],
                ]);
                $hashActual = $stmt->fetchColumn();

                if ($hashActual !== false && password_verify($nuevaPassword, $hashActual)) {
                    $error = 'La nueva contraseña no puede ser igual a la actual.';
                } elseif (!validarCodigoRecuperacion(
                    $conexion,
                    (int) $_SESSION['recuperacion_id_usuario'],
                    $_SESSION['recuperacion_codigo_valido']
                )) {
                    // Revalidación final contra la base de datos: el código ya no
                    // es válido o expiró mientras el usuario escribía la contraseña.
                    limpiarCodigoValidadoSesion();
                    $error = 'Tu código dejó de ser válido o expiró. Solicita un código nuevo.';
                } else {
                    // Código revalidado y marcado como usado = 1: cambiamos la contraseña.
                    $passwordHash = password_hash($nuevaPassword, PASSWORD_BCRYPT);

                    $stmt = $conexion->prepare(
                        "UPDATE usuarios SET password_hash = :password_hash WHERE id_usuario = :id_usuario"
                    );
                    $stmt->execute([
                        ':password_hash' => $passwordHash,
                        ':id_usuario'    => $_SESSION['recuperacion_id_usuario'],
                    ]);

                    unset($_SESSION['recuperacion_id_usuario']);
                    limpiarCodigoValidadoSesion();
                    $contrasenaCambiada = true;
                }
            }
        }
    }
}

// Si el usuario pide un código nuevo, limpiamos el flujo previo
if (isset($_GET['nuevo_codigo'])) {
    unset($_SESSION['recuperacion_id_usuario']);
    limpiarCodigoValidadoSesion();
}

// ¿Mostramos la pantalla 2? Solo si hay un flujo de recuperación activo.
$mostrarPantalla2 = isset($_SESSION['recuperacion_id_usuario']);

// ¿Mostramos el sub-paso B (nueva contraseña)? Solo si el código ya fue
// verificado y sigue dentro de la ventana corta de sesión.
$hayCodigoValidado = isset($_SESSION['recuperacion_codigo_valido'])
    && isset($_SESSION['recuperacion_codigo_valido_en'])
    && (time() - $_SESSION['recuperacion_codigo_valido_en']) <= RECUPERACION_VALIDACION_SEGUNDOS;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Recuperar contraseña - Hospital Raúl Dávila Mena</title>
    <link rel="stylesheet" href="../css/estilo.css">
    <link rel="stylesheet" href="../css/login.css">
</head>
<body>
    <div class="pantalla-login">
        <div class="panel-marca">
            <!-- Carrusel de fotos igual que login.php -->
            <div class="carrusel-fondo" aria-hidden="true">
                <div class="carrusel-slide activo" style="background-image: url('../imagenes/foto_1.jpeg');"></div>
                <div class="carrusel-slide" style="background-image: url('../imagenes/foto_2.jpeg');"></div>
                <div class="carrusel-slide" style="background-image: url('../imagenes/foto_3.jpeg');"></div>
            </div>

            <!-- Degradado celeste/blanco superpuesto sobre las fotos -->
            <div class="carrusel-velo" aria-hidden="true"></div>

            <div class="patron-decorativo" aria-hidden="true"></div>
            <div class="contenido-marca">
                <svg class="icono-cruz" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M11 2H13V11H22V13H13V22H11V13H2V11H11V2Z" fill="#fff"/>
                </svg>
                <h1>Hospital Raúl Dávila Mena</h1>
                <p>Consultas, citas y recetas en un solo lugar para tu equipo médico.</p>
            </div>
        </div>

        <div class="panel-formulario">
            <div class="tarjeta-login">

                <?php if ($contrasenaCambiada): ?>
                    <h2>Contraseña actualizada</h2>
                    <p class="subtitulo">Tu contraseña se cambió correctamente. Ya puedes iniciar sesión con tu nueva contraseña.</p>
                    <a href="login.php" class="btn" style="display:block; text-align:center; text-decoration:none;">Iniciar sesión</a>

                <?php elseif ($mostrarPantalla2 && $hayCodigoValidado): ?>

                    <?php if ($error): ?>
                        <div class="alerta alerta-error"><?php echo htmlspecialchars($error); ?></div>
                    <?php endif; ?>

                    <h2>Nueva contraseña</h2>
                    <p class="subtitulo">Código verificado. Define tu nueva contraseña.</p>

                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                        <div class="campo">
                            <label for="nueva_password">Nueva contraseña</label>
                            <div class="input-con-icono">
                                <input type="password" id="nueva_password" name="nueva_password" required minlength="8" autocomplete="new-password">
                                <button type="button" class="boton-ver-password" id="botonVerNueva" aria-label="Mostrar contraseña">
                                    <svg id="iconoOjoNueva" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <path d="M2 12C2 12 5.5 5 12 5C18.5 5 22 12 22 12C22 12 18.5 19 12 19C5.5 19 2 12 2 12Z" stroke="currentColor" stroke-width="1.6"/>
                                        <circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.6"/>
                                    </svg>
                                </button>
                            </div>
                            <ul class="lista-requisitos-password" id="listaRequisitosNueva">
                                <li data-regla="longitud">Mínimo 8 caracteres</li>
                                <li data-regla="minuscula">Incluye una letra minúscula</li>
                                <li data-regla="mayuscula">Incluye una letra mayúscula</li>
                                <li data-regla="especial">Incluye un carácter especial (!@#$%^&*()[\]{};:,.<>?…)</li>
                                <li data-regla="espacios">Sin espacios en blanco</li>
                            </ul>
                        </div>

                        <div class="campo">
                            <label for="confirmar_password">Confirmar contraseña</label>
                            <div class="input-con-icono">
                                <input type="password" id="confirmar_password" name="confirmar_password" required minlength="8" autocomplete="new-password">
                                <button type="button" class="boton-ver-password" id="botonVerConfirmar" aria-label="Mostrar contraseña">
                                    <svg id="iconoOjoConfirmar" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <path d="M2 12C2 12 5.5 5 12 5C18.5 5 22 12 22 12C22 12 18.5 19 12 19C5.5 19 2 12 2 12Z" stroke="currentColor" stroke-width="1.6"/>
                                        <circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.6"/>
                                    </svg>
                                </button>
                            </div>
                        </div>

                        <button type="submit" class="btn">Restablecer contraseña</button>
                    </form>

                    <p class="subtitulo" style="margin-top:18px; margin-bottom:0;">
                        <a href="recuperar.php?nuevo_codigo=1" class="link-recuperar">Pedir un código nuevo</a>
                        &nbsp;·&nbsp;<a href="login.php" class="link-recuperar">Volver al inicio de sesión</a>
                    </p>

                <?php elseif ($mostrarPantalla2): ?>

                    <?php if ($solicitudEnviada): ?>
                        <div class="alerta alerta-exito">
                            Si la cuenta existe, enviamos un código de 6 dígitos a tu correo. Revisa tu bandeja de entrada (expira en 5 minutos).
                        </div>
                    <?php endif; ?>

                    <?php if ($error): ?>
                        <div class="alerta alerta-error"><?php echo htmlspecialchars($error); ?></div>
                    <?php endif; ?>

                    <h2>Verificar código</h2>
                    <p class="subtitulo">Ingresa el código de 6 dígitos que recibiste por correo para continuar.</p>

                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                        <div class="campo">
                            <label for="codigo">Código de 6 dígitos</label>
                            <input type="text" id="codigo" name="codigo" required maxlength="6" inputmode="numeric" pattern="[0-9]{6}" autofocus>
                        </div>

                        <button type="submit" class="btn">Verificar código</button>
                    </form>

                    <p class="subtitulo" style="margin-top:18px; margin-bottom:0;">
                        <a href="recuperar.php?nuevo_codigo=1" class="link-recuperar">Pedir un código nuevo</a>
                        &nbsp;·&nbsp;<a href="login.php" class="link-recuperar">Volver al inicio de sesión</a>
                    </p>

                <?php else: ?>

                    <h2>Recuperar contraseña</h2>
                    <p class="subtitulo">Ingresa el correo o usuario con el que te registraste. Te enviaremos un código para restablecer tu contraseña.</p>

                    <?php if ($solicitudEnviada): ?>
                        <div class="alerta alerta-exito">
                            Si la cuenta existe, enviamos un código de 6 dígitos a tu correo. Revisa tu bandeja de entrada (expira en 5 minutos).
                        </div>
                    <?php endif; ?>

                    <?php if ($error): ?>
                        <div class="alerta alerta-error"><?php echo htmlspecialchars($error); ?></div>
                    <?php endif; ?>

                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                        <div class="campo">
                            <label for="correo_usuario">Correo o usuario</label>
                            <input type="text" id="correo_usuario" name="correo_usuario" required autofocus autocomplete="username">
                        </div>

                        <button type="submit" class="btn">Enviar código</button>
                    </form>

                    <p class="subtitulo" style="margin-top:18px; margin-bottom:0;">
                        <a href="login.php" class="link-recuperar">Volver al inicio de sesión</a>
                    </p>

                <?php endif; ?>

            </div>
        </div>
    </div>
<script>
// --- Carrusel de fotos del panel de marca (misma lógica que login.php) ---
const carruselFondo = document.querySelector('.carrusel-fondo');
if (carruselFondo) {
    const slides = carruselFondo.querySelectorAll('.carrusel-slide');
    let indice = 0;
    setInterval(() => {
        slides[indice].classList.remove('activo');
        indice = (indice + 1) % slides.length;
        slides[indice].classList.add('activo');
    }, 15000);
}

// Botón "ver contraseña" en ambos campos (mismo patrón que login.php)
document.querySelectorAll('.boton-ver-password').forEach((boton) => {
    const campoPassword = boton.previousElementSibling;
    const iconoOjo = boton.querySelector('svg');
    const ojoAbierto = iconoOjo.innerHTML;
    const ojoCerrado = `
        <path d="M3 3L21 21" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
        <path d="M10.6 5.1C11.06 5.03 11.53 5 12 5C18.5 5 22 12 22 12C21.4 13.2 20.5 14.5 19.3 15.7M6.5 6.6C4 8.3 2 12 2 12C2 12 5.5 19 12 19C13.9 19 15.5 18.5 16.8 17.8" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
        <path d="M9.9 10C9.3 10.6 9 11.3 9 12C9 13.7 10.3 15 12 15C12.7 15 13.4 14.7 14 14.1" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
    `;

    boton.addEventListener('click', () => {
        const oculto = campoPassword.type === 'password';
        campoPassword.type = oculto ? 'text' : 'password';
        iconoOjo.innerHTML = oculto ? ojoCerrado : ojoAbierto;
        boton.setAttribute('aria-label', oculto ? 'Ocultar contraseña' : 'Mostrar contraseña');
    });
});

// Validación en tiempo real de la política de contraseñas (solo capa visual;
// la validación definitiva la hace el servidor en config/politica_password.php)
const requisitosPasswordNueva = [
    { regla: 'longitud',  cumple: (v) => v.length >= 8 },
    { regla: 'minuscula', cumple: (v) => /[a-z]/.test(v) },
    { regla: 'mayuscula', cumple: (v) => /[A-Z]/.test(v) },
    { regla: 'especial',  cumple: (v) => /[!@#$%^&*()_+\-=\[\]{};:,.<>?]/.test(v) },
    { regla: 'espacios',  cumple: (v) => !/\s/.test(v) },
];

function actualizarRequisitosPasswordNueva(valor) {
    requisitosPasswordNueva.forEach(({ regla, cumple }) => {
        const item = document.querySelector(`#listaRequisitosNueva li[data-regla="${regla}"]`);
        if (!item) return;
        item.classList.toggle('cumple', cumple(valor));
    });
}

const campoNuevaPassword = document.getElementById('nueva_password');
const listaRequisitosNueva = document.getElementById('listaRequisitosNueva');
if (campoNuevaPassword && listaRequisitosNueva) {
    campoNuevaPassword.addEventListener('input', () => actualizarRequisitosPasswordNueva(campoNuevaPassword.value));
    actualizarRequisitosPasswordNueva(campoNuevaPassword.value);
}
</script>
</body>
</html>