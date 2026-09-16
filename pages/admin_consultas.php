<?php
require_once __DIR__ . '/../config/sesion.php';

// Protección de sesión: solo administradores.
verificarSesion(['admin']);

require_once __DIR__ . '/_iconos.php'; // función icono() para el HTML
require_once __DIR__ . '/../config/conexion.php'; // expone $conexion (PDO)

// ---------------------------------------------------------------
// Filtros combinables por GET (fecha, especialidad, estado, buscar)
// ---------------------------------------------------------------
$fecha = isset($_GET['fecha']) ? trim($_GET['fecha']) : '';
if ($fecha !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
    $fecha = '';
}

$idEspecialidad = isset($_GET['id_especialidad']) ? trim($_GET['id_especialidad']) : '';
if ($idEspecialidad !== '' && !ctype_digit($idEspecialidad)) {
    $idEspecialidad = '';
}

$estado = isset($_GET['estado']) ? trim($_GET['estado']) : '';
if ($estado !== '' && !in_array($estado, ['en_espera', 'en_consulta', 'atendido'], true)) {
    $estado = '';
}

$buscar = isset($_GET['buscar']) ? trim($_GET['buscar']) : '';

// Calendario: mes visible (YYYY-MM), por defecto el actual; si hay filtro
// de fecha y no viene mes, seguimos el mes de esa fecha.
$mesSeleccion = isset($_GET['mes']) ? trim($_GET['mes']) : '';
if (!preg_match('/^\d{4}-\d{2}$/', $mesSeleccion)) {
    $mesSeleccion = $fecha !== '' ? substr($fecha, 0, 7) : date('Y-m');
}

$porPagina = 12;
$paginaActual = isset($_GET['pagina']) ? max(1, (int) $_GET['pagina']) : 1;

// ---------------------------------------------------------------
// Construcción de la cláusula WHERE (dinámica y combinable)
// ---------------------------------------------------------------
$joins = "FROM turnos t
          LEFT JOIN consultas c ON c.id_turno = t.id_turno
          LEFT JOIN pacientes p ON t.id_paciente = p.id_paciente
          LEFT JOIN medicos m ON c.id_medico = m.id_medico
          LEFT JOIN usuarios u ON m.id_usuario = u.id_usuario
          LEFT JOIN especialidades e ON m.id_especialidad = e.id_especialidad";

$donde = [];
$sqlParams = [];

if ($fecha !== '') {
    $donde[] = 't.fecha = :fecha';
    $sqlParams[':fecha'] = $fecha;
}
if ($idEspecialidad !== '') {
    $donde[] = 'e.id_especialidad = :idesp';
    $sqlParams[':idesp'] = $idEspecialidad;
}
if ($estado !== '') {
    $donde[] = 't.estado = :estado';
    $sqlParams[':estado'] = $estado;
}
if ($buscar !== '') {
    $donde[] = '(p.nombre LIKE :q OR p.apellido LIKE :q OR p.cedula LIKE :q)';
    $sqlParams[':q'] = '%' . $buscar . '%';
}

$clausulaDonde = $donde === [] ? '' : 'WHERE ' . implode(' AND ', $donde);

// ---------------------------------------------------------------
// Estadísticas (tarjetas)
// ---------------------------------------------------------------
// 1) Total de la lista filtrada
$stmt = $conexion->prepare("SELECT COUNT(*) $joins $clausulaDonde");
$stmt->execute($sqlParams);
$totalFiltrado = (int) $stmt->fetchColumn();

// 2) Pacientes distintos de la lista filtrada
$stmt = $conexion->prepare("SELECT COUNT(DISTINCT t.id_paciente) $joins $clausulaDonde");
$stmt->execute($sqlParams);
$pacientesDistintos = (int) $stmt->fetchColumn();

// 3) Consultas de hoy (global)
$consultasHoy = (int) $conexion->query(
    "SELECT COUNT(*) FROM consultas WHERE DATE(fecha_consulta) = CURDATE()"
)->fetchColumn();

// 4) En espera / en consulta hoy (global)
$enEsperaHoy = (int) $conexion->query(
    "SELECT COUNT(*) FROM turnos WHERE estado IN ('en_espera', 'en_consulta') AND fecha = CURDATE()"
)->fetchColumn();

// ---------------------------------------------------------------
// Lista paginada
// ---------------------------------------------------------------
$totalPaginas = max(1, (int) ceil($totalFiltrado / $porPagina));
if ($paginaActual > $totalPaginas) {
    $paginaActual = $totalPaginas;
}
$inicio = ($paginaActual - 1) * $porPagina;

$sql = "SELECT t.id_turno, t.numero_turno, t.tipo, t.estado, t.fecha,
               p.nombre AS p_nombre, p.apellido AS p_apellido, p.cedula,
               c.id_consulta, c.fecha_consulta, c.motivo, c.diagnostico, c.observaciones,
               u.nombre AS m_nombre, u.apellido AS m_apellido,
               e.nombre_especialidad
        $joins
        $clausulaDonde
        ORDER BY t.fecha DESC, CAST(t.numero_turno AS UNSIGNED) ASC, t.id_turno DESC
        LIMIT :limite OFFSET :inicio";

$stmt = $conexion->prepare($sql);
foreach ($sqlParams as $clave => $valor) {
    $stmt->bindValue($clave, $valor, PDO::PARAM_STR);
}
$stmt->bindValue(':limite', $porPagina, PDO::PARAM_INT);
$stmt->bindValue(':inicio', $inicio, PDO::PARAM_INT);
$stmt->execute();
$filas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ---------------------------------------------------------------
// Datos para el panel lateral
// ---------------------------------------------------------------
$especialidades = $conexion->query(
    "SELECT id_especialidad, nombre_especialidad FROM especialidades ORDER BY nombre_especialidad"
)->fetchAll(PDO::FETCH_ASSOC);

// Próximas consultas (turnos no atendidos desde hoy)
$stmt = $conexion->query(
    "SELECT t.numero_turno, t.fecha, t.estado, p.nombre, p.apellido, c.motivo, c.fecha_consulta
     FROM turnos t
     JOIN pacientes p ON t.id_paciente = p.id_paciente
     LEFT JOIN consultas c ON c.id_turno = t.id_turno
     WHERE t.estado IN ('en_espera', 'en_consulta') AND t.fecha >= CURDATE()
     ORDER BY t.fecha ASC, CAST(t.numero_turno AS UNSIGNED) ASC
     LIMIT 6"
);
$proximas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ---------------------------------------------------------------
// Calendario del mes (generado en PHP, sin JavaScript)
// ---------------------------------------------------------------
list($anioCal, $mesCal) = array_map('intval', explode('-', $mesSeleccion));
if ($mesCal < 1 || $mesCal > 12) {
    $mesCal = (int) date('m');
    $anioCal = (int) date('Y');
    $mesSeleccion = date('Y-m');
}
$timestampPrimer = mktime(0, 0, 0, $mesCal, 1, $anioCal);
$mesAnterior = date('Y-m', strtotime('-1 month', $timestampPrimer));
$mesSiguiente = date('Y-m', strtotime('+1 month', $timestampPrimer));
$diasEnMes = (int) date('t', $timestampPrimer);
$inicioSemana = (int) date('w', $timestampPrimer); // 0=Domingo

$nombresMeses = [
    1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio',
    7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
];

$anioHoy = (int) date('Y');
$mesHoy  = (int) date('n');
$diaHoy  = (int) date('j');

$primerDiaMes = $anioCal . '-' . str_pad((string) $mesCal, 2, '0', STR_PAD_LEFT) . '-01';
$ultimoDiaMes = date('Y-m-t', $timestampPrimer);
$stmt = $conexion->prepare("SELECT DISTINCT fecha FROM turnos WHERE fecha BETWEEN :ini AND :fin");
$stmt->execute([':ini' => $primerDiaMes, ':fin' => $ultimoDiaMes]);
$diasConTurnos = [];
foreach ($stmt->fetchAll(PDO::FETCH_NUM) as $filaCal) {
    $diasConTurnos[] = $filaCal[0];
}

// Cadena para conservar filtros en la paginación
$filtrosLink = '';
foreach (['fecha', 'id_especialidad', 'estado', 'buscar', 'mes'] as $claveFiltro) {
    if (isset($_GET[$claveFiltro]) && $_GET[$claveFiltro] !== '') {
        $filtrosLink .= '&' . $claveFiltro . '=' . urlencode($_GET[$claveFiltro]);
    }
}

// ---------------------------------------------------------------
// Helpers de presentación
// ---------------------------------------------------------------
function conIniciales($nombre, $apellido) {
    $n = mb_strtoupper(mb_substr((string) $nombre, 0, 1));
    $a = mb_strtoupper(mb_substr((string) $apellido, 0, 1));
    return $n . $a;
}

function conHoraTurno($fila) {
    if (!empty($fila['fecha_consulta'])) {
        return date('H:i', strtotime($fila['fecha_consulta']));
    }
    return 'Turno ' . $fila['numero_turno'];
}

function conEstadoUI($estado) {
    $mapa = [
        'en_espera'   => ['texto' => 'En espera',   'clase' => 'con-badge-espera'],
        'en_consulta' => ['texto' => 'En consulta', 'clase' => 'con-badge-consulta'],
        'atendido'    => ['texto' => 'Atendido',    'clase' => 'con-badge-atendido'],
    ];
    return $mapa[$estado] ?? ['texto' => $estado, 'clase' => 'con-badge-espera'];
}

function conTipoTexto($tipo) {
    return $tipo === 'con_cita' ? 'Con cita' : 'Espontáneo';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Consultas - Hospital San Rafael</title>
    <link rel="stylesheet" href="../css/estilo.css">
    <link rel="stylesheet" href="../css/admin_consultas.css">
    <link rel="stylesheet" href="../css/admin_tema.css">
</head>
<body class="con-body">

<div class="con-layout">

    <!-- ================= SIDEBAR LIGHT ================= -->
    <aside class="con-sidebar">
        <div class="con-logo">
            <span><?php echo icono('cruz-medica', 20); ?></span>
            Hospital San Rafael
        </div>

        <nav class="con-nav">
            <a href="admin.php"><?php echo icono('home'); ?> Panel de Control</a>

            <div class="con-nav-seccion">Gestión</div>
            <a href="admin.php"><?php echo icono('usuarios'); ?> Usuarios</a>
            <a href="admin_medicos.php"><?php echo icono('medico'); ?> Médicos</a>
            <a href="admin_pacientes.php"><?php echo icono('paciente'); ?> Pacientes</a>
            <a href="admin_especialidades.php"><?php echo icono('estrella'); ?> Especialidades</a>
            <a href="admin_consultas.php" class="activo"><?php echo icono('calendario'); ?> Consultas</a>
            <a href="admin_recetas.php"><?php echo icono('archivo'); ?> Recetas</a>

            <div class="con-nav-seccion">Sistema</div>
            <a href="admin_reportes.php"><?php echo icono('grafico'); ?> Reportes</a>
            <a href="admin_configuracion.php"><?php echo icono('engranaje'); ?> Configuración</a>
            <a href="logout.php"><?php echo icono('salir'); ?> Cerrar Sesión</a>
        </nav>

        <div class="con-sidebar-footer">
            <div class="con-avatar"><?php echo htmlspecialchars(mb_strtoupper(mb_substr($_SESSION['nombre'] ?? '', 0, 1))); ?></div>
            <div>
                <div style="font-size:0.82rem; font-weight:600;"><?php echo htmlspecialchars($_SESSION['nombre'] ?? ''); ?></div>
                <div class="con-estado">En línea</div>
            </div>
        </div>
    </aside>

    <!-- ================= CONTENIDO PRINCIPAL ================= -->
    <div class="con-main">

        <header class="con-banner">
            <span><?php echo icono('calendario', 26); ?></span>
            <div>
                <h1 class="con-banner-titulo">Consultas</h1>
                <p class="con-banner-desc">Vista global de todas las citas y consultas del hospital</p>
            </div>
        </header>

        <!-- ============ TARJETAS DE ESTADÍSTICAS ============ -->
        <div class="con-stats-row">
            <div class="con-stat-card">
                <div class="con-stat-encabezado">
                    <div class="con-stat-icono azul"><?php echo icono('calendario', 20); ?></div>
                    <div class="con-stat-titulo">Total (filtrado)</div>
                </div>
                <div class="con-stat-numero"><?php echo $totalFiltrado; ?></div>
                <div class="con-stat-pie"><span>Turnos/consultas según filtros</span></div>
            </div>

            <div class="con-stat-card">
                <div class="con-stat-encabezado">
                    <div class="con-stat-icono morado"><?php echo icono('usuarios', 20); ?></div>
                    <div class="con-stat-titulo">Pacientes distintos</div>
                </div>
                <div class="con-stat-numero"><?php echo $pacientesDistintos; ?></div>
                <div class="con-stat-pie"><span>Según filtros activos</span></div>
            </div>

            <div class="con-stat-card">
                <div class="con-stat-encabezado">
                    <div class="con-stat-icono verde"><?php echo icono('medico', 20); ?></div>
                    <div class="con-stat-titulo">Consultas de hoy</div>
                </div>
                <div class="con-stat-numero"><?php echo $consultasHoy; ?></div>
                <div class="con-stat-pie"><span><?php echo date('d/m/Y'); ?></span></div>
            </div>

            <div class="con-stat-card">
                <div class="con-stat-encabezado">
                    <div class="con-stat-icono ambra"><?php echo icono('paciente', 20); ?></div>
                    <div class="con-stat-titulo">En espera hoy</div>
                </div>
                <div class="con-stat-numero"><?php echo $enEsperaHoy; ?></div>
                <div class="con-stat-pie"><span>Espera o en consulta hoy</span></div>
            </div>
        </div>

        <!-- ============ FILTROS ============ -->
        <div class="con-toolbar">
            <form method="get" action="admin_consultas.php">
                <div class="con-filtros">
                    <div class="con-filtro">
                        <label for="fecha">Fecha</label>
                        <input type="date" id="fecha" name="fecha" value="<?php echo htmlspecialchars($fecha); ?>">
                    </div>
                    <div class="con-filtro">
                        <label for="id_especialidad">Especialidad</label>
                        <select id="id_especialidad" name="id_especialidad">
                            <option value="">Todas</option>
                            <?php foreach ($especialidades as $esp): ?>
                                <option value="<?php echo $esp['id_especialidad']; ?>"
                                    <?php echo (string) $esp['id_especialidad'] === $idEspecialidad ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($esp['nombre_especialidad']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="con-filtro">
                        <label for="estado">Estado</label>
                        <select id="estado" name="estado">
                            <option value="">Todos</option>
                            <option value="en_espera" <?php echo $estado === 'en_espera' ? 'selected' : ''; ?>>En espera</option>
                            <option value="en_consulta" <?php echo $estado === 'en_consulta' ? 'selected' : ''; ?>>En consulta</option>
                            <option value="atendido" <?php echo $estado === 'atendido' ? 'selected' : ''; ?>>Atendido</option>
                        </select>
                    </div>
                    <div class="con-filtro con-filtro-buscar">
                        <label for="buscar">Buscar paciente</label>
                        <input type="text" id="buscar" name="buscar" placeholder="Nombre o cédula" value="<?php echo htmlspecialchars($buscar); ?>">
                    </div>
                    <div class="con-filtro con-filtro-boton">
                        <?php if ($fecha !== '' || $idEspecialidad !== '' || $estado !== '' || $buscar !== ''): ?>
                            <a href="admin_consultas.php" class="con-btn con-btn-secundario">Limpiar</a>
                        <?php endif; ?>
                        <button type="submit" class="con-btn con-btn-primario">Filtrar</button>
                    </div>
                    <input type="hidden" name="mes" value="<?php echo htmlspecialchars($mesSeleccion); ?>">
                </div>
            </form>
        </div>

        <!-- ============ CONTENIDO: LISTA + PANEL LATERAL ============ -->
        <div class="con-contenido">

            <!-- Tabla de consultas -->
            <div>
                <div class="con-tabla-contenedor">
                    <table class="con-tabla">
                        <thead>
                            <tr>
                                <th>Hora / Turno</th>
                                <th>Paciente</th>
                                <th>Motivo</th>
                                <th>Especialidad</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($filas) === 0): ?>
                                <tr>
                                    <td colspan="6" class="con-vacio">
                                        No se encontraron consultas para los filtros seleccionados.
                                        <?php if ($fecha !== '' || $idEspecialidad !== '' || $estado !== '' || $buscar !== ''): ?>
                                            <a href="admin_consultas.php">Limpiar filtros</a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endif; ?>

                            <?php foreach ($filas as $fila):
                                $ui = conEstadoUI($fila['estado']);
                                $medicoNombre = ($fila['m_nombre'] ?? '') !== '' ? trim($fila['m_nombre'] . ' ' . $fila['m_apellido']) : null;
                                $payload = json_encode([
                                    'paciente'        => trim(($fila['p_nombre'] ?? '') . ' ' . ($fila['p_apellido'] ?? '')),
                                    'cedula'          => $fila['cedula'] ?? '—',
                                    'numero_turno'    => $fila['numero_turno'],
                                    'tipo'            => conTipoTexto($fila['tipo']),
                                    'fecha'           => $fila['fecha'],
                                    'hora'            => conHoraTurno($fila),
                                    'estado_texto'    => $ui['texto'],
                                    'estado_clase'    => $ui['clase'],
                                    'motivo'          => $fila['motivo'] ?? null,
                                    'diagnostico'     => $fila['diagnostico'] ?? null,
                                    'observaciones'   => $fila['observaciones'] ?? null,
                                    'medico'          => $medicoNombre,
                                    'especialidad'    => $fila['nombre_especialidad'] ?? null,
                                ], JSON_UNESCAPED_UNICODE);
                            ?>
                            <tr data-payload="<?php echo htmlspecialchars($payload, ENT_QUOTES, 'UTF-8'); ?>">
                                <td data-label="Hora / Turno">
                                    <strong><?php echo htmlspecialchars(conHoraTurno($fila)); ?></strong>
                                    <span style="display:block; font-size:0.75rem; color:var(--con-texto-suave);">
                                        <?php echo htmlspecialchars(conTipoTexto($fila['tipo'])); ?>
                                    </span>
                                </td>
                                <td data-label="Paciente">
                                    <div class="con-tabla-paciente">
                                        <div class="con-tabla-avatar">
                                            <?php echo htmlspecialchars(conIniciales($fila['p_nombre'], $fila['p_apellido'])); ?>
                                        </div>
                                        <div class="con-tabla-nombre">
                                            <strong><?php echo htmlspecialchars(trim(($fila['p_nombre'] ?? '') . ' ' . ($fila['p_apellido'] ?? ''))); ?></strong>
                                            <span>Cédula: <?php echo htmlspecialchars($fila['cedula'] ?? '—'); ?></span>
                                        </div>
                                    </div>
                                </td>
                                <td data-label="Motivo">
                                    <?php echo $fila['motivo'] !== null ? htmlspecialchars($fila['motivo']) : '—'; ?>
                                </td>
                                <td data-label="Especialidad">
                                    <?php echo $fila['nombre_especialidad'] !== null ? htmlspecialchars($fila['nombre_especialidad']) : '—'; ?>
                                </td>
                                <td data-label="Estado">
                                    <span class="con-badge <?php echo $ui['clase']; ?>"><?php echo $ui['texto']; ?></span>
                                </td>
                                <td data-label="Acciones">
                                    <button type="button" class="con-btn con-btn-ver con-btn-peq" onclick="conVerDetalle(this)">Ver detalle</button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <?php if ($totalPaginas > 1): ?>
                        <div class="con-paginacion">
                            <span class="con-pag-info">Página <?php echo $paginaActual; ?> de <?php echo $totalPaginas; ?></span>
                            <?php if ($paginaActual > 1): ?>
                                <a href="admin_consultas.php?pagina=<?php echo $paginaActual - 1 . $filtrosLink; ?>">&laquo;</a>
                            <?php endif; ?>
                            <?php for ($i = 1; $i <= $totalPaginas; $i++): ?>
                                <?php if ($i === $paginaActual): ?>
                                    <span class="con-pag-actual"><?php echo $i; ?></span>
                                <?php else: ?>
                                    <a href="admin_consultas.php?pagina=<?php echo $i . $filtrosLink; ?>"><?php echo $i; ?></a>
                                <?php endif; ?>
                            <?php endfor; ?>
                            <?php if ($paginaActual < $totalPaginas): ?>
                                <a href="admin_consultas.php?pagina=<?php echo $paginaActual + 1 . $filtrosLink; ?>">&raquo;</a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ============ PANEL LATERAL ============ -->
            <aside class="con-panel-lateral">

                <!-- Calendario del mes -->
                <section class="con-lateral-card">
                    <h3 class="con-lateral-titulo">Calendario</h3>
                    <div class="con-cal-cabecera">
                        <a class="con-cal-flecha" href="admin_consultas.php?mes=<?php echo $mesAnterior; ?>" title="Mes anterior">&laquo;</a>
                        <span class="con-cal-mes"><?php echo mb_strtoupper($nombresMeses[$mesCal] . ' ' . $anioCal); ?></span>
                        <a class="con-cal-flecha" href="admin_consultas.php?mes=<?php echo $mesSiguiente; ?>" title="Mes siguiente">&raquo;</a>
                    </div>
                    <div class="con-cal-grid">
                        <?php
                        $nombresDias = ['D', 'L', 'M', 'M', 'J', 'V', 'S'];
                        foreach ($nombresDias as $nd) {
                            echo '<div class="con-cal-nombre-dia">' . $nd . '</div>';
                        }
                        for ($i = 0; $i < $inicioSemana; $i++) {
                            echo '<div class="con-cal-dia otro-mes"></div>';
                        }
                        for ($dia = 1; $dia <= $diasEnMes; $dia++) {
                            $fechaDia = $anioCal . '-' . str_pad((string) $mesCal, 2, '0', STR_PAD_LEFT) . '-' . str_pad((string) $dia, 2, '0', STR_PAD_LEFT);
                            $clases = ['con-cal-dia'];
                            if ($anioCal === $anioHoy && $mesCal === $mesHoy && $dia === $diaHoy) {
                                $clases[] = 'hoy';
                            }
                            if ($fechaDia === $fecha) {
                                $clases[] = 'activo';
                            }
                            if (in_array($fechaDia, $diasConTurnos, true)) {
                                $clases[] = 'con-cal-con-cita';
                            }
                            $texto = $dia === $diaHoy ? 'Hoy' : (string) $dia;
                            echo '<a class="' . implode(' ', $clases) . '" title="' . date('d/m/Y', strtotime($fechaDia)) . '" '
                               . 'href="admin_consultas.php?fecha=' . $fechaDia . '&mes=' . $mesSeleccion . '">' . $texto . '</a>';
                        }
                        $celdasNecesarias = intdiv($inicioSemana + $diasEnMes + 6, 7) * 7;
                        for ($i = $inicioSemana + $diasEnMes; $i < $celdasNecesarias; $i++) {
                            echo '<div class="con-cal-dia otro-mes"></div>';
                        }
                        ?>
                    </div>
                    <div style="font-size:0.72rem; color:var(--con-texto-suave); margin-top:8px; text-align:center;">
                        Haz clic en un día para filtrar la lista
                    </div>
                </section>

                <!-- Próximas consultas -->
                <section class="con-lateral-card">
                    <h3 class="con-lateral-titulo">Próximas consultas</h3>
                    <?php if (count($proximas) === 0): ?>
                        <p class="con-prox-vacio">No hay turnos en espera desde hoy.</p>
                    <?php else: ?>
                        <div class="con-proximas">
                            <?php foreach ($proximas as $px): ?>
                                <div class="con-proxima">
                                    <span class="con-proxima-hora">#<?php echo htmlspecialchars($px['numero_turno']); ?></span>
                                    <div class="con-proxima-info">
                                        <strong><?php echo htmlspecialchars(trim($px['nombre'] . ' ' . $px['apellido'])); ?></strong>
                                        <span>
                                            <?php echo date('d/m/Y', strtotime($px['fecha'])); ?>
                                            <?php if ($px['motivo'] !== null): ?> · <?php echo htmlspecialchars($px['motivo']); ?><?php endif; ?>
                                        </span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>

            </aside>
        </div>

        <footer class="con-footer">
            Hospital San Rafael · Panel de Administración · Consultas
        </footer>
    </div>
</div>

<!-- ============ MODAL VER DETALLE ============ -->
<div id="conModalOverlay" class="con-modal-overlay oculto" onclick="if (event.target === this) conCerrarModal()">
    <div class="con-modal" role="dialog" aria-modal="true" aria-labelledby="conModalTitulo">
        <div class="con-modal-cabecera">
            <div class="con-modal-nombre">
                <span class="con-modal-icono"><?php echo icono('calendario', 22); ?></span>
                <h3 id="conModalTitulo" class="con-modal-titulo">Detalle de la consulta</h3>
            </div>
            <button type="button" class="con-modal-cerrar" onclick="conCerrarModal()" aria-label="Cerrar">&times;</button>
        </div>
        <div class="con-modal-cuerpo">
            <div class="con-dato">
                <div class="con-dato-label">Paciente</div>
                <p id="conModalPaciente" class="con-dato-valor">—</p>
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                <div class="con-dato">
                    <div class="con-dato-label">Cédula</div>
                    <p id="conModalCedula" class="con-dato-valor">—</p>
                </div>
                <div class="con-dato">
                    <div class="con-dato-label">Turno / Tipo</div>
                    <p id="conModalTurno" class="con-dato-valor">—</p>
                </div>
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                <div class="con-dato">
                    <div class="con-dato-label">Fecha</div>
                    <p id="conModalFecha" class="con-dato-valor">—</p>
                </div>
                <div class="con-dato">
                    <div class="con-dato-label">Hora</div>
                    <p id="conModalHora" class="con-dato-valor">—</p>
                </div>
            </div>
            <div class="con-dato">
                <div class="con-dato-label">Estado</div>
                <span id="conModalEstado" class="con-badge con-badge-espera"><span id="conModalEstadoTexto">En espera</span></span>
            </div>
            <div class="con-dato">
                <div class="con-dato-label">Motivo</div>
                <p id="conModalMotivo" class="con-dato-valor">—</p>
            </div>
            <div class="con-dato">
                <div class="con-dato-label">Diagnóstico</div>
                <p id="conModalDiagnostico" class="con-dato-valor">—</p>
            </div>
            <div class="con-dato">
                <div class="con-dato-label">Observaciones</div>
                <p id="conModalObservaciones" class="con-dato-valor">—</p>
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                <div class="con-dato">
                    <div class="con-dato-label">Médico</div>
                    <p id="conModalMedico" class="con-dato-valor">—</p>
                </div>
                <div class="con-dato">
                    <div class="con-dato-label">Especialidad</div>
                    <p id="conModalEspecialidad" class="con-dato-valor">—</p>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function conCerrarModal() {
    document.getElementById('conModalOverlay').classList.add('oculto');
}

function conVerDetalle(boton) {
    const fila = boton.closest('tr');
    if (!fila) return;
    const d = JSON.parse(fila.getAttribute('data-payload'));

    document.getElementById('conModalPaciente').textContent = d.paciente || '—';
    document.getElementById('conModalCedula').textContent = d.cedula || '—';
    document.getElementById('conModalTurno').textContent = '#' + (d.numero_turno || '') + ' · ' + (d.tipo || '—');
    document.getElementById('conModalFecha').textContent = d.fecha || '—';
    document.getElementById('conModalHora').textContent = d.hora || '—';

    const badge = document.getElementById('conModalEstado');
    badge.className = 'con-badge ' + (d.estado_clase || 'con-badge-espera');
    document.getElementById('conModalEstadoTexto').textContent = d.estado_texto || '—';

    const sinRegistrar = 'Aún no registrada';
    document.getElementById('conModalMotivo').textContent = d.motivo || sinRegistrar;
    document.getElementById('conModalDiagnostico').textContent = d.diagnostico || sinRegistrar;
    document.getElementById('conModalObservaciones').textContent = d.observaciones || sinRegistrar;
    document.getElementById('conModalMedico').textContent = d.medico || '—';
    document.getElementById('conModalEspecialidad').textContent = d.especialidad || '—';

    document.getElementById('conModalOverlay').classList.remove('oculto');
}

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        conCerrarModal();
    }
});
</script>

</body>
</html>