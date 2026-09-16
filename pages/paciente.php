<?php
/**
 * pages/paciente.php
 *
 * Dashboard del paciente: vista general con próximas citas, última
 * consulta y accesos directos a las secciones.
 */

$seccionActiva = 'dashboard';

require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/parciales/paciente_funciones.php';
verificarSesionPaciente();

$idPaciente = (int) $_SESSION['id_paciente'];
$paciente   = obtenerPacienteActual($conexion);

// Mensaje flash (PRG) por si viene de cancelar una cita
$flash = $_SESSION['flash_paciente'] ?? null;
unset($_SESSION['flash_paciente']);

// Próximas citas (>= hoy, estados pendientes/confirmadas)
$stmtProx = $conexion->prepare(
    "SELECT c.id_cita, c.fecha_cita, c.hora_cita, c.estado,
            u.nombre AS medico_nombre, u.apellido AS medico_apellido,
            e.nombre_especialidad
     FROM citas c
     INNER JOIN medicos m ON m.id_medico = c.id_medico
     INNER JOIN usuarios u ON u.id_usuario = m.id_usuario
     INNER JOIN especialidades e ON e.id_especialidad = m.id_especialidad
     WHERE c.id_paciente = :id
       AND c.fecha_cita >= CURDATE()
       AND c.estado IN ('pendiente', 'confirmada')
     ORDER BY c.fecha_cita ASC, c.hora_cita ASC
     LIMIT 5"
);
$stmtProx->execute([':id' => $idPaciente]);
$proximasCitas = $stmtProx->fetchAll(PDO::FETCH_ASSOC);

// Última consulta
$stmtUlt = $conexion->prepare(
    "SELECT con.id_consulta, con.fecha_consulta, con.motivo, con.diagnostico,
            u.nombre AS medico_nombre, u.apellido AS medico_apellido,
            e.nombre_especialidad
     FROM consultas con
     INNER JOIN medicos m ON m.id_medico = con.id_medico
     INNER JOIN usuarios u ON u.id_usuario = m.id_usuario
     INNER JOIN especialidades e ON e.id_especialidad = m.id_especialidad
     WHERE con.id_paciente = :id
     ORDER BY con.fecha_consulta DESC
     LIMIT 1"
);
$stmtUlt->execute([':id' => $idPaciente]);
$ultimaConsulta = $stmtUlt->fetch(PDO::FETCH_ASSOC);

// Contadores
$totalCitas = (int) $conexion->query(
    "SELECT COUNT(*) FROM citas WHERE id_paciente = $idPaciente"
)->fetchColumn();
$totalConsultas = (int) $conexion->query(
    "SELECT COUNT(*) FROM consultas WHERE id_paciente = $idPaciente"
)->fetchColumn();
$totalRecetas = (int) $conexion->query(
    "SELECT COUNT(*) FROM recetas r
     INNER JOIN consultas con ON con.id_consulta = r.id_consulta
     WHERE con.id_paciente = $idPaciente"
)->fetchColumn();

$etiquetasEstadoCita = [
    'pendiente'  => 'Pendiente',
    'confirmada' => 'Confirmada',
    'cancelada'  => 'Cancelada',
    'atendida'   => 'Atendida',
];

require_once __DIR__ . '/parciales/paciente_header.php';
?>

<section class="seccion-panel">
    <h2>Hola, <?= htmlspecialchars($paciente['nombre'] ?? '', ENT_QUOTES, 'UTF-8') ?> 👋</h2>
    <p class="campo__ayuda" style="margin-top:-4px;">
        Desde aquí puedes consultar tus citas, historial de consultas, recetas y datos personales.
    </p>

    <?php if ($flash !== null): ?>
        <?php if (!empty($flash['error'])): ?>
            <div class="mensaje-error"><?= htmlspecialchars($flash['error'], ENT_QUOTES, 'UTF-8') ?></div>
        <?php elseif (!empty($flash['exito'])): ?>
            <div class="mensaje-exito"><?= htmlspecialchars($flash['exito'], ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
    <?php endif; ?>

    <div class="tarjeta-paciente-contadores">
        <a class="tarjeta-contador-pac" href="paciente_citas.php" style="text-decoration:none; color:inherit;">
            <div class="tarjeta-contador-pac__numero"><?= $totalCitas ?></div>
            <div class="tarjeta-contador-pac__etiqueta">Citas registradas</div>
        </a>
        <a class="tarjeta-contador-pac" href="paciente_consultas.php" style="text-decoration:none; color:inherit;">
            <div class="tarjeta-contador-pac__numero"><?= $totalConsultas ?></div>
            <div class="tarjeta-contador-pac__etiqueta">Consultas realizadas</div>
        </a>
        <a class="tarjeta-contador-pac" href="paciente_recetas.php" style="text-decoration:none; color:inherit;">
            <div class="tarjeta-contador-pac__numero"><?= $totalRecetas ?></div>
            <div class="tarjeta-contador-pac__etiqueta">Recetas</div>
        </a>
    </div>

    <div class="tarjeta-paciente">
        <h3>Próximas citas</h3>
        <?php if (count($proximasCitas) === 0): ?>
            <p class="mensaje-info">No tienes citas pendientes. Cuando agendes una, aparecerá aquí.</p>
            <p style="margin-top:8px;"><a href="paciente_citas.php" class="btn btn-paciente-secundario">Ver todas mis citas</a></p>
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
                        <?php foreach ($proximasCitas as $cita): ?>
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
                                    <a href="paciente_citas.php" class="btn btn-paciente-secundario" style="padding:6px 12px; font-size:0.85rem;">Ver</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p style="margin-top:14px;"><a href="paciente_citas.php">Ver todas mis citas →</a></p>
        <?php endif; ?>
    </div>

    <?php if ($ultimaConsulta !== false): ?>
        <div class="tarjeta-paciente">
            <h3>Última consulta</h3>
            <p>
                <strong><?= htmlspecialchars(date('d/m/Y H:i', strtotime($ultimaConsulta['fecha_consulta'])), ENT_QUOTES, 'UTF-8') ?></strong>
                · Dr(a). <?= htmlspecialchars($ultimaConsulta['medico_nombre'] . ' ' . $ultimaConsulta['medico_apellido'], ENT_QUOTES, 'UTF-8') ?>
                · <?= htmlspecialchars($ultimaConsulta['nombre_especialidad'], ENT_QUOTES, 'UTF-8') ?>
            </p>
            <?php if (!empty($ultimaConsulta['motivo'])): ?>
                <p><strong>Motivo:</strong> <?= htmlspecialchars($ultimaConsulta['motivo'], ENT_QUOTES, 'UTF-8') ?></p>
            <?php endif; ?>
            <?php if (!empty($ultimaConsulta['diagnostico'])): ?>
                <p><strong>Diagnóstico:</strong> <?= htmlspecialchars($ultimaConsulta['diagnostico'], ENT_QUOTES, 'UTF-8') ?></p>
            <?php endif; ?>
            <p style="margin-top:10px;"><a href="paciente_consultas.php">Ver todas mis consultas →</a></p>
        </div>
    <?php endif; ?>
</section>

<?php require_once __DIR__ . '/parciales/paciente_footer.php'; ?>
