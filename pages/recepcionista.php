<?php
require_once __DIR__ . '/../config/sesion.php';
verificarSesion(['recepcionista']);

$csrfToken = generarTokenCSRF();

require_once __DIR__ . '/../config/conexion.php'; // expone $conexion (PDO)

$errores = [];
$exito = '';

$nombre = '';
$apellido = '';
$cedula = '';
$fecha_nacimiento = '';
$sexo = '';
$telefono = '';
$direccion = '';
$correo = '';

// -----------------------------------------------------------
// Procesamiento del registro de paciente nuevo
// -----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // 0) Validación CSRF (RNF-08)
    if (!validarTokenCSRF($_POST['csrf_token'] ?? null)) {
        $errores[] = 'La sesión expiró o el formulario no es válido. Recarga la página e inténtalo de nuevo.';
    } else {
        // 1) Recogemos y limpiamos los datos
        $nombre          = trim($_POST['nombre'] ?? '');
        $apellido        = trim($_POST['apellido'] ?? '');
        $cedula          = trim($_POST['cedula'] ?? '');
        $fecha_nacimiento = trim($_POST['fecha_nacimiento'] ?? '');
        $sexo            = $_POST['sexo'] ?? '';
        $telefono        = trim($_POST['telefono'] ?? '');
        $direccion       = trim($_POST['direccion'] ?? '');
        $correo          = trim($_POST['correo'] ?? '');

        // 2) Campos obligatorios
        if ($nombre === '') {
            $errores[] = 'El nombre es obligatorio.';
        }
        if ($apellido === '') {
            $errores[] = 'El apellido es obligatorio.';
        }
        if ($cedula === '') {
            $errores[] = 'La cédula es obligatoria.';
        } elseif (!preg_match('/^(?:[A-Z]{1,2}-)?\d{1,4}-\d{1,4}(?:-\d{1,4})?$/', $cedula)) {
            $errores[] = 'La cédula no tiene un formato válido (ejemplo: 8-123-456).';
        }
        if ($fecha_nacimiento === '') {
            $errores[] = 'La fecha de nacimiento es obligatoria.';
        } else {
            $fecha = DateTime::createFromFormat('Y-m-d', $fecha_nacimiento);
            $erroresFecha = DateTime::getLastErrors();
            if (!$fecha || ($erroresFecha !== false && ($erroresFecha['warning_count'] + $erroresFecha['error_count'] > 0))) {
                $errores[] = 'La fecha de nacimiento no es válida.';
            }
        }
        if ($sexo === '') {
            $errores[] = 'El sexo es obligatorio.';
        } elseif (!in_array($sexo, ['M', 'F', 'Otro'], true)) {
            $errores[] = 'El sexo seleccionado no es válido.';
        }
        if ($correo !== '' && !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            $errores[] = 'El correo no tiene un formato válido.';
        }

        // 3) Cédula duplicada (validación previa)
        if (!$errores) {
            $stmt = $conexion->prepare('SELECT COUNT(*) FROM pacientes WHERE cedula = :cedula');
            $stmt->execute([':cedula' => $cedula]);
            if ($stmt->fetchColumn() > 0) {
                $errores[] = 'La cédula ya está registrada.';
            }
        }

        // 4) Inserción con sentencia preparada
        if (!$errores) {
            try {
                $stmt = $conexion->prepare(
                    'INSERT INTO pacientes (nombre, apellido, cedula, fecha_nacimiento, sexo, telefono, direccion, correo)
                     VALUES (:nombre, :apellido, :cedula, :fecha_nacimiento, :sexo, :telefono, :direccion, :correo)'
                );
                $stmt->execute([
                    ':nombre'           => $nombre,
                    ':apellido'         => $apellido,
                    ':cedula'           => $cedula,
                    ':fecha_nacimiento' => $fecha_nacimiento,
                    ':sexo'             => $sexo,
                    ':telefono'         => $telefono !== '' ? $telefono : null,
                    ':direccion'        => $direccion !== '' ? $direccion : null,
                    ':correo'           => $correo !== '' ? $correo : null,
                ]);

                $exito = 'Paciente registrado con éxito.';

                // Limpiamos el formulario tras el registro exitoso
                $nombre = '';
                $apellido = '';
                $cedula = '';
                $fecha_nacimiento = '';
                $sexo = '';
                $telefono = '';
                $direccion = '';
                $correo = '';
            } catch (PDOException $e) {
                // Condición de carrera: otro registro llegó con la misma cédula
                // justo entre la validación previa y este INSERT (SQLSTATE 23000)
                if ($e->getCode() === '23000') {
                    $errores[] = 'La cédula ya está registrada.';
                } else {
                    // El detalle real queda en el log del servidor, nunca en pantalla
                    error_log('Error al registrar paciente: ' . $e->getMessage());
                    $errores[] = 'No se pudo registrar el paciente. Intenta nuevamente.';
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Panel recepcionista - Hospital Raúl Dávila Mena</title>
    <link rel="stylesheet" href="../css/estilo.css">
</head>
<body>
    <div class="barra-superior">
        <span class="marca">Hospital Raúl Dávila Mena</span>
        <div>
            <span><?= htmlspecialchars($_SESSION['nombre'], ENT_QUOTES, 'UTF-8') ?> · Recepcionista</span>
            &nbsp;·&nbsp;<a href="logout.php">Cerrar sesión</a>
        </div>
    </div>

    <div class="contenido-panel">
        <h2>Registro de paciente nuevo</h2>

        <?php if ($exito !== ''): ?>
            <div class="mensaje-exito"><?= htmlspecialchars($exito, ENT_QUOTES, 'UTF-8') ?></div>
        <?php elseif (count($errores) > 0): ?>
            <div class="mensaje-error">
                <ul>
                    <?php foreach ($errores as $error): ?>
                        <li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="POST" action="recepcionista.php" id="formulario-paciente">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

            <div class="campo">
                <label for="nombre">Nombre</label>
                <input type="text" id="nombre" name="nombre" value="<?= htmlspecialchars($nombre, ENT_QUOTES, 'UTF-8') ?>" autocomplete="given-name">
            </div>

            <div class="campo">
                <label for="apellido">Apellido</label>
                <input type="text" id="apellido" name="apellido" value="<?= htmlspecialchars($apellido, ENT_QUOTES, 'UTF-8') ?>" autocomplete="family-name">
            </div>

            <div class="campo">
                <label for="cedula">Cédula</label>
                <input type="text" id="cedula" name="cedula" value="<?= htmlspecialchars($cedula, ENT_QUOTES, 'UTF-8') ?>" required>
            </div>

            <div class="campo">
                <label for="fecha_nacimiento">Fecha de nacimiento</label>
                <input type="date" id="fecha_nacimiento" name="fecha_nacimiento" value="<?= htmlspecialchars($fecha_nacimiento, ENT_QUOTES, 'UTF-8') ?>">
            </div>

            <div class="campo">
                <label for="sexo">Sexo</label>
                <select id="sexo" name="sexo">
                    <option value="" disabled <?= $sexo === '' ? 'selected' : '' ?>>Seleccione…</option>
                    <option value="M" <?= $sexo === 'M' ? 'selected' : '' ?>>Masculino</option>
                    <option value="F" <?= $sexo === 'F' ? 'selected' : '' ?>>Femenino</option>
                    <option value="Otro" <?= $sexo === 'Otro' ? 'selected' : '' ?>>Otro</option>
                </select>
            </div>

            <div class="campo">
                <label for="telefono">Teléfono</label>
                <input type="tel" id="telefono" name="telefono" value="<?= htmlspecialchars($telefono, ENT_QUOTES, 'UTF-8') ?>" autocomplete="tel">
            </div>

            <div class="campo">
                <label for="direccion">Dirección</label>
                <input type="text" id="direccion" name="direccion" value="<?= htmlspecialchars($direccion, ENT_QUOTES, 'UTF-8') ?>" autocomplete="street-address">
            </div>

            <div class="campo">
                <label for="correo">Correo</label>
                <input type="email" id="correo" name="correo" value="<?= htmlspecialchars($correo, ENT_QUOTES, 'UTF-8') ?>" autocomplete="email">
            </div>

            <button type="submit" class="btn" id="btn-registrar-paciente">Registrar paciente</button>
        </form>
    </div>

    <script>
    // Evita el doble envío: un segundo clic sobre el botón no debe crear
    // dos pacientes. Deshabilitamos el botón en cuanto se dispara el submit.
    document.getElementById('formulario-paciente').addEventListener('submit', function () {
        var boton = document.getElementById('btn-registrar-paciente');
        boton.disabled = true;
        boton.textContent = 'Registrando…';
    });
    </script>
</body>
</html>