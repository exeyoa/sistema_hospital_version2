<?php
/**
 * pages/recepcionista_pacientes.php
 *
 * Subpágina del panel de recepcionista: SOLO registro de paciente
 * nuevo con cédula primero y lookup local en BD.
 *
 * Acciones POST que maneja:
 *   - accion=registrar_paciente (form completo, paciente NUEVO)
 *
 * El lookup de cédula YA EXISTENTE se hace vía AJAX
 * (pages/ajax/verificar_paciente.php); ver $scriptsExtra al final.
 */

$seccionActiva = 'pacientes';

// Cargar dependencias antes de cualquier uso de $conexion
require_once __DIR__ . '/../config/sesion.php';
verificarSesion(['recepcionista']);
$csrfToken = generarTokenCSRF();
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/parciales/recep_funciones.php';

// Variables del formulario (vacías en GET; rellenas en POST si hay errores)
$nombre = $apellido = $cedula = $fecha_nacimiento = $sexo = $telefono = $direccion = $correo = '';
$errores = [];
$exito   = '';

// Flash de PRG
if (!empty($_SESSION['flash_recepcion']) && ($_SESSION['flash_recepcion']['seccion'] ?? '') === 'pacientes') {
    $flash = $_SESSION['flash_recepcion'];
    unset($_SESSION['flash_recepcion']);
    $errores = $flash['errores'] ?? [];
    $exito   = $flash['exito'] ?? '';
}

// POST: procesar registro de paciente
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? 'registrar_paciente';
    if ($accion === 'registrar_paciente') {
        if (!validarTokenCSRF($_POST['csrf_token'] ?? null)) {
            $errores[] = 'La sesión expiró o el formulario no es válido. Recarga la página e inténtalo de nuevo.';
        } else {
            $nombre           = trim($_POST['nombre'] ?? '');
            $apellido         = trim($_POST['apellido'] ?? '');
            $cedula           = trim($_POST['cedula'] ?? '');
            $fecha_nacimiento = trim($_POST['fecha_nacimiento'] ?? '');
            $sexo             = $_POST['sexo'] ?? '';
            $telefono         = trim($_POST['telefono'] ?? '');
            $direccion        = trim($_POST['direccion'] ?? '');
            $correo           = trim($_POST['correo'] ?? '');

            if ($nombre === '')             { $errores[] = 'El nombre es obligatorio.'; }
            if ($apellido === '')           { $errores[] = 'El apellido es obligatorio.'; }
            if ($cedula === '')             { $errores[] = 'La cédula es obligatoria.'; }
            elseif (!preg_match('/^(?:[A-Z]{1,2}-)?\d{1,4}-\d{1,4}(?:-\d{1,4})?$/', $cedula)) {
                $errores[] = 'La cédula no tiene un formato válido (ejemplo: 8-123-456).';
            }
            if ($fecha_nacimiento === '')   { $errores[] = 'La fecha de nacimiento es obligatoria.'; }
            else {
                $fecha = DateTime::createFromFormat('Y-m-d', $fecha_nacimiento);
                $erroresFecha = DateTime::getLastErrors();
                if (!$fecha || ($erroresFecha !== false && ($erroresFecha['warning_count'] + $erroresFecha['error_count']) > 0)) {
                    $errores[] = 'La fecha de nacimiento no es válida.';
                }
            }
            if ($sexo === '')               { $errores[] = 'El sexo es obligatorio.'; }
            elseif (!in_array($sexo, ['M', 'F', 'Otro'], true)) {
                $errores[] = 'El sexo seleccionado no es válido.';
            }
            if ($correo !== '' && !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
                $errores[] = 'El correo no tiene un formato válido.';
            }

            if (!$errores) {
                $stmt = $conexion->prepare('SELECT COUNT(*) FROM pacientes WHERE cedula = :cedula');
                $stmt->execute([':cedula' => $cedula]);
                if ($stmt->fetchColumn() > 0) {
                    $errores[] = 'La cédula ya está registrada.';
                }
            }

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
                    $nombre = $apellido = $cedula = $fecha_nacimiento = $sexo = $telefono = $direccion = $correo = '';
                } catch (PDOException $e) {
                    if ($e->getCode() === '23000') {
                        $errores[] = 'La cédula ya está registrada.';
                    } else {
                        error_log('Error al registrar paciente: ' . $e->getMessage());
                        $errores[] = 'No se pudo registrar el paciente. Intenta nuevamente.';
                    }
                }
            }
        }
    }
}

require_once __DIR__ . '/parciales/recep_header.php';
?>

<section class="seccion-panel">
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

    <form method="POST" action="recepcionista_pacientes.php" id="formulario-paciente">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

        <div class="campo">
            <label>Cédula</label>
            <div class="cedula-panama" role="group" aria-label="Cédula panameña: provincia, tipo, tomo y partida">
                <select id="cedula_provincia" name="cedula_provincia" class="cedula-panama__select"
                        aria-label="Provincia (dos dígitos)" required>
                    <?php for ($p = 0; $p <= 12; $p++): ?>
                        <option value="<?= sprintf('%02d', $p) ?>"><?= sprintf('%02d', $p) ?></option>
                    <?php endfor; ?>
                </select>
                <select id="cedula_tipo" name="cedula_tipo" class="cedula-panama__select"
                        aria-label="Tipo de cédula" required>
                    <option value="00">00</option>
                    <option value="N">N</option>
                    <option value="E">E</option>
                    <option value="EC">EC</option>
                    <option value="PE">PE</option>
                    <option value="AV">AV</option>
                    <option value="PI">PI</option>
                </select>
                <span class="cedula-panama__sep" aria-hidden="true">-</span>
                <input type="text" id="cedula_tomo" name="cedula_tomo" class="cedula-panama__input"
                       maxlength="4" inputmode="numeric" pattern="[0-9]{1,4}"
                       placeholder="----" aria-label="Tomo (solo números)" autocomplete="off">
                <span class="cedula-panama__sep" aria-hidden="true">-</span>
                <input type="text" id="cedula_partida" name="cedula_partida" class="cedula-panama__input"
                       maxlength="4" inputmode="numeric" pattern="[0-9]{1,4}"
                       placeholder="----" aria-label="Partida (solo números)" autocomplete="off">
                <!-- Hidden que recibe la cédula combinada para el POST y para AJAX -->
                <input type="hidden" name="cedula" id="cedula_combinada" value="<?= htmlspecialchars($cedula, ENT_QUOTES, 'UTF-8') ?>">
            </div>
        </div>

        <button type="button" class="btn form-buscar__btn" id="btn-verificar-paciente">Verificar</button>

        <div id="resultado-verificacion" class="resultado-verificacion" role="status" aria-live="polite" hidden></div>

        <div id="resto-form-paciente" hidden>
            <div class="campo">
                <label for="nombre">Nombre</label>
                <input type="text" id="nombre" name="nombre" value="<?= htmlspecialchars($nombre, ENT_QUOTES, 'UTF-8') ?>" autocomplete="given-name">
            </div>
            <div class="campo">
                <label for="apellido">Apellido</label>
                <input type="text" id="apellido" name="apellido" value="<?= htmlspecialchars($apellido, ENT_QUOTES, 'UTF-8') ?>" autocomplete="family-name">
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
        </div>
    </form>
</section>

<?php
$scriptsExtra = <<<'JS'
// Anti-doble-envío para el formulario de registro de paciente
(function () {
    var form = document.getElementById('formulario-paciente');
    if (!form) { return; }
    form.addEventListener('submit', function () {
        var boton = document.getElementById('btn-registrar-paciente');
        if (boton) {
            boton.disabled = true;
            boton.textContent = 'Registrando…';
        }
    });
})();

// Lookup de paciente por cédula (formato panameño: provincia, tipo, tomo, partida)
(function () {
    var selProv   = document.getElementById('cedula_provincia');
    var selTipo   = document.getElementById('cedula_tipo');
    var inTomo    = document.getElementById('cedula_tomo');
    var inPart    = document.getElementById('cedula_partida');
    var oculta    = document.getElementById('cedula_combinada');
    var btnVerif  = document.getElementById('btn-verificar-paciente');
    var resultado = document.getElementById('resultado-verificacion');
    var restoForm = document.getElementById('resto-form-paciente');
    if (!selProv || !selTipo || !inTomo || !inPart || !oculta || !btnVerif || !resultado || !restoForm) { return; }

    // Orden de "foco" para auto-tab y backspace
    var campos = [selProv, selTipo, inTomo, inPart];

    function limpiarSoloDigitos(input) {
        var filtrado = input.value.replace(/[^0-9]/g, '');
        if (filtrado !== input.value) { input.value = filtrado; }
        return filtrado;
    }

    function normalizarProvincia(p) {
        // "00" → sin prefijo numérico
        if (p === '00') return '';
        // "01"–"09" → quitar el cero inicial (compatibilidad con datos
        // existentes en BD que no usan cero inicial: "1-763-120", etc.)
        if (p.length === 2 && p[0] === '0') return p[1];
        // "10"–"12" → dejar como está
        return p;
    }

    function combinar() {
        var prov = selProv.value;
        var tipo = selTipo.value;
        var prefijoNum = normalizarProvincia(prov);
        var prefijoLet = (tipo !== '00') ? tipo : '';
        var tomo = inTomo.value;
        var part = inPart.value;
        var combinada = '';
        if (tomo && part) {
            var prefijo = prefijoNum + prefijoLet;
            combinada = prefijo ? (prefijo + '-' + tomo + '-' + part) : '';
        }
        oculta.value = combinada;
        return combinada;
    }

    function camposPrevios(idx) {
        // Devuelve los campos en orden de foco anterior
        return campos.slice(0, idx);
    }
    function camposSiguientes(idx) {
        return campos.slice(idx + 1);
    }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
        });
    }
    function mostrar(tipo, contenidoHtml) {
        resultado.className = 'resultado-verificacion resultado-verificacion--' + tipo;
        resultado.innerHTML = contenidoHtml;
        resultado.hidden = false;
    }
    function ocultarTodo() {
        resultado.hidden = true;
        resultado.innerHTML = '';
        restoForm.hidden = true;
    }
    function limpiarNombre(s) { return (s || '').trim(); }

    function verificar() {
        var cedula = combinar();
        if (!cedula) {
            mostrar('error',
                '<strong>Cédula incompleta.</strong>' +
                '<p>Seleccione provincia o tipo, y complete tomo y partida.</p>');
            return;
        }
        btnVerif.disabled = true;
        var textoOriginal = btnVerif.textContent;
        btnVerif.textContent = 'Verificando…';
        mostrar('cargando', 'Buscando paciente…');
        fetch('ajax/verificar_paciente.php?cedula=' + encodeURIComponent(cedula), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (datos) {
                if (datos && datos.existe === true && datos.paciente) {
                    var p = datos.paciente;
                    var nombreCompleto = escapeHtml(limpiarNombre(p.nombre) + ' ' + limpiarNombre(p.apellido));
                    var sexoLegible = { 'M': 'Masculino', 'F': 'Femenino', 'Otro': 'Otro' };
                    mostrar('existe',
                        '<strong>✓ Paciente ya registrado</strong>' +
                        '<p class="datos-paciente__pregunta">Verifique que los datos son correctos antes de continuar:</p>' +
                        '<dl class="datos-paciente">' +
                            '<dt>Nombre</dt><dd>' + nombreCompleto + '</dd>' +
                            '<dt>Cédula</dt><dd>' + escapeHtml(p.cedula || '—') + '</dd>' +
                            '<dt>Fecha de nacimiento</dt><dd>' + escapeHtml(p.fecha_nacimiento || '—') + '</dd>' +
                            '<dt>Sexo</dt><dd>' + escapeHtml(sexoLegible[p.sexo] || p.sexo || '—') + '</dd>' +
                        '</dl>' +
                        '<div class="datos-paciente__acciones">' +
                            '<a class="btn" href="recepcionista_citas.php?paciente=' +
                                encodeURIComponent(p.id_paciente) + '">Ir a citas →</a>' +
                        '</div>');
                    restoForm.hidden = true;
                } else if (datos && datos.existe === false) {
                    mostrar('nuevo',
                        '<strong>✦ Paciente nuevo.</strong>' +
                        '<p>Complete los datos a continuación para registrarlo.</p>');
                    restoForm.hidden = false;
                } else {
                    throw new Error('Respuesta inesperada');
                }
            })
            .catch(function () {
                mostrar('error',
                    '<strong>No se pudo verificar.</strong>' +
                    '<p>Intente nuevamente o complete el formulario manualmente.</p>');
                restoForm.hidden = false;
            })
            .then(function () {
                btnVerif.disabled = false;
                btnVerif.textContent = textoOriginal;
            }, function () {
                btnVerif.disabled = false;
                btnVerif.textContent = textoOriginal;
            });
    }

    // Eventos
    selProv.addEventListener('change', function () {
        combinar();
        if (!resultado.hidden) { ocultarTodo(); }
        // Tras provincia, foco a tipo
        selTipo.focus();
    });

    selTipo.addEventListener('change', function () {
        combinar();
        if (!resultado.hidden) { ocultarTodo(); }
        // Tras tipo, foco a tomo
        inTomo.focus();
    });

    inTomo.addEventListener('input', function () {
        limpiarSoloDigitos(inTomo);
        combinar();
        if (inTomo.value.length >= 4) {
            inPart.focus();
        }
        if (!resultado.hidden) { ocultarTodo(); }
    });

    inPart.addEventListener('input', function () {
        limpiarSoloDigitos(inPart);
        combinar();
        if (!resultado.hidden) { ocultarTodo(); }
    });

    inPart.addEventListener('keydown', function (evento) {
        if (evento.key === 'Enter') {
            evento.preventDefault();
            verificar();
        }
    });

    // Backspace en campo vacío → vuelve al anterior
    [inTomo, inPart].forEach(function (input) {
        input.addEventListener('keydown', function (evento) {
            if (evento.key === 'Backspace' && input.value === '') {
                evento.preventDefault();
                var idx = campos.indexOf(input);
                if (idx > 0) {
                    var prev = campos[idx - 1];
                    prev.focus();
                    if (prev.select) { prev.select(); }
                }
            }
        });
    });

    [inTomo, inPart].forEach(function (input) {
        input.addEventListener('focus', function () { input.select(); });
    });

    btnVerif.addEventListener('click', verificar);
})();
JS;

require_once __DIR__ . '/parciales/recep_footer.php';
