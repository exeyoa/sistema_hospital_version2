# 03 — Instrucciones para asistentes de IA

**Público:** cualquier asistente de IA (Claude, ChatGPT, Copilot, u otro) que
esté ayudando a un integrante de este equipo a modificar el sistema.

> Si eres una IA ayudando a un integrante de este equipo, antes de modificar
> cualquier archivo, lee primero **`00_LEEME_PRIMERO.md`**,
> **`01_CAMBIOS_REALIZADOS.md`** y **`02_ARQUITECTURA_Y_SEGURIDAD.md`** en esta
> misma carpeta (`documento/`). Son la fuente de verdad del estado actual del
> proyecto y de las decisiones tomadas.

## 1. Contexto mínimo que debes conocer

- Este repositorio es un **repo nuevo y limpio** (`sistema_hospital_version2`,
  rama `main`, commit inicial único). El historial del repo anterior era
  inmanejable (múltiples ramas/merges) y se descartó a propósito. **No asumas
  que existe trabajo en ramas antiguas ni lo busques en el repo viejo.**
- El proyecto **NO está terminado** (faltan paneles), pero el repo refleja el
  estado actual oficial.
- `config/entorno.php` y `config/correo.php` **no existen en el repositorio**
  (están en `.gitignore` y contienen credenciales reales). Nunca los crees ni
  los rellenes con datos reales en el contexto de una sesión de chat, y jamás
  sugieras subirlos.
- `vendor/` tampoco está en el repo: se restaura con `composer install`.

## 2. Metodología obligatoria: no tocar código "a ciegas"

Ante **cualquier** solicitud de cambio, sigue este ciclo en orden y apóyate en
evidencia real en cada paso. El ser humano debe poder verificar todo.

1. **Investigar con evidencia real** antes de escribir una sola línea:
   - Lee los archivos implicados (no supongas su contenido por su nombre).
   - Usa `git log --oneline -10`, `git status`, `git diff` para conocer el estado.
   - Si involucra la base de datos, lee los archivos `Base_de_datos/*.sql` y,
     si hace falta, ejecuta consultas reales de lectura contra la BD.
   - Muestra qué se encontró y en qué archivo/línea (ruta:línea).
2. **Proponer un plan** conciso: qué se cambiará, dónde, y los riesgos (especialmente
   de seguridad).
3. **Esperar aprobación humana** antes de implementar. No implementar "por si acaso".
4. **Implementar** respetando las convenciones (ver sección 3).
5. **Verificar con evidencia**:
   - Sintaxis PHP: `php -l <archivo>` para cada archivo modificado.
   - Revisar `git diff` y `git status` para confirmar que solo cambió lo planeado.
   - Si hay lógica SQL nueva: probar la consulta real contra la BD antes de darla por buena.
6. **Nunca comitear ni pushear sin revisión humana.** El único que commitea y
   hace push es el humano, después de revisar el diff. Si tú lo haces por él,
   solo después de mostrar `git diff` completo y obtener su aprobación explícita.

## 3. Convenciones que debes respetar al modificar código

- **Capa de seguridad (NO modificar sin necesidad real):**
  - `verificarSesion([...])` en cada página protegida; nunca reescribir el
    mecanismo de sesión ni llamar `session_start()` suelto.
  - Tokens CSRF (`generarTokenCSRF()` / `validarTokenCSRF()`) en **todo** POST.
  - Política de contraseñas en `config/politica_password.php` y
    `password_hash(PASSWORD_BCRYPT)` + `password_verify` (nunca texto plano).
  - Bloqueo de fuerza bruta (`config/intentos_login.php`).
- **PDO:** sentencias preparadas siempre con parámetros enlazados; nunca
  concatenar strings en SQL. No mostrar mensajes de error crudos al usuario
  (usar `error_log()` y mensajes genéricos).
- **Salida HTML:** escapar con `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`.
- **Estilo de código:** seguir el que ya existe en los archivos vecinos.

## 4. Cambios que requieren investigación y planificación ANTES de cualquier implementación

Estos son sensibles: planifícalos con el humano, no los implementes directo:

- **Cambios a la base de datos** (esquema, columnas, índices, datos de prueba).
- **Cambios a `config/sesion.php`** (mecanismo de sesión, CSRF, inactividad).
- **Cambios a archivos compartidos**: `pages/parciales/topbar.php`,
  `pages/parciales/sidebar.php`, `css/estilo.css` (y CSS globales), porque
  impactan a todos los paneles a la vez.

Para estos, antes de tocar nada: lee el archivo completo, revisa quién más lo
usa (`grep` de referencias), y presenta al humano un plan con impacto y riesgos.

## 5. Advertencia específica: Tribunal Electoral y cédula en `usuarios`

> **NO reintroduzcas la integración con el Tribunal Electoral ni el campo
> `cedula` en la tabla `usuarios`** salvo que el equipo lo apruebe
> explícitamente de nuevo.

Puntos que debes recordar:

- La integración externa con el Tribunal Electoral se **revertió por completo**
  (`config/api_tribunal.php`, `componentes/selector_cedula.php`,
  `pages/ajax/consultar_cedula.php` fueron eliminados). No los "restaures",
  "improvises" o "reimplementes" por iniciativa propia, ni consultes APIs
  externas de verificación de cédulas para dar de alta usuarios.
- La tabla **`usuarios` no tiene columna `cedula`**. No la agregues
  (ni con ALTER TABLE ni inventando migraciones) sin aprobación explícita del
  equipo.
- La tabla **`pacientes` SÍ tiene su columna `cedula`** (regla de negocio
  aparte, HTML input manual con validación regex). Eso no se toca ni se elimina.

Si el humano te pide algo que roce estas líneas, pregunta explícitamente si el
equipo ya aprobó reintroducir la integración antes de proceder.

## 6. Regla final

No decidas por el equipo. El humano es el responsable final: investiga,
propón, espera aprobación, implementa, verifica y **nunca** entregues un
cambio grande sin mostrar antes `git diff` y que el humano lo revise.