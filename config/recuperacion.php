<?php
/**
 * config/recuperacion.php
 * -----------------------------------------------------------------
 * Generación, envío y validación de códigos de recuperación de
 * contraseña (6 dígitos, expiran en 5 minutos).
 * Reutiliza el patrón del proyecto: sentencias preparadas PDO y
 * hash_equals() para comparar sin ataques de timing.
 * -----------------------------------------------------------------
 */

require_once __DIR__ . '/correo.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Retención antes de limpiar: los códigos solo se eliminan si tienen más de
// este tiempo de antigüedad, para no afectar flujos de recuperación en curso.
const RECUPERACION_RETENCION_DIAS = 1;

/**
 * Limpieza automática de códigos viejos (usados o expirados) con más de
 * RECUPERACION_RETENCION_DIAS de antigüedad, para que la tabla
 * codigos_recuperacion no crezca indefinidamente. Retorna cuántos eliminó.
 */
function limpiarCodigosRecuperacion(PDO $conexion): int
{
    $sql = "DELETE FROM codigos_recuperacion
            WHERE (usado = 1 OR fecha_expiracion < NOW())
              AND fecha_creacion < (NOW() - INTERVAL :retencion_dias DAY)";

    $stmt = $conexion->prepare($sql);
    $stmt->bindValue(':retencion_dias', RECUPERACION_RETENCION_DIAS, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->rowCount();
}

/**
 * Genera un código aleatorio de 6 dígitos, lo guarda en
 * codigos_recuperacion con expiración de 5 minutos y lo retorna.
 *
 * Polimórfica: el código puede ser para un `usuario` (admin / medico /
 * recepcionista) o para un `paciente`. La tabla `codigos_recuperacion`
 * tiene `id_usuario` y `id_paciente` como columnas NULLables; la
 * aplicación se encarga de que EXACTAMENTE uno de los dos esté
 * establecido.
 *
 * @param string $tipo 'usuario' o 'paciente'
 */
function generarCodigoRecuperacion(PDO $conexion, int $idEntidad, string $tipo = 'usuario'): string
{
    if (!in_array($tipo, ['usuario', 'paciente'], true)) {
        throw new InvalidArgumentException("tipo debe ser 'usuario' o 'paciente'");
    }
    $columnaId = $tipo === 'usuario' ? 'id_usuario' : 'id_paciente';

    // Limpieza ligera y automática (sin cron): con probabilidad 1/20 se borran
    // códigos viejos (usados o expirados, con +24 h). Nunca afecta flujos activos.
    if (random_int(1, 20) === 1) {
        limpiarCodigosRecuperacion($conexion);
    }

    $codigo   = '';
    $intentos = 0;

    // El código no debe repetirse jamás (ni para el mismo usuario). Como los
    // códigos nunca se eliminan físicamente, se valida contra toda la tabla
    // antes de aceptar uno; si ya existió, se regenera con otro número.
    do {
        $codigo = (string) random_int(100000, 999999);

        $stmt = $conexion->prepare("SELECT COUNT(*) FROM codigos_recuperacion WHERE codigo = :codigo");
        $stmt->execute([':codigo' => $codigo]);
        $yaEmitido = (int) $stmt->fetchColumn() > 0;

        $intentos++;
    } while ($yaEmitido && $intentos < 10);

    // Inserta con el id en la columna correspondiente y NULL en la otra.
    $columnaOtra = $tipo === 'usuario' ? 'id_paciente' : 'id_usuario';
    $sql = "INSERT INTO codigos_recuperacion ($columnaId, $columnaOtra, codigo, fecha_creacion, fecha_expiracion)
            VALUES (:id_entidad, NULL, :codigo, NOW(), (NOW() + INTERVAL 5 MINUTE))";

    $stmt = $conexion->prepare($sql);
    $stmt->execute([
        ':id_entidad' => $idEntidad,
        ':codigo'     => $codigo,
    ]);

    return $codigo;
}

/**
 * Envía el código por correo usando PHPMailer + los datos de config/correo.php.
 * Devuelve true si se envió y false si falló, sin mostrar detalles técnicos.
 */
function enviarCorreoCodigo(string $correoDestino, string $nombre, string $codigo): bool
{
    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = CORREO_SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = CORREO_REMITENTE;
        $mail->Password   = CORREO_APP_PASSWORD;
        $mail->Port       = CORREO_SMTP_PUERTO;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;

        $mail->setFrom(CORREO_REMITENTE, 'Hospital Raúl Dávila Mena');
        $mail->addAddress($correoDestino, $nombre);
        $mail->CharSet = 'UTF-8';

        $mail->Subject = 'Código de recuperación - Hospital Raúl Dávila Mena';
        $mail->Body    = 'Hola ' . $nombre . ",\n\n"
            . 'Tu código de recuperación es: ' . $codigo . "\n"
            . 'Este código expira en 5 minutos.' . "\n\n"
            . 'Si no solicitaste este cambio, ignora este correo.';

        return $mail->send();
    } catch (Exception $e) {
        // No mostramos detalles técnicos al usuario final (RNF-08);
        // el detalle queda en el log del servidor.
        error_log('Error enviando correo de recuperación: ' . $e->getMessage());
        return false;
    }
}

/**
 * Verifica que el código sea correcto, no esté usado y no haya expirado,
 * SIN marcarlo como usado. Útil para el sub-paso "verificar código",
 * donde la contraseña todavía no se ha cambiado.
 *
 * Polimórfica: ver generarCodigoRecuperacion() para $tipo.
 */
function verificarCodigoRecuperacion(PDO $conexion, int $idEntidad, string $codigoIngresado, string $tipo = 'usuario'): bool
{
    if (!in_array($tipo, ['usuario', 'paciente'], true)) {
        throw new InvalidArgumentException("tipo debe ser 'usuario' o 'paciente'");
    }
    $columnaId = $tipo === 'usuario' ? 'id_usuario' : 'id_paciente';

    $sql = "SELECT codigo
            FROM codigos_recuperacion
            WHERE $columnaId = :id_entidad
              AND usado = 0
              AND fecha_expiracion > NOW()
            ORDER BY id_codigo DESC
            LIMIT 1";

    $stmt = $conexion->prepare($sql);
    $stmt->execute([':id_entidad' => $idEntidad]);
    $fila = $stmt->fetch(PDO::FETCH_ASSOC);

    return $fila && hash_equals($fila['codigo'], $codigoIngresado);
}

/**
 * Valida que el código ingresado sea correcto, no esté usado y no haya
 * expirado. Si es válido, lo marca como usado = 1 (no se borra) y retorna true.
 *
 * Polimórfica: ver generarCodigoRecuperacion() para $tipo.
 */
function validarCodigoRecuperacion(PDO $conexion, int $idEntidad, string $codigoIngresado, string $tipo = 'usuario'): bool
{
    if (!in_array($tipo, ['usuario', 'paciente'], true)) {
        throw new InvalidArgumentException("tipo debe ser 'usuario' o 'paciente'");
    }
    $columnaId = $tipo === 'usuario' ? 'id_usuario' : 'id_paciente';

    $sql = "SELECT id_codigo, codigo
            FROM codigos_recuperacion
            WHERE $columnaId = :id_entidad
              AND usado = 0
              AND fecha_expiracion > NOW()
            ORDER BY id_codigo DESC
            LIMIT 1";

    $stmt = $conexion->prepare($sql);
    $stmt->execute([':id_entidad' => $idEntidad]);
    $fila = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$fila || !hash_equals($fila['codigo'], $codigoIngresado)) {
        return false;
    }

    // El código es válido: lo marcamos como usado (UPDATE, nunca DELETE).
    $stmt = $conexion->prepare(
        "UPDATE codigos_recuperacion SET usado = 1 WHERE id_codigo = :id_codigo"
    );
    $stmt->execute([':id_codigo' => $fila['id_codigo']]);

    return true;
}