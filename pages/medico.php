<?php
require_once __DIR__ . '/../config/sesion.php';
verificarSesion(['medico']);

require_once __DIR__ . '/../config/conexion.php'; // expone $conexion (PDO)

// --- id_medico correspondiente al usuario en sesión ---
$stmtMedico = $conexion->prepare(
    "SELECT id_medico FROM medicos WHERE id_usuario = :id_usuario LIMIT 1"
);
$stmtMedico->execute([':id_usuario' => $_SESSION['id_usuario']]);
$medico = $stmtMedico->fetch(PDO::FETCH_ASSOC);

if (!$medico) {
    die('Error: no se encontró un registro de médico asociado a este usuario. Contacte al administrador.');
}
$id_medico = $medico['id_medico'];

// --- Cola de pacientes en espera / en consulta, hoy ---
$sqlCola = "
    SELECT t.id_turno, t.numero_turno, t.tipo, t.estado, t.fecha,
           p.id_paciente, p.nombre, p.apellido, p.cedula, p.fecha_nacimiento
    FROM turnos t
    INNER JOIN pacientes p ON p.id_paciente = t.id_paciente
    WHERE t.estado IN ('en_espera', 'en_consulta')
      AND DATE(t.fecha) = CURDATE()
    ORDER BY t.numero_turno ASC
";
$stmtCola = $conexion->prepare($sqlCola);
$stmtCola->execute();
$cola = $stmtCola->fetchAll(PDO::FETCH_ASSOC);

// --- Estadísticas rápidas del día ---
$stmtTotalHoy = $conexion->prepare("SELECT COUNT(*) FROM turnos WHERE DATE(fecha) = CURDATE()");
$stmtTotalHoy->execute();
$totalHoy = (int) $stmtTotalHoy->fetchColumn();

$stmtAtendidos = $conexion->prepare("SELECT COUNT(*) FROM turnos WHERE DATE(fecha) = CURDATE() AND estado = 'atendido'");
$stmtAtendidos->execute();
$atendidosHoy = (int) $stmtAtendidos->fetchColumn();

$pendientesHoy = count($cola);

// --- Función pequeña para calcular edad a partir de fecha_nacimiento ---
function calcularEdad(?string $fechaNacimiento): ?int {
    if (!$fechaNacimiento) {
        return null;
    }
    $nacimiento = new DateTime($fechaNacimiento);
    $hoy = new DateTime();
    return $hoy->diff($nacimiento)->y;
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel Médico | Sistema de Consultas</title>
    <link rel="stylesheet" href="../css/estilo.css">
    <link rel="stylesheet" href="../css/medico.css">
</head>
<body>

<div class="medico-layout">

    <?php include __DIR__ . '/parciales/topbar.php'; ?>

    <?php $paginaActiva = 'cola'; include __DIR__ . '/parciales/sidebar.php'; ?>

    <!-- ===== Contenido principal ===== -->
    <main class="medico-main">

        <div class="medico-main__encabezado">
            <div class="medico-main__titulo">
                <h2>Cola de pacientes</h2>
                <p>Pacientes en espera para ser atendidos hoy</p>
                <p>Selecciona un paciente para iniciar la consulta.</p>
            </div>

            <div class="tarjeta-resumen">
                <div class="tarjeta-resumen__icono">👥</div>
                <div>
                    <div class="tarjeta-resumen__numero"><?= $pendientesHoy ?></div>
                    <div class="tarjeta-resumen__label">Pacientes en espera</div>
                    <div class="tarjeta-resumen__fecha">Actualizado: <?= date('h:i a') ?></div>
                </div>
            </div>
        </div>

        <!-- ===== Tabla de la cola ===== -->
        <section class="panel-tabla">
            <?php if (empty($cola)): ?>
                <div class="panel-tabla__vacio">
                    No hay pacientes en espera en este momento.
                </div>
            <?php else: ?>
                <table class="tabla-cola">
                    <thead>
                        <tr>
                            <th>N° Turno</th>
                            <th>Paciente</th>
                            <th>Cédula</th>
                            <th>Tipo</th>
                            <th>Estado</th>
                            <th>Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($cola as $turno):
                            $edad = calcularEdad($turno['fecha_nacimiento']);
                            $inicialesPaciente = strtoupper(mb_substr($turno['nombre'], 0, 1) . mb_substr($turno['apellido'], 0, 1));
                            $esEnConsulta = $turno['estado'] === 'en_consulta';
                        ?>
                            <tr>
                                <td data-label="N° Turno">
                                    <span class="numero-turno"><?= htmlspecialchars($turno['numero_turno']) ?></span>
                                </td>
                                <td data-label="Paciente">
                                    <div class="celda-paciente">
                                        <div class="avatar-paciente"><?= htmlspecialchars($inicialesPaciente) ?></div>
                                        <div>
                                            <strong><?= htmlspecialchars($turno['nombre'] . ' ' . $turno['apellido']) ?></strong>
                                            <?php if ($edad !== null): ?>
                                                <span><?= $edad ?> años</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td data-label="Cédula"><?= htmlspecialchars($turno['cedula']) ?></td>
                                <td data-label="Tipo">
                                    <span class="badge badge-tipo-<?= htmlspecialchars($turno['tipo']) ?>">
                                        <?= $turno['tipo'] === 'con_cita' ? 'Con cita' : 'Espontáneo' ?>
                                    </span>
                                </td>
                                <td data-label="Estado">
                                    <span class="badge badge-estado-<?= htmlspecialchars($turno['estado']) ?>">
                                        <?= $esEnConsulta ? 'En consulta' : 'En espera' ?>
                                    </span>
                                </td>
                                <td data-label="Acción">
                                    <a href="consulta.php?id_turno=<?= (int) $turno['id_turno'] ?>"
                                       class="btn-accion <?= $esEnConsulta ? 'continuar' : 'atender' ?>">
                                        <?= $esEnConsulta ? '↻ Continuar' : '🩺 Atender' ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <div class="panel-tabla__nota">
                    ℹ️ Los pacientes se muestran ordenados por número de turno. Al atender un paciente, su estado cambiará a "Atendido".
                </div>
            <?php endif; ?>
        </section>

        <!-- ===== Columna lateral derecha ===== -->
        <aside class="medico-aside">
            <div class="tarjeta-lateral">
                <h3>📋 Información rápida</h3>

                <div class="tarjeta-lateral__dato">
                    <div class="tarjeta-lateral__dato-label">Consultas del día</div>
                    <div class="tarjeta-lateral__dato-numero"><?= $totalHoy ?></div>
                </div>

                <div class="tarjeta-lateral__dato">
                    <div class="tarjeta-lateral__dato-label">Consultas atendidas</div>
                    <div class="tarjeta-lateral__dato-numero"><?= $atendidosHoy ?></div>
                </div>

                <div class="tarjeta-lateral__dato">
                    <div class="tarjeta-lateral__dato-label">Pendientes</div>
                    <div class="tarjeta-lateral__dato-numero alerta"><?= $pendientesHoy ?></div>
                </div>
            </div>

            <div class="tarjeta-lateral tarjeta-recordatorio">
                <h3>🔔 Recordatorio</h3>
                <p>No olvides revisar el historial clínico del paciente antes de iniciar la consulta.</p>
            </div>
        </aside>

    </main>
</div>

<script>
    // Abrir/cerrar el menú lateral en celular (solo visual por ahora)
    document.getElementById('btnMenu').addEventListener('click', function () {
        document.getElementById('sidebar').classList.toggle('abierta');
    });
</script>

</body>
</html>
