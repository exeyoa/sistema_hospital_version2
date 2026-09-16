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

// --- Parámetro id_turno (solo dígitos) ---
$idTurno = (isset($_GET['id_turno']) && ctype_digit($_GET['id_turno']))
    ? (int) $_GET['id_turno']
    : 0;

if ($idTurno <= 0) {
    die('Error: falta el número de turno. Vuelve a la cola e intenta de nuevo.');
}

// --- Turno + paciente ---
$stmtTurno = $conexion->prepare(
    "SELECT t.id_turno, t.id_cita, t.id_paciente, t.numero_turno, t.tipo, t.estado, t.fecha,
            p.nombre, p.apellido, p.cedula, p.fecha_nacimiento, p.sexo, p.telefono, p.direccion, p.correo
     FROM turnos t
     INNER JOIN pacientes p ON p.id_paciente = t.id_paciente
     WHERE t.id_turno = :id_turno
     LIMIT 1"
);
$stmtTurno->execute([':id_turno' => $idTurno]);
$turno = $stmtTurno->fetch(PDO::FETCH_ASSOC);

if (!$turno) {
    die('Error: el turno no existe.');
}
$id_paciente = (int) $turno['id_paciente'];

// --- Consulta existente para este turno ---
$stmtConsulta = $conexion->prepare(
    "SELECT id_consulta, id_medico, motivo, diagnostico, observaciones, fecha_consulta
     FROM consultas WHERE id_turno = :id_turno LIMIT 1"
);
$stmtConsulta->execute([':id_turno' => $idTurno]);
$consulta = $stmtConsulta->fetch(PDO::FETCH_ASSOC);

// --- Bloqueo: no permitir atender turnos que no son de este médico ---
$bloqueadoPorOtroMedico = false;

if ($consulta) {
    // Ya existe consulta: solo bloquea si es de OTRO médico.
    $bloqueadoPorOtroMedico = (int) $consulta['id_medico'] !== $id_medico;
} elseif ($turno['tipo'] === 'con_cita') {
    // Cita sin consulta creada todavía: el turno pertenece al médico de su cita.
    // Sin cita válida asociada no se puede probar la propiedad → bloquea también.
    $stmtCita = $conexion->prepare("SELECT id_medico FROM citas WHERE id_cita = :id_cita LIMIT 1");
    $stmtCita->execute([':id_cita' => $turno['id_cita'] !== null ? (int) $turno['id_cita'] : 0]);
    $cita = $stmtCita->fetch(PDO::FETCH_ASSOC);
    $bloqueadoPorOtroMedico = !$cita || (int) $cita['id_medico'] !== $id_medico;
}

// --- Token CSRF para el formulario ---
$csrfToken = generarTokenCSRF();

// --- Procesamiento POST ---
$mensajeError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$bloqueadoPorOtroMedico) {
    if (!validarTokenCSRF($_POST['csrf_token'] ?? null)) {
        die('Solicitud no válida: el token de seguridad expiró. Vuelve a intentar.');
    }

    $accion        = $_POST['accion'] ?? '';
    $motivo        = trim((string) ($_POST['motivo'] ?? ''));
    $diagnostico   = trim((string) ($_POST['diagnostico'] ?? ''));
    $observaciones = trim((string) ($_POST['observaciones'] ?? ''));

    try {
        $conexion->beginTransaction();

        if ($accion === 'atender' && !$consulta) {
            // Re-verificación concurrente: otro médico pudo tomarlo en el ínterin.
            $stmtChk = $conexion->prepare("SELECT id_consulta FROM consultas WHERE id_turno = :id_turno LIMIT 1");
            $stmtChk->execute([':id_turno' => $idTurno]);
            if ($stmtChk->fetchColumn()) {
                throw new RuntimeException('otro_medico');
            }

            // Re-validación de propiedad: una cita pertenece a su médico, no a quien la llame por URL.
            if ($turno['tipo'] === 'con_cita') {
                $stmtCita = $conexion->prepare("SELECT id_medico FROM citas WHERE id_cita = :id_cita LIMIT 1");
                $stmtCita->execute([':id_cita' => $turno['id_cita'] !== null ? (int) $turno['id_cita'] : 0]);
                $cita = $stmtCita->fetch(PDO::FETCH_ASSOC);
                if (!$cita || (int) $cita['id_medico'] !== $id_medico) {
                    throw new RuntimeException('otro_medico');
                }
            }

            $stmtIns = $conexion->prepare(
                "INSERT INTO consultas (id_turno, id_paciente, id_medico, motivo, diagnostico, observaciones)
                 VALUES (:id_turno, :id_paciente, :id_medico, :motivo, :diagnostico, :observaciones)"
            );
            $stmtIns->execute([
                ':id_turno'      => $idTurno,
                ':id_paciente'   => $id_paciente,
                ':id_medico'     => $id_medico,
                ':motivo'        => $motivo,
                ':diagnostico'   => $diagnostico,
                ':observaciones' => $observaciones,
            ]);

            $stmtUpTurno = $conexion->prepare(
                "UPDATE turnos SET estado = 'en_consulta' WHERE id_turno = :id_turno"
            );
            $stmtUpTurno->execute([':id_turno' => $idTurno]);

            $conexion->commit();

            header('Location: consulta.php?id_turno=' . $idTurno);
            exit;

        } elseif ($accion === 'guardar' && $consulta && (int) $consulta['id_medico'] === $id_medico) {

            $stmtUpConsulta = $conexion->prepare(
                "UPDATE consultas SET motivo = :motivo, diagnostico = :diagnostico, observaciones = :observaciones
                 WHERE id_consulta = :id_consulta"
            );
            $stmtUpConsulta->execute([
                ':id_consulta'   => (int) $consulta['id_consulta'],
                ':motivo'        => $motivo,
                ':diagnostico'   => $diagnostico,
                ':observaciones' => $observaciones,
            ]);

            $stmtUpTurno = $conexion->prepare(
                "UPDATE turnos SET estado = 'atendido' WHERE id_turno = :id_turno"
            );
            $stmtUpTurno->execute([':id_turno' => $idTurno]);

            $conexion->commit();

            header('Location: medico.php');
            exit;

        } else {
            throw new RuntimeException('accion_invalida');
        }
    } catch (RuntimeException $e) {
        $conexion->rollBack();
        $mensajeError = $e->getMessage() === 'otro_medico'
            ? 'Otro médico ya tomó este turno. Vuelve a la cola.'
            : 'No se pudo guardar la consulta. Intenta de nuevo.';
    } catch (Throwable $e) {
        if ($conexion->inTransaction()) {
            $conexion->rollBack();
        }
        error_log('Error en consulta.php: ' . $e->getMessage());
        $mensajeError = 'No se pudo guardar la consulta. Intenta de nuevo.';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Consulta | Sistema de Consultas</title>
    <link rel="stylesheet" href="../css/estilo.css">
    <link rel="stylesheet" href="../css/medico.css">
</head>
<body>

<div class="medico-layout">

    <?php include __DIR__ . '/parciales/topbar.php'; ?>

    <?php $paginaActiva = 'cola'; include __DIR__ . '/parciales/sidebar.php'; ?>

    <main class="medico-main">

        <div class="medico-main__encabezado">
            <div class="medico-main__titulo">
                <h2>Consulta — Turno <?= htmlspecialchars($turno['numero_turno']) ?></h2>
                <p><?= $turno['tipo'] === 'con_cita' ? 'Con cita' : 'Espontáneo' ?></p>
            </div>
            <a href="medico.php" class="btn-accion">&larr; Volver a la cola</a>
        </div>

        <?php if ($mensajeError): ?>
            <div class="mensaje-error"><?= htmlspecialchars($mensajeError) ?></div>
        <?php endif; ?>

        <?php if ($bloqueadoPorOtroMedico): ?>
            <section class="panel-tabla" style="grid-column: 1 / -1;">
                <div class="panel-tabla__vacio">
                    ⛔ Este turno ya está siendo atendido por otro médico. No puedes abrirlo.
                </div>
            </section>
        <?php else: ?>

            <section class="panel-tabla" style="grid-column: 1 / -1;">
                <div class="tarjeta-lateral">
                    <h3>🧑 Paciente</h3>
                    <div class="tarjeta-lateral__dato">
                        <div class="tarjeta-lateral__dato-label">Nombre completo</div>
                        <div class="tarjeta-lateral__dato-numero"><?= htmlspecialchars($turno['nombre'] . ' ' . $turno['apellido']) ?></div>
                    </div>
                    <div class="tarjeta-lateral__dato">
                        <div class="tarjeta-lateral__dato-label">Cédula</div>
                        <div class="tarjeta-lateral__dato-numero"><?= htmlspecialchars($turno['cedula'] ?? '') ?></div>
                    </div>
                    <div class="tarjeta-lateral__dato">
                        <div class="tarjeta-lateral__dato-label">Fecha de nacimiento</div>
                        <div class="tarjeta-lateral__dato-numero"><?= htmlspecialchars($turno['fecha_nacimiento'] ?? '') ?></div>
                    </div>
                    <div class="tarjeta-lateral__dato">
                        <div class="tarjeta-lateral__dato-label">Teléfono</div>
                        <div class="tarjeta-lateral__dato-numero"><?= htmlspecialchars($turno['telefono'] ?? '—') ?></div>
                    </div>
                </div>

                <?php if ($turno['estado'] === 'atendido'): ?>
                    <div class="panel-tabla__vacio">
                        ✅ Esta consulta ya fue finalizada. Puedes actualizar los datos si necesitas corregirlos.
                    </div>
                <?php endif; ?>

                <form method="post" action="consulta.php?id_turno=<?= (int) $idTurno ?>" class="campo">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                    <div class="campo">
                        <label for="motivo">Motivo</label>
                        <input type="text" id="motivo" name="motivo" maxlength="255"
                               value="<?= htmlspecialchars($consulta['motivo'] ?? '') ?>">
                    </div>

                    <div class="campo">
                        <label for="diagnostico">Diagnóstico</label>
                        <textarea id="diagnostico" name="diagnostico" rows="4"><?= htmlspecialchars($consulta['diagnostico'] ?? '') ?></textarea>
                    </div>

                    <div class="campo">
                        <label for="observaciones">Observaciones</label>
                        <textarea id="observaciones" name="observaciones" rows="4"><?= htmlspecialchars($consulta['observaciones'] ?? '') ?></textarea>
                    </div>

                    <?php if (!$consulta): ?>
                        <button type="submit" name="accion" value="atender" class="btn">🩺 Atender — iniciar consulta</button>
                    <?php else: ?>
                        <button type="submit" name="accion" value="guardar" class="btn">
                            <?= $turno['estado'] === 'en_consulta' ? 'Guardar y finalizar consulta' : 'Actualizar consulta' ?>
                        </button>
                    <?php endif; ?>
                </form>
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