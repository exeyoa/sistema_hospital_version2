<?php
require_once __DIR__ . '/../config/sesion.php';

// Protección de sesión (igual que en admin.php)
verificarSesion(['admin']);

require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/_iconos.php';

$csrfToken = generarTokenCSRF();

// -----------------------------------------------------------
// Estadísticas de pacientes
// -----------------------------------------------------------
$totalPacientes = (int) $conexion->query("SELECT COUNT(*) FROM pacientes")->fetchColumn();

// Pacientes que ya tienen al menos una consulta registrada
$conConsultas = (int) $conexion->query(
    "SELECT COUNT(DISTINCT id_paciente) FROM consultas"
)->fetchColumn();

// Distribución por sexo
$sexos = ['M', 'F', 'Otro'];
$conteoSexo = array_fill_keys($sexos, 0);
$stmt = $conexion->query("SELECT sexo, COUNT(*) AS c FROM pacientes GROUP BY sexo");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fila) {
    if (isset($conteoSexo[$fila['sexo']])) {
        $conteoSexo[$fila['sexo']] = (int) $fila['c'];
    }
}

// -----------------------------------------------------------
// Buscador funcional (GET ?buscar=)
// -----------------------------------------------------------
$buscar = isset($_GET['buscar']) ? trim($_GET['buscar']) : '';
$donde = '';
$sqlParams = [];
if ($buscar !== '') {
    $donde = "WHERE (p.nombre LIKE :q OR p.apellido LIKE :q OR p.cedula LIKE :q OR p.correo LIKE :q)";
    $sqlParams[':q'] = '%' . $buscar . '%';
}

// -----------------------------------------------------------
// Lista de pacientes paginada
// -----------------------------------------------------------
$porPagina = 6;
$paginaActual = isset($_GET['pagina']) ? max(1, (int) $_GET['pagina']) : 1;
$inicio = ($paginaActual - 1) * $porPagina;

$sqlCount = "SELECT COUNT(*) FROM pacientes p $donde";
$stmt = $conexion->prepare($sqlCount);
$stmt->execute($sqlParams);
$totalPacientesFiltrados = (int) $stmt->fetchColumn();
$totalPaginas = max(1, (int) ceil($totalPacientesFiltrados / $porPagina));

$sql = "SELECT p.id_paciente, p.nombre, p.apellido, p.cedula, p.fecha_nacimiento,
               p.sexo, p.telefono, p.direccion, p.correo,
               COUNT(c.id_consulta) AS total_consultas
        FROM pacientes p
        LEFT JOIN consultas c ON c.id_paciente = p.id_paciente
        $donde
        GROUP BY p.id_paciente, p.nombre, p.apellido, p.cedula, p.fecha_nacimiento,
                 p.sexo, p.telefono, p.direccion, p.correo
        ORDER BY p.nombre, p.apellido
        LIMIT :limite OFFSET :inicio";
$stmt = $conexion->prepare($sql);
foreach ($sqlParams as $clave => $valor) {
    $stmt->bindValue($clave, $valor, PDO::PARAM_STR);
}
$stmt->bindValue(':limite', $porPagina, PDO::PARAM_INT);
$stmt->bindValue(':inicio', $inicio, PDO::PARAM_INT);
$stmt->execute();
$pacientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

$barraBusqueda = $buscar !== '' ? '&buscar=' . urlencode($buscar) : '';

// -----------------------------------------------------------
// Helpers de presentación
// -----------------------------------------------------------
function iniciales($nombre, $apellido) {
    $n = mb_strtoupper(mb_substr($nombre, 0, 1));
    $a = mb_strtoupper(mb_substr($apellido, 0, 1));
    return $n . $a;
}

function fechaMostrar($fecha) {
    if (!$fecha) {
        return '—';
    }
    $dt = DateTime::createFromFormat('Y-m-d', $fecha);
    return $dt ? $dt->format('d/m/Y') : '—';
}

function sexoMostrar($sexo) {
    return ['M' => 'Masculino', 'F' => 'Femenino', 'Otro' => 'Otro'][$sexo] ?? '—';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pacientes</title>
    <link rel="stylesheet" href="../css/estilo.css">
    <link rel="stylesheet" href="../css/admin.css">
    <link rel="stylesheet" href="../css/admin_tema.css">
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
            <a href="admin_medicos.php"><?php echo icono('medico'); ?> Médicos</a>
            <a href="admin_pacientes.php" class="activo"><?php echo icono('paciente'); ?> Pacientes <span class="punto-activo"></span></a>
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
                <h1><?php echo icono('paciente', 22); ?> Pacientes</h1>
                <p class="admin-topbar-subtitulo">Gestiona los pacientes registrados en el sistema</p>
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
                        <div class="tarjeta-stat-icono icono-azul"><?php echo icono('paciente', 20); ?></div>
                        <div class="tarjeta-stat-titulo">Total pacientes</div>
                    </div>
                    <div class="tarjeta-stat-numero"><?php echo $totalPacientes; ?></div>
                    <div class="tarjeta-stat-pie"><span>Registrados en el sistema</span></div>
                </div>

                <div class="tarjeta-stat">
                    <div class="tarjeta-stat-encabezado">
                        <div class="tarjeta-stat-icono icono-verde"><?php echo icono('calendario', 20); ?></div>
                        <div class="tarjeta-stat-titulo">Con consultas</div>
                    </div>
                    <div class="tarjeta-stat-numero"><?php echo $conConsultas; ?></div>
                    <div class="tarjeta-stat-pie"><span>Con al menos una consulta</span></div>
                </div>

                <div class="tarjeta-stat">
                    <div class="tarjeta-stat-encabezado">
                        <div class="tarjeta-stat-icono icono-morado"><?php echo icono('usuarios', 20); ?></div>
                        <div class="tarjeta-stat-titulo">Distribución por sexo</div>
                    </div>
                    <div class="tarjeta-stat-numero">F: <?php echo $conteoSexo['F']; ?> · M: <?php echo $conteoSexo['M']; ?></div>
                    <div class="tarjeta-stat-pie"><span><?php echo $conteoSexo['Otro']; ?> registrado(s) como "Otro"</span></div>
                </div>
            </div>

            <!-- Tarjeta de gestión de pacientes -->
            <div class="tarjeta-usuarios" style="grid-column: 1 / -1;">
                <?php if (isset($_GET['creado'])): ?>
                    <div class="alerta alerta-exito">Paciente creado correctamente.</div>
                <?php elseif (isset($_GET['editado'])): ?>
                    <div class="alerta alerta-exito">Paciente actualizado correctamente.</div>
                <?php elseif (isset($_GET['error']) && $_GET['error'] === 'noEncontrado'): ?>
                    <div class="alerta alerta-error">Paciente no encontrado.</div>
                <?php endif; ?>

                <div class="tarjeta-usuarios-header">
                    <div>
                        <h2>Lista de pacientes</h2>
                        <p>Un paciente no es un usuario del sistema: no inicia sesión con esta cuenta</p>
                    </div>
                    <div class="tarjeta-usuarios-acciones">
                        <form method="get" action="admin_pacientes.php" style="display:flex; gap:8px; flex:1; min-width:0;">
                            <input type="text" name="buscar" class="input-buscar" placeholder="Buscar por nombre, cédula o correo..."
                                   value="<?php echo htmlspecialchars($buscar); ?>">
                            <button class="btn-secundario">Buscar</button>
                            <?php if ($buscar !== ''): ?>
                                <a href="admin_pacientes.php" class="btn-secundario">Limpiar</a>
                            <?php endif; ?>
                        </form>
                        <a href="editar_paciente.php" class="btn-primario"><?php echo icono('usuario-mas', 16); ?> Nuevo Paciente</a>
                    </div>
                </div>

                <table class="tabla-usuarios">
                    <thead>
                        <tr>
                            <th>Paciente</th>
                            <th>Cédula</th>
                            <th>Fecha nac.</th>
                            <th>Sexo</th>
                            <th>Correo</th>
                            <th>Consultas</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($pacientes)): ?>
                        <tr>
                            <td colspan="7" style="text-align:center; color: var(--admin-texto-suave); padding:20px;">
                                <?php if ($buscar !== ''): ?>
                                    No se encontraron pacientes para "<?php echo htmlspecialchars($buscar); ?>".
                                    <a href="admin_pacientes.php">Limpiar búsqueda</a>
                                <?php else: ?>
                                    Todavía no hay pacientes registrados. Usa "Nuevo Paciente" para agregar el primero.
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endif; ?>

                        <?php foreach ($pacientes as $p):
                            $iniciales = iniciales($p['nombre'], $p['apellido']);
                            $colorAvatar = ['#2563eb', '#16a34a', '#7c3aed', '#d97706', '#0891b2'][$p['id_paciente'] % 5];
                        ?>
                        <tr>
                            <td data-label="Paciente">
                                <div class="celda-nombre">
                                    <div class="avatar-usuario" style="background: <?php echo $colorAvatar; ?>;">
                                        <?php echo htmlspecialchars($iniciales); ?>
                                    </div>
                                    <div class="info-nombre">
                                        <strong><?php echo htmlspecialchars($p['nombre'] . ' ' . $p['apellido']); ?></strong>
                                    </div>
                                </div>
                            </td>
                            <td data-label="Cédula"><?php echo htmlspecialchars($p['cedula']); ?></td>
                            <td data-label="Fecha nac."><?php echo fechaMostrar($p['fecha_nacimiento']); ?></td>
                            <td data-label="Sexo"><?php echo htmlspecialchars(sexoMostrar($p['sexo'])); ?></td>
                            <td data-label="Correo"><?php echo $p['correo'] !== null ? htmlspecialchars($p['correo']) : '—'; ?></td>
                            <td data-label="Consultas"><?php echo (int) $p['total_consultas']; ?></td>
                            <td data-label="Acciones" class="acciones-fila">
                                <a href="editar_paciente.php?id=<?php echo $p['id_paciente']; ?>" title="Editar"><?php echo icono('editar', 15); ?></a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <!-- Paginación -->
                <div class="paginacion">
                    <span>
                        Mostrando <?php echo $totalPacientesFiltrados === 0 ? 0 : $inicio + 1; ?>
                        a <?php echo min($inicio + $porPagina, $totalPacientesFiltrados); ?>
                        de <?php echo $totalPacientesFiltrados; ?> pacientes
                    </span>
                    <div class="paginacion-botones">
                        <?php if ($paginaActual > 1): ?>
                            <a href="?pagina=<?php echo $paginaActual - 1 . $barraBusqueda; ?>">‹</a>
                        <?php endif; ?>

                        <?php for ($p = 1; $p <= $totalPaginas; $p++): ?>
                            <?php if ($p == $paginaActual): ?>
                                <span class="pagina-actual"><?php echo $p; ?></span>
                            <?php else: ?>
                                <a href="?pagina=<?php echo $p . $barraBusqueda; ?>"><?php echo $p; ?></a>
                            <?php endif; ?>
                        <?php endfor; ?>

                        <?php if ($paginaActual < $totalPaginas): ?>
                            <a href="?pagina=<?php echo $paginaActual + 1 . $barraBusqueda; ?>">›</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="admin-footer">
            © <?php echo date('Y'); ?> Hospital San Rafael. Todos los derechos reservados.
        </div>
    </div>
</div>

</body>
</html>