<?php
require_once __DIR__ . '/../config/sesion.php';
verificarSesion(['medico']);

require_once __DIR__ . '/../config/conexion.php'; // expone $conexion (PDO)

// --- Datos del médico logueado: usuarios + medicos + especialidades ---
$stmtPerfil = $conexion->prepare(
    "SELECT u.nombre, u.apellido, u.correo, u.usuario, u.fecha_creacion,
            m.numero_colegiado, m.id_especialidad, e.nombre_especialidad
     FROM usuarios u
     INNER JOIN medicos m ON m.id_usuario = u.id_usuario
     LEFT JOIN especialidades e ON e.id_especialidad = m.id_especialidad
     WHERE u.id_usuario = :id_usuario
     LIMIT 1"
);
$stmtPerfil->execute([':id_usuario' => $_SESSION['id_usuario']]);
$perfil = $stmtPerfil->fetch(PDO::FETCH_ASSOC);

if (!$perfil) {
    die('Error: no se encontró un registro de médico asociado a este usuario. Contacte al administrador.');
}

$numeroColegiado = $perfil['numero_colegiado'] !== null ? $perfil['numero_colegiado'] : 'No registrado';
$especialidad    = $perfil['nombre_especialidad'] ?? '—';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mi perfil | Sistema de Consultas</title>
    <link rel="stylesheet" href="../css/estilo.css">
    <link rel="stylesheet" href="../css/medico.css">
</head>
<body>

<div class="medico-layout">

    <?php include __DIR__ . '/parciales/topbar.php'; ?>

    <?php $paginaActiva = 'perfil'; include __DIR__ . '/parciales/sidebar.php'; ?>

    <main class="medico-main">

        <div class="medico-main__encabezado">
            <div class="medico-main__titulo">
                <h2>Mi perfil</h2>
                <p>Datos de tu cuenta y tu registro como médico.</p>
            </div>
        </div>

        <section class="panel-tabla" style="grid-column: 1 / -1;">
            <div class="tarjeta-lateral">
                <h3>👤 Información personal</h3>

                <div class="tarjeta-lateral__dato">
                    <div class="tarjeta-lateral__dato-label">Nombre completo</div>
                    <div class="tarjeta-lateral__dato-numero"><?= htmlspecialchars($perfil['nombre'] . ' ' . $perfil['apellido']) ?></div>
                </div>

                <div class="tarjeta-lateral__dato">
                    <div class="tarjeta-lateral__dato-label">Usuario</div>
                    <div class="tarjeta-lateral__dato-numero"><?= htmlspecialchars($perfil['usuario']) ?></div>
                </div>

                <div class="tarjeta-lateral__dato">
                    <div class="tarjeta-lateral__dato-label">Correo</div>
                    <div class="tarjeta-lateral__dato-numero"><?= htmlspecialchars($perfil['correo'] ?? '') ?></div>
                </div>
            </div>

            <div class="tarjeta-lateral">
                <h3>🩺 Registro de médico</h3>

                <div class="tarjeta-lateral__dato">
                    <div class="tarjeta-lateral__dato-label">N° colegiado</div>
                    <div class="tarjeta-lateral__dato-numero"><?= htmlspecialchars($numeroColegiado) ?></div>
                </div>

                <div class="tarjeta-lateral__dato">
                    <div class="tarjeta-lateral__dato-label">Especialidad</div>
                    <div class="tarjeta-lateral__dato-numero"><?= htmlspecialchars($especialidad) ?></div>
                </div>
            </div>
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