<?php
/**
 * pages/recepcionista_citas.php
 *
 * Subpágina del panel de recepcionista: SOLO agendar y cancelar citas.
 *
 * Acciones POST:
 *   - accion=agendar_cita
 *   - accion=cancelar_cita
 *
 * GET: ?buscar=X&paciente=Y para preseleccionar paciente.
 */

$seccionActiva = 'citas';

// Cargar dependencias antes de cualquier uso de $conexion
require_once __DIR__ . '/../config/sesion.php';
verificarSesion(['recepcionista']);
$csrfToken = generarTokenCSRF();
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/parciales/recep_funciones.php';

const HORA_INICIO_CITAS = '07:00';
const HORA_FIN_CITAS    = '16:30';
const INTERVALO_MINUTOS = 30;

$horasDisponibles = generarHorasDisponibles();
$hoyDb = (string) $conexion->query('SELECT CURDATE()')->fetchColumn();

$erroresCitas = [];
$exitoCitas   = '';

if (!empty($_SESSION['flash_recepcion']) && ($_SESSION['flash_recepcion']['seccion'] ?? '') === 'citas') {
    $flash = $_SESSION['flash_recepcion'];
    unset($_SESSION['flash_recepcion']);
    $erroresCitas = $flash['errores'] ?? [];
    $exitoCitas   = $flash['exito'] ?? '';
}

// POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'agendar_cita') {
        $erroresAccion = [];
        $idPaciente = filter_input(INPUT_POST, 'id_paciente', FILTER_VALIDATE_INT);
        $buscarPost = trim($_POST['buscar'] ?? '');
        $contexto   = [];
        if ($buscarPost !== '') { $contexto['buscar'] = $buscarPost; }
        if ($idPaciente)         { $contexto['paciente'] = $idPaciente; }

        if (!validarTokenCSRF($_POST['csrf_token'] ?? null)) {
            $erroresAccion[] = 'La sesión expiró o el formulario no es válido. Recarga la página e inténtalo de nuevo.';
        } else {
            $idMedico  = filter_input(INPUT_POST, 'id_medico', FILTER_VALIDATE_INT);
            $fechaCita = trim($_POST['fecha_cita'] ?? '');
            $horaCita  = trim($_POST['hora_cita'] ?? '');

            if (!$idPaciente)             { $erroresAccion[] = 'Debes seleccionar un paciente antes de agendar.'; }
            if (!$idMedico)               { $erroresAccion[] = 'Debes seleccionar un médico.'; }
            if ($fechaCita === '')        { $erroresAccion[] = 'La fecha de la cita es obligatoria.'; }
            else {
                $fecha = DateTime::createFromFormat('Y-m-d', $fechaCita);
                $erroresFecha = DateTime::getLastErrors();
                if (!$fecha || ($erroresFecha !== false && ($erroresFecha['warning_count'] + $erroresFecha['error_count']) > 0)) {
                    $erroresAccion[] = 'La fecha de la cita no es válida.';
                } elseif ($fecha->format('Y-m-d') < $hoyDb) {
                    $erroresAccion[] = 'La fecha de la cita no puede ser en el pasado.';
                }
            }
            if ($horaCita === '' || !in_array($horaCita, $horasDisponibles, true)) {
                $erroresAccion[] = 'La hora seleccionada no es válida.';
            }

            if (!$erroresAccion && obtenerPaciente($conexion, $idPaciente) === null) {
                $erroresAccion[] = 'El paciente seleccionado no existe.';
            }

            if (!$erroresAccion) {
                $stmt = $conexion->prepare(
                    'SELECT COUNT(*) FROM medicos m
                     INNER JOIN usuarios u ON u.id_usuario = m.id_usuario
                     WHERE m.id_medico = :id_medico AND u.activo = 1'
                );
                $stmt->execute([':id_medico' => $idMedico]);
                if ((int) $stmt->fetchColumn() === 0) {
                    $erroresAccion[] = 'El médico seleccionado no es válido.';
                }
            }

            if (!$erroresAccion) {
                $stmt = $conexion->prepare(
                    "SELECT COUNT(*) FROM citas
                     WHERE id_paciente = :id_paciente AND id_medico = :id_medico
                       AND fecha_cita = :fecha
                       AND estado IN ('pendiente', 'confirmada')"
                );
                $stmt->execute([
                    ':id_paciente' => $idPaciente,
                    ':id_medico'   => $idMedico,
                    ':fecha'       => $fechaCita,
                ]);
                if ((int) $stmt->fetchColumn() > 0) {
                    $erroresAccion[] = 'Este paciente ya tiene una cita activa con este médico ese día. Si necesita otra, cancele la anterior o elija otro día.';
                }
            }

            if (!$erroresAccion) {
                $stmt = $conexion->prepare(
                    "SELECT COUNT(*) FROM citas
                     WHERE id_medico = :id_medico AND fecha_cita = :fecha AND hora_cita = :hora
                       AND estado IN ('pendiente', 'confirmada')"
                );
                $stmt->execute([
                    ':id_medico' => $idMedico,
                    ':fecha'     => $fechaCita,
                    ':hora'      => $horaCita,
                ]);
                if ((int) $stmt->fetchColumn() > 0) {
                    $erroresAccion[] = 'Ese médico ya tiene una cita agendada en esa fecha y hora.';
                }
            }

            if (!$erroresAccion) {
                try {
                    $stmt = $conexion->prepare(
                        'INSERT INTO citas (id_paciente, id_medico, fecha_cita, hora_cita)
                         VALUES (:id_paciente, :id_medico, :fecha_cita, :hora_cita)'
                    );
                    $stmt->execute([
                        ':id_paciente' => $idPaciente,
                        ':id_medico'   => $idMedico,
                        ':fecha_cita'  => $fechaCita,
                        ':hora_cita'   => $horaCita,
                    ]);
                    flashYRedirigir('citas', [], 'Cita agendada con éxito.', $contexto);
                } catch (PDOException $e) {
                    error_log('Error al agendar cita: ' . $e->getMessage());
                    $erroresAccion[] = 'No se pudo agendar la cita. Intenta nuevamente.';
                }
            }
        }
        flashYRedirigir('citas', $erroresAccion, '', $contexto);
    }

    if ($accion === 'cancelar_cita') {
        $erroresAccion = [];
        if (!validarTokenCSRF($_POST['csrf_token'] ?? null)) {
            $erroresAccion[] = 'La sesión expiró o el formulario no es válido. Recarga la página e inténtalo de nuevo.';
        } else {
            $idCita = filter_input(INPUT_POST, 'id_cita', FILTER_VALIDATE_INT);
            if (!$idCita) {
                $erroresAccion[] = 'La cita indicada no es válida.';
            } else {
                $stmt = $conexion->prepare('SELECT estado FROM citas WHERE id_cita = :id_cita LIMIT 1');
                $stmt->execute([':id_cita' => $idCita]);
                $estadoActual = $stmt->fetchColumn();
                if ($estadoActual === false) {
                    $erroresAccion[] = 'La cita indicada no existe.';
                } elseif (!in_array($estadoActual, ['pendiente', 'confirmada'], true)) {
                    $erroresAccion[] = 'Solo se pueden cancelar citas pendientes o confirmadas.';
                } else {
                    $stmt = $conexion->prepare(
                        "UPDATE citas SET estado = 'cancelada'
                         WHERE id_cita = :id_cita AND estado IN ('pendiente', 'confirmada')"
                    );
                    $stmt->execute([':id_cita' => $idCita]);
                    flashYRedirigir('citas', [], 'La cita fue cancelada.', []);
                }
            }
        }
        flashYRedirigir('citas', $erroresAccion, '', []);
    }
}

// GET
$buscar             = trim($_GET['buscar'] ?? '');
$resultadosBusqueda = null;
$pacienteSel        = null;
if (isset($_GET['buscar'])) {
    $resultadosBusqueda = $buscar === '' ? [] : buscarPacientes($conexion, $buscar);
}
$idPacienteGet = filter_input(INPUT_GET, 'paciente', FILTER_VALIDATE_INT);
if ($idPacienteGet) {
    $pacienteSel = obtenerPaciente($conexion, $idPacienteGet);
}
$medicosActivos  = listarMedicosActivos($conexion);
$citasPendientes = listarCitasPendientes($conexion);

$etiquetasEstadoCita = [
    'pendiente'  => 'Pendiente',
    'confirmada' => 'Confirmada',
    'cancelada'  => 'Cancelada',
    'atendida'   => 'Atendida',
];

require_once __DIR__ . '/parciales/recep_header.php';
?>

<section class="seccion-panel">
    <h2>Citas</h2>

    <?php if ($exitoCitas !== ''): ?>
        <div class="mensaje-exito"><?= htmlspecialchars($exitoCitas, ENT_QUOTES, 'UTF-8') ?></div>
    <?php elseif (count($erroresCitas) > 0): ?>
        <div class="mensaje-error">
            <ul>
                <?php foreach ($erroresCitas as $error): ?>
                    <li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <h3>1. Buscar paciente por cédula</h3>
    <form method="GET" action="recepcionista_citas.php" class="form-buscar" role="search">
        <div class="campo form-buscar__campo">
            <label>Cédula</label>
            <div class="form-buscar__fila">
                <div class="cedula-panama" role="group" aria-label="Cédula panameña para búsqueda">
                    <select id="buscar_provincia" name="buscar_provincia" class="cedula-panama__select"
                            aria-label="Provincia">
                        <?php for ($p = 0; $p <= 12; $p++): ?>
                            <option value="<?= sprintf('%02d', $p) ?>"><?= sprintf('%02d', $p) ?></option>
                        <?php endfor; ?>
                    </select>
                    <select id="buscar_tipo" name="buscar_tipo" class="cedula-panama__select"
                            aria-label="Tipo de cédula">
                        <option value="00">00</option>
                        <option value="N">N</option>
                        <option value="E">E</option>
                        <option value="EC">EC</option>
                        <option value="PE">PE</option>
                        <option value="AV">AV</option>
                        <option value="PI">PI</option>
                    </select>
                    <span class="cedula-panama__sep" aria-hidden="true">-</span>
                    <input type="text" id="buscar_tomo" name="buscar_tomo" class="cedula-panama__input"
                           maxlength="4" inputmode="numeric" pattern="[0-9]{1,4}"
                           placeholder="----" aria-label="Tomo" autocomplete="off">
                    <span class="cedula-panama__sep" aria-hidden="true">-</span>
                    <input type="text" id="buscar_partida" name="buscar_partida" class="cedula-panama__input"
                           maxlength="4" inputmode="numeric" pattern="[0-9]{1,4}"
                           placeholder="----" aria-label="Partida" autocomplete="off">
                    <!-- Hidden que recibe la cédula combinada para el GET -->
                    <input type="hidden" name="buscar" id="buscar_cedula" value="<?= htmlspecialchars($buscar, ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <button type="submit" class="btn form-buscar__btn">Buscar</button>
            </div>
        </div>
    </form>

    <?php if ($resultadosBusqueda !== null): ?>
        <?php if (count($resultadosBusqueda) === 0): ?>
            <p class="mensaje-info">No se encontraron pacientes con ese criterio.</p>
        <?php else: ?>
            <ul class="lista-resultados">
                <?php foreach ($resultadosBusqueda as $resultado): ?>
                    <li>
                        <span>
                            <?= htmlspecialchars($resultado['apellido'] . ', ' . $resultado['nombre'], ENT_QUOTES, 'UTF-8') ?>
                            — C.I. <?= htmlspecialchars($resultado['cedula'], ENT_QUOTES, 'UTF-8') ?>
                        </span>
                        <?php if ($pacienteSel !== null && (int) $pacienteSel['id_paciente'] === (int) $resultado['id_paciente']): ?>
                            <strong class="texto-seleccionado">Seleccionado</strong>
                        <?php else: ?>
                            <a class="btn btn-pequeno" href="recepcionista_citas.php?<?= htmlspecialchars(http_build_query(['buscar' => $buscar, 'paciente' => $resultado['id_paciente']]), ENT_QUOTES, 'UTF-8') ?>">Seleccionar</a>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($pacienteSel !== null): ?>
        <div class="tarjeta-formulario">
            <h3>2. Agendar cita para: <?= htmlspecialchars($pacienteSel['nombre'] . ' ' . $pacienteSel['apellido'], ENT_QUOTES, 'UTF-8') ?> (C.I. <?= htmlspecialchars($pacienteSel['cedula'], ENT_QUOTES, 'UTF-8') ?>)</h3>
            <form method="POST" action="recepcionista_citas.php" id="formulario-cita" class="js-bloquear-envio">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="accion" value="agendar_cita">
                <input type="hidden" name="id_paciente" value="<?= (int) $pacienteSel['id_paciente'] ?>">
                <input type="hidden" name="buscar" value="<?= htmlspecialchars($buscar, ENT_QUOTES, 'UTF-8') ?>">

                <div class="campo">
                    <label for="id_medico">Médico</label>
                    <select id="id_medico" name="id_medico" required>
                        <option value="" disabled selected>Seleccione…</option>
                        <?php foreach ($medicosActivos as $medico): ?>
                            <option value="<?= (int) $medico['id_medico'] ?>">
                                <?= htmlspecialchars($medico['apellido'] . ', ' . $medico['nombre'] . ' — ' . $medico['nombre_especialidad'], ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div id="aviso-conflicto-paciente" class="aviso-conflicto" hidden aria-live="polite"></div>
                </div>

                <div class="campo">
                    <label for="fecha_cita">Fecha de la cita</label>
                    <input type="date" id="fecha_cita" name="fecha_cita"
                           min="<?= htmlspecialchars($hoyDb, ENT_QUOTES, 'UTF-8') ?>"
                           value="<?= htmlspecialchars($hoyDb, ENT_QUOTES, 'UTF-8') ?>" required>
                </div>

                <div class="campo">
                    <label for="hora_cita">Hora de la cita</label>
                    <select id="hora_cita" name="hora_cita" required disabled
                            aria-describedby="estado-hora-cita"
                            data-hours='<?= json_encode(
                                $horasDisponibles,
                                JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
                            ) ?>'>
                        <option value="" selected>Seleccione médico y fecha primero</option>
                    </select>
                    <span id="estado-hora-cita" class="sr-only" aria-live="polite"></span>
                </div>

                <button type="submit" class="btn" id="btn-agendar-cita">Agendar cita</button>
            </form>
        </div>
    <?php endif; ?>

    <h3>Citas de hoy y próximas</h3>
    <?php if (count($citasPendientes) === 0): ?>
        <p class="mensaje-info">No hay citas pendientes ni confirmadas.</p>
    <?php else: ?>
        <div class="panel-tabla">
            <table class="tabla-recepcion">
                <thead>
                    <tr>
                        <th scope="col">Fecha</th>
                        <th scope="col">Hora</th>
                        <th scope="col">Paciente</th>
                        <th scope="col">Cédula</th>
                        <th scope="col">Médico</th>
                        <th scope="col">Especialidad</th>
                        <th scope="col">Estado</th>
                        <th scope="col">Acción</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($citasPendientes as $cita): ?>
                        <tr>
                            <td data-label="Fecha"><?= htmlspecialchars(date('d/m/Y', strtotime($cita['fecha_cita'])), ENT_QUOTES, 'UTF-8') ?></td>
                            <td data-label="Hora"><?= htmlspecialchars(substr($cita['hora_cita'], 0, 5), ENT_QUOTES, 'UTF-8') ?></td>
                            <td data-label="Paciente"><?= htmlspecialchars($cita['paciente_nombre'] . ' ' . $cita['paciente_apellido'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td data-label="Cédula"><?= htmlspecialchars($cita['cedula'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td data-label="Médico"><?= htmlspecialchars($cita['medico_nombre'] . ' ' . $cita['medico_apellido'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td data-label="Especialidad"><?= htmlspecialchars($cita['nombre_especialidad'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td data-label="Estado">
                                <span class="badge badge-cita-<?= htmlspecialchars($cita['estado'], ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars($etiquetasEstadoCita[$cita['estado']] ?? $cita['estado'], ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </td>
                            <td data-label="Acción">
                                <form method="POST" action="recepcionista_citas.php" class="form-accion-inline js-bloquear-envio" data-confirmar="¿Seguro que deseas cancelar esta cita?">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="accion" value="cancelar_cita">
                                    <input type="hidden" name="id_cita" value="<?= (int) $cita['id_cita'] ?>">
                                    <button type="submit" class="btn-accion-cancelar">Cancelar</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php
$scriptsExtra = <<<'JS'
// Combinar los 4 campos de cédula (buscar) en el hidden antes del submit
(function () {
    var selProv = document.getElementById('buscar_provincia');
    var selTipo = document.getElementById('buscar_tipo');
    var inTomo  = document.getElementById('buscar_tomo');
    var inPart  = document.getElementById('buscar_partida');
    var oculta  = document.getElementById('buscar_cedula');
    if (!selProv || !selTipo || !inTomo || !inPart || !oculta) { return; }

    function normalizar(p) {
        if (p === '00') return '';
        if (p.length === 2 && p[0] === '0') return p[1];
        return p;
    }
    function soloDigitos(input) {
        var f = input.value.replace(/[^0-9]/g, '');
        if (f !== input.value) { input.value = f; }
        return f;
    }
    function combinar() {
        var prefijoNum = normalizar(selProv.value);
        var prefijoLet = (selTipo.value !== '00') ? selTipo.value : '';
        var tomo = inTomo.value;
        var part = inPart.value;
        var combinada = '';
        if (tomo && part) {
            var prefijo = prefijoNum + prefijoLet;
            combinada = prefijo ? (prefijo + '-' + tomo + '-' + part) : '';
        }
        oculta.value = combinada;
    }

    selProv.addEventListener('change', function () { combinar(); selTipo.focus(); });
    selTipo.addEventListener('change', function () { combinar(); inTomo.focus(); });
    inTomo.addEventListener('input', function () {
        soloDigitos(inTomo);
        combinar();
        if (inTomo.value.length >= 4) { inPart.focus(); }
    });
    inPart.addEventListener('input', function () { soloDigitos(inPart); combinar(); });
    [inTomo, inPart].forEach(function (i) {
        i.addEventListener('focus', function () { i.select(); });
        i.addEventListener('keydown', function (e) {
            if (e.key === 'Backspace' && i.value === '') {
                e.preventDefault();
                if (i === inTomo) { selTipo.focus(); }
                else if (i === inPart) { inTomo.focus(); }
            }
        });
    });
    inPart.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            // El form se enviará al hacer clic en Buscar; con Enter dejamos
            // que el browser lo envíe normalmente después de combinar.
            combinar();
        }
    });
})();

// Anti-doble-envío para los formularios de citas
document.querySelectorAll('form.js-bloquear-envio').forEach(function (formulario) {
    formulario.addEventListener('submit', function (evento) {
        var pregunta = formulario.getAttribute('data-confirmar');
        if (pregunta && !window.confirm(pregunta)) {
            evento.preventDefault();
            return;
        }
        var boton = formulario.querySelector('button[type="submit"]');
        if (boton) {
            boton.disabled = true;
            boton.textContent = 'Procesando…';
        }
    });
});

// Disponibilidad de horas al agendar cita
(function () {
    var selectMedico = document.getElementById('id_medico');
    var selectFecha  = document.getElementById('fecha_cita');
    var selectHora   = document.getElementById('hora_cita');
    var estadoHora   = document.getElementById('estado-hora-cita');
    var aviso        = document.getElementById('aviso-conflicto-paciente');
    if (!selectMedico || !selectFecha || !selectHora) { return; }

    var inputIdPaciente = document.querySelector('input[type="hidden"][name="id_paciente"]');
    var idPaciente = inputIdPaciente ? inputIdPaciente.value : '';

    var horasBase = [];
    try { horasBase = JSON.parse(selectHora.getAttribute('data-hours') || '[]'); } catch (e) { horasBase = []; }
    var secuenciaActual = 0;

    function placeholderHora(texto, deshabilitar) {
        selectHora.innerHTML = '';
        var opt = document.createElement('option');
        opt.value = '';
        opt.textContent = texto;
        opt.selected = true;
        selectHora.appendChild(opt);
        selectHora.disabled = !!deshabilitar;
        if (estadoHora) { estadoHora.textContent = texto; }
    }
    function reconstruir(ocupadas) {
        selectHora.innerHTML = '';
        var ph = document.createElement('option');
        ph.value = ''; ph.disabled = true; ph.selected = true; ph.textContent = 'Seleccione…';
        selectHora.appendChild(ph);
        var libres = 0;
        horasBase.forEach(function (h) {
            var opt = document.createElement('option');
            opt.value = h;
            if (ocupadas.indexOf(h) !== -1) {
                opt.disabled = true; opt.textContent = h + ' — Ocupado';
            } else {
                opt.textContent = h; libres++;
            }
            selectHora.appendChild(opt);
        });
        selectHora.disabled = false;
        if (horasBase.length === 0) {
            if (estadoHora) { estadoHora.textContent = ''; }
        } else if (libres === 0) {
            if (estadoHora) { estadoHora.textContent = 'Este médico no tiene horarios disponibles ese día. Elija otra fecha.'; }
        } else {
            if (estadoHora) {
                estadoHora.textContent = ocupadas.length === 0
                    ? 'Todas las horas están disponibles.'
                    : (ocupadas.length + ' horas ocupadas. No puede elegirlas.');
            }
        }
    }
    function mostrarAviso(conflicto) {
        if (!aviso) { return; }
        if (conflicto && conflicto.fecha && conflicto.hora) {
            var partes = conflicto.fecha.split('-');
            aviso.textContent = '\u26A0 Este paciente ya tiene una cita con este médico el '
                + partes[2] + '/' + partes[1] + '/' + partes[0] + ' a las ' + conflicto.hora + '.';
            aviso.hidden = false;
        } else {
            aviso.textContent = '';
            aviso.hidden = true;
        }
    }
    function consultar() {
        var idMedico = selectMedico.value;
        var fecha    = selectFecha.value;
        if (!idMedico) {
            mostrarAviso(null);
            placeholderHora('Seleccione médico y fecha primero', true);
            return;
        }
        if (fecha) {
            placeholderHora('Verificando disponibilidad…', true);
        } else {
            placeholderHora('Seleccione una fecha para ver horas disponibles', true);
        }
        var miSecuencia = ++secuenciaActual;
        var url = 'ajax/consultar_disponibilidad.php?id_medico=' + encodeURIComponent(idMedico);
        if (idPaciente) { url += '&id_paciente=' + encodeURIComponent(idPaciente); }
        if (fecha)      { url += '&fecha=' + encodeURIComponent(fecha); }
        fetch(url, { credentials: 'same-origin' })
            .then(function (respuesta) { return respuesta.json(); })
            .then(function (datos) {
                if (miSecuencia !== secuenciaActual) { return; }
                if (!datos || !Array.isArray(datos.ocupadas)) { throw new Error('Respuesta inesperada'); }
                mostrarAviso(datos.conflicto_paciente);
                if (fecha) { reconstruir(datos.ocupadas); }
            })
            .catch(function () {
                if (miSecuencia !== secuenciaActual) { return; }
                mostrarAviso(null);
                if (fecha) {
                    reconstruir([]);
                    if (estadoHora) { estadoHora.textContent = 'No se pudo verificar disponibilidad; se validará al guardar.'; }
                }
            });
    }
    selectMedico.addEventListener('change', consultar);
    selectFecha.addEventListener('change', consultar);
    placeholderHora('Seleccione médico y fecha primero', true);
})();
JS;

require_once __DIR__ . '/parciales/recep_footer.php';
