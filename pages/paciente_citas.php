<?php
/**
 * pages/paciente_citas.php
 *
 * Listado completo de citas del paciente logueado. Permite cancelar
 * citas pendientes o confirmadas (no atendidas, no canceladas).
 *
 * POST:
 *   - accion=cancelar_cita
 */

$seccionActiva = 'citas';

require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/parciales/paciente_funciones.php';
verificarSesionPaciente();

$idPaciente = (int) $_SESSION['id_paciente'];

$errores = [];
$exito   = '';

// ----- POST: cancelar cita -----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'cancelar_cita') {
    if (!validarTokenCSRF($_POST['csrf_token'] ?? null)) {
        $errores[] = 'La sesión expiró. Recarga la página.';
    } else {
        $idCita = filter_input(INPUT_POST, 'id_cita', FILTER_VALIDATE_INT);
        if (!$idCita) {
            $errores[] = 'La cita indicada no es válida.';
        } else {
            // Verificar que la cita pertenece al paciente y está en estado cancelable
            $stmt = $conexion->prepare(
                'SELECT estado FROM citas WHERE id_cita = :id_cita AND id_paciente = :id_paciente LIMIT 1'
            );
            $stmt->execute([
                ':id_cita'     => $idCita,
                ':id_paciente' => $idPaciente,
            ]);
            $estadoActual = $stmt->fetchColumn();
            if ($estadoActual === false) {
                $errores[] = 'La cita indicada no existe o no te pertenece.';
            } elseif (!in_array($estadoActual, ['pendiente', 'confirmada'], true)) {
                $errores[] = 'Solo se pueden cancelar citas pendientes o confirmadas.';
            } else {
                $stmt = $conexion->prepare(
                    "UPDATE citas SET estado = 'cancelada'
                     WHERE id_cita = :id_cita AND id_paciente = :id_paciente
                       AND estado IN ('pendiente', 'confirmada')"
                );
                $stmt->execute([
                    ':id_cita'     => $idCita,
                    ':id_paciente' => $idPaciente,
                ]);
                $exito = 'La cita fue cancelada.';
            }
        }
    }
}

$csrfToken = generarTokenCSRF();

// Listar todas las citas del paciente
$citas = listarCitasPaciente($conexion, $idPaciente);

$etiquetasEstadoCita = [
    'pendiente'  => 'Pendiente',
    'confirmada' => 'Confirmada',
    'cancelada'  => 'Cancelada',
    'atendida'   => 'Atendida',
];

require_once __DIR__ . '/parciales/paciente_header.php';
?>

<section class="seccion-panel">
    <h2>Mis citas</h2>

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

    <?php if (count($citas) === 0): ?>
        <div class="tarjeta-paciente">
            <p class="mensaje-info">Aún no tienes citas registradas. Pide una en recepción.</p>
        </div>
    <?php else: ?>
        <div class="panel-tabla">
            <table class="tabla-paciente">
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Hora</th>
                        <th>Médico</th>
                        <th>Especialidad</th>
                        <th>Estado</th>
                        <th>Acción</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($citas as $cita): ?>
                        <tr>
                            <td data-label="Fecha"><?= htmlspecialchars(date('d/m/Y', strtotime($cita['fecha_cita'])), ENT_QUOTES, 'UTF-8') ?></td>
                            <td data-label="Hora"><?= htmlspecialchars(substr($cita['hora_cita'], 0, 5), ENT_QUOTES, 'UTF-8') ?></td>
                            <td data-label="Médico">Dr(a). <?= htmlspecialchars($cita['medico_nombre'] . ' ' . $cita['medico_apellido'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td data-label="Especialidad"><?= htmlspecialchars($cita['nombre_especialidad'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td data-label="Estado">
                                <span class="badge-paciente badge-paciente-<?= htmlspecialchars($cita['estado'], ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars($etiquetasEstadoCita[$cita['estado']] ?? $cita['estado'], ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </td>
                            <td data-label="Acción">
                                <?php if (in_array($cita['estado'], ['pendiente', 'confirmada'], true)): ?>
                                    <form method="POST" style="display:inline; margin:0;"
                                          onsubmit="return confirm('¿Cancelar esta cita?');">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="accion" value="cancelar_cita">
                                        <input type="hidden" name="id_cita" value="<?= (int) $cita['id_cita'] ?>">
                                        <button type="submit" class="btn-accion-cancelar">Cancelar</button>
                                    </form>
                                <?php else: ?>
                                    <span class="campo__ayuda">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php require_once __DIR__ . '/parciales/paciente_footer.php'; ?>
