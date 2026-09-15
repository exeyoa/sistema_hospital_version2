# 02 — Arquitectura y seguridad

**Público:** todos los integrantes del equipo (especialmente quienes vayan a
tocar código).

Todo lo descrito aquí fue **confirmado leyendo los archivos reales** del
repositorio, no de memoria. Si vas a modificar alguno de estos mecanismos,
piénsalo dos veces y coméntalo con el equipo primero.

## Mapa de archivos clave

| Archivo | Responsabilidad |
|---|---|
| `config/entorno.php` | **NO subido al repo.** Único lugar con credenciales reales (BD + SMTP). Define `DB_HOST`, `DB_USER`, `DB_PASSWORD`, `DB_NAME`, `CORREO_SMTP_HOST`, `CORREO_SMTP_PUERTO`, `CORREO_REMITENTE`, `CORREO_APP_PASSWORD`. |
| `config/conexion.php` | Requiere `entorno.php` y crea el objeto PDO `$conexion` con `ERRMODE_EXCEPTION` y `charset=utf8`. |
| `config/sesion.php` | Sesión segura, control de acceso por rol, inactividad, CSRF. |
| `config/politica_password.php` | Valida la política de contraseñas en servidor. |
| `config/intentos_login.php` | Control de fuerza bruta en el login (RNF-08). |
| `pages/procesar_login.php` | Orquesta el login: CSRF, consulta con PDO, fuerza bruta, `password_verify`, regeneración de sesión. |

## 1. Sesión y control de acceso (`config/sesion.php`)

### `iniciarSesionSegura()`
Configura y arranca la sesión con cookies seguras:
- `httponly = true` → JavaScript no puede leer la cookie de sesión.
- `samesite = Lax` → mitigación CSRF para la mayoría de escenarios.
- `secure` → activo solo si la conexión es HTTPS (en XAMPP local va en false).
- `lifetime = 0` → la cookie muere al cerrar el navegador.
- Es idempotente: si la sesión ya está activa, no la reinicia.

### `verificarSesion(array $rolesPermitidos)`
Protege una página completa. Hace 3 comprobaciones en orden:
1. **¿Hay sesión iniciada?** Si no existe `$_SESSION['id_usuario']`, redirige a `login.php`.
2. **¿Expiró por inactividad?** (RNF-07) Si pasó más de `TIEMPO_INACTIVIDAD_SEGUNDOS`
   (5 minutos) desde `ultima_actividad`, destruye la sesión completa y redirige a
   `login.php?expirada=1`. Si está activa, renueva la marca de tiempo.
3. **¿El rol es permitido?** Comprueba `$_SESSION['rol']` contra la lista pasada.

Uso típico:

```php
verificarSesion(['admin']);              // solo admin
verificarSesion(['medico', 'admin']);    // médico y admin
verificarSesion(['recepcionista']);      // solo recepcionista
```

### `cerrarSesionCompleta()`
Destruye los datos de sesión **y** la cookie del navegador (porque
`session_destroy()` solo borra los datos del servidor).

### Anti "session fixation"
En `procesar_login.php`, después de un login exitoso se llama
`session_regenerate_id(true)` **antes** de guardar los datos nuevos en
`$_SESSION`, y se genera un token CSRF nuevo.

## 2. Protección CSRF (RNF-08)

En `config/sesion.php`:

- `generarTokenCSRF()` → genera un token aleatorio de 32 bytes (`random_bytes`),
  lo guarda en sesión y lo devuelve. Debe usarse en los formularios como campo
  oculto:

  ```php
  $csrfToken = generarTokenCSRF();
  // <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
  ```

- `validarTokenCSRF(?string $tokenRecibido)` → compara el token enviado contra
  el de la sesión usando `hash_equals` (compara en tiempo constante, evita
  ataques de timing). Se llama en todo POST que modifique datos:
  `procesar_login.php`, `crear_usuario.php`, `recepcionista.php`.

Ejemplo (tomado de `crear_usuario.php`):

```php
if (!validarTokenCSRF($_POST['csrf_token'] ?? null)) {
    header('Location: crear_usuario.php');
    exit;
}
```

## 3. Política de contraseñas (`config/politica_password.php`)

`validarPoliticaPassword(string $password): array` devuelve la lista de reglas
incumplidas (vacío = válida). Una contraseña debe:

1. Tener **al menos 8 caracteres**.
2. Incluir **al menos una letra mayúscula** (`A-Z`).
3. Incluir **al menos una letra minúscula** (`a-z`).
4. **No contener espacios** en blanco.
5. Incluir **al menos un carácter especial** de la lista explícita:
   `! @ # $ % ^ & * ( ) _ + - = [ ] { } ; : , . < > ?`
   (las letras acentuadas NO cuentan como especiales).

El `pattern` de HTML es solo una ayuda visual; **la validación que manda es la
del servidor**. Las contraseñas se guardan con `password_hash($password,
PASSWORD_BCRYPT)` (nunca en texto plano) y se verifican con `password_verify`.

## 4. Bloqueo de fuerza bruta (RNF-08)

En `config/intentos_login.php`:

- Constantes: `MAX_INTENTOS_FALLIDOS = 3` y `VENTANA_BLOQUEO_MINUTOS = 15`.
- `registrarIntentoLogin($conexion, $idUsuario, $exitoso)` → inserta una fila en
  la tabla `intentos_login` (con `id_usuario`, `exitoso` e IP).
- `contarIntentosFallidosRecientes($conexion, $idUsuario)` → cuenta los fallidos
  **consecutivos y recientes** (ventana de 15 minutos); un intento exitoso más
  reciente corta el conteo.

En `procesar_login.php`:

```php
if ($fila) {
    $fallidosRecientes = contarIntentosFallidosRecientes($conexion, (int) $fila['id_usuario']);
    if ($fallidosRecientes >= MAX_INTENTOS_FALLIDOS) {
        $_SESSION['error_login'] = 'Cuenta bloqueada temporalmente...';
        header('Location: login.php');
        exit;
    }
} else {
    usleep(500000); // 0.5s — dificulta ataques automatizados / enumeración de usuarios
}
```

### Limitación conocida del diseño
La tabla `intentos_login.id_usuario` es `NOT NULL` con FK a `usuarios`, así que
**solo se puede contar/bloquear a usuarios que SÍ existen**. Para un nombre de
usuario inexistente se usa un retraso de 0.5 s (`usleep(500000)`) como
mitigación. Si el equipo quisiera bloquear también usuarios inexistentes,
habría que permitir `NULL` en `id_usuario` o guardar el texto del usuario
intentado en otra columna (no se ha hecho).

## 5. Manejo de errores (`config/conexion.php`)

- El modo de error de PDO es `ERRMODE_EXCEPTION` (los errores lanzan excepciones
  capturables, no fallan en silencio).
- **Nunca se muestra el mensaje real del error al usuario** (RNF-08): se escribe
  en el log del servidor (`error_log()`) y al cliente se le muestra un mensaje
  genérico.
- `config/sesion.php` además desactiva `display_errors` y activa `log_errors`.

## 6. Convención: sentencias preparadas PDO (NO romperla)

Toda consulta que involucre datos del usuario debe usar sentencias preparadas
con **parámetros enlazados**, nunca concatenación de strings. Ejemplo correcto:

```php
$stmt = $conexion->prepare('SELECT COUNT(*) FROM pacientes WHERE cedula = :cedula');
$stmt->execute([':cedula' => $cedula]);
```

**Por qué no debe romperse:**
- Es la barrera contra **inyección SQL**: los valores viajan como datos, no como
  SQL ejecutable. Concatenar `"... WHERE cedula = '$cedula'"` permite que un
  atacante cierre la cadena e inyecte su propia consulta.
- Además, `password_hash`/`password_verify` garantizan que las contraseñas no se
  comparen ni se almacenen en texto plano.
- Regla de oro: **si una consulta usa datos que vinieron de `$_POST`,
  `$_GET`, `$_FILES`, cookies o cualquier input externo → sentencia preparada,
  punto**. Incluso si "nadie la va a atacar": es una regla de consistencia del
  proyecto.

## 7. Flujo de login (resumen de `procesar_login.php`)

1. `iniciarSesionSegura()`.
2. Validar token CSRF.
3. Consultar usuario con `WHERE u.usuario = :usuario AND u.activo = 1` (solo
   activos pueden ingresar) uniendo `roles` para obtener `nombre_rol`.
4. Control de fuerza bruta (ver §4).
5. Si el hash coincide con `password_verify`: registrar intento exitoso,
   regenerar id de sesión, guardar `id_usuario`, `nombre`, `rol` y
   `ultima_actividad`, limpiar token CSRF viejo, y redirigir a
   `<rol>.php` (`admin.php`, `medico.php` o `recepcionista.php`).
6. Si no coincide: registrar intento fallido y mensaje genérico "Usuario o
   contraseña incorrectos" (no se revela cuál de los dos falló).