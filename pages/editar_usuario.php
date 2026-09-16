<?php
require_once __DIR__ . '/../config/sesion.php';

// Protección de sesión (igual que en admin.php)
verificarSesion(['admin']);

require_once __DIR__ . '/_iconos.php'; // función icono() para el HTML

$csrfToken = generarTokenCSRF();

require_once __DIR__ . '/../config/conexion.php'; // expone $conexion (PDO)
require_once __DIR__ . '/../config/politica_password.php';

// A qué página regresar después de guardar: admin.php (usuarios) o admin_medicos.php
$volver = ($_GET['volver'] ?? '') === 'medicos' ? 'admin_medicos.php' : 'admin.php';
$volverParam = $volver === 'admin_medicos.php' ? '&volver=medicos' : '';

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    header("Location: $volver?error=noEncontrado");
    exit;
}

// Cargamos el usuario que se va a editar (con su rol). Si es médico,
// también traemos su especialidad y número de colegiado para precargarlo.
$stmt = $conexion->prepare(
    "SELECT u.*, r.nombre_rol, m.id_medico, m.id_especialidad, m.numero_colegiado
     FROM usuarios u
     INNER JOIN roles r ON u.id_rol = r.id_rol
     LEFT JOIN medicos m ON m.id_usuario = u.id_usuario
     WHERE u.id_usuario = :id"
);
$stmt->execute([':id' => $id]);
$usuarioActual = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$usuarioActual) {
    header("Location: $volver?error=noEncontrado");
    exit;
}

$roles = $conexion->query("SELECT id_rol, nombre_rol FROM roles ORDER BY nombre_rol")->fetchAll(PDO::FETCH_ASSOC);
$especialidades = $conexion->query("SELECT id_especialidad, nombre_especialidad FROM especialidades ORDER BY nombre_especialidad")->fetchAll(PDO::FETCH_ASSOC);

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // 1) Validación CSRF (RNF-08)
    if (!validarTokenCSRF($_POST['csrf_token'] ?? null)) {
        header("Location: editar_usuario.php?id=$id$volverParam");
        exit;
    }

    $nombre      = trim($_POST['nombre'] ?? '');
    $apellido    = trim($_POST['apellido'] ?? '');
    $correo      = trim($_POST['correo'] ?? '');
    $usuario     = trim($_POST['usuario'] ?? '');
    $password    = $_POST['password'] ?? '';
    $id_rol      = $_POST['id_rol'] ?? '';
    $id_especialidad = $_POST['id_especialidad'] ?? '';
    $numero_colegiado = trim($_POST['numero_colegiado'] ?? '');
    $activo      = isset($_POST['activo']) ? 1 : 0;

    // 2) Validaciones básicas
    if ($nombre === '' || $apellido === '' || $correo === '' || $usuario === '' || $id_rol === '') {
        $error = 'Todos los campos marcados son obligatorios.';
    } elseif (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        $error = 'El correo no tiene un formato válido.';
    } elseif ($password !== '' && ($erroresPolitica = validarPoliticaPassword($password))) {
        $error = 'La contraseña debe ' . implode(', ', $erroresPolitica) . '.';
    } else {
        // 3) Nombre del rol elegido (para validar médico y el cambio de rol)
        $stmtRol = $conexion->prepare("SELECT nombre_rol FROM roles WHERE id_rol = :id_rol");
        $stmtRol->execute([':id_rol' => $id_rol]);
        $nombreRolNuevo = $stmtRol->fetchColumn();

        // 4) Regla de negocio: un usuario con fila en medicoes no puede
        //    pasar a un rol no-médico (evita filas huérfanas). Debe
        //    desactivarse primero o mantener el rol de médico.
        $tieneFilaMedico = $usuarioActual['id_medico'] !== null;
        if ($tieneFilaMedico && $nombreRolNuevo !== 'medico') {
            $error = 'No se puede cambiar el rol de un médico activo; desactive el usuario primero o mantenga el rol de médico.';
        } elseif ($nombreRolNuevo === 'medico' && ($id_especialidad === '' || $numero_colegiado === '')) {
            $error = 'Debes indicar especialidad y número de colegiado para un médico.';
        } else {
            // 5) Verificamos que el correo y el usuario no existan YA con otro id
            $stmt = $conexion->prepare(
                "SELECT COUNT(*) FROM usuarios
                 WHERE (correo = :correo OR usuario = :usuario)
                   AND id_usuario <> :id"
            );
            $stmt->execute([':correo' => $correo, ':usuario' => $usuario, ':id' => $id]);

            if ($stmt->fetchColumn() > 0) {
                $error = 'Ya existe un usuario con ese correo o nombre de usuario.';
            } else {
                // 6) Todo validado -> actualizamos en una transacción
                try {
                    $conexion->beginTransaction();

                    // Contraseña: solo se reescribe si el admin la escribió
                    $sqlUpdate = "UPDATE usuarios SET
                                    nombre = :nombre,
                                    apellido = :apellido,
                                    correo = :correo,
                                    usuario = :usuario,
                                    id_rol = :id_rol,
                                    activo = :activo
                                  WHERE id_usuario = :id";
                    $params = [
                        ':nombre'   => $nombre,
                        ':apellido' => $apellido,
                        ':correo'   => $correo,
                        ':usuario'  => $usuario,
                        ':id_rol'   => $id_rol,
                        ':activo'   => $activo,
                        ':id'       => $id,
                    ];

                    if ($password !== '') {
                        $sqlUpdate = "UPDATE usuarios SET
                                        nombre = :nombre,
                                        apellido = :apellido,
                                        correo = :correo,
                                        usuario = :usuario,
                                        password_hash = :password_hash,
                                        id_rol = :id_rol,
                                        activo = :activo
                                      WHERE id_usuario = :id";
                        $params[':password_hash'] = password_hash($password, PASSWORD_BCRYPT);
                    }

                    $stmt = $conexion->prepare($sqlUpdate);
                    $stmt->execute($params);

                    // Si el rol final es médico, actualizamos (o insertamos) su fila en medicos
                    if ($nombreRolNuevo === 'medico') {
                        if ($tieneFilaMedico) {
                            $stmt = $conexion->prepare(
                                "UPDATE medicos
                                 SET id_especialidad = :id_especialidad,
                                     numero_colegiado = :numero_colegiado
                                 WHERE id_usuario = :id"
                            );
                            $stmt->execute([
                                ':id_especialidad'   => $id_especialidad,
                                ':numero_colegiado'  => $numero_colegiado,
                                ':id'                => $id,
                            ]);
                        } else {
                            $stmt = $conexion->prepare(
                                "INSERT INTO medicos (id_usuario, id_especialidad, numero_colegiado)
                                 VALUES (:id_usuario, :id_especialidad, :numero_colegiado)"
                            );
                            $stmt->execute([
                                ':id_usuario'       => $id,
                                ':id_especialidad'  => $id_especialidad,
                                ':numero_colegiado' => $numero_colegiado,
                            ]);
                        }
                    }

                    $conexion->commit();
                    header("Location: $volver?editado=1");
                    exit;

                } catch (PDOException $e) {
                    $conexion->rollBack();
                    if ($e->getCode() === '23000') {
                        $error = 'Ya existe un usuario con ese correo o nombre de usuario.';
                    } else {
                        error_log('Error al actualizar usuario: ' . $e->getMessage());
                        $error = 'No se pudo actualizar el usuario. Intenta nuevamente o contacta al administrador del sistema.';
                    }
                }
            }
        }
    }
}

// Valores para el formulario: los enviados (si hay error) o los actuales
$vNombre   = $_POST['nombre'] ?? $usuarioActual['nombre'];
$vApellido = $_POST['apellido'] ?? $usuarioActual['apellido'];
$vCorreo   = $_POST['correo'] ?? $usuarioActual['correo'];
$vUsuario  = $_POST['usuario'] ?? $usuarioActual['usuario'];
$vIdRol    = $_POST['id_rol'] ?? $usuarioActual['id_rol'];
$vEsp      = $_POST['id_especialidad'] ?? $usuarioActual['id_especialidad'] ?? '';
$vCole     = $_POST['numero_colegiado'] ?? $usuarioActual['numero_colegiado'] ?? '';
$vActivo   = isset($_POST['activo']) ? true : (bool) $usuarioActual['activo'];

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
    <title>Editar Usuario</title>
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
            <a href="admin.php" class="activo"><?php echo icono('usuarios'); ?> Usuarios <span class="punto-activo"></span></a>
            <a href="admin_medicos.php"><?php echo icono('medico'); ?> Médicos</a>
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
            <div class="admin-topbar-izquierda">
                <a href="<?php echo $volver; ?>" class="admin-back-btn"><?php echo icono('flecha-izq', 18); ?></a>
                <div>
                    <h1>Editar usuario</h1>
                    <p class="admin-topbar-subtitulo">Actualiza los datos de la cuenta en el sistema</p>
                </div>
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
            <div class="form-contenedor">
                <div class="form-card">

                    <div class="form-card-header">
                        <div class="form-card-header-icono"><?php echo icono('editar', 26); ?></div>
                        <div>
                            <h2>Editar datos del usuario</h2>
                            <p>Modifica los datos de <?php echo htmlspecialchars($usuarioActual['nombre'] . ' ' . $usuarioActual['apellido']); ?>.</p>
                        </div>
                    </div>

                    <?php if ($error): ?>
                        <div class="alerta alerta-error"><?php echo htmlspecialchars($error); ?></div>
                    <?php endif; ?>

                    <form method="POST" action="editar_usuario.php?id=<?php echo $id; ?><?php echo $volverParam; ?>" id="formEditarUsuario">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                        <div class="form-fila">
                            <div class="form-grupo">
                                <label for="nombre"><?php echo icono('usuario', 15); ?> Nombre</label>
                                <div class="campo-icono">
                                    <?php echo icono('usuario', 16); ?>
                                    <input type="text" id="nombre" name="nombre" placeholder="Ingresa el nombre" required
                                           value="<?php echo htmlspecialchars($vNombre); ?>">
                                </div>
                            </div>
                            <div class="form-grupo">
                                <label for="apellido"><?php echo icono('usuario', 15); ?> Apellido</label>
                                <div class="campo-icono">
                                    <?php echo icono('usuario', 16); ?>
                                    <input type="text" id="apellido" name="apellido" placeholder="Ingresa el apellido" required
                                           value="<?php echo htmlspecialchars($vApellido); ?>">
                                </div>
                            </div>
                        </div>

                        <div class="form-fila">
                            <div class="form-grupo">
                                <label for="correo"><?php echo icono('correo', 15); ?> Correo electrónico</label>
                                <div class="campo-icono">
                                    <?php echo icono('correo', 16); ?>
                                    <input type="email" id="correo" name="correo" placeholder="ejemplo@correo.com" required
                                           value="<?php echo htmlspecialchars($vCorreo); ?>">
                                </div>
                            </div>
                            <div class="form-grupo">
                                <label for="usuario"><?php echo icono('usuario', 15); ?> Nombre de usuario</label>
                                <div class="campo-icono">
                                    <?php echo icono('usuario', 16); ?>
                                    <input type="text" id="usuario" name="usuario" placeholder="Ingresa el nombre de usuario" required
                                           value="<?php echo htmlspecialchars($vUsuario); ?>">
                                </div>
                            </div>
                        </div>

                        <div class="form-fila">
                            <div class="form-grupo">
                                <label for="password">Contraseña (opcional)</label>
                                <input type="password" id="password" name="password" placeholder="Déjala vacía para no cambiarla">
                                <div class="form-ayuda">Si la dejas vacía, la contraseña actual se mantiene. Mínimo 8 caracteres.</div>
                            </div>
                            <div class="form-grupo">
                                <label for="id_rol"><?php echo icono('escudo', 15); ?> Rol</label>
                                <div class="campo-icono">
                                    <?php echo icono('escudo', 16); ?>
                                    <select id="id_rol" name="id_rol" required onchange="mostrarCamposMedico()">
                                        <option value="">Selecciona un rol</option>
                                        <?php foreach ($roles as $r): ?>
                                            <option value="<?php echo $r['id_rol']; ?>"
                                                data-rol="<?php echo htmlspecialchars($r['nombre_rol']); ?>"
                                                <?php echo ((string) $r['id_rol'] === (string) $vIdRol) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars(ucfirst($r['nombre_rol'])); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Solo se muestra cuando el rol elegido es "medico" -->
                        <div id="camposMedico" style="display:none;">
                            <div class="seccion-titulo">Información adicional</div>
                            <div class="seccion-nota">Estos campos solo aparecen cuando el rol seleccionado es Médico.</div>

                            <div class="form-fila">
                                <div class="form-grupo">
                                    <label for="id_especialidad"><?php echo icono('estrella', 15); ?> Especialidad</label>
                                    <div class="campo-icono">
                                        <?php echo icono('estrella', 16); ?>
                                        <select id="id_especialidad" name="id_especialidad">
                                            <option value="">Selecciona especialidad</option>
                                            <?php foreach ($especialidades as $esp): ?>
                                                <option value="<?php echo $esp['id_especialidad']; ?>"
                                                    <?php echo ((string) $esp['id_especialidad'] === (string) $vEsp) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($esp['nombre_especialidad']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="form-grupo">
                                    <label for="numero_colegiado"><?php echo icono('archivo', 15); ?> Número de colegiado</label>
                                    <div class="campo-icono">
                                        <?php echo icono('archivo', 16); ?>
                                        <input type="text" id="numero_colegiado" name="numero_colegiado" placeholder="Ingresa número de colegiado"
                                               value="<?php echo htmlspecialchars($vCole); ?>">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="seccion-titulo">Estado de la cuenta</div>
                        <div class="bloque-estado-cuenta">
                            <div style="display:flex; align-items:center; gap:10px;">
                                <div class="icono-estado"><?php echo icono('candado', 17); ?></div>
                                <div>
                                    <strong>Estado de la cuenta</strong>
                                    <span>¿Podrá acceder al sistema?</span>
                                </div>
                            </div>
                            <label class="interruptor">
                                <input type="checkbox" name="activo" <?php echo $vActivo ? 'checked' : ''; ?>>
                                <span class="deslizador"></span>
                            </label>
                        </div>

                        <div class="form-acciones">
                            <a href="<?php echo $volver; ?>" class="btn-secundario">Cancelar</a>
                            <button type="submit" class="btn-primario"><?php echo icono('guardar', 16); ?> Guardar cambios</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="admin-footer">
            © <?php echo date('Y'); ?> Hospital San Rafael. Todos los derechos reservados.
        </div>
    </div>
</div>

<script>
function mostrarCamposMedico() {
    const select = document.getElementById('id_rol');
    const opcionElegida = select.options[select.selectedIndex];
    const rolTexto = opcionElegida ? opcionElegida.getAttribute('data-rol') : '';
    document.getElementById('camposMedico').style.display = (rolTexto === 'medico') ? 'block' : 'none';
}
document.addEventListener('DOMContentLoaded', mostrarCamposMedico);
</script>

</body>
</html>