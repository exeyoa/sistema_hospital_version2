<?php
require_once __DIR__ . '/../config/sesion.php';
verificarSesion(['recepcionista']);

// Un mismo token CSRF para TODAS las secciones de esta página
// (#pacientes, #citas y #turnos): generarTokenCSRF() es idempotente
// (solo crea el token si la sesión aún no tiene uno) y
// validarTokenCSRF() NO lo consume ni lo regenera al validar. Por eso
// los tres formularios conviven en la misma carga de página y el token
// sigue siendo válido después de cada POST + redirección (PRG).
$csrfToken = generarTokenCSRF();

require_once __DIR__ . '/../config/conexion.php'; // expone $conexion (PDO)

// ============================================================
// HORARIO DE CITAS — configuración en un solo lugar
// ------------------------------------------------------------
// Actualmente el horario es EL MISMO para todos los médicos y todas
// las especialidades: de 07:00 a 16:30, en intervalos de 30 minutos
// (NO varía por médico ni por especialidad).
// PUNTO DE EXTENSIÓN: si en el futuro cada médico o especialidad
// necesita su propio horario, este es el lugar a tocar: habría que
// leer ese horario (p. ej. desde una tabla de configuración por
// médico) y generar las opciones con él, en vez de usar estas
// constantes. No se implementa ahora a propósito.
// ============================================================
const HORA_INICIO_CITAS = '07:00';
const HORA_FIN_CITAS    = '16:30';
const INTERVALO_MINUTOS = 30;

/**
 * Genera las opciones válidas de hora ('HH:MM') para el <select> de
 * citas, derivadas SIEMPRE de las constantes de horario de arriba.
 */
function generarHorasDisponibles(): array
{
    $horas  = [];
    $inicio = strtotime(HORA_INICIO_CITAS);
    $fin    = strtotime(HORA_FIN_CITAS);
    for ($t = $inicio; $t <= $fin; $t += INTERVALO_MINUTOS * 60) {
        $horas[] = date('H:i', $t);
    }
    return $horas;
}

$horasDisponibles = generarHorasDisponibles();

// "Hoy" se toma SIEMPRE del reloj de la base de datos (CURDATE()), no
// de date() de PHP: en XAMPP el php.ini puede tener otra zona horaria
// que MySQL y los "de hoy" dejarían de coincidir (CURDATE() ya se usa
// en todas las consultas de citas/turnos de esta página y del sistema).
$hoyDb = (string) $conexion->query('SELECT CURDATE()')->fetchColumn();

// -----------------------------------------------------------
// Mensajes por sección (cada sección muestra solo los suyos)
// -----------------------------------------------------------
$errores = [];          // sección #pacientes (registro)
$exito   = '';
$erroresCitas  = [];    // sección #citas
$exitoCitas    = '';
$erroresTurnos = [];    // sección #turnos
$exitoTurnos   = '';

// Patrón POST → Redirect → GET (PRG) en las secciones nuevas: tras
// procesar un POST se redirige a la sección correspondiente y el
// mensaje viaja en sesión una sola vez. Así, refrescar (F5) no reenvía
// el formulario y el token CSRF sigue siendo el mismo de la sesión.
if (!empty($_SESSION['flash_recepcion'])) {
    $flash = $_SESSION['flash_recepcion'];
    unset($_SESSION['flash_recepcion']);
    if ($flash['seccion'] === 'citas') {
        $erroresCitas = $flash['errores'];
        $exitoCitas   = $flash['exito'];
    } elseif ($flash['seccion'] === 'turnos') {
        $erroresTurnos = $flash['errores'];
        $exitoTurnos   = $flash['exito'];
    }
}

/**
 * Guarda un mensaje flash y redirige (PRG) a la sección indicada,
 * conservando el contexto GET (texto buscado / paciente seleccionado)
 * para que la recepcionista no pierda lo que estaba haciendo.
 */
function flashYRedirigir(string $seccion, array $errores, string $exito, array $contextoGet = []): void
{
    $_SESSION['flash_recepcion'] = [
        'seccion' => $seccion,
        'errores' => $errores,
        'exito'   => $exito,
    ];
    $destino = 'recepcionista.php';
    if ($contextoGet !== []) {
        $destino .= '?' . http_build_query($contextoGet);
    }
    header('Location: ' . $destino . '#' . $seccion);
    exit;
}

// -----------------------------------------------------------
// Consultas reutilizables de las secciones #citas y #turnos
// (todas con sentencias preparadas o sin datos del usuario)
// -----------------------------------------------------------

/**
 * Busca pacientes por cédula EXACTA o por nombre/apellido PARCIAL.
 */
function buscarPacientes(PDO $conexion, string $texto): array
{
    // 1) Coincidencia exacta por cédula (columna UNIQUE: 0 o 1 fila)
    $stmt = $conexion->prepare(
        'SELECT id_paciente, nombre, apellido, cedula FROM pacientes WHERE cedula = :texto LIMIT 1'
    );
    $stmt->execute([':texto' => $texto]);
    $exacto = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($exacto) {
        return [$exacto];
    }

    // 2) Coincidencia parcial por nombre/apellido.
    // DECISIÓN CONSCIENTE DEL EQUIPO (no es un descuido): se usa
    // LIKE '%...%' sin índice específico porque a la escala actual del
    // hospital (cientos de pacientes) el costo es despreciable. Si el
    // volumen de pacientes creciera mucho, aquí se evaluaría un índice
    // FULLTEXT sobre (nombre, apellido). No implementarlo ahora.
    $stmt = $conexion->prepare(
        'SELECT id_paciente, nombre, apellido, cedula FROM pacientes
         WHERE nombre LIKE :parcial OR apellido LIKE :parcial
         ORDER BY apellido, nombre
         LIMIT 20'
    );
    $stmt->execute([':parcial' => '%' . $texto . '%']);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Devuelve un paciente por id, o null si no existe. */
function obtenerPaciente(PDO $conexion, int $idPaciente): ?array
{
    $stmt = $conexion->prepare(
        'SELECT id_paciente, nombre, apellido, cedula FROM pacientes WHERE id_paciente = :id LIMIT 1'
    );
    $stmt->execute([':id' => $idPaciente]);
    $paciente = $stmt->fetch(PDO::FETCH_ASSOC);
    return $paciente === false ? null : $paciente;
}

/** Médicos con cuenta de usuario ACTIVA, con su especialidad. */
function listarMedicosActivos(PDO $conexion): array
{
    return $conexion->query(
        'SELECT m.id_medico, u.nombre, u.apellido, e.nombre_especialidad
         FROM medicos m
         INNER JOIN usuarios u ON u.id_usuario = m.id_usuario
         INNER JOIN especialidades e ON e.id_especialidad = m.id_especialidad
         WHERE u.activo = 1
         ORDER BY u.apellido, u.nombre'
    )->fetchAll(PDO::FETCH_ASSOC);
}

/** Citas vivas (pendiente/confirmada) de hoy en adelante. */
function listarCitasPendientes(PDO $conexion): array
{
    return $conexion->query(
        "SELECT c.id_cita, c.fecha_cita, c.hora_cita, c.estado,
                p.nombre AS paciente_nombre, p.apellido AS paciente_apellido, p.cedula,
                u.nombre AS medico_nombre, u.apellido AS medico_apellido,
                e.nombre_especialidad
         FROM citas c
         INNER JOIN pacientes p ON p.id_paciente = c.id_paciente
         INNER JOIN medicos m ON m.id_medico = c.id_medico
         INNER JOIN usuarios u ON u.id_usuario = m.id_usuario
         INNER JOIN especialidades e ON e.id_especialidad = m.id_especialidad
         WHERE c.fecha_cita >= CURDATE() AND c.estado IN ('pendiente', 'confirmada')
         ORDER BY c.fecha_cita, c.hora_cita"
    )->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Citas de HOY de un paciente (pendiente/confirmada). Cada fila incluye
 * cuántos turnos activos (no atendidos) tiene ya esa cita: 0 significa
 * que todavía se puede generar su turno (regla: UN turno por cita).
 */
function listarCitasDeHoyDelPaciente(PDO $conexion, int $idPaciente): array
{
    $stmt = $conexion->prepare(
        "SELECT c.id_cita, c.hora_cita, c.estado,
                u.nombre AS medico_nombre, u.apellido AS medico_apellido,
                e.nombre_especialidad,
                (SELECT COUNT(*) FROM turnos t
                  WHERE t.id_cita = c.id_cita AND t.estado != 'atendido') AS turnos_activos
         FROM citas c
         INNER JOIN medicos m ON m.id_medico = c.id_medico
         INNER JOIN usuarios u ON u.id_usuario = m.id_usuario
         INNER JOIN especialidades e ON e.id_especialidad = m.id_especialidad
         WHERE c.id_paciente = :id_paciente
           AND c.fecha_cita = CURDATE()
           AND c.estado IN ('pendiente', 'confirmada')
         ORDER BY c.hora_cita"
    );
    $stmt->execute([':id_paciente' => $idPaciente]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Contadores de turnos del día por estado (tarjetas de resumen). */
function contarTurnosDeHoy(PDO $conexion): array
{
    $fila = $conexion->query(
        "SELECT COUNT(*) AS total,
                SUM(CASE WHEN estado = 'en_espera'   THEN 1 ELSE 0 END) AS en_espera,
                SUM(CASE WHEN estado = 'en_consulta' THEN 1 ELSE 0 END) AS en_consulta,
                SUM(CASE WHEN estado = 'atendido'    THEN 1 ELSE 0 END) AS atendido
         FROM turnos
         WHERE fecha = CURDATE()"
    )->fetch(PDO::FETCH_ASSOC);

    // Si no hay turnos hoy, los SUM() devuelven NULL: normalizar a 0
    return [
        'total'       => (int) $fila['total'],
        'en_espera'   => (int) ($fila['en_espera'] ?? 0),
        'en_consulta' => (int) ($fila['en_consulta'] ?? 0),
        'atendido'    => (int) ($fila['atendido'] ?? 0),
    ];
}

/** Cola de turnos del día, ordenada por número (numérico, no alfabético). */
function listarTurnosDeHoy(PDO $conexion): array
{
    return $conexion->query(
        'SELECT t.id_turno, t.numero_turno, t.tipo, t.estado,
                p.nombre AS paciente_nombre, p.apellido AS paciente_apellido,
                u.nombre AS medico_nombre, u.apellido AS medico_apellido
         FROM turnos t
         INNER JOIN pacientes p ON p.id_paciente = t.id_paciente
         LEFT JOIN citas c ON c.id_cita = t.id_cita
         LEFT JOIN medicos m ON m.id_medico = c.id_medico
         LEFT JOIN usuarios u ON u.id_usuario = m.id_usuario
         WHERE t.fecha = CURDATE()
         ORDER BY CAST(t.numero_turno AS UNSIGNED)'
    )->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Inserta un turno para HOY calculando el siguiente numero_turno de
 * forma segura ante concurrencia (dos recepcionistas a la vez):
 *
 *  1) Abre una transacción y bloquea las filas de turnos de hoy con
 *     SELECT ... FOR UPDATE: ninguna otra sesión puede leerlas para
 *     calcular el mismo MAX()+1 mientras esta transacción vive.
 *  2) Inserta dentro de la misma transacción y hace COMMIT.
 *  3) Si aun así ocurre una violación de UNIQUE (SQLSTATE 23000 — ver
 *     el índice propuesto en el comentario al final de este archivo),
 *     hace ROLLBACK y REINTENTA una sola vez recalculando el número.
 *     Si el segundo intento también falla, devuelve un mensaje claro
 *     para el usuario (nunca el error crudo de MySQL).
 */
function generarTurno(PDO $conexion, int $idPaciente, ?int $idCita, string $tipo, ?string &$mensajeError): bool
{
    $maxIntentos = 2; // intento normal + un único reintento automático
    for ($intento = 1; $intento <= $maxIntentos; $intento++) {
        try {
            $conexion->beginTransaction();

            // MAX(CAST(...)): numero_turno es VARCHAR pero contiene solo
            // dígitos; el CAST ordena/calcula como número (10 va después
            // de 9, no entre 1 y 2). FOR UPDATE bloquea las filas de hoy.
            $stmt = $conexion->prepare(
                'SELECT MAX(CAST(numero_turno AS UNSIGNED)) FROM turnos WHERE fecha = CURDATE() FOR UPDATE'
            );
            $stmt->execute();
            $siguienteNumero = (int) $stmt->fetchColumn() + 1;

            $stmt = $conexion->prepare(
                "INSERT INTO turnos (id_paciente, id_cita, numero_turno, tipo, estado, fecha)
                 VALUES (:id_paciente, :id_cita, :numero_turno, :tipo, 'en_espera', CURDATE())"
            );
            $stmt->bindValue(':id_paciente', $idPaciente, PDO::PARAM_INT);
            // id_cita es NULL en los turnos espontáneos (sin cita previa)
            $stmt->bindValue(':id_cita', $idCita, $idCita === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $stmt->bindValue(':numero_turno', (string) $siguienteNumero);
            $stmt->bindValue(':tipo', $tipo);
            $stmt->execute();

            $conexion->commit();
            return true;
        } catch (PDOException $e) {
            if ($conexion->inTransaction()) {
                $conexion->rollBack();
            }
            // 23000 = violación de UNIQUE: otro proceso tomó el mismo
            // número de turno en el mismo instante → reintentar UNA vez.
            if ($e->getCode() === '23000' && $intento < $maxIntentos) {
                continue;
            }
            if ($e->getCode() === '23000') {
                $mensajeError = 'No se pudo generar el turno porque el número fue tomado por otro proceso. Intenta nuevamente.';
            } else {
                // Detalle real al log del servidor, nunca en pantalla
                error_log('Error al generar turno: ' . $e->getMessage());
                $mensajeError = 'No se pudo generar el turno. Intenta nuevamente.';
            }
            return false;
        }
    }
    return false;
}

// -----------------------------------------------------------
// Variables del formulario de registro de paciente (existente)
// -----------------------------------------------------------
$nombre = '';
$apellido = '';
$cedula = '';
$fecha_nacimiento = '';
$sexo = '';
$telefono = '';
$direccion = '';
$correo = '';

// ===========================================================
// Procesamiento de formularios (POST)
// ===========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // El formulario de pacientes (existente) no envía 'accion'; los
    // formularios nuevos sí. Así se distinguen sin tocar el de pacientes.
    $accion = $_POST['accion'] ?? 'registrar_paciente';

    // -------------------------------------------------------
    // Acción: agendar una cita nueva
    // -------------------------------------------------------
    if ($accion === 'agendar_cita') {
        verificarSesion(['recepcionista']); // control de acceso por acción, no solo al cargar la página

        $erroresAccion = [];
        $idPaciente = filter_input(INPUT_POST, 'id_paciente', FILTER_VALIDATE_INT);
        $buscarPost = trim($_POST['buscar'] ?? '');
        $contexto   = [];
        if ($buscarPost !== '') {
            $contexto['buscar'] = $buscarPost;
        }
        if ($idPaciente) {
            $contexto['paciente'] = $idPaciente;
        }

        if (!validarTokenCSRF($_POST['csrf_token'] ?? null)) {
            $erroresAccion[] = 'La sesión expiró o el formulario no es válido. Recarga la página e inténtalo de nuevo.';
        } else {
            $idMedico  = filter_input(INPUT_POST, 'id_medico', FILTER_VALIDATE_INT);
            $fechaCita = trim($_POST['fecha_cita'] ?? '');
            $horaCita  = trim($_POST['hora_cita'] ?? '');

            // Campos obligatorios
            if (!$idPaciente) {
                $erroresAccion[] = 'Debes seleccionar un paciente antes de agendar.';
            }
            if (!$idMedico) {
                $erroresAccion[] = 'Debes seleccionar un médico.';
            }
            if ($fechaCita === '') {
                $erroresAccion[] = 'La fecha de la cita es obligatoria.';
            } else {
                $fecha = DateTime::createFromFormat('Y-m-d', $fechaCita);
                $erroresFecha = DateTime::getLastErrors();
                if (!$fecha || ($erroresFecha !== false && ($erroresFecha['warning_count'] + $erroresFecha['error_count'] > 0))) {
                    $erroresAccion[] = 'La fecha de la cita no es válida.';
                } elseif ($fecha->format('Y-m-d') < $hoyDb) {
                    $erroresAccion[] = 'La fecha de la cita no puede ser en el pasado.';
                }
            }
            // La hora debe ser una de las opciones generadas con las
            // constantes de horario (07:00–16:30 cada 30 minutos)
            if ($horaCita === '' || !in_array($horaCita, $horasDisponibles, true)) {
                $erroresAccion[] = 'La hora seleccionada no es válida.';
            }

            // Reverificación en servidor: el paciente debe existir
            // (nunca confiar en el id escondido en el HTML)
            if (!$erroresAccion && obtenerPaciente($conexion, $idPaciente) === null) {
                $erroresAccion[] = 'El paciente seleccionado no existe.';
            }

            // El médico debe existir y tener su cuenta de usuario activa
            if (!$erroresAccion) {
                $stmt = $conexion->prepare(
                    'SELECT COUNT(*) FROM medicos m
                     INNER JOIN usuarios u ON u.id_usuario = m.id_usuario
                     WHERE m.id_medico = :id_medico AND u.activo = 1'
                );
                $stmt->execute([':id_medico' => $idMedico]);
                if ((int) $stmt->fetchColumn() === 0) {
                    $erroresAccion[] = 'El médico seleccionado no es válido.';
                }
            }

            // Duplicado: mismo médico + fecha + hora con una cita viva.
            // Las citas canceladas NO bloquean el horario (se puede
            // reagendar el mismo horario después de cancelar).
            if (!$erroresAccion) {
                $stmt = $conexion->prepare(
                    "SELECT COUNT(*) FROM citas
                     WHERE id_medico = :id_medico AND fecha_cita = :fecha AND hora_cita = :hora
                       AND estado IN ('pendiente', 'confirmada')"
                );
                $stmt->execute([
                    ':id_medico' => $idMedico,
                    ':fecha'     => $fechaCita,
                    ':hora'      => $horaCita,
                ]);
                if ((int) $stmt->fetchColumn() > 0) {
                    $erroresAccion[] = 'Ese médico ya tiene una cita agendada en esa fecha y hora.';
                }
            }

            if (!$erroresAccion) {
                try {
                    $stmt = $conexion->prepare(
                        'INSERT INTO citas (id_paciente, id_medico, fecha_cita, hora_cita)
                         VALUES (:id_paciente, :id_medico, :fecha_cita, :hora_cita)'
                    );
                    $stmt->execute([
                        ':id_paciente' => $idPaciente,
                        ':id_medico'   => $idMedico,
                        ':fecha_cita'  => $fechaCita,
                        ':hora_cita'   => $horaCita,
                    ]);
                    flashYRedirigir('citas', [], 'Cita agendada con éxito.', $contexto);
                } catch (PDOException $e) {
                    error_log('Error al agendar cita: ' . $e->getMessage());
                    $erroresAccion[] = 'No se pudo agendar la cita. Intenta nuevamente.';
                }
            }
        }
        flashYRedirigir('citas', $erroresAccion, '', $contexto);
    }

    // -------------------------------------------------------
    // Acción: cancelar una cita (NUNCA se borra: estado='cancelada')
    // -------------------------------------------------------
    if ($accion === 'cancelar_cita') {
        verificarSesion(['recepcionista']); // control de acceso por acción, no solo al cargar la página

        $erroresAccion = [];

        if (!validarTokenCSRF($_POST['csrf_token'] ?? null)) {
            $erroresAccion[] = 'La sesión expiró o el formulario no es válido. Recarga la página e inténtalo de nuevo.';
        } else {
            $idCita = filter_input(INPUT_POST, 'id_cita', FILTER_VALIDATE_INT);
            if (!$idCita) {
                $erroresAccion[] = 'La cita indicada no es válida.';
            } else {
                $stmt = $conexion->prepare('SELECT estado FROM citas WHERE id_cita = :id_cita LIMIT 1');
                $stmt->execute([':id_cita' => $idCita]);
                $estadoActual = $stmt->fetchColumn();

                if ($estadoActual === false) {
                    $erroresAccion[] = 'La cita indicada no existe.';
                } elseif (!in_array($estadoActual, ['pendiente', 'confirmada'], true)) {
                    $erroresAccion[] = 'Solo se pueden cancelar citas pendientes o confirmadas.';
                } else {
                    // UPDATE, jamás DELETE: la cita queda en el historial
                    // con estado 'cancelada' y su horario queda libre.
                    $stmt = $conexion->prepare(
                        "UPDATE citas SET estado = 'cancelada'
                         WHERE id_cita = :id_cita AND estado IN ('pendiente', 'confirmada')"
                    );
                    $stmt->execute([':id_cita' => $idCita]);
                    flashYRedirigir('citas', [], 'La cita fue cancelada.', []);
                }
            }
        }
        flashYRedirigir('citas', $erroresAccion, '', []);
    }

    // -------------------------------------------------------
    // Acción: generar un turno (UN turno por cita / UN espontáneo al día)
    // -------------------------------------------------------
    if ($accion === 'generar_turno') {
        verificarSesion(['recepcionista']); // control de acceso por acción, no solo al cargar la página

        $erroresAccion = [];
        $idPaciente = filter_input(INPUT_POST, 'id_paciente', FILTER_VALIDATE_INT);
        $buscarTPost = trim($_POST['buscar_t'] ?? '');
        $contexto    = [];
        if ($buscarTPost !== '') {
            $contexto['buscar_t'] = $buscarTPost;
        }
        if ($idPaciente) {
            $contexto['paciente_t'] = $idPaciente;
        }

        if (!validarTokenCSRF($_POST['csrf_token'] ?? null)) {
            $erroresAccion[] = 'La sesión expiró o el formulario no es válido. Recarga la página e inténtalo de nuevo.';
        } else {
            $tipo = $_POST['tipo'] ?? '';
            if (!$idPaciente) {
                $erroresAccion[] = 'Debes seleccionar un paciente antes de generar un turno.';
            }
            if (!in_array($tipo, ['con_cita', 'espontaneo'], true)) {
                $erroresAccion[] = 'El tipo de turno no es válido.';
            }

            // Reverificación en servidor: el paciente debe existir
            if (!$erroresAccion && obtenerPaciente($conexion, $idPaciente) === null) {
                $erroresAccion[] = 'El paciente seleccionado no existe.';
            }

            $idCita = null;
            if (!$erroresAccion && $tipo === 'con_cita') {
                $idCita = filter_input(INPUT_POST, 'id_cita', FILTER_VALIDATE_INT);
                if (!$idCita) {
                    $erroresAccion[] = 'Debes indicar la cita para la que se genera el turno.';
                } else {
                    // La cita debe ser de HOY, de ESTE paciente y estar
                    // pendiente o confirmada
                    $stmt = $conexion->prepare(
                        "SELECT COUNT(*) FROM citas
                         WHERE id_cita = :id_cita AND id_paciente = :id_paciente
                           AND fecha_cita = CURDATE() AND estado IN ('pendiente', 'confirmada')"
                    );
                    $stmt->execute([':id_cita' => $idCita, ':id_paciente' => $idPaciente]);
                    if ((int) $stmt->fetchColumn() === 0) {
                        $erroresAccion[] = 'La cita no es válida para generar turno hoy.';
                    } else {
                        // REGLA DE NEGOCIO: el turno es POR CITA, no por día.
                        // Solo se bloquea si ESTA cita ya tiene un turno
                        // activo (no atendido). Otra cita del mismo
                        // paciente hoy puede generar su propio turno.
                        $stmt = $conexion->prepare(
                            "SELECT COUNT(*) FROM turnos WHERE id_cita = :id_cita AND estado != 'atendido'"
                        );
                        $stmt->execute([':id_cita' => $idCita]);
                        if ((int) $stmt->fetchColumn() > 0) {
                            $erroresAccion[] = 'Esa cita ya tiene un turno generado.';
                        }
                    }
                }
            }

            if (!$erroresAccion && $tipo === 'espontaneo') {
                // REGLA DE NEGOCIO: un paciente sin cita puede sacar como
                // MÁXIMO un turno espontáneo por día (paciente + hoy).
                $stmt = $conexion->prepare(
                    "SELECT COUNT(*) FROM turnos
                     WHERE id_paciente = :id_paciente AND tipo = 'espontaneo' AND fecha = CURDATE()"
                );
                $stmt->execute([':id_paciente' => $idPaciente]);
                if ((int) $stmt->fetchColumn() > 0) {
                    $erroresAccion[] = 'Este paciente ya sacó un turno espontáneo hoy.';
                }
            }

            if (!$erroresAccion) {
                $mensajeError = null;
                if (generarTurno($conexion, $idPaciente, $idCita, $tipo, $mensajeError)) {
                    flashYRedirigir('turnos', [], 'Turno generado con éxito.', $contexto);
                }
                $erroresAccion[] = $mensajeError;
            }
        }
        flashYRedirigir('turnos', $erroresAccion, '', $contexto);
    }

    // -------------------------------------------------------
    // Acción por defecto: registro de paciente nuevo (flujo existente,
    // sin cambios de comportamiento: responde en la misma carga)
    // -------------------------------------------------------
    if ($accion === 'registrar_paciente') {
        verificarSesion(['recepcionista']); // control de acceso por acción, no solo al cargar la página

        // 0) Validación CSRF (RNF-08)
        if (!validarTokenCSRF($_POST['csrf_token'] ?? null)) {
            $errores[] = 'La sesión expiró o el formulario no es válido. Recarga la página e inténtalo de nuevo.';
        } else {
            // 1) Recogemos y limpiamos los datos
            $nombre          = trim($_POST['nombre'] ?? '');
            $apellido        = trim($_POST['apellido'] ?? '');
            $cedula          = trim($_POST['cedula'] ?? '');
            $fecha_nacimiento = trim($_POST['fecha_nacimiento'] ?? '');
            $sexo            = $_POST['sexo'] ?? '';
            $telefono        = trim($_POST['telefono'] ?? '');
            $direccion       = trim($_POST['direccion'] ?? '');
            $correo          = trim($_POST['correo'] ?? '');

            // 2) Campos obligatorios
            if ($nombre === '') {
                $errores[] = 'El nombre es obligatorio.';
            }
            if ($apellido === '') {
                $errores[] = 'El apellido es obligatorio.';
            }
            if ($cedula === '') {
                $errores[] = 'La cédula es obligatoria.';
            } elseif (!preg_match('/^(?:[A-Z]{1,2}-)?\d{1,4}-\d{1,4}(?:-\d{1,4})?$/', $cedula)) {
                $errores[] = 'La cédula no tiene un formato válido (ejemplo: 8-123-456).';
            }
            if ($fecha_nacimiento === '') {
                $errores[] = 'La fecha de nacimiento es obligatoria.';
            } else {
                $fecha = DateTime::createFromFormat('Y-m-d', $fecha_nacimiento);
                $erroresFecha = DateTime::getLastErrors();
                if (!$fecha || ($erroresFecha !== false && ($erroresFecha['warning_count'] + $erroresFecha['error_count'] > 0))) {
                    $errores[] = 'La fecha de nacimiento no es válida.';
                }
            }
            if ($sexo === '') {
                $errores[] = 'El sexo es obligatorio.';
            } elseif (!in_array($sexo, ['M', 'F', 'Otro'], true)) {
                $errores[] = 'El sexo seleccionado no es válido.';
            }
            if ($correo !== '' && !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
                $errores[] = 'El correo no tiene un formato válido.';
            }

            // 3) Cédula duplicada (validación previa)
            if (!$errores) {
                $stmt = $conexion->prepare('SELECT COUNT(*) FROM pacientes WHERE cedula = :cedula');
                $stmt->execute([':cedula' => $cedula]);
                if ($stmt->fetchColumn() > 0) {
                    $errores[] = 'La cédula ya está registrada.';
                }
            }

            // 4) Inserción con sentencia preparada
            if (!$errores) {
                try {
                    $stmt = $conexion->prepare(
                        'INSERT INTO pacientes (nombre, apellido, cedula, fecha_nacimiento, sexo, telefono, direccion, correo)
                         VALUES (:nombre, :apellido, :cedula, :fecha_nacimiento, :sexo, :telefono, :direccion, :correo)'
                    );
                    $stmt->execute([
                        ':nombre'           => $nombre,
                        ':apellido'         => $apellido,
                        ':cedula'           => $cedula,
                        ':fecha_nacimiento' => $fecha_nacimiento,
                        ':sexo'             => $sexo,
                        ':telefono'         => $telefono !== '' ? $telefono : null,
                        ':direccion'        => $direccion !== '' ? $direccion : null,
                        ':correo'           => $correo !== '' ? $correo : null,
                    ]);

                    $exito = 'Paciente registrado con éxito.';

                    // Limpiamos el formulario tras el registro exitoso
                    $nombre = '';
                    $apellido = '';
                    $cedula = '';
                    $fecha_nacimiento = '';
                    $sexo = '';
                    $telefono = '';
                    $direccion = '';
                    $correo = '';
                } catch (PDOException $e) {
                    // Condición de carrera: otro registro llegó con la misma cédula
                    // justo entre la validación previa y este INSERT (SQLSTATE 23000)
                    if ($e->getCode() === '23000') {
                        $errores[] = 'La cédula ya está registrada.';
                    } else {
                        // El detalle real queda en el log del servidor, nunca en pantalla
                        error_log('Error al registrar paciente: ' . $e->getMessage());
                        $errores[] = 'No se pudo registrar el paciente. Intenta nuevamente.';
                    }
                }
            }
        }
    }
}

// -----------------------------------------------------------
// Datos para pintar las secciones #citas y #turnos (GET)
// -----------------------------------------------------------

// Sección #citas: buscador + paciente seleccionado + listados
$buscar             = trim($_GET['buscar'] ?? '');
$resultadosBusqueda = null; // null = todavía no se ha buscado nada
$pacienteSel        = null;
if (isset($_GET['buscar'])) {
    $resultadosBusqueda = $buscar === '' ? [] : buscarPacientes($conexion, $buscar);
}
$idPacienteGet = filter_input(INPUT_GET, 'paciente', FILTER_VALIDATE_INT);
if ($idPacienteGet) {
    $pacienteSel = obtenerPaciente($conexion, $idPacienteGet);
}
$medicosActivos  = listarMedicosActivos($conexion);
$citasPendientes = listarCitasPendientes($conexion);

// Sección #turnos: buscador + paciente seleccionado + cola del día
$buscarT             = trim($_GET['buscar_t'] ?? '');
$resultadosBusquedaT = null;
$pacienteSelT        = null;
$citasPacienteHoy    = [];
if (isset($_GET['buscar_t'])) {
    $resultadosBusquedaT = $buscarT === '' ? [] : buscarPacientes($conexion, $buscarT);
}
$idPacienteTGet = filter_input(INPUT_GET, 'paciente_t', FILTER_VALIDATE_INT);
if ($idPacienteTGet) {
    $pacienteSelT = obtenerPaciente($conexion, $idPacienteTGet);
    if ($pacienteSelT !== null) {
        $citasPacienteHoy = listarCitasDeHoyDelPaciente($conexion, (int) $pacienteSelT['id_paciente']);
    }
}
$contadoresTurnos = contarTurnosDeHoy($conexion);
$turnosHoy        = listarTurnosDeHoy($conexion);

// Etiquetas legibles para los valores ENUM de la base de datos
$etiquetasEstadoCita = [
    'pendiente'  => 'Pendiente',
    'confirmada' => 'Confirmada',
    'cancelada'  => 'Cancelada',
    'atendida'   => 'Atendida',
];
$etiquetasEstadoTurno = [
    'en_espera'   => 'En espera',
    'en_consulta' => 'En consulta',
    'atendido'    => 'Atendido',
];
$etiquetasTipoTurno = [
    'con_cita'   => 'Con cita',
    'espontaneo' => 'Espontáneo',
];

/*
 * ---------------------------------------------------------------
 * PENDIENTE DE APROBACIÓN DEL EQUIPO (NO aplicado a la base de datos):
 * índice UNIQUE para blindar numero_turno por día a nivel de esquema
 * (defensa en depth además de la transacción con FOR UPDATE):
 *
 *   ALTER TABLE turnos
 *       ADD UNIQUE KEY uq_turno_dia (fecha, numero_turno);
 *
 * Sin este índice, el SELECT ... FOR UPDATE + reintento de esta página
 * ya cubre la concurrencia a nivel de aplicación; con el índice, la
 * base de datos también lo garantiza. Aplicarlo solo si el equipo lo
 * aprueba explícitamente.
 * ---------------------------------------------------------------
 */
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Panel recepcionista - Hospital Raúl Dávila Mena</title>
    <link rel="stylesheet" href="../css/estilo.css">
    <link rel="stylesheet" href="../css/recepcionista.css">
</head>
<body>
    <div class="barra-superior">
        <span class="marca">Hospital Raúl Dávila Mena</span>
        <nav class="nav-secciones" aria-label="Secciones del panel">
            <a href="#pacientes">Pacientes</a>
            <a href="#citas">Citas</a>
            <a href="#turnos">Turnos</a>
        </nav>
        <div>
            <span><?= htmlspecialchars($_SESSION['nombre'], ENT_QUOTES, 'UTF-8') ?> · Recepcionista</span>
            &nbsp;·&nbsp;<a href="logout.php">Cerrar sesión</a>
        </div>
    </div>

    <div class="contenido-panel contenido-panel--ancho">

        <!-- ===================================================
             Sección: registro de paciente nuevo (existente)
             =================================================== -->
        <section id="pacientes" class="seccion-panel">
            <h2>Registro de paciente nuevo</h2>

            <?php if ($exito !== ''): ?>
                <div class="mensaje-exito"><?= htmlspecialchars($exito, ENT_QUOTES, 'UTF-8') ?></div>
            <?php elseif (count($errores) > 0): ?>
                <div class="mensaje-error">
                    <ul>
                        <?php foreach ($errores as $error): ?>
                            <li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="POST" action="recepcionista.php" id="formulario-paciente">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

                <div class="campo">
                    <label for="nombre">Nombre</label>
                    <input type="text" id="nombre" name="nombre" value="<?= htmlspecialchars($nombre, ENT_QUOTES, 'UTF-8') ?>" autocomplete="given-name">
                </div>

                <div class="campo">
                    <label for="apellido">Apellido</label>
                    <input type="text" id="apellido" name="apellido" value="<?= htmlspecialchars($apellido, ENT_QUOTES, 'UTF-8') ?>" autocomplete="family-name">
                </div>

                <div class="campo">
                    <label for="cedula">Cédula</label>
                    <input type="text" id="cedula" name="cedula" value="<?= htmlspecialchars($cedula, ENT_QUOTES, 'UTF-8') ?>" required>
                </div>

                <div class="campo">
                    <label for="fecha_nacimiento">Fecha de nacimiento</label>
                    <input type="date" id="fecha_nacimiento" name="fecha_nacimiento" value="<?= htmlspecialchars($fecha_nacimiento, ENT_QUOTES, 'UTF-8') ?>">
                </div>

                <div class="campo">
                    <label for="sexo">Sexo</label>
                    <select id="sexo" name="sexo">
                        <option value="" disabled <?= $sexo === '' ? 'selected' : '' ?>>Seleccione…</option>
                        <option value="M" <?= $sexo === 'M' ? 'selected' : '' ?>>Masculino</option>
                        <option value="F" <?= $sexo === 'F' ? 'selected' : '' ?>>Femenino</option>
                        <option value="Otro" <?= $sexo === 'Otro' ? 'selected' : '' ?>>Otro</option>
                    </select>
                </div>

                <div class="campo">
                    <label for="telefono">Teléfono</label>
                    <input type="tel" id="telefono" name="telefono" value="<?= htmlspecialchars($telefono, ENT_QUOTES, 'UTF-8') ?>" autocomplete="tel">
                </div>

                <div class="campo">
                    <label for="direccion">Dirección</label>
                    <input type="text" id="direccion" name="direccion" value="<?= htmlspecialchars($direccion, ENT_QUOTES, 'UTF-8') ?>" autocomplete="street-address">
                </div>

                <div class="campo">
                    <label for="correo">Correo</label>
                    <input type="email" id="correo" name="correo" value="<?= htmlspecialchars($correo, ENT_QUOTES, 'UTF-8') ?>" autocomplete="email">
                </div>

                <button type="submit" class="btn" id="btn-registrar-paciente">Registrar paciente</button>
            </form>
        </section>

        <!-- ===================================================
             Sección: agendar y cancelar citas
             =================================================== -->
        <section id="citas" class="seccion-panel">
            <h2>Citas</h2>

            <?php if ($exitoCitas !== ''): ?>
                <div class="mensaje-exito"><?= htmlspecialchars($exitoCitas, ENT_QUOTES, 'UTF-8') ?></div>
            <?php elseif (count($erroresCitas) > 0): ?>
                <div class="mensaje-error">
                    <ul>
                        <?php foreach ($erroresCitas as $error): ?>
                            <li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <h3>1. Buscar paciente</h3>
            <form method="GET" action="recepcionista.php#citas" class="form-buscar" role="search">
                <div class="campo">
                    <label for="buscar">Cédula exacta o nombre/apellido</label>
                    <input type="text" id="buscar" name="buscar" value="<?= htmlspecialchars($buscar, ENT_QUOTES, 'UTF-8') ?>" placeholder="Ej.: 8-123-4560 o María">
                </div>
                <button type="submit" class="btn">Buscar</button>
            </form>

            <?php if ($resultadosBusqueda !== null): ?>
                <?php if (count($resultadosBusqueda) === 0): ?>
                    <p class="mensaje-info">No se encontraron pacientes con ese criterio.</p>
                <?php else: ?>
                    <ul class="lista-resultados">
                        <?php foreach ($resultadosBusqueda as $resultado): ?>
                            <li>
                                <span>
                                    <?= htmlspecialchars($resultado['apellido'] . ', ' . $resultado['nombre'], ENT_QUOTES, 'UTF-8') ?>
                                    — C.I. <?= htmlspecialchars($resultado['cedula'], ENT_QUOTES, 'UTF-8') ?>
                                </span>
                                <?php if ($pacienteSel !== null && (int) $pacienteSel['id_paciente'] === (int) $resultado['id_paciente']): ?>
                                    <strong class="texto-seleccionado">Seleccionado</strong>
                                <?php else: ?>
                                    <a class="btn btn-pequeno" href="recepcionista.php?<?= htmlspecialchars(http_build_query(['buscar' => $buscar, 'paciente' => $resultado['id_paciente']]), ENT_QUOTES, 'UTF-8') ?>#citas">Seleccionar</a>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($pacienteSel !== null): ?>
                <div class="tarjeta-formulario">
                    <h3>2. Agendar cita para: <?= htmlspecialchars($pacienteSel['nombre'] . ' ' . $pacienteSel['apellido'], ENT_QUOTES, 'UTF-8') ?> (C.I. <?= htmlspecialchars($pacienteSel['cedula'], ENT_QUOTES, 'UTF-8') ?>)</h3>
                    <form method="POST" action="recepcionista.php#citas" id="formulario-cita" class="js-bloquear-envio">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="accion" value="agendar_cita">
                        <input type="hidden" name="id_paciente" value="<?= (int) $pacienteSel['id_paciente'] ?>">
                        <input type="hidden" name="buscar" value="<?= htmlspecialchars($buscar, ENT_QUOTES, 'UTF-8') ?>">

                        <div class="campo">
                            <label for="id_medico">Médico</label>
                            <select id="id_medico" name="id_medico" required>
                                <option value="" disabled selected>Seleccione…</option>
                                <?php foreach ($medicosActivos as $medico): ?>
                                    <option value="<?= (int) $medico['id_medico'] ?>">
                                        <?= htmlspecialchars($medico['apellido'] . ', ' . $medico['nombre'] . ' — ' . $medico['nombre_especialidad'], ENT_QUOTES, 'UTF-8') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="campo">
                            <label for="fecha_cita">Fecha de la cita</label>
                            <input type="date" id="fecha_cita" name="fecha_cita" min="<?= htmlspecialchars($hoyDb, ENT_QUOTES, 'UTF-8') ?>" required>
                        </div>

                        <div class="campo">
                            <label for="hora_cita">Hora de la cita</label>
                            <select id="hora_cita" name="hora_cita" required disabled
                                    aria-describedby="estado-hora-cita"
                                    data-hours='<?= json_encode(
                                        $horasDisponibles,
                                        JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
                                    ) ?>'>
                                <option value="" selected>Seleccione médico y fecha primero</option>
                            </select>
                            <span id="estado-hora-cita" class="sr-only" aria-live="polite"></span>
                        </div>

                        <button type="submit" class="btn" id="btn-agendar-cita">Agendar cita</button>
                    </form>
                </div>
            <?php endif; ?>

            <h3>Citas de hoy y próximas</h3>
            <?php if (count($citasPendientes) === 0): ?>
                <p class="mensaje-info">No hay citas pendientes ni confirmadas.</p>
            <?php else: ?>
                <div class="panel-tabla">
                    <table class="tabla-recepcion">
                        <thead>
                            <tr>
                                <th scope="col">Fecha</th>
                                <th scope="col">Hora</th>
                                <th scope="col">Paciente</th>
                                <th scope="col">Cédula</th>
                                <th scope="col">Médico</th>
                                <th scope="col">Especialidad</th>
                                <th scope="col">Estado</th>
                                <th scope="col">Acción</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($citasPendientes as $cita): ?>
                                <tr>
                                    <td data-label="Fecha"><?= htmlspecialchars(date('d/m/Y', strtotime($cita['fecha_cita'])), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td data-label="Hora"><?= htmlspecialchars(substr($cita['hora_cita'], 0, 5), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td data-label="Paciente"><?= htmlspecialchars($cita['paciente_nombre'] . ' ' . $cita['paciente_apellido'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td data-label="Cédula"><?= htmlspecialchars($cita['cedula'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td data-label="Médico"><?= htmlspecialchars($cita['medico_nombre'] . ' ' . $cita['medico_apellido'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td data-label="Especialidad"><?= htmlspecialchars($cita['nombre_especialidad'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td data-label="Estado">
                                        <span class="badge badge-cita-<?= htmlspecialchars($cita['estado'], ENT_QUOTES, 'UTF-8') ?>">
                                            <?= htmlspecialchars($etiquetasEstadoCita[$cita['estado']] ?? $cita['estado'], ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    </td>
                                    <td data-label="Acción">
                                        <form method="POST" action="recepcionista.php#citas" class="form-accion-inline js-bloquear-envio" data-confirmar="¿Seguro que deseas cancelar esta cita?">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="accion" value="cancelar_cita">
                                            <input type="hidden" name="id_cita" value="<?= (int) $cita['id_cita'] ?>">
                                            <button type="submit" class="btn-accion-cancelar">Cancelar</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <!-- ===================================================
             Sección: turnos del día
             =================================================== -->
        <section id="turnos" class="seccion-panel">
            <h2>Turnos del día</h2>

            <?php if ($exitoTurnos !== ''): ?>
                <div class="mensaje-exito"><?= htmlspecialchars($exitoTurnos, ENT_QUOTES, 'UTF-8') ?></div>
            <?php elseif (count($erroresTurnos) > 0): ?>
                <div class="mensaje-error">
                    <ul>
                        <?php foreach ($erroresTurnos as $error): ?>
                            <li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <h3>1. Buscar paciente</h3>
            <form method="GET" action="recepcionista.php#turnos" class="form-buscar" role="search">
                <div class="campo">
                    <label for="buscar_t">Cédula exacta o nombre/apellido</label>
                    <input type="text" id="buscar_t" name="buscar_t" value="<?= htmlspecialchars($buscarT, ENT_QUOTES, 'UTF-8') ?>" placeholder="Ej.: 8-123-4560 o María">
                </div>
                <button type="submit" class="btn">Buscar</button>
            </form>

            <?php if ($resultadosBusquedaT !== null): ?>
                <?php if (count($resultadosBusquedaT) === 0): ?>
                    <p class="mensaje-info">No se encontraron pacientes con ese criterio.</p>
                <?php else: ?>
                    <ul class="lista-resultados">
                        <?php foreach ($resultadosBusquedaT as $resultado): ?>
                            <li>
                                <span>
                                    <?= htmlspecialchars($resultado['apellido'] . ', ' . $resultado['nombre'], ENT_QUOTES, 'UTF-8') ?>
                                    — C.I. <?= htmlspecialchars($resultado['cedula'], ENT_QUOTES, 'UTF-8') ?>
                                </span>
                                <?php if ($pacienteSelT !== null && (int) $pacienteSelT['id_paciente'] === (int) $resultado['id_paciente']): ?>
                                    <strong class="texto-seleccionado">Seleccionado</strong>
                                <?php else: ?>
                                    <a class="btn btn-pequeno" href="recepcionista.php?<?= htmlspecialchars(http_build_query(['buscar_t' => $buscarT, 'paciente_t' => $resultado['id_paciente']]), ENT_QUOTES, 'UTF-8') ?>#turnos">Seleccionar</a>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($pacienteSelT !== null): ?>
                <div class="tarjeta-formulario">
                    <h3>2. Generar turno para: <?= htmlspecialchars($pacienteSelT['nombre'] . ' ' . $pacienteSelT['apellido'], ENT_QUOTES, 'UTF-8') ?> (C.I. <?= htmlspecialchars($pacienteSelT['cedula'], ENT_QUOTES, 'UTF-8') ?>)</h3>

                    <h4>Citas de hoy del paciente</h4>
                    <?php if (count($citasPacienteHoy) === 0): ?>
                        <p class="mensaje-info">El paciente no tiene citas para hoy. Si llegó sin cita, genera un turno espontáneo.</p>
                    <?php else: ?>
                        <ul class="lista-citas-hoy">
                            <?php foreach ($citasPacienteHoy as $citaHoy): ?>
                                <li>
                                    <span>
                                        <?= htmlspecialchars(substr($citaHoy['hora_cita'], 0, 5), ENT_QUOTES, 'UTF-8') ?>
                                        — <?= htmlspecialchars($citaHoy['medico_nombre'] . ' ' . $citaHoy['medico_apellido'], ENT_QUOTES, 'UTF-8') ?>
                                        (<?= htmlspecialchars($citaHoy['nombre_especialidad'], ENT_QUOTES, 'UTF-8') ?>)
                                        <span class="badge badge-cita-<?= htmlspecialchars($citaHoy['estado'], ENT_QUOTES, 'UTF-8') ?>">
                                            <?= htmlspecialchars($etiquetasEstadoCita[$citaHoy['estado']] ?? $citaHoy['estado'], ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    </span>
                                    <?php if ((int) $citaHoy['turnos_activos'] > 0): ?>
                                        <strong class="texto-seleccionado">Turno ya generado</strong>
                                    <?php else: ?>
                                        <form method="POST" action="recepcionista.php#turnos" class="form-accion-inline js-bloquear-envio">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="accion" value="generar_turno">
                                            <input type="hidden" name="tipo" value="con_cita">
                                            <input type="hidden" name="id_cita" value="<?= (int) $citaHoy['id_cita'] ?>">
                                            <input type="hidden" name="id_paciente" value="<?= (int) $pacienteSelT['id_paciente'] ?>">
                                            <input type="hidden" name="buscar_t" value="<?= htmlspecialchars($buscarT, ENT_QUOTES, 'UTF-8') ?>">
                                            <button type="submit" class="btn btn-pequeno">Generar turno</button>
                                        </form>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

                    <h4>Sin cita previa</h4>
                    <form method="POST" action="recepcionista.php#turnos" class="js-bloquear-envio">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="accion" value="generar_turno">
                        <input type="hidden" name="tipo" value="espontaneo">
                        <input type="hidden" name="id_paciente" value="<?= (int) $pacienteSelT['id_paciente'] ?>">
                        <input type="hidden" name="buscar_t" value="<?= htmlspecialchars($buscarT, ENT_QUOTES, 'UTF-8') ?>">
                        <button type="submit" class="btn" id="btn-turno-espontaneo">Generar turno espontáneo</button>
                    </form>
                </div>
            <?php endif; ?>

            <h3>Cola de hoy</h3>
            <div class="contadores-turnos">
                <div class="contador">
                    <span class="contador__numero"><?= $contadoresTurnos['total'] ?></span>
                    <span class="contador__etiqueta">Total hoy</span>
                </div>
                <div class="contador">
                    <span class="contador__numero"><?= $contadoresTurnos['en_espera'] ?></span>
                    <span class="contador__etiqueta">En espera</span>
                </div>
                <div class="contador">
                    <span class="contador__numero"><?= $contadoresTurnos['en_consulta'] ?></span>
                    <span class="contador__etiqueta">En consulta</span>
                </div>
                <div class="contador">
                    <span class="contador__numero"><?= $contadoresTurnos['atendido'] ?></span>
                    <span class="contador__etiqueta">Atendidos</span>
                </div>
            </div>

            <?php if (count($turnosHoy) === 0): ?>
                <p class="mensaje-info">Todavía no hay turnos generados hoy.</p>
            <?php else: ?>
                <div class="panel-tabla">
                    <table class="tabla-recepcion">
                        <thead>
                            <tr>
                                <th scope="col">N.°</th>
                                <th scope="col">Paciente</th>
                                <th scope="col">Tipo</th>
                                <th scope="col">Médico</th>
                                <th scope="col">Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($turnosHoy as $turno): ?>
                                <tr>
                                    <td data-label="N.°"><span class="numero-turno"><?= htmlspecialchars($turno['numero_turno'], ENT_QUOTES, 'UTF-8') ?></span></td>
                                    <td data-label="Paciente"><?= htmlspecialchars($turno['paciente_nombre'] . ' ' . $turno['paciente_apellido'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td data-label="Tipo">
                                        <span class="badge badge-tipo-<?= htmlspecialchars($turno['tipo'], ENT_QUOTES, 'UTF-8') ?>">
                                            <?= htmlspecialchars($etiquetasTipoTurno[$turno['tipo']] ?? $turno['tipo'], ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    </td>
                                    <td data-label="Médico">
                                        <?= $turno['medico_nombre'] !== null
                                            ? htmlspecialchars($turno['medico_nombre'] . ' ' . $turno['medico_apellido'], ENT_QUOTES, 'UTF-8')
                                            : '—' ?>
                                    </td>
                                    <td data-label="Estado">
                                        <span class="badge badge-estado-<?= htmlspecialchars($turno['estado'], ENT_QUOTES, 'UTF-8') ?>">
                                            <?= htmlspecialchars($etiquetasEstadoTurno[$turno['estado']] ?? $turno['estado'], ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <script>
    // Evita el doble envío: un segundo clic sobre el botón no debe crear
    // dos pacientes. Deshabilitamos el botón en cuanto se dispara el submit.
    document.getElementById('formulario-paciente').addEventListener('submit', function () {
        var boton = document.getElementById('btn-registrar-paciente');
        boton.disabled = true;
        boton.textContent = 'Registrando…';
    });

    // Mismo anti-doble-envío para los formularios de citas y turnos.
    // Los formularios con data-confirmar primero piden confirmación; si
    // el usuario cancela, el botón NO se deshabilita.
    document.querySelectorAll('form.js-bloquear-envio').forEach(function (formulario) {
        formulario.addEventListener('submit', function (evento) {
            var pregunta = formulario.getAttribute('data-confirmar');
            if (pregunta && !window.confirm(pregunta)) {
                evento.preventDefault();
                return;
            }
            var boton = formulario.querySelector('button[type="submit"]');
            if (boton) {
                boton.disabled = true;
                boton.textContent = 'Procesando…';
            }
        });
    });

    // -------------------------------------------------------------
    // Disponibilidad de horas al agendar cita
    // -------------------------------------------------------------
    // Consulta pages/ajax/consultar_horarios_ocupados.php cada vez que
    // cambian el médico o la fecha. Las horas devueltas como ocupadas
    // se marcan como deshabilitadas en el <select>. Esta es solo una
    // capa de UX: la validación real al INSERT sigue siendo la
    // autoridad final (recepcionista.php, acción agendar_cita).
    //
    // Protección contra respuestas fuera de orden: si la recepcionista
    // cambia de médico dos veces rápido, podría llegar primero la
    // respuesta del segundo y luego la del primero. La variable
    // secuenciaActual descarta las respuestas obsoletas.
    (function () {
        var selectMedico = document.getElementById('id_medico');
        var selectFecha  = document.getElementById('fecha_cita');
        var selectHora   = document.getElementById('hora_cita');
        var estadoHora   = document.getElementById('estado-hora-cita');
        if (!selectMedico || !selectFecha || !selectHora) { return; }

        var horasBase = [];
        try {
            horasBase = JSON.parse(selectHora.getAttribute('data-hours') || '[]');
        } catch (e) {
            horasBase = [];
        }

        var secuenciaActual = 0;

        function placeholder(texto, deshabilitar) {
            selectHora.innerHTML = '';
            var opt = document.createElement('option');
            opt.value = '';
            opt.textContent = texto;
            opt.selected = true;
            selectHora.appendChild(opt);
            selectHora.disabled = !!deshabilitar;
            if (estadoHora) { estadoHora.textContent = texto; }
        }

        function reconstruir(ocupadas) {
            selectHora.innerHTML = '';
            var ph = document.createElement('option');
            ph.value = '';
            ph.disabled = true;
            ph.selected = true;
            ph.textContent = 'Seleccione…';
            selectHora.appendChild(ph);

            var libres = 0;
            horasBase.forEach(function (h) {
                var opt = document.createElement('option');
                opt.value = h;
                if (ocupadas.indexOf(h) !== -1) {
                    opt.disabled = true;
                    opt.textContent = h + ' — Ocupado';
                } else {
                    opt.textContent = h;
                    libres++;
                }
                selectHora.appendChild(opt);
            });

            selectHora.disabled = false;

            if (horasBase.length === 0) {
                if (estadoHora) { estadoHora.textContent = ''; }
            } else if (libres === 0) {
                if (estadoHora) {
                    estadoHora.textContent =
                        'Este médico no tiene horarios disponibles ese día. Elija otra fecha.';
                }
            } else {
                if (estadoHora) {
                    estadoHora.textContent = ocupadas.length === 0
                        ? 'Todas las horas están disponibles.'
                        : (ocupadas.length + ' horas ocupadas. No puede elegirlas.');
                }
            }
        }

        function consultar() {
            var idMedico = selectMedico.value;
            var fecha    = selectFecha.value;
            if (!idMedico || !fecha) {
                placeholder('Seleccione médico y fecha primero', true);
                return;
            }
            placeholder('Verificando disponibilidad…', true);

            var miSecuencia = ++secuenciaActual;

            fetch('ajax/consultar_horarios_ocupados.php'
                    + '?id_medico=' + encodeURIComponent(idMedico)
                    + '&fecha=' + encodeURIComponent(fecha),
                  { credentials: 'same-origin' })
                .then(function (respuesta) { return respuesta.json(); })
                .then(function (datos) {
                    if (miSecuencia !== secuenciaActual) { return; }
                    if (datos && Array.isArray(datos.ocupadas)) {
                        reconstruir(datos.ocupadas);
                    } else {
                        throw new Error('Respuesta inesperada');
                    }
                })
                .catch(function () {
                    if (miSecuencia !== secuenciaActual) { return; }
                    // No bloquear el formulario: la validación server-side
                    // al INSERT sigue siendo la autoridad final.
                    reconstruir([]);
                    if (estadoHora) {
                        estadoHora.textContent =
                            'No se pudo verificar disponibilidad; se validará al guardar.';
                    }
                });
        }

        selectMedico.addEventListener('change', consultar);
        selectFecha.addEventListener('change', consultar);
        placeholder('Seleccione médico y fecha primero', true);
    })();
    </script>
</body>
</html>
