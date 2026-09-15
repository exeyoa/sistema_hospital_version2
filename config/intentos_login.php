<?php
/**
 * config/intentos_login.php
 * -----------------------------------------------------------------
 * Control de fuerza bruta en el login (RNF-08), usando la tabla
 * intentos_login (ver base_de_datos/intentos_login.sql).
 *
 * NOTA IMPORTANTE sobre el diseño de la tabla:
 * intentos_login.id_usuario es NOT NULL con FK a usuarios, así que
 * solo podemos registrar/contar intentos cuando el usuario SÍ existe
 * en la base de datos. Para un nombre de usuario inexistente no hay
 * fila de "usuarios" a la cual asociar el intento, así que ese caso
 * se mitiga aparte con un pequeño retraso (ver procesar_login.php),
 * en vez de un bloqueo real. Si más adelante quieren bloquear también
 * intentos con usuarios inexistentes, habría que permitir NULL en
 * id_usuario o guardar el texto de usuario intentado en otra columna.
 * -----------------------------------------------------------------
 */

const MAX_INTENTOS_FALLIDOS    = 3;
const VENTANA_BLOQUEO_MINUTOS  = 15;

/**
 * Cuenta los intentos fallidos MÁS RECIENTES y consecutivos de un
 * usuario dentro de la ventana de tiempo. Si aparece un intento
 * exitoso antes de llegar a uno fallido (yendo del más reciente al
 * más antiguo), el conteo se detiene ahí: un login correcto reinicia
 * el contador de fuerza bruta.
 */
function contarIntentosFallidosRecientes(PDO $conexion, int $idUsuario): int
{
    $sql = "SELECT exitoso
            FROM intentos_login
            WHERE id_usuario = :id_usuario
              AND fecha_hora >= (NOW() - INTERVAL :minutos MINUTE)
            ORDER BY fecha_hora DESC";

    $stmt = $conexion->prepare($sql);
    $stmt->bindValue(':id_usuario', $idUsuario, PDO::PARAM_INT);
    $stmt->bindValue(':minutos', VENTANA_BLOQUEO_MINUTOS, PDO::PARAM_INT);
    $stmt->execute();

    $fallidosSeguidos = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $intento) {
        if ((int) $intento['exitoso'] === 1) {
            break; // el intento exitoso más reciente detiene el conteo
        }
        $fallidosSeguidos++;
    }

    return $fallidosSeguidos;
}

/**
 * Registra un intento de login (exitoso o fallido) para auditoría
 * y para el conteo de fuerza bruta.
 */
function registrarIntentoLogin(PDO $conexion, int $idUsuario, bool $exitoso): void
{
    $sql = "INSERT INTO intentos_login (id_usuario, exitoso, ip)
            VALUES (:id_usuario, :exitoso, :ip)";

    $stmt = $conexion->prepare($sql);
    $stmt->execute([
        ':id_usuario' => $idUsuario,
        ':exitoso'    => $exitoso ? 1 : 0,
        ':ip'         => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
}
