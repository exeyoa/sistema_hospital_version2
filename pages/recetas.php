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

// --- Rutas: listado (por defecto) | accion=nueva (crear) | id_receta=X (ver/editar) ---
$accion   = (string) ($_GET['accion'] ?? '');
$idReceta = (isset($_GET['id_receta']) && ctype_digit($_GET['id_receta']))
    ? (int) $_GET['id_receta']
    : 0;

$mensajeError = null;
$mensajeExito = isset($_GET['exito']) ? 'Medicamentos guardados correctamente.' : null;

// --- Variables para detalle de receta ---
$recetaData          = null;
$bloqueadoPorOtroMedico = false;
$recetaNoExiste      = false;
$detalle             = [];
$medicamentos        = [];

// ============================================================
// RUTA: id_receta — Ver / agregar medicamentos a una receta
// ============================================================
if ($idReceta > 0) {
    // --- Cargar receta + verificación de propiedad (dos pasos) ---
    $stmtRec = $conexion->prepare(
        "SELECT r.id_receta, r.fecha_emision, c.id_consulta, c.id_medico,
                c.motivo, c.diagnostico, c.fecha_consulta,
                p.id_paciente, p.nombre, p.apellido, p.cedula
         FROM recetas r
         INNER JOIN consultas c ON c.id_consulta = r.id_consulta
         INNER JOIN pacientes p ON p.id_paciente = c.id_paciente
         WHERE r.id_receta = :id_receta
         LIMIT 1"
    );
    $stmtRec->bindValue(':id_receta', $idReceta, PDO::PARAM_INT);
    $stmtRec->execute();
    $recetaData = $stmtRec->fetch(PDO::FETCH_ASSOC);

    if (!$recetaData) {
        $recetaNoExiste = true;
    } elseif ((int) $recetaData['id_medico'] !== $id_medico) {
        $bloqueadoPorOtroMedico = true;
    } else {
        // --- Medicamentos ya agregados (lectura) ---
        $stmtDet = $conexion->prepare(
            "SELECT rd.dosis, rd.frecuencia, rd.duracion,
                    m.nombre_medicamento, m.presentacion
             FROM receta_detalle rd
             INNER JOIN medicamentos m ON m.id_medicamento = rd.id_medicamento
             WHERE rd.id_receta = :id_receta
             ORDER BY rd.id_detalle ASC"
        );
        $stmtDet->bindValue(':id_receta', $idReceta, PDO::PARAM_INT);
        $stmtDet->execute();
        $detalle = $stmtDet->fetchAll(PDO::FETCH_ASSOC);

        // --- Catálogo de medicamentos (para el formulario) ---
        $stmtMed = $conexion->prepare(
            "SELECT id_medicamento, nombre_medicamento, presentacion
             FROM medicamentos ORDER BY nombre_medicamento ASC"
        );
        $stmtMed->execute();
        $medicamentos = $stmtMed->fetchAll(PDO::FETCH_ASSOC);

        // --- POST: agregar medicamentos a la receta ---
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!validarTokenCSRF($_POST['csrf_token'] ?? null)) {
                die('Solicitud no válida: el token de seguridad expiró. Vuelve a intentar.');
            }

            $ids      = $_POST['id_medicamento'] ?? [];
            $dosisArr = $_POST['dosis']           ?? [];
            $freqArr  = $_POST['frecuencia']      ?? [];
            $durArr   = $_POST['duracion']        ?? [];

            // Recopilar filas completas, ignorar vacías
            $rows   = [];
            $count  = max(count($ids), count($dosisArr), count($freqArr), count($durArr));
            for ($i = 0; $i < $count; $i++) {
                $idMed = trim((string) ($ids[$i]      ?? ''));
                $dosis = trim((string) ($dosisArr[$i]  ?? ''));
                $freq  = trim((string) ($freqArr[$i]   ?? ''));
                $dur   = trim((string) ($durArr[$i]    ?? ''));
                if ($idMed !== '' && ctype_digit($idMed) && $dosis !== '' && $freq !== '' && $dur !== '') {
                    $rows[] = [
                        'id_medicamento' => (int) $idMed,
                        'dosis'          => $dosis,
                        'frecuencia'     => $freq,
                        'duracion'       => $dur,
                    ];
                }
            }

            if (!empty($rows)) {
                try {
                    $conexion->beginTransaction();
                    $stmtIns = $conexion->prepare(
                        "INSERT INTO receta_detalle
                             (id_receta, id_medicamento, dosis, frecuencia, duracion)
                         VALUES
                             (:id_receta, :id_medicamento, :dosis, :frecuencia, :duracion)"
                    );
                    foreach ($rows as $row) {
                        $stmtIns->bindValue(':id_receta',      $idReceta,            PDO::PARAM_INT);
                        $stmtIns->bindValue(':id_medicamento', $row['id_medicamento'], PDO::PARAM_INT);
                        $stmtIns->bindValue(':dosis',          $row['dosis'],          PDO::PARAM_STR);
                        $stmtIns->bindValue(':frecuencia',     $row['frecuencia'],     PDO::PARAM_STR);
                        $stmtIns->bindValue(':duracion',       $row['duracion'],       PDO::PARAM_STR);
                        $stmtIns->execute();
                    }
                    $conexion->commit();
                    header('Location: recetas.php?id_receta=' . $idReceta . '&exito=1');
                    exit;
                } catch (Throwable $e) {
                    if ($conexion->inTransaction()) {
                        $conexion->rollBack();
                    }
                    error_log('Error agregando medicamentos: ' . $e->getMessage());
                    $mensajeError = 'No se pudieron guardar los medicamentos. Intenta de nuevo.';
                }
            } else {
                // Sin filas válidas — redirigir para evitar reenvío
                header('Location: recetas.php?id_receta=' . $idReceta);
                exit;
            }
        }
    }
}

// ============================================================
// RUTA: accion=nueva — Crear receta (puede quedar vacía)
// ============================================================
if ($idReceta <= 0 && $accion === 'nueva' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validarTokenCSRF($_POST['csrf_token'] ?? null)) {
        die('Solicitud no válida: el token de seguridad expiró. Vuelve a intentar.');
    }

    $idConsulta = (isset($_POST['id_consulta']) && ctype_digit($_POST['id_consulta']))
        ? (int) $_POST['id_consulta']
        : 0;

    if ($idConsulta <= 0) {
        $mensajeError = 'Selecciona una consulta válida.';
    } else {
        try {
            $conexion->beginTransaction();

            $stmtVerifica = $conexion->prepare(
                "SELECT c.id_consulta
                 FROM consultas c
                 INNER JOIN turnos t ON t.id_turno = c.id_turno
                 LEFT JOIN recetas r ON r.id_consulta = c.id_consulta
                 WHERE c.id_consulta = :id_consulta
                   AND c.id_medico = :id_medico
                   AND t.estado = 'atendido'
                   AND r.id_receta IS NULL
                 LIMIT 1"
            );
            $stmtVerifica->bindValue(':id_consulta', $idConsulta, PDO::PARAM_INT);
            $stmtVerifica->bindValue(':id_medico',   $id_medico,   PDO::PARAM_INT);
            $stmtVerifica->execute();
            if (!$stmtVerifica->fetchColumn()) {
                throw new RuntimeException('consulta_no_valida');
            }

            $stmtIns = $conexion->prepare("INSERT INTO recetas (id_consulta) VALUES (:id_consulta)");
            $stmtIns->bindValue(':id_consulta', $idConsulta, PDO::PARAM_INT);
            $stmtIns->execute();

            $nuevoId = (int) $conexion->lastInsertId();
            $conexion->commit();

            header('Location: recetas.php?id_receta=' . $nuevoId);
            exit;
        } catch (PDOException $e) {
            $conexion->rollBack();
            if ($e->getCode() === '23000') {
                $mensajeError = 'Esta consulta ya tiene una receta.';
            } else {
                error_log('Error creando receta: ' . $e->getMessage());
                $mensajeError = 'No se pudo crear la receta. Intenta de nuevo.';
            }
        } catch (RuntimeException $e) {
            $conexion->rollBack();
            $mensajeError = 'Solo puedes emitir recetas de tus propias consultas finalizadas y sin receta.';
        } catch (Throwable $e) {
            if ($conexion->inTransaction()) {
                $conexion->rollBack();
            }
            error_log('Error creando receta: ' . $e->getMessage());
            $mensajeError = 'No se pudo crear la receta. Intenta de nuevo.';
        }
    }
}

// --- Consultas disponibles para emitir receta ---
$consultasDisponibles = [];
if ($idReceta <= 0 && $accion === 'nueva') {
    $sqlConsulta = "
        SELECT c.id_consulta, c.fecha_consulta, c.motivo,
               p.id_paciente, p.nombre, p.apellido, p.cedula
        FROM consultas c
        INNER JOIN pacientes p ON p.id_paciente = c.id_paciente
        INNER JOIN turnos t ON t.id_turno = c.id_turno
        LEFT JOIN recetas r ON r.id_consulta = c.id_consulta
        WHERE c.id_medico = :id_medico
          AND t.estado = 'atendido'
          AND r.id_receta IS NULL
        ORDER BY c.fecha_consulta DESC
    ";
    $stmtConsulta = $conexion->prepare($sqlConsulta);
    $stmtConsulta->bindValue(':id_medico', $id_medico, PDO::PARAM_INT);
    $stmtConsulta->execute();
    $consultasDisponibles = $stmtConsulta->fetchAll(PDO::FETCH_ASSOC);
}

// ============================================================
// RUTA: listado (por defecto)
// ============================================================
$sqlRecetas = "
    SELECT r.id_receta, r.fecha_emision, c.id_consulta, c.fecha_consulta,
           p.id_paciente, p.nombre, p.apellido, p.cedula,
           COUNT(rd.id_detalle) AS num_medicamentos
    FROM recetas r
    INNER JOIN consultas c ON c.id_consulta = r.id_consulta
    INNER JOIN pacientes p ON p.id_paciente = c.id_paciente
    LEFT JOIN receta_detalle rd ON rd.id_receta = r.id_receta
    WHERE c.id_medico = :id_medico
    GROUP BY r.id_receta, r.fecha_emision, c.id_consulta, c.fecha_consulta,
             p.id_paciente, p.nombre, p.apellido, p.cedula
    ORDER BY r.fecha_emision DESC
";
$stmtRecetas = $conexion->prepare($sqlRecetas);
$stmtRecetas->bindValue(':id_medico', $id_medico, PDO::PARAM_INT);
$stmtRecetas->execute();
$recetas = $stmtRecetas->fetchAll(PDO::FETCH_ASSOC);

$csrfToken = generarTokenCSRF();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recetas emitidas | Sistema de Consultas</title>
    <link rel="stylesheet" href="../css/estilo.css">
    <link rel="stylesheet" href="../css/medico.css">
</head>
<body>

<div class="medico-layout">

    <?php include __DIR__ . '/parciales/topbar.php'; ?>

    <?php $paginaActiva = 'recetas'; include __DIR__ . '/parciales/sidebar.php'; ?>

    <main class="medico-main">

        <?php if ($recetaNoExiste): ?>

            <!-- ===== Receta no encontrada ===== -->
            <div class="medico-main__encabezado">
                <div class="medico-main__titulo">
                    <h2>Receta no encontrada</h2>
                    <p>La receta que buscas no existe.</p>
                </div>
                <a href="recetas.php" class="btn-accion">&larr; Volver a recetas</a>
            </div>

        <?php elseif ($bloqueadoPorOtroMedico): ?>

            <!-- ===== Bloqueo: receta de otro médico ===== -->
            <div class="medico-main__encabezado">
                <div class="medico-main__titulo">
                    <h2>Acceso denegado</h2>
                    <p>⛔ Esta receta pertenece a otra médica. No puedes editarla.</p>
                </div>
                <a href="recetas.php" class="btn-accion">&larr; Volver a recetas</a>
            </div>

        <?php elseif ($idReceta > 0 && $recetaData): ?>

            <!-- ===== Detalle de receta + agregar medicamentos ===== -->
            <div class="medico-main__encabezado">
                <div class="medico-main__titulo">
                    <h2>Receta — <?= htmlspecialchars($recetaData['nombre'] . ' ' . $recetaData['apellido']) ?></h2>
                    <p>Fecha de emisión: <?= date('d/m/Y h:i a', strtotime($recetaData['fecha_emision'])) ?></p>
                </div>
                <a href="recetas.php" class="btn-accion">&larr; Volver a recetas</a>
            </div>

            <section class="panel-tabla" style="grid-column: 1 / -1;">

                <?php if ($mensajeError): ?>
                    <div class="mensaje-error"><?= htmlspecialchars($mensajeError) ?></div>
                <?php endif; ?>

                <?php if ($mensajeExito): ?>
                    <div style="background:#d4edda;color:#155724;border:1px solid #c3e6cb;border-radius:4px;padding:1rem;margin-bottom:1rem;">
                        <?= htmlspecialchars($mensajeExito) ?>
                    </div>
                <?php endif; ?>

                <!-- Datos de la consulta -->
                <div class="tarjeta-lateral">
                    <h3>Consulta asociada</h3>
                    <div class="tarjeta-lateral__dato">
                        <div class="tarjeta-lateral__dato-label">Fecha consulta</div>
                        <div class="tarjeta-lateral__dato-numero"><?= date('d/m/Y h:i a', strtotime($recetaData['fecha_consulta'])) ?></div>
                    </div>
                    <div class="tarjeta-lateral__dato">
                        <div class="tarjeta-lateral__dato-label">Paciente</div>
                        <div class="tarjeta-lateral__dato-numero"><?= htmlspecialchars($recetaData['nombre'] . ' ' . $recetaData['apellido'] . ' (' . $recetaData['cedula'] . ')') ?></div>
                    </div>
                    <?php if (!empty($recetaData['motivo'])): ?>
                    <div class="tarjeta-lateral__dato">
                        <div class="tarjeta-lateral__dato-label">Motivo</div>
                        <div class="tarjeta-lateral__dato-numero"><?= htmlspecialchars($recetaData['motivo']) ?></div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Medicamentos ya agregados -->
                <h3 style="margin-top:1.5rem;">Medicamentos en esta receta</h3>
                <?php if (empty($detalle)): ?>
                    <div class="panel-tabla__vacio">
                        Aún no hay medicamentos en esta receta. Agrega uno o más abajo.
                    </div>
                <?php else: ?>
                    <table class="tabla-cola">
                        <thead>
                        <tr>
                            <th>Medicamento</th>
                            <th>Presentación</th>
                            <th>Dosis</th>
                            <th>Frecuencia</th>
                            <th>Duración</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($detalle as $fila): ?>
                            <tr>
                                <td data-label="Medicamento"><?= htmlspecialchars($fila['nombre_medicamento']) ?></td>
                                <td data-label="Presentación"><?= htmlspecialchars($fila['presentacion'] ?? '—') ?></td>
                                <td data-label="Dosis"><?= htmlspecialchars($fila['dosis']) ?></td>
                                <td data-label="Frecuencia"><?= htmlspecialchars($fila['frecuencia']) ?></td>
                                <td data-label="Duración"><?= htmlspecialchars($fila['duracion']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

                <!-- Formulario para agregar nuevos medicamentos -->
                <h3 style="margin-top:1.5rem;">Agregar medicamentos</h3>
                <form method="post" action="recetas.php?id_receta=<?= $idReceta ?>">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                    <div id="filas">
                        <div class="campo receta-fila" style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr 1fr;gap:0.5rem;align-items:end;margin-bottom:0.5rem;">
                            <div>
                                <label>Medicamento</label>
                                <select name="id_medicamento[]">
                                    <option value="">— seleccionar —</option>
                                    <?php foreach ($medicamentos as $med): ?>
                                        <option value="<?= (int) $med['id_medicamento'] ?>">
                                            <?= htmlspecialchars($med['nombre_medicamento'] . ' — ' . ($med['presentacion'] ?? '')) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label>Dosis</label>
                                <input type="text" name="dosis[]" maxlength="60" placeholder="Ej: 1 tableta">
                            </div>
                            <div>
                                <label>Frecuencia</label>
                                <input type="text" name="frecuencia[]" maxlength="60" placeholder="Ej: Cada 8 horas">
                            </div>
                            <div>
                                <label>Duración</label>
                                <input type="text" name="duracion[]" maxlength="60" placeholder="Ej: 5 días">
                            </div>
                            <div>&nbsp;</div>
                        </div>
                    </div>

                    <div style="display:flex;gap:0.5rem;margin-top:0.5rem;">
                        <button type="button" class="btn-accion" onclick="agregarFila()">+ Agregar otro medicamento</button>
                        <button type="submit" class="btn">Guardar medicamentos</button>
                    </div>
                </form>

            </section>

        <?php elseif ($accion === 'nueva'): ?>

            <!-- ===== Nueva receta: selector de consulta ===== -->
            <div class="medico-main__encabezado">
                <div class="medico-main__titulo">
                    <h2>Nueva receta</h2>
                    <p>Selecciona una consulta finalizada tuya que aún no tenga receta.</p>
                </div>
                <a href="recetas.php" class="btn-accion">&larr; Volver a recetas</a>
            </div>

            <section class="panel-tabla" style="grid-column: 1 / -1;">
                <?php if ($mensajeError): ?>
                    <div class="mensaje-error"><?= htmlspecialchars($mensajeError) ?></div>
                <?php endif; ?>

                <?php if (empty($consultasDisponibles)): ?>
                    <div class="panel-tabla__vacio">
                        No hay consultas finalizadas sin receta para emitir una nueva. Finaliza primero una consulta desde la cola.
                    </div>
                <?php else: ?>
                    <form method="post" action="recetas.php?accion=nueva" class="campo">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                        <div class="campo">
                            <label for="id_consulta">Consulta</label>
                            <select name="id_consulta" id="id_consulta">
                                <?php foreach ($consultasDisponibles as $consulta): ?>
                                    <option value="<?= (int) $consulta['id_consulta'] ?>">
                                        <?= htmlspecialchars(
                                            $consulta['nombre'] . ' ' . $consulta['apellido']
                                            . ' — ' . date('d/m/Y h:i a', strtotime($consulta['fecha_consulta']))
                                            . ($consulta['motivo'] !== null ? ' — ' . $consulta['motivo'] : '')
                                        ) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <button type="submit" class="btn">Crear receta</button>
                    </form>
                <?php endif; ?>
            </section>

        <?php else: ?>

            <!-- ===== Listado ===== -->
            <div class="medico-main__encabezado">
                <div class="medico-main__titulo">
                    <h2>Recetas emitidas</h2>
                    <p>Recetas que has generado a tus pacientes.</p>
                </div>
                <a href="recetas.php?accion=nueva" class="btn-accion">+ Nueva receta</a>
            </div>

            <section class="panel-tabla" style="grid-column: 1 / -1;">
                <?php if (empty($recetas)): ?>
                    <div class="panel-tabla__vacio">
                        Aún no has emitido recetas. Usa "+ Nueva receta" para crear la primera.
                    </div>
                <?php else: ?>
                    <table class="tabla-cola">
                        <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Paciente</th>
                            <th>Medicamentos</th>
                            <th>Ver</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($recetas as $receta): ?>
                            <tr>
                                <td data-label="Fecha"><?= date('d/m/Y h:i a', strtotime($receta['fecha_emision'])) ?></td>
                                <td data-label="Paciente"><?= htmlspecialchars($receta['nombre'] . ' ' . $receta['apellido']) ?></td>
                                <td data-label="Medicamentos">
                                    <?php if ((int) $receta['num_medicamentos'] === 0): ?>
                                        <span class="badge badge-sin-receta">Sin medicamentos agregados todavía</span>
                                    <?php else: ?>
                                        <span class="badge badge-estado-atendido"><?= (int) $receta['num_medicamentos'] ?> medicamentos</span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Ver">
                                    <a class="btn-accion atender"
                                       href="recetas.php?id_receta=<?= (int) $receta['id_receta'] ?>">
                                        Ver receta
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

    function agregarFila() {
        var contenedor = document.getElementById('filas');
        var plantilla  = contenedor.querySelector('.receta-fila');
        var nueva      = plantilla.cloneNode(true);

        // Limpiar valores
        var selects = nueva.querySelectorAll('select');
        var inputs  = nueva.querySelectorAll('input[type="text"]');
        selects.forEach(function(s) { s.selectedIndex = 0; });
        inputs.forEach(function(i) { i.value = ''; });

        contenedor.appendChild(nueva);
    }
</script>

</body>
</html>