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
$id_medico = (int) $medico['id_medico'];

// --- Paciente seleccionado para ver su historial (?id_paciente=X) ---
$idPaciente = (isset($_GET['id_paciente']) && ctype_digit($_GET['id_paciente']))
    ? (int) $_GET['id_paciente']
    : 0;

$pacienteSeleccionado = null;
$consultasPaciente    = [];

if ($idPaciente > 0) {
    // El paciente debe tener al menos una consulta CON ESTE médico; si no,
    // no se muestran sus datos (nunca se filtra información de otros médicos).
    $stmtPac = $conexion->prepare(
        "SELECT p.id_paciente, p.nombre, p.apellido, p.cedula, p.fecha_nacimiento,
                p.sexo, p.telefono, p.direccion, p.correo
         FROM pacientes p
         INNER JOIN consultas c ON c.id_paciente = p.id_paciente
         WHERE p.id_paciente = :id_paciente AND c.id_medico = :id_medico
         LIMIT 1"
    );
    $stmtPac->execute([':id_paciente' => $idPaciente, ':id_medico' => $id_medico]);
    $pacienteSeleccionado = $stmtPac->fetch(PDO::FETCH_ASSOC);

    if ($pacienteSeleccionado) {
        $stmtCons = $conexion->prepare(
            "SELECT c.id_consulta, c.fecha_consulta, c.motivo, c.diagnostico, c.observaciones,
                    r.id_receta
             FROM consultas c
             LEFT JOIN recetas r ON r.id_consulta = c.id_consulta
             WHERE c.id_paciente = :id_paciente AND c.id_medico = :id_medico
             ORDER BY c.fecha_consulta DESC"
        );
        $stmtCons->execute([':id_paciente' => $idPaciente, ':id_medico' => $id_medico]);
        $consultasPaciente = $stmtCons->fetchAll(PDO::FETCH_ASSOC);
    }
}

// --- Búsqueda de pacientes atendidos por este médico (o listado completo) ---
$busqueda = trim((string) ($_GET['busqueda'] ?? ''));
$termino  = '%' . $busqueda . '%';

$sqlPacientes = "
    SELECT p.id_paciente, p.nombre, p.apellido, p.cedula,
           COUNT(c.id_consulta) AS num_consultas
    FROM pacientes p
    INNER JOIN consultas c ON c.id_paciente = p.id_paciente
    WHERE c.id_medico = :id_medico
";
if ($busqueda !== '') {
    $sqlPacientes .= " AND (p.cedula LIKE :termino OR p.nombre LIKE :termino OR p.apellido LIKE :termino)";
}
$sqlPacientes .= " GROUP BY p.id_paciente, p.nombre, p.apellido, p.cedula
                   ORDER BY p.apellido ASC, p.nombre ASC";

$stmtPacientes = $conexion->prepare($sqlPacientes);
$stmtPacientes->bindValue(':id_medico', $id_medico, PDO::PARAM_INT);
if ($busqueda !== '') {
    $stmtPacientes->bindValue(':termino', $termino, PDO::PARAM_STR);
}
$stmtPacientes->execute();
$pacientes = $stmtPacientes->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Historial clínico | Sistema de Consultas</title>
    <link rel="stylesheet" href="../css/estilo.css">
    <link rel="stylesheet" href="../css/medico.css">
</head>
<body>

<div class="medico-layout">

    <?php include __DIR__ . '/parciales/topbar.php'; ?>

    <?php $paginaActiva = 'historial'; include __DIR__ . '/parciales/sidebar.php'; ?>

    <main class="medico-main">

        <?php if ($pacienteSeleccionado): ?>
            <!-- ===== Detalle del paciente ===== -->
            <div class="medico-main__encabezado">
                <div class="medico-main__titulo">
                    <h2><?= htmlspecialchars($pacienteSeleccionado['nombre'] . ' ' . $pacienteSeleccionado['apellido']) ?></h2>
                    <p>Historial de consultas que le has atendido.</p>
                </div>
                <a href="historial.php" class="btn-accion">&larr; Volver al historial</a>
            </div>

            <section class="panel-tabla" style="grid-column: 1 / -1;">
                <div class="tarjeta-lateral">
                    <h3>🧑 Datos básicos</h3>
                    <div class="tarjeta-lateral__dato">
                        <div class="tarjeta-lateral__dato-label">Cédula</div>
                        <div class="tarjeta-lateral__dato-numero"><?= htmlspecialchars($pacienteSeleccionado['cedula'] ?? '') ?></div>
                    </div>
                    <div class="tarjeta-lateral__dato">
                        <div class="tarjeta-lateral__dato-label">Fecha de nacimiento</div>
                        <div class="tarjeta-lateral__dato-numero"><?= htmlspecialchars($pacienteSeleccionado['fecha_nacimiento'] ?? '') ?></div>
                    </div>
                    <div class="tarjeta-lateral__dato">
                        <div class="tarjeta-lateral__dato-label">Sexo</div>
                        <div class="tarjeta-lateral__dato-numero"><?= $pacienteSeleccionado['sexo'] === 'F' ? 'Femenino' : 'Masculino' ?></div>
                    </div>
                    <?php if (!empty($pacienteSeleccionado['telefono'])): ?>
                    <div class="tarjeta-lateral__dato">
                        <div class="tarjeta-lateral__dato-label">Teléfono</div>
                        <div class="tarjeta-lateral__dato-numero"><?= htmlspecialchars($pacienteSeleccionado['telefono']) ?></div>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if (empty($consultasPaciente)): ?>
                    <div class="panel-tabla__vacio">
                        No hay consultas registradas con este paciente.
                    </div>
                <?php else: ?>
                    <table class="tabla-cola">
                        <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Motivo</th>
                            <th>Diagnóstico</th>
                            <th>Observaciones</th>
                            <th>Receta</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($consultasPaciente as $consulta): ?>
                            <tr>
                                <td data-label="Fecha"><?= date('d/m/Y h:i a', strtotime($consulta['fecha_consulta'])) ?></td>
                                <td data-label="Motivo" class="texto-recortado"><?= htmlspecialchars($consulta['motivo'] ?? '') ?></td>
                                <td data-label="Diagnóstico" class="texto-recortado"><?= htmlspecialchars($consulta['diagnostico'] ?? '') ?></td>
                                <td data-label="Observaciones" class="texto-recortado"><?= htmlspecialchars($consulta['observaciones'] ?? '') ?></td>
                                <td data-label="Receta">
                                    <?php if ($consulta['id_receta']): ?>
                                        <span class="badge badge-estado-atendido">Con receta</span>
                                    <?php else: ?>
                                        <span class="badge badge-sin-receta">Sin receta</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>

        <?php else: ?>
            <!-- ===== Listado + buscador de pacientes atendidos ===== -->
            <div class="medico-main__encabezado">
                <div class="medico-main__titulo">
                    <h2>Historial clínico</h2>
                    <p>Pacientes que has atendido. Busca por cédula, nombre o apellido.</p>
                </div>
            </div>

            <section class="panel-tabla" style="grid-column: 1 / -1;">
                <form method="get" action="historial.php" class="campo panel-tabla__filtros">
                    <div style="flex:1;">
                        <input type="text" name="busqueda" id="busqueda"
                               placeholder="Buscar por cédula, nombre o apellido…"
                               value="<?= htmlspecialchars($busqueda) ?>">
                    </div>
                    <button type="submit" class="btn">Buscar</button>
                </form>

                <?php if ($idPaciente > 0): ?>
                    <div class="panel-tabla__vacio">
                        No se encontraron consultas tuyas para ese paciente, o no lo has atendido. Solo puedes consultar el historial de pacientes a los que ya atendiste.
                    </div>
                <?php elseif (empty($pacientes)): ?>
                    <div class="panel-tabla__vacio">
                        <?= $busqueda !== '' ? 'No se encontraron pacientes con esa búsqueda.' : 'Aún no has atendido pacientes.' ?>
                    </div>
                <?php else: ?>
                    <table class="tabla-cola">
                        <thead>
                        <tr>
                            <th>Paciente</th>
                            <th>Cédula</th>
                            <th>Consultas</th>
                            <th>Historial</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($pacientes as $paciente): ?>
                            <tr>
                                <td data-label="Paciente">
                                    <?= htmlspecialchars($paciente['nombre'] . ' ' . $paciente['apellido']) ?>
                                </td>
                                <td data-label="Cédula"><?= htmlspecialchars($paciente['cedula']) ?></td>
                                <td data-label="Consultas"><?= (int) $paciente['num_consultas'] ?></td>
                                <td data-label="Historial">
                                    <a class="btn-accion atender"
                                       href="historial.php?id_paciente=<?= (int) $paciente['id_paciente'] ?>">
                                        Ver historial
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>
        <?php endif; ?>

    </main>
</div>

<script>
    document.getElementById('btnMenu').addEventListener('click', function () {
        document.getElementById('sidebar').classList.toggle('abierta');
    });
</script>

</body>
</html>