<?php
require_once __DIR__ . '/../config/sesion.php';

// Protección de sesión: solo administradores.
verificarSesion(['admin']);

require_once __DIR__ . '/_iconos.php'; // función icono() para el HTML
require_once __DIR__ . '/../config/conexion.php'; // expone $conexion (PDO)

$csrfToken = generarTokenCSRF();

// ---------------------------------------------------------------
// Helpers de presentación
// ---------------------------------------------------------------
// Extrae la duración en días de un texto libre tipo "3 días".
// Si el texto no se puede interpretar, devuelve null (sin datos
// suficientes -> la receta se mantiene activa).
function recDiasDuracion($texto) {
    if ($texto === null || trim((string) $texto) === '') {
        return null;
    }
    if (preg_match('/(\d+)\s*d[ií]as?/i', (string) $texto, $m)) {
        return (int) $m[1];
    }
    return null;
}

function recEstadoUI($estado) {
    return ($estado === 'expirada')
        ? ['texto' => 'Expirada', 'clase' => 'rec-badge-expirada']
        : ['texto' => 'Activa', 'clase' => 'rec-badge-activa'];
}

function recFecha($fechaHora) {
    return date('d/m/Y', strtotime($fechaHora));
}

function recFechaHora($fechaHora) {
    return date('d/m/Y H:i', strtotime($fechaHora));
}

// Carga el detalle completo de una receta (consulta + paciente + médico + medicamentos).
function recCargarDetalle($conexion, $idReceta) {
    $stmt = $conexion->prepare(
        "SELECT r.id_receta, r.fecha_emision, r.estado, r.indicaciones,
                p.nombre p_nombre, p.apellido p_apellido, p.cedula,
                c.id_consulta, c.motivo,
                u.nombre m_nombre, u.apellido m_apellido,
                e.nombre_especialidad
         FROM recetas r
         JOIN consultas c ON c.id_consulta = r.id_consulta
         JOIN pacientes p ON p.id_paciente = c.id_paciente
         JOIN medicos m ON m.id_medico = c.id_medico
         JOIN usuarios u ON u.id_usuario = m.id_usuario
         JOIN especialidades e ON e.id_especialidad = m.id_especialidad
         WHERE r.id_receta = :id"
    );
    $stmt->execute([':id' => $idReceta]);
    $detalle = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$detalle) {
        return null;
    }
    $stmt = $conexion->prepare(
        "SELECT rd.id_detalle, rd.dosis, rd.frecuencia, rd.duracion,
                m.nombre_medicamento, m.presentacion
         FROM receta_detalle rd
         JOIN medicamentos m ON m.id_medicamento = rd.id_medicamento
         WHERE rd.id_receta = :id
         ORDER BY rd.id_detalle"
    );
    $stmt->execute([':id' => (int) $detalle['id_receta']]);
    $detalle['medicamentos'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return $detalle;
}

// ---------------------------------------------------------------
// POST: guardar indicaciones de una receta (con CSRF)
// ---------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['guardar_indicaciones'])) {
    if (!validarTokenCSRF($_POST['csrf_token'] ?? null)) {
        $alerta = ['error', 'Token de seguridad inválido. Recargue la página e intente de nuevo.'];
    } else {
        $idRecetaPost = (int) ($_POST['id_receta'] ?? 0);
        $indicaciones = trim($_POST['indicaciones'] ?? '');
        if ($idRecetaPost <= 0) {
            $alerta = ['error', 'Receta no identificada.'];
        } else {
            $stmt = $conexion->prepare('UPDATE recetas SET indicaciones = :ind WHERE id_receta = :id');
            $stmt->execute([':ind' => $indicaciones, ':id' => $idRecetaPost]);
            $query = [];
            foreach (['fecha', 'id_especialidad', 'buscar', 'pagina'] as $clave) {
                if (isset($_GET[$clave]) && $_GET[$clave] !== '') {
                    $query[] = $clave . '=' . urlencode($_GET[$clave]);
                }
            }
            $query[] = 'ver_receta=' . $idRecetaPost;
            $query[] = 'mensaje=ok';
            header('Location: admin_recetas.php?' . implode('&', $query));
            exit;
        }
    }
}

// ---------------------------------------------------------------
// Sincronización automática del estado de las recetas.
// Regla: una receta es "expirada" si su fecha de emisión más la
// duración máxima entre sus medicamentos (en días) es anterior a hoy.
// Sin datos de duración interpretables -> se mantiene "activa".
// ---------------------------------------------------------------
$hoy = date('Y-m-d');
$stmt = $conexion->query(
    "SELECT r.id_receta, DATE_FORMAT(r.fecha_emision, '%Y-%m-%d') AS fecha_emision, r.estado,
            rd.duracion
     FROM recetas r
     LEFT JOIN receta_detalle rd ON rd.id_receta = r.id_receta"
);
$datosRecetas = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $filaSync) {
    $idSync = (int) $filaSync['id_receta'];
    if (!isset($datosRecetas[$idSync])) {
        $datosRecetas[$idSync] = [
            'fecha_emision' => $filaSync['fecha_emision'],
            'estado'        => $filaSync['estado'],
            'max_dias'      => 0,
        ];
    }
    $dias = recDiasDuracion($filaSync['duracion']);
    if ($dias !== null) {
        $datosRecetas[$idSync]['max_dias'] = max($datosRecetas[$idSync]['max_dias'], $dias);
    }
}
$vencePorReceta = [];
$cambios = [];
foreach ($datosRecetas as $idSync => $datos) {
    $vence = null;
    if ($datos['max_dias'] > 0) {
        $vence = date('Y-m-d', strtotime($datos['fecha_emision'] . ' +' . $datos['max_dias'] . ' days'));
    }
    $vencePorReceta[$idSync] = $vence;
    $nuevoEstado = ($vence !== null && $vence < $hoy) ? 'expirada' : 'activa';
    if ($datos['estado'] !== $nuevoEstado) {
        $cambios[] = ['id' => $idSync, 'estado' => $nuevoEstado];
    }
}
if ($cambios !== []) {
    $stmt = $conexion->prepare('UPDATE recetas SET estado = :estado WHERE id_receta = :id');
    foreach ($cambios as $cambio) {
        $stmt->execute([':estado' => $cambio['estado'], ':id' => $cambio['id']]);
    }
}

// ---------------------------------------------------------------
// Filtros combinables por GET (fecha, especialidad, buscar)
// ---------------------------------------------------------------
$fecha = isset($_GET['fecha']) ? trim($_GET['fecha']) : '';
if ($fecha !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
    $fecha = '';
}

$idEspecialidad = isset($_GET['id_especialidad']) ? trim($_GET['id_especialidad']) : '';
if ($idEspecialidad !== '' && !ctype_digit($idEspecialidad)) {
    $idEspecialidad = '';
}

$buscar = isset($_GET['buscar']) ? trim($_GET['buscar']) : '';

$porPagina = 10;
$paginaActual = isset($_GET['pagina']) ? max(1, (int) $_GET['pagina']) : 1;

// ---------------------------------------------------------------
// Construcción de la cláusula WHERE (dinámica y combinable)
// ---------------------------------------------------------------
$joins = "FROM recetas r
          JOIN consultas c ON c.id_consulta = r.id_consulta
          JOIN pacientes p ON p.id_paciente = c.id_paciente
          JOIN medicos m ON m.id_medico = c.id_medico
          JOIN usuarios u ON u.id_usuario = m.id_usuario
          JOIN especialidades e ON e.id_especialidad = m.id_especialidad
          LEFT JOIN receta_detalle rd ON rd.id_receta = r.id_receta";

$donde = [];
$sqlParams = [];

if ($fecha !== '') {
    $donde[] = 'DATE(r.fecha_emision) = :fecha';
    $sqlParams[':fecha'] = $fecha;
}
if ($idEspecialidad !== '') {
    $donde[] = 'e.id_especialidad = :idesp';
    $sqlParams[':idesp'] = $idEspecialidad;
}
if ($buscar !== '') {
    $donde[] = '(p.nombre LIKE :q OR p.apellido LIKE :q OR p.cedula LIKE :q)';
    $sqlParams[':q'] = '%' . $buscar . '%';
}

$clausulaDonde = $donde === [] ? '' : 'WHERE ' . implode(' AND ', $donde);

// ---------------------------------------------------------------
// Estadísticas (tarjetas)
// ---------------------------------------------------------------
// 1) Recetas emitidas hoy (global)
$recetasHoy = (int) $conexion->query(
    "SELECT COUNT(*) FROM recetas WHERE DATE(fecha_emision) = CURDATE()"
)->fetchColumn();

// 2) Total de la lista filtrada
$stmt = $conexion->prepare("SELECT COUNT(DISTINCT r.id_receta) $joins $clausulaDonde");
$stmt->execute($sqlParams);
$totalFiltrado = (int) $stmt->fetchColumn();

// 3) Promedio de recetas por paciente (global)
$filaPromedio = $conexion->query(
    "SELECT COUNT(*) total, COUNT(DISTINCT c.id_paciente) pacientes
     FROM recetas r
     JOIN consultas c ON c.id_consulta = r.id_consulta"
)->fetch(PDO::FETCH_ASSOC);
$promedioPorPaciente = ($filaPromedio['pacientes'] > 0)
    ? number_format($filaPromedio['total'] / $filaPromedio['pacientes'], 1)
    : '0.0';

// ---------------------------------------------------------------
// Lista paginada
// ---------------------------------------------------------------
$totalPaginas = max(1, (int) ceil($totalFiltrado / $porPagina));
if ($paginaActual > $totalPaginas) {
    $paginaActual = $totalPaginas;
}
$inicio = ($paginaActual - 1) * $porPagina;

$sql = "SELECT r.id_receta, r.fecha_emision, r.estado,
               p.nombre p_nombre, p.apellido p_apellido, p.cedula,
               u.nombre m_nombre, u.apellido m_apellido,
               e.nombre_especialidad,
               COUNT(rd.id_detalle) AS num_medicamentos
        $joins
        $clausulaDonde
        GROUP BY r.id_receta, r.fecha_emision, r.estado, p.nombre, p.apellido, p.cedula,
                 u.nombre, u.apellido, e.nombre_especialidad
        ORDER BY r.fecha_emision DESC, r.id_receta DESC
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
// Datos para el panel lateral y el filtro de especialidad
// ---------------------------------------------------------------
$especialidades = $conexion->query(
    "SELECT id_especialidad, nombre_especialidad FROM especialidades ORDER BY nombre_especialidad"
)->fetchAll(PDO::FETCH_ASSOC);

// Receta seleccionada para el panel (por GET o la más reciente)
$verReceta = isset($_GET['ver_receta']) ? trim($_GET['ver_receta']) : '';
if ($verReceta !== '' && !ctype_digit($verReceta)) {
    $verReceta = '';
}

$detalle = null;
if ($verReceta !== '') {
    $detalle = recCargarDetalle($conexion, (int) $verReceta);
    if ($detalle === null) {
        $verReceta = '';
    }
}
if ($detalle === null) {
    $idUltima = (int) $conexion->query(
        "SELECT id_receta FROM recetas ORDER BY fecha_emision DESC, id_receta DESC LIMIT 1"
    )->fetchColumn();
    if ($idUltima > 0) {
        $detalle = recCargarDetalle($conexion, $idUltima);
        $verReceta = (string) $idUltima;
    }
}

// Cadena para conservar filtros en links de paginación y filas
$filtrosLink = '';
foreach (['fecha', 'id_especialidad', 'buscar'] as $claveFiltro) {
    if (isset($_GET[$claveFiltro]) && $_GET[$claveFiltro] !== '') {
        $filtrosLink .= '&' . $claveFiltro . '=' . urlencode($_GET[$claveFiltro]);
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recetas - Hospital San Rafael</title>
    <link rel="stylesheet" href="../css/estilo.css">
    <link rel="stylesheet" href="../css/admin_recetas.css">
</head>
<body class="rec-body">

<div class="rec-layout">

    <!-- ================= SIDEBAR LIGHT ================= -->
    <aside class="rec-sidebar">
        <div class="rec-logo">
            <span><?php echo icono('cruz-medica', 20); ?></span>
            Hospital San Rafael
        </div>

        <nav class="rec-nav">
            <a href="admin.php"><?php echo icono('home'); ?> Panel de Control</a>

            <div class="rec-nav-seccion">Gestión</div>
            <a href="admin.php"><?php echo icono('usuarios'); ?> Usuarios</a>
            <a href="admin_medicos.php"><?php echo icono('medico'); ?> Médicos</a>
            <a href="admin_pacientes.php"><?php echo icono('paciente'); ?> Pacientes</a>
            <a href="admin_especialidades.php"><?php echo icono('estrella'); ?> Especialidades</a>
            <a href="admin_consultas.php"><?php echo icono('calendario'); ?> Consultas</a>
            <a href="admin_recetas.php" class="activo"><?php echo icono('archivo'); ?> Recetas</a>

            <div class="rec-nav-seccion">Sistema</div>
            <a href="admin_reportes.php"><?php echo icono('grafico'); ?> Reportes</a>
            <a href="admin_configuracion.php"><?php echo icono('engranaje'); ?> Configuración</a>
            <a href="logout.php"><?php echo icono('salir'); ?> Cerrar Sesión</a>
        </nav>

        <div class="rec-sidebar-footer">
            <div class="rec-avatar"><?php echo htmlspecialchars(mb_strtoupper(mb_substr($_SESSION['nombre'] ?? '', 0, 1))); ?></div>
            <div>
                <div style="font-size:0.82rem; font-weight:600;"><?php echo htmlspecialchars($_SESSION['nombre'] ?? ''); ?></div>
                <div class="rec-estado">En línea</div>
            </div>
        </div>
    </aside>

    <!-- ================= CONTENIDO PRINCIPAL ================= -->
    <div class="rec-main">

        <header class="rec-banner">
            <span><?php echo icono('archivo', 26); ?></span>
            <div>
                <h1 class="rec-banner-titulo">Recetas</h1>
                <p class="rec-banner-desc">Vista global de las recetas médicas emitidas a los pacientes</p>
            </div>
        </header>

        <?php if (isset($_GET['mensaje']) && $_GET['mensaje'] === 'ok'): ?>
            <div class="rec-alerta rec-alerta-exito"><?php echo icono('estrella', 18); ?> Indicaciones guardadas correctamente.</div>
        <?php endif; ?>

        <?php if (isset($alerta) && $alerta[0] === 'error'): ?>
            <div class="rec-alerta rec-alerta-error"><?php echo icono('salir', 18); ?> <?php echo htmlspecialchars($alerta[1]); ?></div>
        <?php endif; ?>

        <!-- ============ TARJETAS DE ESTADÍSTICAS ============ -->
        <div class="rec-stats-row">
            <div class="rec-stat-card">
                <div class="rec-stat-encabezado">
                    <div class="rec-stat-icono verde"><?php echo icono('archivo', 20); ?></div>
                    <div class="rec-stat-titulo">Recetas de hoy</div>
                </div>
                <div class="rec-stat-numero"><?php echo $recetasHoy; ?></div>
                <div class="rec-stat-pie"><span><?php echo date('d/m/Y'); ?></span></div>
            </div>

            <div class="rec-stat-card">
                <div class="rec-stat-encabezado">
                    <div class="rec-stat-icono azul"><?php echo icono('calendario', 20); ?></div>
                    <div class="rec-stat-titulo">Total (filtrado)</div>
                </div>
                <div class="rec-stat-numero"><?php echo $totalFiltrado; ?></div>
                <div class="rec-stat-pie"><span>Recetas según filtros</span></div>
            </div>

            <div class="rec-stat-card">
                <div class="rec-stat-encabezado">
                    <div class="rec-stat-icono morado"><?php echo icono('usuarios', 20); ?></div>
                    <div class="rec-stat-titulo">Promedio por paciente</div>
                </div>
                <div class="rec-stat-numero"><?php echo $promedioPorPaciente; ?></div>
                <div class="rec-stat-pie"><span>Global</span></div>
            </div>
        </div>

        <!-- ============ FILTROS ============ -->
        <div class="rec-toolbar">
            <form method="get" action="admin_recetas.php">
                <div class="rec-filtros">
                    <div class="rec-filtro">
                        <label for="fecha">Fecha</label>
                        <input type="date" id="fecha" name="fecha" value="<?php echo htmlspecialchars($fecha); ?>">
                    </div>
                    <div class="rec-filtro">
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
                    <div class="rec-filtro rec-filtro-buscar">
                        <label for="buscar">Buscar paciente</label>
                        <input type="text" id="buscar" name="buscar" placeholder="Nombre o cédula" value="<?php echo htmlspecialchars($buscar); ?>">
                    </div>
                    <div class="rec-filtro rec-filtro-boton">
                        <?php if ($fecha !== '' || $idEspecialidad !== '' || $buscar !== ''): ?>
                            <a href="admin_recetas.php" class="rec-btn rec-btn-secundario">Limpiar</a>
                        <?php endif; ?>
                        <button type="submit" class="rec-btn rec-btn-primario">Filtrar</button>
                    </div>
                </div>
            </form>
        </div>

        <!-- ============ CONTENIDO: LISTA + PANEL LATERAL ============ -->
        <div class="rec-contenido">

            <!-- Tabla de recetas -->
            <div class="rec-lista">
                <div class="rec-tabla-contenedor">
                    <table class="rec-tabla">
                        <thead>
                            <tr>
                                <th>Fecha / Emisión</th>
                                <th>Paciente</th>
                                <th>Médico</th>
                                <th>Medicamentos</th>
                                <th>Vence</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($filas) === 0): ?>
                                <tr>
                                    <td colspan="7" class="rec-vacio">
                                        No se encontraron recetas para los filtros seleccionados.
                                        <?php if ($fecha !== '' || $idEspecialidad !== '' || $buscar !== ''): ?>
                                            <a href="admin_recetas.php">Limpiar filtros</a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endif; ?>

                            <?php foreach ($filas as $fila):
                                $ui = recEstadoUI($fila['estado']);
                                $linkVer = 'admin_recetas.php?ver_receta=' . $fila['id_receta'] . $filtrosLink;
                                $vence = $vencePorReceta[(int) $fila['id_receta']] ?? null;
                                $esActiva = (string) $fila['id_receta'] === $verReceta;
                            ?>
                            <tr class="<?php echo $esActiva ? 'rec-fila-activa' : ''; ?>" onclick="location.href='<?php echo htmlspecialchars($linkVer, ENT_QUOTES, 'UTF-8'); ?>'">
                                <td data-label="Fecha / Emisión">
                                    <strong><?php echo htmlspecialchars(recFechaHora($fila['fecha_emision'])); ?></strong>
                                </td>
                                <td data-label="Paciente">
                                    <div class="rec-tabla-paciente">
                                        <div class="rec-tabla-avatar">
                                            <?php echo htmlspecialchars(mb_strtoupper(mb_substr((string) $fila['p_nombre'], 0, 1)) . mb_strtoupper(mb_substr((string) $fila['p_apellido'], 0, 1))); ?>
                                        </div>
                                        <div class="rec-tabla-nombre">
                                            <strong><?php echo htmlspecialchars(trim($fila['p_nombre'] . ' ' . $fila['p_apellido'])); ?></strong>
                                            <span>Cédula: <?php echo htmlspecialchars($fila['cedula']); ?></span>
                                        </div>
                                    </div>
                                </td>
                                <td data-label="Médico">
                                    <strong><?php echo htmlspecialchars(trim($fila['m_nombre'] . ' ' . $fila['m_apellido'])); ?></strong>
                                    <span style="display:block; font-size:0.78rem; color:var(--rec-texto-suave);">
                                        <?php echo htmlspecialchars($fila['nombre_especialidad'] ?? '—'); ?>
                                    </span>
                                </td>
                                <td data-label="Medicamentos">
                                    <strong><?php echo (int) $fila['num_medicamentos']; ?></strong>
                                    <span style="display:block; font-size:0.78rem; color:var(--rec-texto-suave);">
                                        medicamento(s)
                                    </span>
                                </td>
                                <td data-label="Vence">
                                    <?php echo $vence !== null ? htmlspecialchars(date('d/m/Y', strtotime($vence))) : '—'; ?>
                                </td>
                                <td data-label="Estado">
                                    <span class="rec-badge <?php echo $ui['clase']; ?>"><?php echo $ui['texto']; ?></span>
                                </td>
                                <td data-label="Acciones">
                                    <button type="button" class="rec-btn rec-btn-ver rec-btn-peq" onclick="event.stopPropagation(); location.href='<?php echo htmlspecialchars($linkVer, ENT_QUOTES, 'UTF-8'); ?>'">Ver detalle</button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <?php if ($totalPaginas > 1): ?>
                        <div class="rec-paginacion">
                            <span class="rec-pag-info">Página <?php echo $paginaActual; ?> de <?php echo $totalPaginas; ?></span>
                            <?php if ($paginaActual > 1): ?>
                                <a href="admin_recetas.php?pagina=<?php echo $paginaActual - 1 . $filtrosLink; ?>">&laquo;</a>
                            <?php endif; ?>
                            <?php for ($i = 1; $i <= $totalPaginas; $i++): ?>
                                <?php if ($i === $paginaActual): ?>
                                    <span class="rec-pag-actual"><?php echo $i; ?></span>
                                <?php else: ?>
                                    <a href="admin_recetas.php?pagina=<?php echo $i . $filtrosLink; ?>"><?php echo $i; ?></a>
                                <?php endif; ?>
                            <?php endfor; ?>
                            <?php if ($paginaActual < $totalPaginas): ?>
                                <a href="admin_recetas.php?pagina=<?php echo $paginaActual + 1 . $filtrosLink; ?>">&raquo;</a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ============ PANEL LATERAL: DETALLE DE RECETA ============ -->
            <aside class="rec-panel-detalle">
                <?php if ($detalle === null): ?>
                    <div class="rec-detalle-vacio">
                        <p>No hay recetas registradas aún.</p>
                    </div>
                <?php else:
                    $medicoNombre = trim($detalle['m_nombre'] . ' ' . $detalle['m_apellido']);
                    $pacienteNombre = trim($detalle['p_nombre'] . ' ' . $detalle['p_apellido']);
                    $uiDetalle = recEstadoUI($detalle['estado']);
                    $venceDetalle = $vencePorReceta[(int) $detalle['id_receta']] ?? null;
                    $linkImprimir = 'admin_recetas.php?ver_receta=' . $detalle['id_receta'] . $filtrosLink;
                ?>
                <section class="rec-detalle-card">
                    <div class="rec-detalle-cabecera">
                        <div class="rec-detalle-numero">
                            <span class="rec-detalle-icono"><?php echo icono('archivo', 22); ?></span>
                            <div>
                                <h3 class="rec-detalle-titulo">Receta #<?php echo (int) $detalle['id_receta']; ?></h3>
                                <div class="rec-detalle-sub">Emitida el <?php echo htmlspecialchars(recFechaHora($detalle['fecha_emision'])); ?></div>
                            </div>
                        </div>
                        <span class="rec-badge <?php echo $uiDetalle['clase']; ?>"><?php echo $uiDetalle['texto']; ?></span>
                    </div>

                    <div class="rec-dato">
                        <div class="rec-dato-label">Paciente</div>
                        <p class="rec-dato-valor"><?php echo htmlspecialchars($pacienteNombre); ?> · Cédula <?php echo htmlspecialchars($detalle['cedula']); ?></p>
                    </div>

                    <div class="rec-datos-grid">
                        <div class="rec-dato">
                            <div class="rec-dato-label">Médico</div>
                            <p class="rec-dato-valor"><?php echo htmlspecialchars($medicoNombre); ?></p>
                        </div>
                        <div class="rec-dato">
                            <div class="rec-dato-label">Especialidad</div>
                            <p class="rec-dato-valor"><?php echo htmlspecialchars($detalle['nombre_especialidad'] ?? '—'); ?></p>
                        </div>
                    </div>

                    <div class="rec-datos-grid">
                        <div class="rec-dato">
                            <div class="rec-dato-label">Consulta #<?php echo (int) $detalle['id_consulta']; ?></div>
                            <p class="rec-dato-valor"><?php echo htmlspecialchars($detalle['motivo'] ?? '—'); ?></p>
                        </div>
                        <div class="rec-dato">
                            <div class="rec-dato-label">Válida hasta</div>
                            <p class="rec-dato-valor">
                                <?php echo $venceDetalle !== null ? htmlspecialchars(date('d/m/Y', strtotime($venceDetalle))) : 'Indefinida'; ?>
                            </p>
                        </div>
                    </div>

                    <h4 class="rec-med-titulo">Medicamentos</h4>
                    <?php if (count($detalle['medicamentos']) === 0): ?>
                        <p class="rec-sin-datos">Esta receta no tiene medicamentos registrados.</p>
                    <?php else: ?>
                        <div class="rec-med-lista">
                            <?php foreach ($detalle['medicamentos'] as $med): ?>
                                <div class="rec-med-item">
                                    <div class="rec-med-nombre"><?php echo htmlspecialchars($med['nombre_medicamento']); ?></div>
                                    <div class="rec-med-pres"><?php echo htmlspecialchars($med['presentacion'] ?? '—'); ?></div>
                                    <div class="rec-med-linea">
                                        <span><?php echo htmlspecialchars($med['dosis'] ?? '—'); ?></span>
                                        <span><?php echo htmlspecialchars($med['frecuencia'] ?? '—'); ?></span>
                                        <span>Duración: <?php echo htmlspecialchars($med['duracion'] ?? '—'); ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <div class="rec-indicaciones">
                        <h4 class="rec-med-titulo">Indicaciones</h4>
                        <?php if ($detalle['indicaciones'] !== null && $detalle['indicaciones'] !== ''): ?>
                            <p class="rec-indicaciones-texto"><?php echo nl2br(htmlspecialchars($detalle['indicaciones'])); ?></p>
                        <?php endif; ?>
                        <form method="post" action="admin_recetas.php?ver_receta=<?php echo (int) $detalle['id_receta'] . $filtrosLink; ?>" class="rec-indicaciones-form">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                            <input type="hidden" name="guardar_indicaciones" value="1">
                            <input type="hidden" name="id_receta" value="<?php echo (int) $detalle['id_receta']; ?>">
                            <textarea name="indicaciones" placeholder="Escriba aquí las indicaciones generales de la receta..."><?php echo htmlspecialchars($detalle['indicaciones'] ?? ''); ?></textarea>
                            <button type="submit" class="rec-btn rec-btn-primario rec-btn-peq">Guardar indicaciones</button>
                        </form>
                    </div>

                    <a href="<?php echo htmlspecialchars($linkImprimir, ENT_QUOTES, 'UTF-8'); ?>" class="rec-btn rec-btn-ver rec-boton-imprimir" onclick="window.print(); return false;">
                        <?php echo icono('archivo', 16); ?> Imprimir Receta
                    </a>
                </section>
                <?php endif; ?>
            </aside>
        </div>

        <footer class="rec-footer">
            Hospital San Rafael · Panel de Administración · Recetas
        </footer>
    </div>
</div>

</body>
</html>