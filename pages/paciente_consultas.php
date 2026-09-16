<?php
/**
 * pages/paciente_consultas.php
 *
 * Listado de las consultas (atenciones médicas) del paciente logueado.
 * Solo lectura.
 */

$seccionActiva = 'consultas';

require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/parciales/paciente_funciones.php';
verificarSesionPaciente();

$idPaciente = (int) $_SESSION['id_paciente'];

$consultas = listarConsultasPaciente($conexion, $idPaciente);

require_once __DIR__ . '/parciales/paciente_header.php';
?>

<section class="seccion-panel">
    <h2>Mis consultas</h2>

    <?php if (count($consultas) === 0): ?>
        <div class="tarjeta-paciente">
            <p class="mensaje-info">Aún no tienes consultas registradas.</p>
        </div>
    <?php else: ?>
        <?php foreach ($consultas as $consulta): ?>
            <div class="tarjeta-paciente">
                <h3>
                    <?= htmlspecialchars(date('d/m/Y H:i', strtotime($consulta['fecha_consulta'])), ENT_QUOTES, 'UTF-8') ?>
                    · Dr(a). <?= htmlspecialchars($consulta['medico_nombre'] . ' ' . $consulta['medico_apellido'], ENT_QUOTES, 'UTF-8') ?>
                </h3>
                <p class="campo__ayuda" style="margin-top:-8px;"><?= htmlspecialchars($consulta['nombre_especialidad'], ENT_QUOTES, 'UTF-8') ?></p>

                <?php if (!empty($consulta['motivo'])): ?>
                    <p><strong>Motivo de consulta:</strong> <?= nl2br(htmlspecialchars($consulta['motivo'], ENT_QUOTES, 'UTF-8')) ?></p>
                <?php endif; ?>
                <?php if (!empty($consulta['diagnostico'])): ?>
                    <p><strong>Diagnóstico:</strong> <?= nl2br(htmlspecialchars($consulta['diagnostico'], ENT_QUOTES, 'UTF-8')) ?></p>
                <?php endif; ?>
                <?php if (!empty($consulta['observaciones'])): ?>
                    <p><strong>Observaciones:</strong> <?= nl2br(htmlspecialchars($consulta['observaciones'], ENT_QUOTES, 'UTF-8')) ?></p>
                <?php endif; ?>

                <?php if (!empty($consulta['id_receta'])): ?>
                    <p style="margin-top:14px;">
                        <a class="btn" style="display:inline-block; text-decoration:none; padding:8px 14px; font-size:0.9rem;"
                           href="paciente_receta_pdf.php?id_receta=<?= (int) $consulta['id_receta'] ?>">
                            📄 Descargar receta (PDF)
                        </a>
                    </p>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</section>

<?php require_once __DIR__ . '/parciales/paciente_footer.php'; ?>
