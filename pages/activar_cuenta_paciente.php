<?php
/**
 * pages/activar_cuenta_paciente.php
 *
 * Wizard de activación de cuenta del paciente (4 pasos):
 *
 *   Paso 1: Cédula → busca al paciente y guarda su id en sesión
 *   Paso 2: Datos personales (nombre completo, nacimiento, teléfono)
 *           → deben coincidir con la BD
 *   Paso 3: Correo (se valida que coincida con la BD) + botón "Enviar código"
 *   Paso 4: Código de 6 dígitos → si es válido, abre sesión con rol='paciente'
 *
 * NO usa contraseña: la activación se basa en datos personales verificados
 * contra la BD + un código de un solo uso enviado al correo.
 *
 * Reusa `config/recuperacion.php` (polimórfico) y `config/correo.php` (PHPMailer).
 */
require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/recuperacion.php';

iniciarSesionSegura();

// Si ya tiene sesión de paciente, va directo al panel
if (isset($_SESSION['id_paciente']) && ($_SESSION['rol'] ?? '') === 'paciente') {
    header('Location: paciente.php');
    exit;
}

// Límite de intentos por ventana (mitigación de spam)
const ACTIVAR_MAX_INTENTOS    = 5;
const ACTIVAR_VENTANA_MINUTOS = 15;

function activarEnLimite(): bool
{
    $ahora = time();
    if (!isset($_SESSION['activar_primer_intento'])) {
        $_SESSION['activar_primer_intento'] = $ahora;
        $_SESSION['activar_conteo']         = 0;
    }
    if ($ahora - $_SESSION['activar_primer_intento'] > ACTIVAR_VENTANA_MINUTOS * 60) {
        $_SESSION['activar_primer_intento'] = $ahora;
        $_SESSION['activar_conteo']         = 0;
    }
    return $_SESSION['activar_conteo'] >= ACTIVAR_MAX_INTENTOS;
}

function registrarIntentoActivar(): void
{
    $_SESSION['activar_conteo'] = ($_SESSION['activar_conteo'] ?? 0) + 1;
}

function limpiarEstadoActivacion(): void
{
    unset($_SESSION['activar_id_paciente']);
    unset($_SESSION['activar_datos_validados']);
    unset($_SESSION['activar_codigo_enviado']);
    unset($_SESSION['activar_correo_validado']);
    unset($_SESSION['activar_primer_intento']);
    unset($_SESSION['activar_conteo']);
}

$error  = '';
$exito  = '';
$csrfToken = generarTokenCSRF();

// Determinar paso actual según el estado de la sesión
$step = 1;
if (isset($_SESSION['activar_id_paciente'])) {
    $step = 2;
}
if (($_SESSION['activar_datos_validados'] ?? false) === true) {
    $step = 3;
}
if (($_SESSION['activar_correo_validado'] ?? false) === true) {
    $step = 4;
}

// GET ?reiniciar=1 → empezar de nuevo
if (isset($_GET['reiniciar'])) {
    limpiarEstadoActivacion();
    header('Location: activar_cuenta_paciente.php');
    exit;
}

// =====================================================================
// POST: Paso 1 — Cédula
// =====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['paso_cedula'])) {
    if (!validarTokenCSRF($_POST['csrf_token'] ?? null)) {
        $error = 'Tu formulario expiró, intenta de nuevo.';
    } elseif (activarEnLimite()) {
        $error = 'Demasiados intentos. Espera ' . ACTIVAR_VENTANA_MINUTOS . ' minutos e intenta de nuevo.';
    } else {
        registrarIntentoActivar();
        $cedula = trim($_POST['cedula'] ?? '');
        if ($cedula === '') {
            $error = 'Ingresa tu cédula.';
        } else {
            $stmt = $conexion->prepare(
                'SELECT id_paciente, nombre, apellido, fecha_nacimiento, telefono, correo
                 FROM pacientes
                 WHERE cedula = :cedula
                 LIMIT 1'
            );
            $stmt->execute([':cedula' => $cedula]);
            $paciente = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$paciente) {
                $error = 'No encontramos un paciente con esa cédula. Verifica o acércate a recepción.';
            } elseif (empty($paciente['correo'])) {
                $error = 'Tu cuenta no tiene un correo registrado. Pasa por recepción para que lo agreguen.';
            } else {
                $_SESSION['activar_id_paciente'] = (int) $paciente['id_paciente'];
                $step = 2;
            }
        }
    }
}

// =====================================================================
// POST: Paso 2 — Datos personales
// =====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['paso_datos'])) {
    if (!validarTokenCSRF($_POST['csrf_token'] ?? null)) {
        $error = 'Tu formulario expiró, intenta de nuevo.';
    } elseif (!isset($_SESSION['activar_id_paciente'])) {
        header('Location: activar_cuenta_paciente.php');
        exit;
    } else {
        $nombre      = trim($_POST['nombre']      ?? '');
        $apellido    = trim($_POST['apellido']    ?? '');
        $nacimiento  = trim($_POST['fecha_nacimiento'] ?? '');
        $telefono    = trim($_POST['telefono']    ?? '');

        $stmt = $conexion->prepare(
            'SELECT nombre, apellido, fecha_nacimiento, telefono
             FROM pacientes
             WHERE id_paciente = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $_SESSION['activar_id_paciente']]);
        $paciente = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$paciente) {
            limpiarEstadoActivacion();
            $error = 'Sesión inválida. Empieza de nuevo.';
            $step = 1;
        } else {
            $nombreOk     = strcasecmp(trim((string) $paciente['nombre']), $nombre) === 0;
            $apellidoOk   = strcasecmp(trim((string) $paciente['apellido']), $apellido) === 0;
            $nacimientoOk = ($paciente['fecha_nacimiento'] !== null && (string) $paciente['fecha_nacimiento'] === $nacimiento);
            $telefonoOk   = trim((string) $paciente['telefono']) === $telefono;

            if (!$nombreOk || !$apellidoOk || !$nacimientoOk || !$telefonoOk) {
                $error = 'Los datos no coinciden con nuestros registros. Verifica e intenta de nuevo.';
            } else {
                $_SESSION['activar_datos_validados'] = true;
                $step = 3;
            }
        }
    }
}

// =====================================================================
// POST: Paso 3 — Validar correo y enviar código
// =====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['paso_correo'])) {
    if (!validarTokenCSRF($_POST['csrf_token'] ?? null)) {
        $error = 'Tu formulario expiró, intenta de nuevo.';
    } elseif (!isset($_SESSION['activar_id_paciente']) || ($_SESSION['activar_datos_validados'] ?? false) !== true) {
        $error = 'Primero debes completar los pasos anteriores.';
        $step = 2;
    } else {
        $correoIngresado = trim($_POST['correo'] ?? '');
        if ($correoIngresado === '' || !filter_var($correoIngresado, FILTER_VALIDATE_EMAIL)) {
            $error = 'Ingresa un correo válido.';
        } else {
            $stmt = $conexion->prepare(
                'SELECT correo, nombre FROM pacientes WHERE id_paciente = :id LIMIT 1'
            );
            $stmt->execute([':id' => $_SESSION['activar_id_paciente']]);
            $paciente = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$paciente || empty($paciente['correo'])) {
                $error = 'Tu cuenta no tiene un correo registrado. Pasa por recepción.';
            } elseif (strcasecmp(trim((string) $paciente['correo']), $correoIngresado) !== 0) {
                $error = 'El correo no coincide con el registrado en tu historia clínica.';
            } else {
                // OK: correo validado, generar y enviar código
                try {
                    $codigo = generarCodigoRecuperacion(
                        $conexion,
                        (int) $_SESSION['activar_id_paciente'],
                        'paciente'
                    );
                    enviarCorreoCodigo($paciente['correo'], $paciente['nombre'], $codigo);
                    $_SESSION['activar_correo_validado'] = true;
                    $exito = 'Te enviamos un código de 6 dígitos a tu correo. Revisa tu bandeja (expira en 5 minutos).';
                    $step = 4;
                } catch (Throwable $e) {
                    error_log('Error al enviar código de activación: ' . $e->getMessage());
                    $error = 'No se pudo enviar el código. Intenta de nuevo.';
                }
            }
        }
    }
}

// =====================================================================
// POST: Paso 4 — Verificar código
// =====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['paso_codigo'])) {
    if (!validarTokenCSRF($_POST['csrf_token'] ?? null)) {
        $error = 'Tu formulario expiró, intenta de nuevo.';
    } elseif (!isset($_SESSION['activar_id_paciente'])
              || ($_SESSION['activar_datos_validados'] ?? false) !== true
              || ($_SESSION['activar_correo_validado'] ?? false) !== true) {
        limpiarEstadoActivacion();
        $error = 'Sesión inválida. Empieza de nuevo.';
        $step = 1;
    } else {
        $codigoIngresado = trim($_POST['codigo'] ?? '');
        if ($codigoIngresado === '') {
            $error = 'Ingresa el código de 6 dígitos.';
        } elseif (verificarCodigoRecuperacion(
            $conexion,
            (int) $_SESSION['activar_id_paciente'],
            $codigoIngresado,
            'paciente'
        )) {
            // Re-validar contra la BD (revoca si expiró mientras escribía)
            if (!validarCodigoRecuperacion(
                $conexion,
                (int) $_SESSION['activar_id_paciente'],
                $codigoIngresado,
                'paciente'
            )) {
                limpiarEstadoActivacion();
                $error = 'El código expiró mientras lo escribías. Empieza de nuevo.';
                $step = 1;
            } else {
                // Activar sesión del paciente
                $stmt = $conexion->prepare(
                    'SELECT id_paciente, nombre, apellido FROM pacientes WHERE id_paciente = :id LIMIT 1'
                );
                $stmt->execute([':id' => $_SESSION['activar_id_paciente']]);
                $paciente = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$paciente) {
                    limpiarEstadoActivacion();
                    $error = 'No se pudo activar la cuenta. Empieza de nuevo.';
                    $step = 1;
                } else {
                    session_regenerate_id(true);
                    $_SESSION['id_paciente']      = (int) $paciente['id_paciente'];
                    $_SESSION['nombre']           = $paciente['nombre'];
                    $_SESSION['apellido']         = $paciente['apellido'];
                    $_SESSION['rol']              = 'paciente';
                    $_SESSION['ultima_actividad'] = time();
                    unset($_SESSION['csrf_token']);

                    limpiarEstadoActivacion();

                    header('Location: paciente.php');
                    exit;
                }
            }
        } else {
            $error = 'El código no es válido o ya expiró. Solicita uno nuevo.';
        }
    }
}

// GET ?nuevo_codigo=1 → permite reenviar el código en el paso 4
if (isset($_GET['nuevo_codigo'])) {
    unset($_SESSION['activar_correo_validado']);
    header('Location: activar_cuenta_paciente.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Activar cuenta de paciente - Hospital Raúl Dávila Mena</title>
    <link rel="stylesheet" href="../css/estilo.css">
    <link rel="stylesheet" href="../css/login.css">
    <style>
        .wizard-pasos {
            display: flex;
            gap: 6px;
            margin-bottom: 18px;
        }
        .wizard-paso {
            flex: 1;
            height: 6px;
            border-radius: 3px;
            background: #DCE6F0;
        }
        .wizard-paso.activo {
            background: #1878A8;
        }
        .wizard-paso.completado {
            background: #0B3D66;
        }
        .campo__ayuda {
            margin: 4px 0 0;
            font-size: 0.8rem;
            color: #6B7C8C;
        }
    </style>
</head>
<body>
    <div class="pantalla-login">
        <div class="panel-marca">
            <div class="carrusel-fondo" aria-hidden="true">
                <div class="carrusel-slide activo" style="background-image: url('../imagenes/foto_1.jpeg');"></div>
                <div class="carrusel-slide" style="background-image: url('../imagenes/foto_2.jpeg');"></div>
                <div class="carrusel-slide" style="background-image: url('../imagenes/foto_3.jpeg');"></div>
            </div>
            <div class="carrusel-velo" aria-hidden="true"></div>
            <div class="patron-decorativo" aria-hidden="true"></div>
            <div class="contenido-marca">
                <svg class="icono-cruz" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M11 2H13V11H22V13H13V22H11V13H2V11H11V2Z" fill="#fff"/>
                </svg>
                <h1>Hospital Raúl Dávila Mena</h1>
                <p>Activa tu cuenta de paciente con tus datos personales.</p>
            </div>
        </div>

        <div class="panel-formulario">
            <div class="tarjeta-login">

                <div class="wizard-pasos" aria-label="Progreso">
                    <div class="wizard-paso <?= $step >= 1 ? ($step > 1 ? 'completado' : 'activo') : '' ?>" title="Cédula"></div>
                    <div class="wizard-paso <?= $step >= 2 ? ($step > 2 ? 'completado' : 'activo') : '' ?>" title="Datos personales"></div>
                    <div class="wizard-paso <?= $step >= 3 ? ($step > 3 ? 'completado' : 'activo') : '' ?>" title="Correo"></div>
                    <div class="wizard-paso <?= $step >= 4 ? 'activo' : '' ?>" title="Código"></div>
                </div>

                <?php if ($error): ?>
                    <div class="alerta alerta-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
                <?php if ($exito): ?>
                    <div class="alerta alerta-exito"><?= htmlspecialchars($exito, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>

                <?php if ($step === 1): ?>
                    <h2>Paso 1 de 4 · Tu cédula</h2>
                    <p class="subtitulo">Ingresa tu cédula para empezar.</p>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <div class="campo">
                            <label for="cedula">Cédula</label>
                            <input type="text" id="cedula" name="cedula" required autofocus autocomplete="username">
                        </div>
                        <button type="submit" name="paso_cedula" class="btn">Continuar</button>
                    </form>

                <?php elseif ($step === 2): ?>
                    <h2>Paso 2 de 4 · Tus datos personales</h2>
                    <p class="subtitulo">Confirma los datos tal como aparecen en tu historia clínica.</p>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <div class="campo">
                            <label for="nombre">Nombre</label>
                            <input type="text" id="nombre" name="nombre" required autofocus autocomplete="given-name">
                        </div>
                        <div class="campo">
                            <label for="apellido">Apellido</label>
                            <input type="text" id="apellido" name="apellido" required autocomplete="family-name">
                        </div>
                        <div class="campo">
                            <label for="fecha_nacimiento">Fecha de nacimiento</label>
                            <input type="date" id="fecha_nacimiento" name="fecha_nacimiento" required>
                        </div>
                        <div class="campo">
                            <label for="telefono">Teléfono</label>
                            <input type="tel" id="telefono" name="telefono" required autocomplete="tel">
                        </div>
                        <button type="submit" name="paso_datos" class="btn">Continuar</button>
                    </form>

                <?php elseif ($step === 3): ?>
                    <h2>Paso 3 de 4 · Tu correo</h2>
                    <p class="subtitulo">Ingresa el correo que tienes registrado en el hospital. Te enviaremos un código de 6 dígitos.</p>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <div class="campo">
                            <label for="correo">Correo</label>
                            <input type="email" id="correo" name="correo" required autofocus autocomplete="email">
                            <p class="campo__ayuda">Debe coincidir con el correo registrado en tu historia clínica.</p>
                        </div>
                        <button type="submit" name="paso_correo" class="btn">Enviar código</button>
                    </form>

                <?php elseif ($step === 4): ?>
                    <h2>Paso 4 de 4 · Código de verificación</h2>
                    <p class="subtitulo">Ingresa el código de 6 dígitos que te enviamos al correo.</p>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <div class="campo">
                            <label for="codigo">Código de 6 dígitos</label>
                            <input type="text" id="codigo" name="codigo" inputmode="numeric"
                                   pattern="[0-9]{6}" maxlength="6" minlength="6" required
                                   autocomplete="one-time-code" autofocus>
                        </div>
                        <button type="submit" name="paso_codigo" class="btn">Activar mi cuenta</button>
                    </form>
                    <p class="subtitulo" style="margin-top:18px; margin-bottom:0;">
                        <a href="activar_cuenta_paciente.php?nuevo_codigo=1" class="link-recuperar">Enviar un código nuevo</a>
                    </p>
                <?php endif; ?>

                <p class="subtitulo" style="margin-top:18px; margin-bottom:0;">
                    ¿Eres personal del hospital?
                    <a href="login.php" class="link-recuperar">Inicia sesión aquí</a>
                </p>

            </div>
        </div>
    </div>
</body>
</html>
