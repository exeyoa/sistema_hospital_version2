<?php
/**
 * pages/recepcionista_perfil.php
 *
 * Página de perfil del recepcionista: ver y actualizar datos
 * personales, foto de perfil (con cropper 1:1) y contraseña
 * (con código de verificación por correo, reutilizando
 * config/recuperacion.php).
 *
 * Acciones POST:
 *   - accion=actualizar_nombre
 *   - accion=actualizar_usuario
 *   - accion=subir_foto (multipart con crop_data base64)
 *   - accion=enviar_codigo_password
 *   - accion=verificar_codigo_password
 *   - accion=cambiar_password
 *
 * AJAX usado: pages/ajax/verificar_usuario.php (GET idempotente,
 * verifica disponibilidad del nombre de usuario).
 */

require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/../config/recuperacion.php';
require_once __DIR__ . '/../config/politica_password.php';

verificarSesion(['recepcionista']);
$csrfToken = generarTokenCSRF();

// Tamaño máximo permitido para la foto subida (2 MB, decisión de la fase 5).
const PERFIL_FOTO_BYTES_MAX = 2 * 1024 * 1024;

// ---------------------------------------------------------
// Cargar datos actuales del usuario logueado
// ---------------------------------------------------------
$idUsuarioActual = (int) $_SESSION['id_usuario'];

$stmtUsuario = $conexion->prepare(
    'SELECT u.id_usuario, u.nombre, u.apellido, u.usuario, u.correo,
            u.foto_perfil, u.fecha_creacion, r.nombre_rol
     FROM usuarios u
     INNER JOIN roles r ON r.id_rol = u.id_rol
     WHERE u.id_usuario = :id LIMIT 1'
);
$stmtUsuario->execute([':id' => $idUsuarioActual]);
$usuarioActual = $stmtUsuario->fetch(PDO::FETCH_ASSOC);

if ($usuarioActual === false) {
    cerrarSesionCompleta();
    header('Location: login.php');
    exit;
}

// Iniciales para los avatares que se muestran cuando no hay foto.
$partesNombre = explode(' ', trim(($usuarioActual['nombre'] ?? '') . ' ' . ($usuarioActual['apellido'] ?? '')));
$inicialesUsuario = mb_strtoupper(
    mb_substr($partesNombre[0] ?? '', 0, 1)
    . (isset($partesNombre[1]) && $partesNombre[1] !== '' ? mb_substr($partesNombre[1], 0, 1) : '')
);
if ($inicialesUsuario === '') { $inicialesUsuario = 'U'; }

// Rutas de archivos de la página (para includes y assets).
$rutaImagenesPerfiles = realpath(__DIR__ . '/../imagenes/perfiles');
$rutaImagenesPerfiles = ($rutaImagenesPerfiles !== false) ? $rutaImagenesPerfiles : (__DIR__ . '/../imagenes/perfiles');

// ---------------------------------------------------------
// Acumuladores de mensajes para cada acción
// ---------------------------------------------------------
$erroresDatos    = [];
$erroresPassword = [];
$exitoDatos      = '';
$exitoPassword   = '';

$hayCodigoValidadoPassword = isset($_SESSION['perfil_codigo_valido'])
    && isset($_SESSION['perfil_codigo_valido_en'])
    && (time() - $_SESSION['perfil_codigo_valido_en']) <= 5 * 60
    && isset($_SESSION['perfil_id_usuario'])
    && (int) $_SESSION['perfil_id_usuario'] === $idUsuarioActual;

// Limpiar flag de password si pertenece a otro usuario
if (isset($_SESSION['perfil_id_usuario']) && (int) $_SESSION['perfil_id_usuario'] !== $idUsuarioActual) {
    unset($_SESSION['perfil_codigo_valido'], $_SESSION['perfil_codigo_valido_en'], $_SESSION['perfil_id_usuario']);
    $hayCodigoValidadoPassword = false;
}

// ---------------------------------------------------------
// POST: actualizar_nombre
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'actualizar_nombre') {
    if (!validarTokenCSRF($_POST['csrf_token'] ?? null)) {
        $erroresDatos[] = 'La sesión expiró. Recarga la página e inténtalo de nuevo.';
    } else {
        $nuevoNombre   = trim($_POST['nombre'] ?? '');
        $nuevoApellido = trim($_POST['apellido'] ?? '');

        if ($nuevoNombre === '' || mb_strlen($nuevoNombre) > 60) {
            $erroresDatos[] = 'El nombre es obligatorio (máx. 60 caracteres).';
        }
        if ($nuevoApellido === '' || mb_strlen($nuevoApellido) > 60) {
            $erroresDatos[] = 'El apellido es obligatorio (máx. 60 caracteres).';
        }

        if (!$erroresDatos) {
            try {
                $stmt = $conexion->prepare(
                    'UPDATE usuarios SET nombre = :nombre, apellido = :apellido WHERE id_usuario = :id'
                );
                $stmt->execute([
                    ':nombre'   => $nuevoNombre,
                    ':apellido' => $nuevoApellido,
                    ':id'       => $idUsuarioActual,
                ]);
                $_SESSION['nombre']   = $nuevoNombre;
                $_SESSION['apellido'] = $nuevoApellido;
                $usuarioActual['nombre']   = $nuevoNombre;
                $usuarioActual['apellido'] = $nuevoApellido;

                $partesNombre = explode(' ', trim($nuevoNombre . ' ' . $nuevoApellido));
                $inicialesUsuario = mb_strtoupper(
                    mb_substr($partesNombre[0] ?? '', 0, 1)
                    . (isset($partesNombre[1]) && $partesNombre[1] !== '' ? mb_substr($partesNombre[1], 0, 1) : '')
                );
                if ($inicialesUsuario === '') { $inicialesUsuario = 'U'; }

                $exitoDatos = 'Nombre y apellido actualizados.';
            } catch (PDOException $e) {
                error_log('Error al actualizar nombre/apellido: ' . $e->getMessage());
                $erroresDatos[] = 'No se pudo actualizar el nombre. Intenta nuevamente.';
            }
        }
    }
}

// ---------------------------------------------------------
// POST: actualizar_usuario
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'actualizar_usuario') {
    if (!validarTokenCSRF($_POST['csrf_token'] ?? null)) {
        $erroresDatos[] = 'La sesión expiró. Recarga la página e inténtalo de nuevo.';
    } else {
        $nuevoUsuario = trim($_POST['usuario'] ?? '');
        if ($nuevoUsuario === '' || strlen($nuevoUsuario) < 3 || strlen($nuevoUsuario) > 40) {
            $erroresDatos[] = 'El usuario debe tener entre 3 y 40 caracteres.';
        } elseif (!preg_match('/^[A-Za-z0-9._-]+$/', $nuevoUsuario)) {
            $erroresDatos[] = 'El usuario solo puede contener letras, números, guion bajo, guion medio y punto.';
        } else {
            // Verificar unicidad (excluyendo al propio usuario)
            try {
                $stmt = $conexion->prepare(
                    'SELECT COUNT(*) FROM usuarios WHERE usuario = :usuario AND id_usuario != :id'
                );
                $stmt->execute([':usuario' => $nuevoUsuario, ':id' => $idUsuarioActual]);
                if ((int) $stmt->fetchColumn() > 0) {
                    $erroresDatos[] = 'Ese nombre de usuario ya está en uso. Elige otro.';
                }
            } catch (PDOException $e) {
                error_log('Error al verificar usuario: ' . $e->getMessage());
                $erroresDatos[] = 'No se pudo verificar el usuario. Intenta nuevamente.';
            }
        }

        if (!$erroresDatos) {
            try {
                $stmt = $conexion->prepare(
                    'UPDATE usuarios SET usuario = :usuario WHERE id_usuario = :id'
                );
                $stmt->execute([':usuario' => $nuevoUsuario, ':id' => $idUsuarioActual]);
                $usuarioActual['usuario'] = $nuevoUsuario;
                $exitoDatos = 'Nombre de usuario actualizado.';
            } catch (PDOException $e) {
                error_log('Error al actualizar usuario: ' . $e->getMessage());
                $erroresDatos[] = 'No se pudo actualizar el usuario. Intenta nuevamente.';
            }
        }
    }
}

// ---------------------------------------------------------
// POST: subir_foto (crop_data es un data URL base64 con la imagen ya recortada)
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'subir_foto') {
    if (!validarTokenCSRF($_POST['csrf_token'] ?? null)) {
        $erroresDatos[] = 'La sesión expiró. Recarga la página.';
    } else {
        $cropData = trim($_POST['crop_data'] ?? '');
        if ($cropData === '') {
            $erroresDatos[] = 'No se recibió la imagen recortada.';
        } elseif (!preg_match('#^data:image/(jpeg|png|webp);base64,([A-Za-z0-9+/=]+)$#', $cropData, $matches)) {
            $erroresDatos[] = 'La imagen no tiene un formato válido.';
        } else {
            $formato = $matches[1]; // jpeg | png | webp
            $binario = base64_decode($matches[2], true);
            if ($binario === false) {
                $erroresDatos[] = 'La imagen está dañada.';
            } elseif (strlen($binario) > PERFIL_FOTO_BYTES_MAX) {
                $erroresDatos[] = 'La imagen supera el tamaño máximo permitido (2 MB).';
            } else {
                $extension = ($formato === 'jpeg') ? 'jpg' : $formato;
                $nombreArchivo = 'user_' . $idUsuarioActual . '.' . $extension;
                $rutaDestino = $rutaImagenesPerfiles . DIRECTORY_SEPARATOR . $nombreArchivo;

                // Eliminar foto anterior si era otro archivo (cambio de extensión, etc.)
                if (!empty($usuarioActual['foto_perfil']) && $usuarioActual['foto_perfil'] !== $nombreArchivo) {
                    $rutaAnterior = $rutaImagenesPerfiles . DIRECTORY_SEPARATOR . $usuarioActual['foto_perfil'];
                    if (is_file($rutaAnterior)) {
                        @unlink($rutaAnterior);
                    }
                }

                if (file_put_contents($rutaDestino, $binario) === false) {
                    $erroresDatos[] = 'No se pudo guardar la imagen en el servidor.';
                } else {
                    try {
                        $stmt = $conexion->prepare(
                            'UPDATE usuarios SET foto_perfil = :foto WHERE id_usuario = :id'
                        );
                        $stmt->execute([':foto' => $nombreArchivo, ':id' => $idUsuarioActual]);
                        $usuarioActual['foto_perfil'] = $nombreArchivo;
                        $exitoDatos = 'Foto de perfil actualizada.';
                    } catch (PDOException $e) {
                        error_log('Error al guardar foto_perfil en BD: ' . $e->getMessage());
                        $erroresDatos[] = 'No se pudo registrar la foto en la base de datos.';
                        @unlink($rutaDestino);
                    }
                }
            }
        }
    }
}

// ---------------------------------------------------------
// POST: enviar_codigo_password (paso 1 del flujo de cambio)
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'enviar_codigo_password') {
    if (!validarTokenCSRF($_POST['csrf_token'] ?? null)) {
        $erroresPassword[] = 'La sesión expiró.';
    } elseif (empty($usuarioActual['correo'])) {
        $erroresPassword[] = 'Tu cuenta no tiene un correo registrado. Contacta al administrador para actualizarlo antes de cambiar la contraseña.';
    } else {
        try {
            $codigo = generarCodigoRecuperacion($conexion, $idUsuarioActual);
            enviarCorreoCodigo($usuarioActual['correo'], $usuarioActual['nombre'], $codigo);
            $_SESSION['perfil_id_usuario'] = $idUsuarioActual;
            unset($_SESSION['perfil_codigo_valido'], $_SESSION['perfil_codigo_valido_en']);
            $hayCodigoValidadoPassword = false;
            $exitoPassword = 'Te enviamos un código de 6 dígitos a tu correo. Revisa tu bandeja (expira en 5 minutos).';
        } catch (Throwable $e) {
            error_log('Error al enviar código de password desde perfil: ' . $e->getMessage());
            $erroresPassword[] = 'No se pudo enviar el código. Intenta nuevamente.';
        }
    }
}

// ---------------------------------------------------------
// POST: verificar_codigo_password (paso 2 del flujo de cambio)
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'verificar_codigo_password') {
    if (!validarTokenCSRF($_POST['csrf_token'] ?? null)) {
        $erroresPassword[] = 'La sesión expiró.';
    } elseif (!isset($_SESSION['perfil_id_usuario']) || (int) $_SESSION['perfil_id_usuario'] !== $idUsuarioActual) {
        $erroresPassword[] = 'Primero debes solicitar un código nuevo.';
    } else {
        $codigoIngresado = trim($_POST['codigo'] ?? '');
        if ($codigoIngresado === '') {
            $erroresPassword[] = 'Ingresa el código de 6 dígitos.';
        } elseif (verificarCodigoRecuperacion($conexion, $idUsuarioActual, $codigoIngresado)) {
            $_SESSION['perfil_codigo_valido']    = $codigoIngresado;
            $_SESSION['perfil_codigo_valido_en'] = time();
            $hayCodigoValidadoPassword = true;
        } else {
            $erroresPassword[] = 'El código no es válido o ya expiró. Solicita uno nuevo.';
            unset($_SESSION['perfil_codigo_valido'], $_SESSION['perfil_codigo_valido_en']);
            $hayCodigoValidadoPassword = false;
        }
    }
}

// ---------------------------------------------------------
// POST: cambiar_password (paso 3 del flujo de cambio)
// ---------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'cambiar_password') {
    if (!validarTokenCSRF($_POST['csrf_token'] ?? null)) {
        $erroresPassword[] = 'La sesión expiró.';
    } elseif (!$hayCodigoValidadoPassword) {
        $erroresPassword[] = 'Tu código dejó de ser válido o expiró. Solicita uno nuevo.';
    } else {
        $nuevaPassword     = $_POST['nueva_password'] ?? '';
        $confirmarPassword = $_POST['confirmar_password'] ?? '';

        if ($nuevaPassword === '' || $confirmarPassword === '') {
            $erroresPassword[] = 'Completa ambos campos.';
        } elseif ($erroresPol = validarPoliticaPassword($nuevaPassword)) {
            $erroresPassword[] = 'La contraseña debe ' . implode(', ', $erroresPol) . '.';
        } elseif ($nuevaPassword !== $confirmarPassword) {
            $erroresPassword[] = 'Las contraseñas no coinciden.';
        } else {
            try {
                $stmt = $conexion->prepare(
                    'SELECT password_hash FROM usuarios WHERE id_usuario = :id'
                );
                $stmt->execute([':id' => $idUsuarioActual]);
                $hashActual = $stmt->fetchColumn();

                if ($hashActual !== false && password_verify($nuevaPassword, $hashActual)) {
                    $erroresPassword[] = 'La nueva contraseña no puede ser igual a la actual.';
                } elseif (!validarCodigoRecuperacion(
                    $conexion,
                    $idUsuarioActual,
                    $_SESSION['perfil_codigo_valido']
                )) {
                    $erroresPassword[] = 'Tu código expiró mientras escribías. Solicita uno nuevo.';
                    unset($_SESSION['perfil_codigo_valido'], $_SESSION['perfil_codigo_valido_en'], $_SESSION['perfil_id_usuario']);
                    $hayCodigoValidadoPassword = false;
                } else {
                    $passwordHash = password_hash($nuevaPassword, PASSWORD_BCRYPT);
                    $stmt = $conexion->prepare(
                        'UPDATE usuarios SET password_hash = :password_hash WHERE id_usuario = :id'
                    );
                    $stmt->execute([
                        ':password_hash' => $passwordHash,
                        ':id'             => $idUsuarioActual,
                    ]);
                    unset($_SESSION['perfil_codigo_valido'], $_SESSION['perfil_codigo_valido_en'], $_SESSION['perfil_id_usuario']);
                    $hayCodigoValidadoPassword = false;
                    $exitoPassword = 'Tu contraseña fue actualizada correctamente.';
                }
            } catch (PDOException $e) {
                error_log('Error al cambiar contraseña desde perfil: ' . $e->getMessage());
                $erroresPassword[] = 'No se pudo actualizar la contraseña. Intenta nuevamente.';
            }
        }
    }
}

// ---------------------------------------------------------
// Render
// ---------------------------------------------------------
$seccionActiva = 'perfil';

// CSS y JS extra: Cropper.js para el recorte de la foto
// Sin 'defer' para garantizar que Cropper esté cargado antes que el script inline del perfil.
$extraHead = '<link rel="stylesheet" href="../js/cropper.min.css">' . "\n"
    . '<script src="../js/cropper.min.js"></script>' . "\n";

$fotoPerfilUrl = null;
if (!empty($usuarioActual['foto_perfil'])) {
    $rutaFisicaFoto = $rutaImagenesPerfiles . DIRECTORY_SEPARATOR . $usuarioActual['foto_perfil'];
    if (is_file($rutaFisicaFoto)) {
        $fotoPerfilUrl = '../imagenes/perfiles/' . $usuarioActual['foto_perfil'];
    }
}

require_once __DIR__ . '/parciales/recep_header.php';
?>

<section class="seccion-panel">
    <h2>Mi perfil</h2>

    <?php if ($exitoDatos !== ''): ?>
        <div class="mensaje-exito" role="status"><?= htmlspecialchars($exitoDatos, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if ($exitoPassword !== ''): ?>
        <div class="mensaje-exito" role="status"><?= htmlspecialchars($exitoPassword, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <!-- Tarjeta principal: avatar + datos de solo lectura -->
    <div class="tarjeta-perfil tarjeta-perfil--resumen">
        <div class="tarjeta-perfil__avatar-grande">
            <?php if ($fotoPerfilUrl !== null): ?>
                <img src="<?= htmlspecialchars($fotoPerfilUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Foto de perfil" class="tarjeta-perfil__avatar-img" id="foto-preview">
            <?php else: ?>
                <span class="tarjeta-perfil__avatar-inicial" id="foto-preview" aria-hidden="true"><?= htmlspecialchars($inicialesUsuario, ENT_QUOTES, 'UTF-8') ?></span>
            <?php endif; ?>
        </div>
        <div class="tarjeta-perfil__resumen-datos">
            <h3><?= htmlspecialchars($usuarioActual['nombre'] . ' ' . $usuarioActual['apellido'], ENT_QUOTES, 'UTF-8') ?></h3>
            <p class="tarjeta-perfil__linea"><strong>Usuario:</strong> <?= htmlspecialchars($usuarioActual['usuario'], ENT_QUOTES, 'UTF-8') ?></p>
            <p class="tarjeta-perfil__linea"><strong>Correo:</strong> <?= htmlspecialchars($usuarioActual['correo'] ?? '(sin correo)', ENT_QUOTES, 'UTF-8') ?></p>
            <p class="tarjeta-perfil__linea"><strong>Rol:</strong> <?= htmlspecialchars(ucfirst($usuarioActual['nombre_rol']), ENT_QUOTES, 'UTF-8') ?></p>
            <p class="tarjeta-perfil__linea tarjeta-perfil__linea--suave">
                <strong>Cuenta creada:</strong> <?= htmlspecialchars(date('d/m/Y H:i', strtotime($usuarioActual['fecha_creacion'])), ENT_QUOTES, 'UTF-8') ?>
            </p>
        </div>
    </div>

    <!-- Sección: Nombre y apellido -->
    <div class="tarjeta-perfil">
        <h3>Nombre y apellido</h3>
        <?php if (!empty($erroresDatos) && (($_POST['accion'] ?? '') === 'actualizar_nombre')): ?>
            <div class="mensaje-error">
                <ul>
                    <?php foreach ($erroresDatos as $error): ?>
                        <li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        <form method="POST" class="form-perfil js-bloquear-envio">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="accion" value="actualizar_nombre">
            <div class="campo">
                <label for="perfil-nombre">Nombre</label>
                <input type="text" id="perfil-nombre" name="nombre" maxlength="60"
                       value="<?= htmlspecialchars($usuarioActual['nombre'], ENT_QUOTES, 'UTF-8') ?>" required autocomplete="given-name">
            </div>
            <div class="campo">
                <label for="perfil-apellido">Apellido</label>
                <input type="text" id="perfil-apellido" name="apellido" maxlength="60"
                       value="<?= htmlspecialchars($usuarioActual['apellido'], ENT_QUOTES, 'UTF-8') ?>" required autocomplete="family-name">
            </div>
            <button type="submit" class="btn">Guardar nombre</button>
        </form>
    </div>

    <!-- Sección: Usuario -->
    <div class="tarjeta-perfil">
        <h3>Nombre de usuario</h3>
        <?php if (!empty($erroresDatos) && (($_POST['accion'] ?? '') === 'actualizar_usuario')): ?>
            <div class="mensaje-error">
                <ul>
                    <?php foreach ($erroresDatos as $error): ?>
                        <li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        <form method="POST" class="form-perfil" id="form-usuario">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="accion" value="actualizar_usuario">
            <div class="campo">
                <label for="perfil-usuario">Usuario</label>
                <input type="text" id="perfil-usuario" name="usuario" minlength="3" maxlength="40"
                       pattern="[A-Za-z0-9._\-]+"
                       value="<?= htmlspecialchars($usuarioActual['usuario'], ENT_QUOTES, 'UTF-8') ?>"
                       required autocomplete="username">
                <p class="campo__ayuda">Entre 3 y 40 caracteres. Solo letras, números, guion bajo, guion medio y punto.</p>
                <p class="usuario-indicador" id="usuario-indicador" aria-live="polite"></p>
            </div>
            <button type="submit" class="btn">Guardar usuario</button>
        </form>
    </div>

    <!-- Sección: Foto de perfil -->
    <div class="tarjeta-perfil">
        <h3>Foto de perfil</h3>
        <?php if (!empty($erroresDatos) && (($_POST['accion'] ?? '') === 'subir_foto')): ?>
            <div class="mensaje-error">
                <ul>
                    <?php foreach ($erroresDatos as $error): ?>
                        <li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        <form method="POST" class="form-perfil" id="form-foto" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="accion" value="subir_foto">
            <input type="hidden" name="crop_data" id="crop_data">

            <div class="campo">
                <label for="input-foto">Selecciona una imagen (JPG, PNG o WebP; máx. 2 MB)</label>
                <input type="file" id="input-foto" accept="image/jpeg,image/png,image/webp">
            </div>

            <p class="campo__ayuda">Podrás recortar la imagen (formato cuadrado) antes de guardarla.</p>

            <button type="button" class="btn" id="btn-abrir-cropper" hidden>Recortar imagen</button>
        </form>
    </div>

    <!-- Modal de recorte -->
    <div id="modal-cropper" class="modal-cropper" hidden role="dialog" aria-modal="true" aria-labelledby="modal-cropper-titulo">
        <div class="modal-cropper__contenido">
            <h3 id="modal-cropper-titulo">Recorta tu foto</h3>
            <p class="campo__ayuda">Ajusta el recorte cuadrado y luego pulsa "Aplicar".</p>
            <div class="modal-cropper__contenedor-img">
                <img id="cropper-img" alt="" src="">
            </div>
            <div class="modal-cropper__acciones">
                <button type="button" class="btn btn-secundario" id="btn-cancelar-crop">Cancelar</button>
                <button type="button" class="btn" id="btn-aplicar-crop">Aplicar recorte</button>
            </div>
        </div>
    </div>

    <!-- Sección: Cambiar contraseña -->
    <div class="tarjeta-perfil">
        <h3>Cambiar contraseña</h3>
        <p class="campo__ayuda">Te enviaremos un código de 6 dígitos a tu correo para confirmar el cambio.</p>

        <?php if (!empty($erroresPassword)): ?>
            <div class="mensaje-error">
                <ul>
                    <?php foreach ($erroresPassword as $error): ?>
                        <li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if (!$hayCodigoValidadoPassword): ?>
            <!-- Paso 1: solicitar código (o paso 2: ingresarlo) -->
            <?php if (!isset($_SESSION['perfil_id_usuario']) || (int) $_SESSION['perfil_id_usuario'] !== $idUsuarioActual): ?>
                <form method="POST" class="form-perfil js-bloquear-envio">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="accion" value="enviar_codigo_password">
                    <button type="submit" class="btn">Enviar código a mi correo</button>
                </form>
            <?php else: ?>
                <form method="POST" class="form-perfil">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="accion" value="verificar_codigo_password">
                    <div class="campo">
                        <label for="perfil-codigo">Código de 6 dígitos</label>
                        <input type="text" id="perfil-codigo" name="codigo" inputmode="numeric"
                               pattern="[0-9]{6}" maxlength="6" minlength="6" required
                               autocomplete="one-time-code">
                    </div>
                    <button type="submit" class="btn">Verificar código</button>
                </form>
            <?php endif; ?>
        <?php else: ?>
            <!-- Paso 3: nueva contraseña -->
            <form method="POST" class="form-perfil" id="form-cambiar-password">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="accion" value="cambiar_password">

                <div class="campo">
                    <label for="perfil-nueva-password">Nueva contraseña</label>
                    <input type="password" id="perfil-nueva-password" name="nueva_password"
                           minlength="8" required autocomplete="new-password">
                    <ul class="lista-requisitos-password" id="lista-requisitos-perfil">
                        <li data-regla="longitud">Mínimo 8 caracteres</li>
                        <li data-regla="minuscula">Incluye una letra minúscula</li>
                        <li data-regla="mayuscula">Incluye una letra mayúscula</li>
                        <li data-regla="especial">Incluye un carácter especial (!@#$%^&*()[]{};:,.<>?…)</li>
                        <li data-regla="espacios">Sin espacios en blanco</li>
                    </ul>
                </div>
                <div class="campo">
                    <label for="perfil-confirmar-password">Confirmar contraseña</label>
                    <input type="password" id="perfil-confirmar-password" name="confirmar_password"
                           minlength="8" required autocomplete="new-password">
                </div>
                <button type="submit" class="btn">Actualizar contraseña</button>
            </form>
        <?php endif; ?>
    </div>
</section>

<?php
$scriptsExtra = <<<'JS'
// Anti-doble-envío para todos los forms del perfil
document.querySelectorAll('form.js-bloquear-envio').forEach(function (formulario) {
    formulario.addEventListener('submit', function () {
        var boton = formulario.querySelector('button[type="submit"]');
        if (boton) {
            boton.disabled = true;
            boton.textContent = 'Procesando…';
        }
    });
});

// Verificación AJAX de disponibilidad de usuario
(function () {
    var input = document.getElementById('perfil-usuario');
    var indicador = document.getElementById('usuario-indicador');
    if (!input || !indicador) return;

    var valorOriginal = input.value;
    var secuencia = 0;
    var timer = null;

    function setIndicador(texto, clase) {
        indicador.textContent = texto;
        indicador.className = 'usuario-indicador' + (clase ? ' ' + clase : '');
    }

    input.addEventListener('input', function () {
        clearTimeout(timer);
        var valor = input.value.trim();
        if (valor === '' || valor.length < 3 || valor.length > 40) {
            setIndicador('');
            return;
        }
        if (valor === valorOriginal) {
            setIndicador('Tu usuario actual', 'usuario-indicador--ok');
            return;
        }
        setIndicador('Verificando…', 'usuario-indicador--verificando');
        timer = setTimeout(function () {
            var miSec = ++secuencia;
            fetch('ajax/verificar_usuario.php?usuario=' + encodeURIComponent(valor) + '&id_usuario=' + ID_USUARIO_ACTUAL, { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (datos) {
                    if (miSec !== secuencia) return;
                    if (datos && datos.disponible === true) {
                        setIndicador('✓ Disponible', 'usuario-indicador--ok');
                    } else {
                        setIndicador('✗ Usuario no disponible', 'usuario-indicador--error');
                    }
                })
                .catch(function () {
                    if (miSec !== secuencia) return;
                    setIndicador('No se pudo verificar', 'usuario-indicador--error');
                });
        }, 350);
    });
})();

// Cropper.js para la foto de perfil
(function () {
    var inputFile   = document.getElementById('input-foto');
    var modal       = document.getElementById('modal-cropper');
    var img         = document.getElementById('cropper-img');
    var btnAbrir    = document.getElementById('btn-abrir-cropper');
    var btnCancelar = document.getElementById('btn-cancelar-crop');
    var btnAplicar  = document.getElementById('btn-aplicar-crop');
    var formFoto    = document.getElementById('form-foto');
    var hiddenData  = document.getElementById('crop_data');
    var preview     = document.getElementById('foto-preview');

    if (!inputFile || !modal || !img || !btnCancelar || !btnAplicar || !formFoto || !hiddenData) return;

    var cropper = null;
    var TAMANO_MAX = 2 * 1024 * 1024;

    function destruirCropper() {
        if (cropper) { cropper.destroy(); cropper = null; }
    }
    function cerrarModal() {
        destruirCropper();
        modal.hidden = true;
        img.src = '';
    }

    function mostrarMensaje(texto, tipo) {
        var cont = formFoto.parentNode.querySelector('.mensaje-error, .mensaje-info-crop');
        if (!cont) {
            cont = document.createElement('div');
            cont.className = tipo === 'error' ? 'mensaje-error' : 'mensaje-info-crop mensaje-info';
            formFoto.insertBefore(cont, formFoto.firstChild);
        }
        cont.className = (tipo === 'error' ? 'mensaje-error' : 'mensaje-info');
        cont.textContent = texto;
    }

    function cargarYRecortar(file) {
        if (!/^image\/(jpeg|png|webp)$/.test(file.type)) {
            mostrarMensaje('Tipo de imagen no soportado. Usa JPG, PNG o WebP.', 'error');
            inputFile.value = '';
            return;
        }
        if (file.size > TAMANO_MAX) {
            mostrarMensaje('La imagen es demasiado grande (máx. 2 MB).', 'error');
            inputFile.value = '';
            return;
        }
        var reader = new FileReader();
        reader.onload = function (ev) {
            img.src = ev.target.result;
            modal.hidden = false;
            destruirCropper();
            cropper = new Cropper(img, {
                aspectRatio: 1,
                viewMode: 1,
                autoCropArea: 0.9,
                responsive: true,
                restore: true,
                background: false,
                movable: true,
                zoomable: true,
                rotatable: false,
                scalable: false
            });
        };
        reader.readAsDataURL(file);
    }

    inputFile.addEventListener('change', function () {
        var file = inputFile.files && inputFile.files[0];
        if (!file) return;
        cargarYRecortar(file);
    });

    if (btnAbrir) {
        btnAbrir.addEventListener('click', function () {
            var file = inputFile.files && inputFile.files[0];
            if (file) cargarYRecortar(file);
        });
    }

    btnCancelar.addEventListener('click', cerrarModal);

    btnAplicar.addEventListener('click', function () {
        if (!cropper) return;
        var canvas = cropper.getCroppedCanvas({
            width: 400,
            height: 400,
            imageSmoothingEnabled: true,
            imageSmoothingQuality: 'high'
        });
        if (!canvas) return;
        var dataUrl = canvas.toDataURL('image/jpeg', 0.9);
        hiddenData.value = dataUrl;
        if (preview && preview.tagName === 'IMG') {
            preview.src = dataUrl;
        }
        cerrarModal();
        // Mostrar botón "Aplicar" final como submit
        var submit = document.createElement('button');
        submit.type = 'submit';
        submit.className = 'btn';
        submit.textContent = 'Guardar foto';
        // Reemplazar el botón "Recortar" por el submit
        if (btnAbrir && btnAbrir.parentNode) {
            btnAbrir.hidden = true;
        }
        if (!formFoto.querySelector('button[type="submit"]')) {
            formFoto.appendChild(submit);
        }
    });

    // Validación en tiempo real de la política de contraseñas (perfil)
    var requisitosPassword = [
        { regla: 'longitud',  cumple: function (v) { return v.length >= 8; } },
        { regla: 'minuscula', cumple: function (v) { return /[a-z]/.test(v); } },
        { regla: 'mayuscula', cumple: function (v) { return /[A-Z]/.test(v); } },
        { regla: 'especial',  cumple: function (v) { return /[!@#$%^&*()_+\-=\[\]{};:,.<>?]/.test(v); } },
        { regla: 'espacios',  cumple: function (v) { return !/\s/.test(v); } }
    ];
    function actualizarRequisitosPassword(valor) {
        requisitosPassword.forEach(function (r) {
            var item = document.querySelector('#lista-requisitos-perfil li[data-regla="' + r.regla + '"]');
            if (item) item.classList.toggle('cumple', r.cumple(valor));
        });
    }
    var campoNueva = document.getElementById('perfil-nueva-password');
    if (campoNueva) {
        campoNueva.addEventListener('input', function () {
            actualizarRequisitosPassword(campoNueva.value);
        });
        actualizarRequisitosPassword(campoNueva.value);
    }
})();
JS;

// Inyectar el id de usuario en el JS para la llamada AJAX
$scriptsExtra = str_replace(
    'ID_USUARIO_ACTUAL',
    (string) $idUsuarioActual,
    $scriptsExtra
);

require_once __DIR__ . '/parciales/recep_footer.php';
