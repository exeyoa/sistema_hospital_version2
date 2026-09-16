<?php
require_once __DIR__ . '/../config/sesion.php';

verificarSesion(['admin']);

require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/_iconos.php';

$csrfToken = generarTokenCSRF();

// -----------------------------------------------------------
// Mapa de íconos SVG y colores de acento por especialidad
// (fácil de ampliar: agregar entrada en $espIconos y en $espColores)
// -----------------------------------------------------------
$espIconos = [
    'medicina general'          => '<path d="M10 3h4v7h7v4h-7v7h-4v-7H3v-4h7z"/>',
    'pediatria'                 => '<polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/>',
    'ginecologia'               => '<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>',
    'cardiologia'               => '<path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>',
    'neumologia'                => '<path d="M9.59 4.59A2 2 0 1 1 11 8H2"/><path d="M17.73 7.73A2.5 2.5 0 1 1 19.5 12H2"/><path d="M12.52 15.52a2.5 2.5 0 1 1 1.77 4.27H2"/>',
    'neurologia'                => '<rect x="4" y="4" width="16" height="16" rx="2"/><rect x="9" y="9" width="6" height="6"/><path d="M9 1v3M15 1v3M9 20v3M15 20v3M20 9h3M20 15h3M1 9h3M1 15h3"/>',
    'dermatologia'              => '<circle cx="12" cy="12" r="10"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><line x1="9" y1="9" x2="9.01" y2="9"/><line x1="15" y1="9" x2="15.01" y2="9"/>',
    'oftalmologia'              => '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>',
    'ortopedia'                 => '<circle cx="5.5" cy="12" r="3.2"/><circle cx="18.5" cy="12" r="3.2"/><path d="M8.7 12h6.6"/>',
    'urologia'                  => '<path d="M12 2.69l5.66 5.66a8 8 0 1 1-11.31 0z"/>',
    'otorrinolaringologia'      => '<path d="M3 18v-6a9 9 0 0 1 18 0v6"/><path d="M21 19a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3z"/><path d="M3 19a2 2 0 0 0 2 2h1a2 2 0 0 0 2-2v-3a2 2 0 0 0-2-2H3z"/>',
    'gastroenterologia'         => '<path d="M3 11h18a9 9 0 0 1-18 0z"/><path d="M7 11V8a5 5 0 0 1 10 0v3"/>',
];
$espColores = [
    'medicina general'      => ['#2563EB', '#DBEAFE'],
    'pediatria'             => ['#EC4899', '#FCE7F3'],
    'ginecologia'           => ['#A855F7', '#F3E8FF'],
    'cardiologia'           => ['#DC2626', '#FEE2E2'],
    'neumologia'            => ['#0891B2', '#CFFAFE'],
    'neurologia'            => ['#6366F1', '#E0E7FF'],
    'dermatologia'          => ['#D97706', '#FEF3C7'],
    'oftalmologia'          => ['#059669', '#D1FAE5'],
    'ortopedia'             => ['#EA580C', '#FFEDD5'],
    'urologia'              => ['#0284C7', '#E0F2FE'],
    'otorrinolaringologia'  => ['#7C3AED', '#EDE9FE'],
    'gastroenterologia'     => ['#65A30D', '#ECFCCB'],
];
$espColorDefault      = ['#475569', '#F1F5F9'];
$espIconoDefault      = '<path d="M10 3h4v7h7v4h-7v7h-4v-7H3v-4h7z"/>';

function espClave($nombre) {
    $letras = ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n'];
    return strtr(mb_strtolower(trim($nombre)), $letras);
}

function espSvg($nombre) {
    global $espIconos, $espIconoDefault;
    $clave = espClave($nombre);
    $icono = $espIconos[$clave] ?? $espIconoDefault;
    return '<svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $icono . '</svg>';
}

function espColores($nombre) {
    global $espColores, $espColorDefault;
    $clave = espClave($nombre);
    return $espColores[$clave] ?? $espColorDefault;
}

// -----------------------------------------------------------
// Procesamiento POST (crear / editar / eliminar) - antes del HTML
// -----------------------------------------------------------
$alertaError = '';
$alertaExito = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    $token = $_POST['csrf_token'] ?? '';

    if (!validarTokenCSRF($token)) {
        $alertaError = 'Sesión expirada. Intenta de nuevo.';
    } elseif ($accion === 'crear' || $accion === 'editar') {
        $nombre = trim($_POST['nombre_especialidad'] ?? '');
        $descripcion = trim($_POST['descripcion'] ?? '');
        $idEditar = $accion === 'editar' ? (int) ($_POST['id_especialidad'] ?? 0) : 0;

        if ($nombre === '' || mb_strlen($nombre) > 60) {
            $alertaError = 'El nombre es obligatorio y debe tener como máximo 60 caracteres.';
        } else {
            try {
                if ($accion === 'crear') {
                    $stmt = $conexion->prepare('INSERT INTO especialidades (nombre_especialidad, descripcion) VALUES (:n, :d)');
                    $stmt->bindValue(':n', $nombre, PDO::PARAM_STR);
                    $stmt->bindValue(':d', $descripcion !== '' ? $descripcion : null, PDO::PARAM_STR);
                    $stmt->execute();
                    header('Location: admin_especialidades.php?creada=1');
                    exit;
                } else {
                    $stmt = $conexion->prepare(
                        'UPDATE especialidades SET nombre_especialidad = :n, descripcion = :d WHERE id_especialidad = :id'
                    );
                    $stmt->bindValue(':n', $nombre, PDO::PARAM_STR);
                    $stmt->bindValue(':d', $descripcion !== '' ? $descripcion : null, PDO::PARAM_STR);
                    $stmt->bindValue(':id', $idEditar, PDO::PARAM_INT);
                    $stmt->execute();
                    header('Location: admin_especialidades.php?editada=1');
                    exit;
                }
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    $alertaError = 'Ya existe una especialidad con el nombre "' . htmlspecialchars($nombre, ENT_QUOTES) . '".';
                } else {
                    $alertaError = 'No se pudo guardar la especialidad. Intenta de nuevo.';
                }
            }
        }
    } elseif ($accion === 'eliminar') {
        $idEliminar = (int) ($_POST['id_especialidad'] ?? 0);
        if ($idEliminar > 0) {
            $stmt = $conexion->prepare('SELECT COUNT(*) FROM medicos WHERE id_especialidad = :id');
            $stmt->bindValue(':id', $idEliminar, PDO::PARAM_INT);
            $stmt->execute();
            $medicosAsignados = (int) $stmt->fetchColumn();

            if ($medicosAsignados > 0) {
                $alertaError = 'Esta especialidad tiene ' . $medicosAsignados
                    . ' médico(s) asignado(s), no se puede eliminar.';
            } else {
                try {
                    $stmt = $conexion->prepare('DELETE FROM especialidades WHERE id_especialidad = :id');
                    $stmt->bindValue(':id', $idEliminar, PDO::PARAM_INT);
                    $stmt->execute();
                    header('Location: admin_especialidades.php?eliminada=1');
                    exit;
                } catch (PDOException $e) {
                    if ($e->getCode() === '23000') {
                        $alertaError = 'Esta especialidad está referenciada por otros registros, no se puede eliminar.';
                    } else {
                        $alertaError = 'No se pudo eliminar la especialidad. Intenta de nuevo.';
                    }
                }
            }
        } else {
            $alertaError = 'Especialidad no válida.';
        }
    }
}

// -----------------------------------------------------------
// Estado de la página: ver / nueva / editar
// -----------------------------------------------------------
$modo = 'ver';
$formId = 0;
$formNombre = '';
$formDescripcion = '';
$formTitulo = '';

if (isset($_GET['nueva'])) {
    $modo = 'crear';
    $formTitulo = 'Nueva Especialidad';
} elseif (isset($_GET['editar'])) {
    $idForm = (int) $_GET['editar'];
    $stmt = $conexion->prepare('SELECT id_especialidad, nombre_especialidad, descripcion FROM especialidades WHERE id_especialidad = :id');
    $stmt->bindValue(':id', $idForm, PDO::PARAM_INT);
    $stmt->execute();
    $filaEditar = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($filaEditar) {
        $modo = 'editar';
        $formId = (int) $filaEditar['id_especialidad'];
        $formNombre = $filaEditar['nombre_especialidad'];
        $formDescripcion = (string) $filaEditar['descripcion'];
        $formTitulo = 'Editar Especialidad';
    }
}

if (isset($_GET['creada'])) {
    $alertaExito = 'Especialidad creada correctamente.';
} elseif (isset($_GET['editada'])) {
    $alertaExito = 'Especialidad actualizada correctamente.';
} elseif (isset($_GET['eliminada'])) {
    $alertaExito = 'Especialidad eliminada correctamente.';
}

// -----------------------------------------------------------
// Consultas: resumen general, buscador/orden, médicos por especialidad
// -----------------------------------------------------------
$totalEspecialidades = (int) $conexion->query('SELECT COUNT(*) FROM especialidades')->fetchColumn();
$totalMedicos = (int) $conexion->query('SELECT COUNT(*) FROM medicos')->fetchColumn();
$inicioMes = date('Y-m-01');
$stmt = $conexion->prepare('SELECT COUNT(*) FROM consultas WHERE fecha_consulta >= :ini');
$stmt->bindValue(':ini', $inicioMes, PDO::PARAM_STR);
$stmt->execute();
$consultasMes = (int) $stmt->fetchColumn();

$buscar = isset($_GET['buscar']) ? trim($_GET['buscar']) : '';
$orden = isset($_GET['orden']) ? $_GET['orden'] : 'alfabetico';
$donde = '';
$sqlParams = [];
if ($buscar !== '') {
    $donde = 'WHERE e.nombre_especialidad LIKE :q';
    $sqlParams[':q'] = '%' . $buscar . '%';
}
$orderBy = 'e.nombre_especialidad ASC';
if ($orden === 'medicos') {
    $orderBy = 'total_medicos DESC, e.nombre_especialidad ASC';
}

$sql = "SELECT e.id_especialidad, e.nombre_especialidad, e.descripcion,
               COUNT(DISTINCT m.id_medico) AS total_medicos,
               (SELECT COUNT(*) FROM consultas c
                 JOIN medicos m2 ON m2.id_medico = c.id_medico
                WHERE m2.id_especialidad = e.id_especialidad
                  AND c.fecha_consulta >= :ini) AS consultas_mes
        FROM especialidades e
        LEFT JOIN medicos m ON m.id_especialidad = e.id_especialidad
        $donde
        GROUP BY e.id_especialidad, e.nombre_especialidad, e.descripcion
        ORDER BY $orderBy";
$stmt = $conexion->prepare($sql);
$stmt->bindValue(':ini', $inicioMes, PDO::PARAM_STR);
foreach ($sqlParams as $clave => $valor) {
    $stmt->bindValue($clave, $valor, PDO::PARAM_STR);
}
$stmt->execute();
$especialidades = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Médicos por especialidad (para el "Ver detalles")
$medicosPorEspecialidad = [];
$stmt = $conexion->query(
    'SELECT m.id_especialidad, u.nombre, u.apellido
       FROM medicos m
       JOIN usuarios u ON u.id_usuario = m.id_usuario
      ORDER BY u.nombre, u.apellido'
);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fila) {
    $idEsp = (int) $fila['id_especialidad'];
    $medicosPorEspecialidad[$idEsp][] = $fila['nombre'] . ' ' . $fila['apellido'];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Especialidades</title>
    <link rel="stylesheet" href="../css/estilo.css">
    <link rel="stylesheet" href="../css/admin_especialidades.css">
</head>
<body class="esp-body">

<div class="esp-layout">

    <!-- ================= SIDEBAR LIGHT ================= -->
    <aside class="esp-sidebar">
        <div class="esp-logo">
            <span><?php echo icono('cruz-medica', 20); ?></span>
            Hospital San Rafael
        </div>

        <nav class="esp-nav">
            <a href="admin.php"><?php echo icono('home'); ?> Panel de Control</a>

            <div class="esp-nav-seccion">Gestión</div>
            <a href="admin.php"><?php echo icono('usuarios'); ?> Usuarios</a>
            <a href="admin_medicos.php"><?php echo icono('medico'); ?> Médicos</a>
            <a href="admin_pacientes.php"><?php echo icono('paciente'); ?> Pacientes</a>
            <a href="admin_especialidades.php" class="activo"><?php echo icono('estrella'); ?> Especialidades</a>
            <a href="admin_consultas.php"><?php echo icono('calendario'); ?> Consultas</a>
            <a href="admin_recetas.php"><?php echo icono('archivo'); ?> Recetas</a>

            <div class="esp-nav-seccion">Sistema</div>
            <a href="admin_reportes.php"><?php echo icono('grafico'); ?> Reportes</a>
            <a href="admin_configuracion.php"><?php echo icono('engranaje'); ?> Configuración</a>
            <a href="logout.php"><?php echo icono('salir'); ?> Cerrar Sesión</a>
        </nav>

        <div class="esp-sidebar-footer">
            <div class="esp-avatar"><?php echo htmlspecialchars(mb_strtoupper(mb_substr($_SESSION['nombre'] ?? '', 0, 1))); ?></div>
            <div>
                <div style="font-size:0.82rem; font-weight:600;"><?php echo htmlspecialchars($_SESSION['nombre'] ?? ''); ?></div>
                <div class="esp-estado">En línea</div>
            </div>
        </div>
    </aside>

    <!-- ================= CONTENIDO PRINCIPAL ================= -->
    <div class="esp-main">

        <header class="esp-banner">
            <span><?php echo icono('cruz-medica', 26); ?></span>
            <div>
                <h1 class="esp-banner-titulo">Especialidades Médicas</h1>
                <p class="esp-banner-desc">Administra las especialidades del hospital, sus médicos y la actividad del mes</p>
            </div>
        </header>

        <?php if ($alertaExito !== ''): ?>
            <div class="esp-alerta esp-alerta-exito"><?php echo htmlspecialchars($alertaExito); ?></div>
        <?php endif; ?>

        <?php if ($alertaError !== ''): ?>
            <div class="esp-alerta esp-alerta-error"><?php echo $alertaError; ?></div>
        <?php endif; ?>

        <?php if ($modo === 'crear' || $modo === 'editar'): ?>

            <!-- ============ FORMULARIO CREAR / EDITAR ============ -->
            <section class="esp-form-card">
                <h2 class="esp-form-titulo"><?php echo htmlspecialchars($formTitulo); ?></h2>
                <p class="esp-form-subt">Los campos marcados con * son obligatorios.</p>

                <form method="post" action="admin_especialidades.php">
                    <input type="hidden" name="accion" value="<?php echo $modo; ?>">
                    <?php if ($modo === 'editar'): ?>
                        <input type="hidden" name="id_especialidad" value="<?php echo $formId; ?>">
                    <?php endif; ?>
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">

                    <div class="esp-campo">
                        <label for="nombre_especialidad">Nombre *</label>
                        <input type="text" id="nombre_especialidad" name="nombre_especialidad" maxlength="60"
                               value="<?php echo htmlspecialchars($formNombre, ENT_QUOTES); ?>" required>
                    </div>

                    <div class="esp-campo">
                        <label for="descripcion">Descripción</label>
                        <textarea id="descripcion" name="descripcion" rows="3" maxlength="1000"
                                  placeholder="Breve descripción de la especialidad (opcional)"><?php echo htmlspecialchars($formDescripcion); ?></textarea>
                    </div>

                    <div class="esp-form-acciones">
                        <button type="submit" class="esp-btn esp-btn-primario">Guardar Especialidad</button>
                        <a href="admin_especialidades.php" class="esp-btn esp-btn-secundario">Cancelar</a>
                    </div>
                </form>
            </section>

        <?php else: ?>

            <!-- ============ TOOLBAR ============ -->
            <div class="esp-toolbar">
                <form method="get" action="admin_especialidades.php">
                    <div class="esp-buscar">
                        <input type="text" name="buscar" value="<?php echo htmlspecialchars($buscar, ENT_QUOTES); ?>"
                               placeholder="Buscar especialidad...">
                        <button type="submit" class="esp-btn esp-btn-secundario esp-btn-peq">Buscar</button>
                        <select name="orden" class="esp-orden" onchange="this.form.submit()">
                            <option value="alfabetico" <?php echo $orden === 'alfabetico' ? 'selected' : ''; ?>>Orden: Alfabético</option>
                            <option value="medicos" <?php echo $orden === 'medicos' ? 'selected' : ''; ?>>Orden: Más médicos</option>
                        </select>
                    </div>
                </form>
                <a href="admin_especialidades.php?nueva=1" class="esp-btn esp-btn-primario" style="margin-left:auto;">
                    + Nueva Especialidad
                </a>
            </div>

            <div class="esp-contenido">

                <!-- ============ GRID DE TARJETAS ============ -->
                <div>
                    <?php if (count($especialidades) === 0): ?>
                        <div class="esp-alerta esp-alerta-info">No se encontraron especialidades.</div>
                    <?php endif; ?>

                    <div class="esp-grid">
                        <?php foreach ($especialidades as $esp):
                            $idEsp = (int) $esp['id_especialidad'];
                            $nombreEsp = $esp['nombre_especialidad'];
                            $defaultEsp = espColores($nombreEsp);
                            $payload = json_encode([
                                'nombre'      => $nombreEsp,
                                'descripcion' => (string) $esp['descripcion'],
                                'medicos'     => (int) $esp['total_medicos'],
                                'consultas'   => (int) $esp['consultas_mes'],
                                'lista'       => $medicosPorEspecialidad[$idEsp] ?? [],
                                'acento'      => $defaultEsp[0],
                            ], JSON_UNESCAPED_UNICODE);
                            $tieneMedicos = (int) $esp['total_medicos'] > 0;
                        ?>
                        <article class="esp-tarjeta"
                                 style="--esp-acento-var: <?php echo $defaultEsp[0]; ?>; --esp-acento-suave-var: <?php echo $defaultEsp[1]; ?>;"
                                 data-payload="<?php echo htmlspecialchars($payload, ENT_QUOTES, 'UTF-8'); ?>">
                            <div class="esp-tarjeta-cabeza">
                                <span class="esp-icono"><?php echo espSvg($nombreEsp); ?></span>
                                <h3 class="esp-nombre"><?php echo htmlspecialchars($nombreEsp); ?></h3>
                            </div>

                            <?php if (trim((string) $esp['descripcion']) !== ''): ?>
                                <p class="esp-descripcion"><?php echo htmlspecialchars($esp['descripcion']); ?></p>
                            <?php else: ?>
                                <p class="esp-descripcion">Sin descripción</p>
                            <?php endif; ?>

                            <div class="esp-metrica">
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                                <strong><?php echo (int) $esp['total_medicos']; ?></strong> médico(s)
                                · <strong><?php echo (int) $esp['consultas_mes']; ?></strong> consulta(s) este mes
                            </div>

                            <div class="esp-tarjeta-acciones">
                                <button type="button" class="esp-btn esp-btn-ver esp-btn-peq" onclick="espVerDetalles(this.closest('.esp-tarjeta'))">Ver detalles</button>
                                <a href="admin_especialidades.php?editar=<?php echo $idEsp; ?>" class="esp-btn esp-btn-secundario esp-btn-peq">Editar</a>
                                <?php if ($tieneMedicos): ?>
                                    <button type="button" class="esp-btn esp-btn-eliminar esp-btn-peq esp-btn-deshabilitado"
                                            title="Tiene médico(s) asignado(s), no se puede eliminar">Eliminar</button>
                                <?php else: ?>
                                    <form method="post" action="admin_especialidades.php"
                                          onsubmit="return confirm('¿Eliminar esta especialidad? Esta acción no se puede deshacer.');">
                                        <input type="hidden" name="accion" value="eliminar">
                                        <input type="hidden" name="id_especialidad" value="<?php echo $idEsp; ?>">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                        <button type="submit" class="esp-btn esp-btn-eliminar esp-btn-peq">Eliminar</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </article>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- ============ PANEL LATERAL ============ -->
                <aside class="esp-panel-lateral">
                    <section class="esp-lateral-card">
                        <h3 class="esp-lateral-titulo">Resumen General</h3>
                        <div class="esp-stat">
                            <span class="esp-stat-label">Total especialidades</span>
                            <span class="esp-stat-num"><?php echo $totalEspecialidades; ?></span>
                        </div>
                        <div class="esp-stat">
                            <span class="esp-stat-label">Médicos asignados</span>
                            <span class="esp-stat-num"><?php echo $totalMedicos; ?></span>
                        </div>
                        <div class="esp-stat">
                            <span class="esp-stat-label">Consultas este mes</span>
                            <span class="esp-stat-num"><?php echo $consultasMes; ?></span>
                        </div>
                    </section>

                    <section class="esp-lateral-card">
                        <h3 class="esp-lateral-titulo">Acciones rápidas</h3>
                        <div class="esp-acciones-lista">
                            <a href="admin_especialidades.php?nueva=1" class="esp-accion">
                                <span>+ Nueva Especialidad</span>
                            </a>
                            <a href="crear_usuario.php?rol=medico" class="esp-accion">
                                <span>Agregar Médico</span>
                            </a>
                            <div class="esp-accion esp-accion-deshabilitada" title="Los médicos no se pueden filtrar por especialidad todavía">
                                <span>Ver Médicos por Especialidad</span>
                                <span class="esp-prox">Próximamente</span>
                            </div>
                            <div class="esp-accion esp-accion-deshabilitada" title="El módulo de reportes aún no está disponible">
                                <span>Generar Reporte</span>
                                <span class="esp-prox">Próximamente</span>
                            </div>
                        </div>
                    </section>
                </aside>

            </div>

        <?php endif; ?>

        <footer class="esp-footer">
            Hospital San Rafael · Panel de Administración · Especialidades
        </footer>
    </div>
</div>

<!-- ============ MODAL VER DETALLES ============ -->
<div id="espModal" class="esp-modal-overlay oculto" onclick="if (event.target === this) espCerrarModal()">
    <div class="esp-modal" role="dialog" aria-modal="true" aria-labelledby="espModalTitulo">
        <div class="esp-modal-cabecera">
            <div class="esp-modal-nombre">
                <span id="espModalIcono" class="esp-icono"></span>
                <h3 id="espModalTitulo" class="esp-modal-titulo">Detalles</h3>
            </div>
            <button type="button" class="esp-modal-cerrar" onclick="espCerrarModal()" aria-label="Cerrar">&times;</button>
        </div>
        <div class="esp-modal-cuerpo">
            <div class="esp-dato">
                <div class="esp-dato-label">Descripción</div>
                <p id="espModalDesc" class="esp-dato-valor">—</p>
            </div>
            <div class="esp-dato">
                <div class="esp-dato-label">Médicos asignados</div>
                <ul id="espModalMedicos" class="esp-lista-medicos"></ul>
            </div>
            <div class="esp-dato">
                <div class="esp-dato-label">Consultas este mes</div>
                <p id="espModalConsultas" class="esp-dato-valor">—</p>
            </div>
        </div>
    </div>
</div>

<script>
function espVerDetalles(tarjeta) {
    var datos;
    try {
        datos = JSON.parse(tarjeta.getAttribute('data-payload'));
    } catch (e) {
        return;
    }

    var acento = datos.acento || '#2563EB';
    var icono = tarjeta.querySelector('.esp-icono');
    var modalIcono = document.getElementById('espModalIcono');

    modalIcono.innerHTML = icono ? icono.innerHTML : '';
    modalIcono.style.setProperty('--esp-acento-var', acento);
    modalIcono.style.setProperty('--esp-acento-suave-var', 'rgba(37, 99, 235, 0.12)');

    document.getElementById('espModalTitulo').textContent = datos.nombre;

    var desc = datos.descripcion && datos.descripcion.trim() !== '' ? datos.descripcion : 'Sin descripción.';
    document.getElementById('espModalDesc').textContent = desc;

    var lista = datos.lista || [];
    var contenedor = document.getElementById('espModalMedicos');
    contenedor.innerHTML = '';
    if (lista.length === 0) {
        var liVacio = document.createElement('li');
        liVacio.textContent = 'Sin médicos asignados';
        liVacio.className = 'esp-sin-datos';
        contenedor.appendChild(liVacio);
    } else {
        lista.forEach(function (nombre) {
            var li = document.createElement('li');
            li.textContent = nombre;
            contenedor.appendChild(li);
        });
    }

    document.getElementById('espModalConsultas').textContent = datos.consultas + ' consulta(s) este mes';

    document.getElementById('espModal').classList.remove('oculto');
}

function espCerrarModal() {
    document.getElementById('espModal').classList.add('oculto');
}

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        espCerrarModal();
    }
});
</script>

</body>
</html>