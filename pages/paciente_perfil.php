<?php
/**
 * pages/paciente_perfil.php
 *
 * Perfil del paciente: ver y editar teléfono, dirección y correo.
 * El nombre, apellido y cédula NO se editan aquí (son datos
 * registrados por recepción; para cambiarlos debe ir presencialmente).
 */

$seccionActiva = 'perfil';

require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/parciales/paciente_funciones.php';
verificarSesionPaciente();

$idPaciente = (int) $_SESSION['id_paciente'];

$errores = [];
$exito   = '';

// POST: actualizar perfil
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'actualizar_perfil') {
    if (!validarTokenCSRF($_POST['csrf_token'] ?? null)) {
        $errores[] = 'La sesión expiró. Recarga la página.';
    } else {
        $telefono  = trim($_POST['telefono'] ?? '');
        $direccion = trim($_POST['direccion'] ?? '');
        $correo    = trim($_POST['correo'] ?? '');

        if ($telefono !== '' && mb_strlen($telefono) > 20) {
            $errores[] = 'El teléfono no puede tener más de 20 caracteres.';
        }
        if ($direccion !== '' && mb_strlen($direccion) > 150) {
            $errores[] = 'La dirección no puede tener más de 150 caracteres.';
        }
        if ($correo !== '' && !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            $errores[] = 'El correo no tiene un formato válido.';
        }
        if ($correo !== '' && mb_strlen($correo) > 100) {
            $errores[] = 'El correo no puede tener más de 100 caracteres.';
        }

        if (!$errores) {
            try {
                $stmt = $conexion->prepare(
                    'UPDATE pacientes SET telefono = :telefono, direccion = :direccion, correo = :correo
                     WHERE id_paciente = :id'
                );
                $stmt->execute([
                    ':telefono'  => $telefono  !== '' ? $telefono  : null,
                    ':direccion' => $direccion !== '' ? $direccion : null,
                    ':correo'    => $correo    !== '' ? $correo    : null,
                    ':id'        => $idPaciente,
                ]);
                $exito = 'Datos actualizados correctamente.';
            } catch (PDOException $e) {
                error_log('Error al actualizar perfil del paciente: ' . $e->getMessage());
                $errores[] = 'No se pudieron guardar los cambios.';
            }
        }
    }
}

$paciente = obtenerPacienteActual($conexion);
$csrfToken = generarTokenCSRF();

require_once __DIR__ . '/parciales/paciente_header.php';
?>

<section class="seccion-panel">
    <h2>Mi perfil</h2>

    <?php if ($exito !== ''): ?>
        <div class="mensaje-exito"><?= htmlspecialchars($exito, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if (!empty($errores)): ?>
        <div class="mensaje-error">
            <ul>
                <?php foreach ($errores as $error): ?>
                    <li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <!-- Tarjeta: datos no editables -->
    <div class="tarjeta-paciente">
        <h3>Datos personales</h3>
        <p><strong>Nombre:</strong> <?= htmlspecialchars($paciente['nombre'] . ' ' . $paciente['apellido'], ENT_QUOTES, 'UTF-8') ?></p>
        <p><strong>Cédula:</strong> <?= htmlspecialchars($paciente['cedula'], ENT_QUOTES, 'UTF-8') ?></p>
        <?php if (!empty($paciente['fecha_nacimiento'])): ?>
            <p><strong>Fecha de nacimiento:</strong> <?= htmlspecialchars(date('d/m/Y', strtotime($paciente['fecha_nacimiento'])), ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
        <?php if (!empty($paciente['sexo'])): ?>
            <p><strong>Sexo:</strong> <?= htmlspecialchars($paciente['sexo'], ENT_QUOTES, 'UTF-8') === 'M' ? 'Masculino' : ($paciente['sexo'] === 'F' ? 'Femenino' : 'Otro') ?></p>
        <?php endif; ?>
        <p class="campo__ayuda">Estos datos solo pueden modificarse presencialmente en recepción.</p>
    </div>

    <!-- Tarjeta: formulario editable -->
    <div class="tarjeta-paciente">
        <h3>Datos de contacto</h3>
        <form method="POST" class="js-bloquear-envio">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="accion" value="actualizar_perfil">

            <div class="campo">
                <label for="perfil-telefono">Teléfono</label>
                <input type="tel" id="perfil-telefono" name="telefono" maxlength="20"
                       value="<?= htmlspecialchars($paciente['telefono'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                       autocomplete="tel">
            </div>
            <div class="campo">
                <label for="perfil-direccion">Dirección</label>
                <input type="text" id="perfil-direccion" name="direccion" maxlength="150"
                       value="<?= htmlspecialchars($paciente['direccion'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                       autocomplete="street-address">
            </div>
            <div class="campo">
                <label for="perfil-correo">Correo</label>
                <input type="email" id="perfil-correo" name="correo" maxlength="100"
                       value="<?= htmlspecialchars($paciente['correo'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                       autocomplete="email">
                <p class="campo__ayuda">Este es el correo al que enviaremos códigos de verificación.</p>
            </div>

            <button type="submit" class="btn">Guardar cambios</button>
        </form>
    </div>

    <!-- Acceso y seguridad -->
    <div class="tarjeta-paciente">
        <h3>Acceso y seguridad</h3>
        <p class="campo__ayuda">
            Tu cuenta se activa con tu cédula y datos personales. Si necesitas volver a activarla
            (por ejemplo, después de un tiempo sin usar), solo necesitas tu correo registrado.
        </p>
        <p>
            <a href="activar_cuenta_paciente.php?reiniciar=1" class="btn btn-paciente-secundario">Reactivar mi cuenta</a>
        </p>
    </div>
</section>

<?php
$scriptsExtra = <<<'JS'
document.querySelectorAll('form.js-bloquear-envio').forEach(function (formulario) {
    formulario.addEventListener('submit', function () {
        var boton = formulario.querySelector('button[type="submit"]');
        if (boton) {
            boton.disabled = true;
            boton.textContent = 'Guardando…';
        }
    });
});
JS;

require_once __DIR__ . '/parciales/paciente_footer.php';
