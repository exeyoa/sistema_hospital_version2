<?php
require_once __DIR__ . '/../config/sesion.php';

// Protección de sesión (igual que en admin.php)
verificarSesion(['admin']);

require_once __DIR__ . '/_iconos.php'; // función icono() para el HTML

$csrfToken = generarTokenCSRF();

require_once __DIR__ . '/../config/conexion.php'; // expone $conexion (PDO)

// -----------------------------------------------------------
// Modo: ?id= edita, sin ?id= crea
// -----------------------------------------------------------
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$esEdicion = $id > 0;

$paciente = null;
if ($esEdicion) {
    $stmt = $conexion->prepare("SELECT * FROM pacientes WHERE id_paciente = :id");
    $stmt->execute([':id' => $id]);
    $paciente = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$paciente) {
        header('Location: admin_pacientes.php?error=noEncontrado');
        exit;
    }
}

// -----------------------------------------------------------
// Validación de datos
// -----------------------------------------------------------
function validarCedula($cedula) {
    // Mismo formato que recepcionista.php: 8-123-456 (opcionales prefijo y tercer grupo)
    return preg_match('/^(?:[A-Z]{1,2}-)?\d{1,4}-\d{1,4}(?:-\d{1,4})?$/', $cedula);
}

function validarFecha($fecha) {
    $dt = DateTime::createFromFormat('Y-m-d', $fecha);
    $erroresFecha = DateTime::getLastErrors();
    return $dt && ($erroresFecha === false || ($erroresFecha['warning_count'] + $erroresFecha['error_count'] === 0));
}

$errores = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // 1) Validación CSRF (RNF-08)
    if (!validarTokenCSRF($_POST['csrf_token'] ?? null)) {
        $destino = $esEdicion ? 'editar_paciente.php?id=' . $id : 'editar_paciente.php';
        header("Location: $destino");
        exit;
    }

    $nombre           = trim($_POST['nombre'] ?? '');
    $apellido         = trim($_POST['apellido'] ?? '');
    $cedula           = trim($_POST['cedula'] ?? '');
    $fecha_nacimiento = trim($_POST['fecha_nacimiento'] ?? '');
    $sexo             = $_POST['sexo'] ?? '';
    $telefono         = trim($_POST['telefono'] ?? '');
    $direccion        = trim($_POST['direccion'] ?? '');
    $correo           = trim($_POST['correo'] ?? '');

    // 2) Campos obligatorios
    if ($nombre === '') {
        $errores[] = 'El nombre es obligatorio.';
    }
    if ($apellido === '') {
        $errores[] = 'El apellido es obligatorio.';
    }
    if ($cedula === '') {
        $errores[] = 'La cédula es obligatoria.';
    } elseif (!validarCedula($cedula)) {
        $errores[] = 'La cédula no tiene un formato válido (ejemplo: 8-123-456).';
    }
    if ($fecha_nacimiento === '') {
        $errores[] = 'La fecha de nacimiento es obligatoria.';
    } elseif (!validarFecha($fecha_nacimiento)) {
        $errores[] = 'La fecha de nacimiento no es válida.';
    }
    if ($sexo === '') {
        $errores[] = 'El sexo es obligatorio.';
    } elseif (!in_array($sexo, ['M', 'F', 'Otro'], true)) {
        $errores[] = 'El sexo seleccionado no es válido.';
    }
    if ($correo !== '' && !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        $errores[] = 'El correo no tiene un formato válido.';
    }

    // 3) Cédula duplicada (excluyendo el propio id en modo edición)
    if (!$errores) {
        $sql = 'SELECT COUNT(*) FROM pacientes WHERE cedula = :cedula';
        $parametros = [':cedula' => $cedula];
        if ($esEdicion) {
            $sql .= ' AND id_paciente <> :id';
            $parametros[':id'] = $id;
        }
        $stmt = $conexion->prepare($sql);
        $stmt->execute($parametros);
        if ($stmt->fetchColumn() > 0) {
            $errores[] = 'La cédula ya está registrada con otro paciente.';
        }
    }

    // 4) Inserción o actualización con sentencia preparada
    if (!$errores) {
        $datos = [
            ':nombre'           => $nombre,
            ':apellido'         => $apellido,
            ':cedula'           => $cedula,
            ':fecha_nacimiento' => $fecha_nacimiento,
            ':sexo'             => $sexo,
            ':telefono'         => $telefono !== '' ? $telefono : null,
            ':direccion'        => $direccion !== '' ? $direccion : null,
            ':correo'           => $correo !== '' ? $correo : null,
        ];

        try {
            if ($esEdicion) {
                $stmt = $conexion->prepare(
                    'UPDATE pacientes SET
                        nombre = :nombre,
                        apellido = :apellido,
                        cedula = :cedula,
                        fecha_nacimiento = :fecha_nacimiento,
                        sexo = :sexo,
                        telefono = :telefono,
                        direccion = :direccion,
                        correo = :correo
                     WHERE id_paciente = :id'
                );
                $datos[':id'] = $id;
            } else {
                $stmt = $conexion->prepare(
                    'INSERT INTO pacientes
                        (nombre, apellido, cedula, fecha_nacimiento, sexo, telefono, direccion, correo)
                     VALUES
                        (:nombre, :apellido, :cedula, :fecha_nacimiento, :sexo, :telefono, :direccion, :correo)'
                );
            }

            $stmt->execute($datos);

            header('Location: admin_pacientes.php?' . ($esEdicion ? 'editado=1' : 'creado=1'));
            exit;

        } catch (PDOException $e) {
            // Condición de carrera: llegó otra cédula igual entre la validación y el INSERT/UPDATE
            if ($e->getCode() === '23000') {
                $errores[] = 'La cédula ya está registrada con otro paciente.';
            } else {
                // El detalle real queda en el log del servidor, nunca en pantalla
                error_log('Error al guardar paciente: ' . $e->getMessage());
                $errores[] = 'No se pudo guardar el paciente. Intenta nuevamente.';
            }
        }
    }
}

// -----------------------------------------------------------
// Valores para el formulario: los enviados (si hay error) o los actuales
// -----------------------------------------------------------
$vNombre           = $_POST['nombre'] ?? $paciente['nombre'] ?? '';
$vApellido         = $_POST['apellido'] ?? $paciente['apellido'] ?? '';
$vCedula           = $_POST['cedula'] ?? $paciente['cedula'] ?? '';
$vFechaNacimiento  = $_POST['fecha_nacimiento'] ?? $paciente['fecha_nacimiento'] ?? '';
$vSexo             = $_POST['sexo'] ?? $paciente['sexo'] ?? '';
$vTelefono         = $_POST['telefono'] ?? $paciente['telefono'] ?? '';
$vDireccion        = $_POST['direccion'] ?? $paciente['direccion'] ?? '';
$vCorreo           = $_POST['correo'] ?? $paciente['correo'] ?? '';

$tituloPagina  = $esEdicion ? 'Editar paciente' : 'Nuevo paciente';
$tituloCard    = $esEdicion ? 'Editar datos del paciente' : 'Registrar paciente nuevo';
$nombrePaciente = $esEdicion ? htmlspecialchars($paciente['nombre'] . ' ' . $paciente['apellido']) : '';

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
    <title><?php echo $tituloPagina; ?></title>
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
            <div class="admin-topbar-izquierda">
                <a href="admin_pacientes.php" class="admin-back-btn"><?php echo icono('flecha-izq', 18); ?></a>
                <div>
                    <h1><?php echo $tituloPagina; ?></h1>
                    <p class="admin-topbar-subtitulo"><?php echo $esEdicion ? 'Actualiza los datos del paciente en el sistema' : 'Registra un nuevo paciente en el sistema'; ?></p>
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
                        <div class="form-card-header-icono"><?php echo icono('paciente', 26); ?></div>
                        <div>
                            <h2><?php echo $tituloCard; ?></h2>
                            <p><?php echo $esEdicion ? 'Modifica los datos de ' . $nombrePaciente . '.' : 'Completa los datos del paciente.'; ?></p>
                        </div>
                    </div>

                    <?php if (count($errores) > 0): ?>
                        <div class="alerta alerta-error">
                            <?php foreach ($errores as $error): ?>
                                <div><?php echo htmlspecialchars($error); ?></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="editar_paciente.php<?php echo $esEdicion ? '?id=' . $id : ''; ?>" id="formEditarPaciente">
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
                                <label for="cedula"><?php echo icono('archivo', 15); ?> Cédula</label>
                                <div class="campo-icono">
                                    <?php echo icono('archivo', 16); ?>
                                    <input type="text" id="cedula" name="cedula" placeholder="Ejemplo: 8-123-456" required
                                           value="<?php echo htmlspecialchars($vCedula); ?>">
                                </div>
                                <div class="form-ayuda">Formato: 8-123-456. No puede repetirse entre pacientes.</div>
                            </div>
                            <div class="form-grupo">
                                <label for="fecha_nacimiento"><?php echo icono('calendario', 15); ?> Fecha de nacimiento</label>
                                <div class="campo-icono">
                                    <?php echo icono('calendario', 16); ?>
                                    <input type="date" id="fecha_nacimiento" name="fecha_nacimiento" required
                                           value="<?php echo htmlspecialchars($vFechaNacimiento); ?>">
                                </div>
                            </div>
                        </div>

                        <div class="form-fila">
                            <div class="form-grupo">
                                <label for="sexo"><?php echo icono('usuario', 15); ?> Sexo</label>
                                <div class="campo-icono">
                                    <?php echo icono('usuario', 16); ?>
                                    <select id="sexo" name="sexo" required>
                                        <option value="" disabled <?php echo $vSexo === '' ? 'selected' : ''; ?>>Selecciona una opción</option>
                                        <option value="M" <?php echo $vSexo === 'M' ? 'selected' : ''; ?>>Masculino</option>
                                        <option value="F" <?php echo $vSexo === 'F' ? 'selected' : ''; ?>>Femenino</option>
                                        <option value="Otro" <?php echo $vSexo === 'Otro' ? 'selected' : ''; ?>>Otro</option>
                                    </select>
                                </div>
                            </div>
                            <div class="form-grupo">
                                <label for="telefono"><?php echo icono('campana', 15); ?> Teléfono (opcional)</label>
                                <div class="campo-icono">
                                    <?php echo icono('campana', 16); ?>
                                    <input type="tel" id="telefono" name="telefono" placeholder="Ejemplo: 6600-1111"
                                           value="<?php echo htmlspecialchars($vTelefono); ?>">
                                </div>
                            </div>
                        </div>

                        <div class="form-fila">
                            <div class="form-grupo">
                                <label for="direccion"><?php echo icono('home', 15); ?> Dirección (opcional)</label>
                                <div class="campo-icono">
                                    <?php echo icono('home', 16); ?>
                                    <input type="text" id="direccion" name="direccion" placeholder="Dirección de residencia"
                                           value="<?php echo htmlspecialchars($vDireccion); ?>">
                                </div>
                            </div>
                            <div class="form-grupo">
                                <label for="correo"><?php echo icono('correo', 15); ?> Correo (opcional)</label>
                                <div class="campo-icono">
                                    <?php echo icono('correo', 16); ?>
                                    <input type="email" id="correo" name="correo" placeholder="ejemplo@correo.com"
                                           value="<?php echo htmlspecialchars($vCorreo); ?>">
                                </div>
                            </div>
                        </div>

                        <div class="form-acciones">
                            <a href="admin_pacientes.php" class="btn-secundario">Cancelar</a>
                            <button type="submit" class="btn-primario"><?php echo icono('guardar', 16); ?> <?php echo $esEdicion ? 'Guardar cambios' : 'Registrar paciente'; ?></button>
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

</body>
</html>