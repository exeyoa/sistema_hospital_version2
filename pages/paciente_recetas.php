<?php
/**
 * pages/paciente_recetas.php
 *
 * Listado de recetas del paciente logueado, agrupadas con sus
 * medicamentos. Solo lectura + enlace para descargar cada receta en PDF.
 */

$seccionActiva = 'recetas';

require_once __DIR__ . '/../config/sesion.php';
require_once __DIR__ . '/../config/conexion.php';
require_once __DIR__ . '/parciales/paciente_funciones.php';
verificarSesionPaciente();

$idPaciente = (int) $_SESSION['id_paciente'];

$recetas = obtenerRecetasPaciente($conexion, $idPaciente);

require_once __DIR__ . '/parciales/paciente_header.php';
?>

<section class="seccion-panel">
    <h2>Mis recetas</h2>

    <?php if (count($recetas) === 0): ?>
        <div class="tarjeta-paciente">
            <p class="mensaje-info">Aún no tienes recetas registradas.</p>
        </div>
    <?php else: ?>
        <?php foreach ($recetas as $receta): ?>
            <div class="tarjeta-paciente">
                <h3>
                    Receta del <?= htmlspecialchars(date('d/m/Y', strtotime($receta['fecha_emision'])), ENT_QUOTES, 'UTF-8') ?>
                    · Dr(a). <?= htmlspecialchars($receta['medico_nombre'] . ' ' . $receta['medico_apellido'], ENT_QUOTES, 'UTF-8') ?>
                </h3>
                <p class="campo__ayuda" style="margin-top:-8px;">
                    <?= htmlspecialchars($receta['nombre_especialidad'], ENT_QUOTES, 'UTF-8') ?>
                    · Consulta del <?= htmlspecialchars(date('d/m/Y', strtotime($receta['fecha_consulta'])), ENT_QUOTES, 'UTF-8') ?>
                </p>

                <?php if (!empty($receta['motivo'])): ?>
                    <p><strong>Motivo:</strong> <?= nl2br(htmlspecialchars($receta['motivo'], ENT_QUOTES, 'UTF-8')) ?></p>
                <?php endif; ?>
                <?php if (!empty($receta['diagnostico'])): ?>
                    <p><strong>Diagnóstico:</strong> <?= nl2br(htmlspecialchars($receta['diagnostico'], ENT_QUOTES, 'UTF-8')) ?></p>
                <?php endif; ?>

                <?php if (count($receta['medicamentos']) > 0): ?>
                    <h4 style="margin-top:14px; margin-bottom:6px; color: var(--pac-azul-medio); font-size:0.95rem;">Medicamentos</h4>
                    <ul style="margin:0; padding-left:20px;">
                        <?php foreach ($receta['medicamentos'] as $med): ?>
                            <li>
                                <strong><?= htmlspecialchars($med['nombre_medicamento'], ENT_QUOTES, 'UTF-8') ?></strong>
                                <?php if (!empty($med['presentacion'])): ?>
                                    — <?= htmlspecialchars($med['presentacion'], ENT_QUOTES, 'UTF-8') ?>
                                <?php endif; ?>
                                <br>
                                <span class="campo__ayuda">
                                    Dosis: <?= htmlspecialchars($med['dosis'], ENT_QUOTES, 'UTF-8') ?>
                                    · Frecuencia: <?= htmlspecialchars($med['frecuencia'], ENT_QUOTES, 'UTF-8') ?>
                                    · Duración: <?= htmlspecialchars($med['duracion'], ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <p style="margin-top:14px;">
                    <a class="btn" style="display:inline-block; text-decoration:none; padding:8px 14px; font-size:0.9rem;"
                       href="paciente_receta_pdf.php?id_receta=<?= (int) $receta['id_receta'] ?>">
                        📄 Descargar en PDF
                    </a>
                </p>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</section>

<?php require_once __DIR__ . '/parciales/paciente_footer.php'; ?>
