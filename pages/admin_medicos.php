<?php
session_start();

if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] !== 'admin') {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/_iconos.php';

// -----------------------------------------------------------
// Estadísticas rápidas de médicos
// -----------------------------------------------------------
$totalMedicos = (int) $conexion->query(
    "SELECT COUNT(*) FROM usuarios u INNER JOIN roles r ON u.id_rol = r.id_rol WHERE r.nombre_rol = 'medico'"
)->fetchColumn();

$medicosActivos = (int) $conexion->query(
    "SELECT COUNT(*) FROM usuarios u INNER JOIN roles r ON u.id_rol = r.id_rol
     WHERE r.nombre_rol = 'medico' AND u.activo = 1"
)->fetchColumn();

$totalEspecialidades = (int) $conexion->query("SELECT COUNT(*) FROM especialidades")->fetchColumn();

// -----------------------------------------------------------
// Lista de médicos (usuarios con rol medico + su fila en medicos)
// -----------------------------------------------------------
$sql = "SELECT u.id_usuario, u.nombre, u.apellido, u.correo, u.activo,
               m.numero_colegiado, e.nombre_especialidad
        FROM usuarios u
        INNER JOIN roles r ON u.id_rol = r.id_rol
        LEFT JOIN medicos m ON m.id_usuario = u.id_usuario
        LEFT JOIN especialidades e ON e.id_especialidad = m.id_especialidad
        WHERE r.nombre_rol = 'medico'
        ORDER BY u.nombre, u.apellido";
$medicos = $conexion->query($sql)->fetchAll(PDO::FETCH_ASSOC);

function iniciales($nombre, $apellido) {
    $n = mb_strtoupper(mb_substr($nombre, 0, 1));
    $a = mb_strtoupper(mb_substr($apellido, 0, 1));
    return $n . $a;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Médicos</title>
    <link rel="stylesheet" href="../css/estilo.css">
    <link rel="stylesheet" href="../css/admin.css">
</head>
<body class="admin-body">

<div class="admin-layout">

    <!-- ================= SIDEBAR ================= -->
    <aside class="admin-sidebar">
        <div class="admin-logo">
            <span class="icono-logo"><?php echo icono('cruz-medica', 22); ?></span>
            Hospital San Rafael
        </div>

        <nav class="admin-nav">
            <a href="admin.php"><?php echo icono('home'); ?> Panel de Control</a>

            <div class="admin-nav-seccion">Gestión</div>
            <a href="admin.php"><?php echo icono('usuarios'); ?> Usuarios</a>
            <a href="admin_medicos.php" class="activo"><?php echo icono('medico'); ?> Médicos <span class="punto-activo"></span></a>
            <a href="admin_pacientes.php"><?php echo icono('paciente'); ?> Pacientes</a>
            <a href="admin_especialidades.php"><?php echo icono('estrella'); ?> Especialidades</a>
            <a href="admin_consultas.php"><?php echo icono('calendario'); ?> Consultas</a>
            <a href="admin_recetas.php"><?php echo icono('archivo'); ?> Recetas</a>

            <div class="admin-nav-seccion">Sistema</div>
            <a href="admin_reportes.php"><?php echo icono('grafico'); ?> Reportes</a>
            <a href="admin_configuracion.php"><?php echo icono('engranaje'); ?> Configuración</a>
            <a href="logout.php"><?php echo icono('salir'); ?> Cerrar Sesión</a>
        </nav>

        <div class="admin-sidebar-footer">
            <div class="avatar-mini"><?php echo htmlspecialchars(iniciales($_SESSION['nombre'], '')); ?></div>
            <div>
                <div style="font-size:0.85rem; font-weight:600;"><?php echo htmlspecialchars($_SESSION['nombre']); ?></div>
                <div class="estado-linea">En línea</div>
            </div>
        </div>
    </aside>

    <!-- ================= CONTENIDO PRINCIPAL ================= -->
    <div class="admin-main">

        <div class="admin-topbar">
            <div class="admin-topbar-titulos">
                <h1><?php echo icono('medico', 22); ?> Médicos</h1>
                <p class="admin-topbar-subtitulo">Gestiona los médicos registrados en el sistema</p>
            </div>
            <div class="admin-topbar-derecha">
                <div class="admin-campana"><?php echo icono('campana', 20); ?><span class="badge-num"></span></div>
                <div class="admin-usuario-topbar">
                    <div class="avatar-mini"><?php echo htmlspecialchars(iniciales($_SESSION['nombre'], '')); ?></div>
                    <?php echo htmlspecialchars($_SESSION['nombre']); ?> ▾
                </div>
            </div>
        </div>

        <div class="admin-contenido">

            <!-- Tarjetas de estadísticas -->
            <div class="admin-stats-row">
                <div class="tarjeta-stat">
                    <div class="tarjeta-stat-encabezado">
                        <div class="tarjeta-stat-icono icono-azul"><?php echo icono('medico', 20); ?></div>
                        <div class="tarjeta-stat-titulo">Total de médicos</div>
                    </div>
                    <div class="tarjeta-stat-numero"><?php echo $totalMedicos; ?></div>
                    <div class="tarjeta-stat-pie"><span>Registrados en el sistema</span></div>
                </div>

                <div class="tarjeta-stat">
                    <div class="tarjeta-stat-encabezado">
                        <div class="tarjeta-stat-icono icono-verde"><?php echo icono('escudo', 20); ?></div>
                        <div class="tarjeta-stat-titulo">Médicos activos</div>
                    </div>
                    <div class="tarjeta-stat-numero"><?php echo $medicosActivos; ?></div>
                    <div class="tarjeta-stat-pie"><span>Pueden acceder al sistema</span></div>
                </div>

                <div class="tarjeta-stat">
                    <div class="tarjeta-stat-encabezado">
                        <div class="tarjeta-stat-icono icono-morado"><?php echo icono('estrella', 20); ?></div>
                        <div class="tarjeta-stat-titulo">Especialidades</div>
                    </div>
                    <div class="tarjeta-stat-numero"><?php echo $totalEspecialidades; ?></div>
                    <div class="tarjeta-stat-pie"><span>Disponibles en el sistema</span></div>
                </div>
            </div>

            <!-- Tarjeta de gestión de médicos -->
            <div class="tarjeta-usuarios" style="grid-column: 1 / -1;">
                <?php if (isset($_GET['editado'])): ?>
                    <div class="alerta alerta-exito">Médico actualizado correctamente.</div>
                <?php elseif (isset($_GET['estadoActualizado'])): ?>
                    <div class="alerta alerta-exito">Estado del médico actualizado.</div>
                <?php endif; ?>

                <div class="tarjeta-usuarios-header">
                    <div>
                        <h2>Lista de médicos</h2>
                        <p>Cada médico es también un usuario del sistema, por eso comparte edición con el panel de Usuarios</p>
                    </div>
                    <div class="tarjeta-usuarios-acciones">
                        <a href="crear_usuario.php?rol=medico" class="btn-primario"><?php echo icono('usuario-mas', 16); ?> Nuevo Médico</a>
                    </div>
                </div>

                <table class="tabla-usuarios">
                    <thead>
                        <tr>
                            <th>Médico</th>
                            <th>Especialidad</th>
                            <th>Colegiado</th>
                            <th>Correo</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($medicos)): ?>
                        <tr>
                            <td colspan="6" style="text-align:center; color: var(--admin-texto-suave); padding:20px;">
                                Todavía no hay médicos registrados. Usa "Nuevo Médico" para agregar el primero.
                            </td>
                        </tr>
                        <?php endif; ?>

                        <?php foreach ($medicos as $m):
                            $iniciales = iniciales($m['nombre'], $m['apellido']);
                            $colorAvatar = ['#2563eb', '#16a34a', '#7c3aed', '#d97706', '#0891b2'][$m['id_usuario'] % 5];
                        ?>
                        <tr>
                            <td>
                                <div class="celda-nombre">
                                    <div class="avatar-usuario" style="background: <?php echo $colorAvatar; ?>;">
                                        <?php echo htmlspecialchars($iniciales); ?>
                                    </div>
                                    <div class="info-nombre">
                                        <strong>Dr(a). <?php echo htmlspecialchars($m['nombre'] . ' ' . $m['apellido']); ?></strong>
                                    </div>
                                </div>
                            </td>
                            <td><?php echo htmlspecialchars($m['nombre_especialidad'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars($m['numero_colegiado'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars($m['correo']); ?></td>
                            <td>
                                <?php if ($m['activo']): ?>
                                    <span class="estado-punto estado-activo">Activo</span>
                                <?php else: ?>
                                    <span class="estado-punto estado-inactivo">Inactivo</span>
                                <?php endif; ?>
                            </td>
                            <td class="acciones-fila">
                                <a href="editar_usuario.php?id=<?php echo $m['id_usuario']; ?>" title="Editar"><?php echo icono('editar', 15); ?></a>
                                <?php if ($m['activo']): ?>
                                    <a href="cambiar_estado_usuario.php?id=<?php echo $m['id_usuario']; ?>&volver=medicos" title="Desactivar"
                                       onclick="return confirm('¿Desactivar a Dr(a). <?php echo htmlspecialchars(addslashes($m['nombre'] . ' ' . $m['apellido'])); ?>?');">
                                        <?php echo icono('candado', 15); ?>
                                    </a>
                                <?php else: ?>
                                    <a href="cambiar_estado_usuario.php?id=<?php echo $m['id_usuario']; ?>&volver=medicos" title="Activar"
                                       onclick="return confirm('¿Activar a Dr(a). <?php echo htmlspecialchars(addslashes($m['nombre'] . ' ' . $m['apellido'])); ?>?');">
                                        <?php echo icono('escudo', 15); ?>
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="admin-footer">
            © <?php echo date('Y'); ?> Hospital San Rafael. Todos los derechos reservados.
        </div>
    </div>
</div>

</body>
</html>
