<?php
require_once __DIR__ . '/../config/sesion.php';

// --- Protección de sesión (idéntica a medico.php) ---
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

// --- Filtro: 'hoy' (por defecto) o 'todas' ---
// Solo aceptamos dos valores posibles (whitelist), así que es seguro
// usarlo directamente en el SQL sin necesidad de parámetro preparado para esta parte.
$filtro = (isset($_GET['filtro']) && $_GET['filtro'] === 'todas') ? 'todas' : 'hoy';
$condicionFecha = ($filtro === 'hoy') ? 'AND DATE(c.fecha_consulta) = CURDATE()' : '';

// --- Consulta: historial de consultas de este médico ---
$sqlConsultas = "
    SELECT c.id_consulta, c.fecha_consulta, c.motivo, c.diagnostico,
           p.nombre, p.apellido, p.cedula,
           r.id_receta
    FROM consultas c
    INNER JOIN pacientes p ON p.id_paciente = c.id_paciente
    LEFT JOIN recetas r ON r.id_consulta = c.id_consulta
    WHERE c.id_medico = :id_medico
    $condicionFecha
    ORDER BY c.fecha_consulta DESC
";
$stmtConsultas = $conexion->prepare($sqlConsultas);
$stmtConsultas->execute([':id_medico' => $id_medico]);
$consultas = $stmtConsultas->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mis Consultas | Sistema de Consultas</title>
    <link rel="stylesheet" href="../css/estilo.css">
    <link rel="stylesheet" href="../css/medico.css">
</head>
<body>

<div class="medico-layout">

    <?php include __DIR__ . '/parciales/topbar.php'; ?>

    <?php $paginaActiva = 'mis_consultas'; include __DIR__ . '/parciales/sidebar.php'; ?>

    <main class="medico-main">

        <div class="medico-main__encabezado">
            <div class="medico-main__titulo">
                <h2>Mis consultas</h2>
                <p>Historial de consultas que has atendido.</p>
            </div>

            <div class="filtro-tabs">
                <a href="mis_consultas.php?filtro=hoy" class="filtro-tab <?= $filtro === 'hoy' ? 'activo' : '' ?>">Hoy</a>
                <a href="mis_consultas.php?filtro=todas" class="filtro-tab <?= $filtro === 'todas' ? 'activo' : '' ?>">Todas</a>
            </div>
        </div>

        <section class="panel-tabla" style="grid-column: 1 / -1;">
            <?php if (empty($consultas)): ?>
                <div class="panel-tabla__vacio">
                    <?= $filtro === 'hoy' ? 'Todavía no has atendido consultas hoy.' : 'No tienes consultas registradas.' ?>
                </div>
            <?php else: ?>
                <table class="tabla-cola">
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Paciente</th>
                            <th>Motivo</th>
                            <th>Diagnóstico</th>
                            <th>Receta</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($consultas as $consulta):
                            $inicialesPaciente = strtoupper(mb_substr($consulta['nombre'], 0, 1) . mb_substr($consulta['apellido'], 0, 1));
                        ?>
                            <tr>
                                <td data-label="Fecha">
                                    <?= date('d/m/Y h:i a', strtotime($consulta['fecha_consulta'])) ?>
                                </td>
                                <td data-label="Paciente">
                                    <div class="celda-paciente">
                                        <div class="avatar-paciente"><?= htmlspecialchars($inicialesPaciente) ?></div>
                                        <div>
                                            <strong><?= htmlspecialchars($consulta['nombre'] . ' ' . $consulta['apellido']) ?></strong>
                                            <span><?= htmlspecialchars($consulta['cedula']) ?></span>
                                        </div>
                                    </div>
                                </td>
                                <td data-label="Motivo" class="texto-recortado"><?= htmlspecialchars($consulta['motivo']) ?></td>
                                <td data-label="Diagnóstico" class="texto-recortado"><?= htmlspecialchars($consulta['diagnostico']) ?></td>
                                <td data-label="Receta">
                                    <?php if ($consulta['id_receta']): ?>
                                        <span class="badge badge-estado-atendido">Con receta</span>
                                    <?php else: ?>
                                        <span class="badge" style="background-color:#eee;color:#777;">Sin receta</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>

    </main>
</div>

<script>
    document.getElementById('btnMenu').addEventListener('click', function () {
        document.getElementById('sidebar').classList.toggle('abierta');
    });
</script>

</body>
</html>
