# Auditoría de seguridad — Sistema Hospital

> **Fecha:** 2026-09-05
> **Rama auditada:** `Administrador`
> **Alcance:** Todos los archivos PHP del sistema (`config/`, `pages/`, `pages/parciales/`, `index.php`), base de datos (`Base_de_datos/`), hojas de estilo (`css/`) y documentación.
> **Modo:** Solo lectura. No se modificó ni creó código. El patrón de referencia es `config/sesion.php` + `pages/login.php` + `pages/procesar_login.php`.

---

## Resumen ejecutivo

| Categoría | Estado |
|---|---|
| 1. Cookies y configuración de sesión | Cumple parcialmente |
| 2. Cierre de sesión por inactividad (5 min) | **No cumple** |
| 3. Inyección SQL | Cumple |
| 4. XSS | Cumple |
| 5. CSRF | **No cumple** |
| 6. Session fixation | Cumple |
| 7. Fuerza bruta | Cumple parcialmente |
| 8. Control de acceso por acción | **No cumple** |
| 9. Integridad de datos (no borrado físico) | Cumple |
| 10. Manejo de errores | Cumple parcialmente |
| 11. Contraseñas | Cumple |

**5 hallazgos más urgentes al final del documento.**

---

## 1. Cookies y configuración de sesión
**Estado: Cumple parcialmente**

- ✅ `pages/login.php:3` y `pages/procesar_login.php:6` usan `iniciarSesionSegura()` (HttpOnly + SameSite=Lax + Secure condicional). El patrón central es correcto (`config/sesion.php:36-54`).
- ❌ **Medio** — `pages/admin.php:2`, `pages/medico.php:2`, `pages/recepcionista.php:2`, `pages/mis_consultas.php:2`, `pages/crear_usuario.php:2` usan `session_start()` suelto, sin el helper central.
- ❌ **Medio** — `pages/logout.php:2` y `pages/recuperar.php:2` inician sesión cruda; si ese request *crea* la cookie de sesión (usuario llega directo a recuperar.php sin sesión previa), se genera bajo los defaults del `php.ini` de XAMPP (sin HttpOnly/SameSite garantizados).
- **Qué habría que hacer:** migrar los 7 `session_start()` sueltos a `iniciarSesionSegura()` / `verificarSesion()`.

## 2. Cierre de sesión por inactividad (5 minutos)
**Estado: No cumple**

- ❌ **Alto** — La lógica de timeout ya existe (`config/sesion.php:75-81`, constante `TIEMPO_INACTIVIDAD_SEGUNDOS`) pero **ningún panel la invoca**: `admin.php`, `medico.php`, `recepcionista.php`, `mis_consultas.php` y `crear_usuario.php` hacen control manual de rol sin tiempo. `$_SESSION['ultima_actividad']` solo se setea en `procesar_login.php:65` y nunca se vuelve a comparar. La sesión queda viva indefinidamente.
- ❌ **Medio** — `pages/logout.php:2-3` hace `session_destroy()` **sin borrar la cookie física** (no usa `cerrarSesionCompleta()` de `config/sesion.php:96-114`).
- **Qué habría que hacer:** anteponer `verificarSesion([...])` al inicio de cada panel/endpoint (método ya probado en `verificarSesion()`).

## 3. Inyección SQL
**Estado: Cumple**

- ✅ Todas las consultas usan sentencias preparadas con parámetros bindeados (`admin.php:48-57` hasta el `LIMIT/OFFSET` con `PARAM_INT`, `procesar_login.php:25-33`, `crear_usuario.php:45-69,73-89`, `medico.php:12-15,33-34`, `mis_consultas.php:42-43`, `intentos_login.php:32-41`).
- La única interpolación es `$condicionFecha` en `mis_consultas.php:28`, pero proviene de una **whitelist de 2 valores** (`mis_consultas.php:27`), segura.
- Sin hallazgos de severidad.

## 4. XSS (Cross-Site Scripting)
**Estado: Cumple**

- ✅ Toda salida de BD y de `$_GET/$_POST` pasa por `htmlspecialchars()`: nombres de pacientes, cédula, motivo, diagnóstico (`medico.php:122-150`, `mis_consultas.php:103-117`), datos de sesión (`admin.php:125-127`, `recepcionista.php:20`, `topbar.php:21-26`), parámetros persistidos en formularios (`crear_usuario.php:187-205`), tokens (`login.php:54`).
- Los pocos `echo` sin escape son enteros o valores internos seguros (`admin.php:228` id int, `admin.php:231` color de array fija, paginación int).
- Sin hallazgos de severidad.

## 5. CSRF
**Estado: No cumple**

- ✅ Login correcto: token emitido (`login.php:20,54`) y validado con `hash_equals` (`procesar_login.php:9-13`).
- ❌ **Alto** — `pages/crear_usuario.php`: el formulario que crea un usuario (con rol, incl. `admin`) se procesa en `:24` **sin `generarTokenCSRF()` ni `validarTokenCSRF()`**. Un CSRF puede crear una cuenta de administrador.
- ❌ **Bajo** — `pages/recuperar.php:44` formulario POST sin CSRF (efecto limitado: no crea ni modifica datos reales).
- ❌ **Bajo** — Cierre de sesión por enlace GET (`admin.php:121`, `sidebar.php:33`) sin CSRF (logout CSRF).
- **Qué habría que hacer:** token CSRF en todo formulario con efecto de estado; los futuros endpoints de acción (editar/desactivar/consulta/receta/turno) deben ser POST + CSRF desde el día uno.

## 6. Session fixation
**Estado: Cumple**

- ✅ `session_regenerate_id(true)` tras login exitoso (`procesar_login.php:60`), antes de escribir datos sensibles.
- No existe hoy ningún flujo de cambio de rol/reautenticación, así que no hay otro punto requerido.
- Sin hallazgos.

## 7. Fuerza bruta
**Estado: Cumple parcialmente**

- ✅ Login: `MAX_INTENTOS_FALLIDOS=3` en ventana de 15 min (`intentos_login.php:20-21`), aplicado en `procesar_login.php:40-51` + `usleep` para usuarios inexistentes (`:50`).
- ❌ **Medio** — `pages/recuperar.php` (endpoint POST público) **no tiene** control de intentos ni retardo: un bot puede bombardear solicitudes de "avisar al administrador".
- 📌 **Bajo** — Limitación documentada: solo cuenta a usuarios existentes (no se puede bloquear por nombre inexistente); la columna `ip` se guarda (`intentos_login.php:67`) pero no se usa para bloqueo por IP.
- **Qué habría que hacer:** aplicar `contarIntentosFallidosRecientes`/retardo también en recuperar.php (o moverlo a menú/endpoint) antes de producción.

## 8. Control de acceso por acción (no solo por página)
**Estado: No cumple**

- ❌ **Alto** — `pages/medico.php:4`: validación invertida — exige `$_SESSION['rol'] !== 'admin'` en vez de `'medico'`. Resultado: los **médicos no pueden entrar** a su panel y el admin sí (un admin puede atender como médico). Lógica de autorización incorrecta.
- ✅ `crear_usuario.php:5` valida `admin` en el propio archivo (el handler POST está dentro del mismo script, así que la acción queda cubierta) y `mis_consultas.php:5,38` valida `medico` + filtra por `id_medico` de la sesión (sin cruce de datos). `recepcionista.php:3` correcto.
- 🔎 **Nota de diseño** — `admin.php:251-252` enlaza a `editar_usuario.php`/`cambiar_estado_usuario.php`, y `medico.php:147` a `consulta.php`, que **no existen** (links muertos). Hoy no hay endpoint de acción expuesto, pero cuando se creen, cada uno deberá validar sesión + rol + CSRF por sí mismo, no depender del panel.
- **Qué habría que hacer:** corregir `medico.php:4` a `'medico'`; al crear los módulos pendientes, replicar el patrón de `crear_usuario.php` (protección a nivel de archivo de acción).

## 9. Integridad de datos (no borrado físico)
**Estado: Cumple**

- ✅ No hay ninguna sentencia `DELETE`/`TRUNCATE`/`DROP` en el código PHP (grep global: 0 resultados). El esquema soporta desactivación lógica de usuarios (`usuarios.activo`, `hospital_db.sql:22`).
- 📌 Nota: las acciones de editar/desactivar aún no están implementadas; al crearlas deben ser `UPDATE estado/activo`, nunca `DELETE`.
- Sin hallazgos de severidad.

## 10. Manejo de errores
**Estado: Cumple parcialmente**

- ✅ `display_errors=0` + `log_errors=1` aplicados en `config/sesion.php:24-26` (solo llegan a login y procesar_login, que son los que lo importan).
- ❌ **Medio** — Los paneles (`admin.php`, `medico.php`, `recepcionista.php`, `mis_consultas.php`, `crear_usuario.php`) **no incluyen `config/sesion.php`**, así que dependen del `php.ini` de XAMPP (`display_errors=On` en dev) y un warning/notice puede verse en pantalla. Conviene centralizar el `display_errors` vía `config/conexion.php` (que sí importan todos) o vía `.htaccess`/INI.
- ❌ **Medio** — `crear_usuario.php:98` muestra `$e->getMessage()` al usuario; una excepción PDO puede filtrar estructura de BD/SQL. (El propio `config/conexion.php:14-15` ya hace lo correcto: log + mensaje genérico → imitar ese patrón.)
- ⚠️ **Bajo** — `medico.php:19` y `mis_consultas.php:20` hacen `die('Error: no se encontró un registro de médico...')`: mensaje técnico visible a un usuario logueado.
- No hay `var_dump`/`print_r` de depuración.

## 11. Contraseñas
**Estado: Cumple**

- ✅ Creación con `password_hash($password, PASSWORD_BCRYPT)` (`crear_usuario.php:55`) y verificación con `password_verify()` (`procesar_login.php:53`).
- ✅ Ningún formulario/envío devuelve `password_hash` al HTML (en crear_usuario no se precarga nada; cuando se cree `editar_usuario.php`, NO precargar el hash).
- ⚠️ **Bajo** — `Base_de_datos/usuarios_prueba.sql:7-9`: los 3 usuarios comparten **exactamente el mismo hash bcrypt** (mismo salt), o sea, la misma contraseña `prueba123`, y el hash viaja en el repo junto con la documentación de la contraseña (`docs/documento.md:115`). Antes de producción: hashes distintos por usuario y rotar credenciales.
- ⚠️ **Bajo** — Mínimo de 6 caracteres (`crear_usuario.php:41`): débil para un sistema de salud.

---

## Ranking — 5 hallazgos más urgentes

| # | Severidad | Ubicación | Hallazgo |
|---|---|---|---|
| 1 | 🔴 Alto | `crear_usuario.php:24,181` | Crear usuario (con rol `admin`) **sin protección CSRF** → escalada de privilegios por CSRF. Mismo arreglo que ya usa `login.php`/`procesar_login.php`. |
| 2 | 🔴 Alto | `medico.php:4` | Validación de rol invertida (`admin` en lugar de `medico`) → médicos sin acceso a su panel y admin con acceso indebido. |
| 3 | 🔴 Alto | `admin.php`, `medico.php`, `recepcionista.php`, `mis_consultas.php`, `crear_usuario.php` | Timeout de 5 min **nunca aplicado**: `verificarSesion()` ya está escrito pero no se usa en ningún panel → sesiones vivas indefinidamente (falla RNF-07). |
| 4 | 🟠 Medio | `logout.php:2-3` | `session_destroy()` sin borrar la cookie física y sin `iniciarSesionSegura()`: cerrar sesión no garantiza limpieza completa. |
| 5 | 🟠 Medio | `recuperar.php:2,44` | Sesión iniciada sin configuraciones seguras + POST sin CSRF y sin control de intentos (fuerza bruta/correo-spam). |