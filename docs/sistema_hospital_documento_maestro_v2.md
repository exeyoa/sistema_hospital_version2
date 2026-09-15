# Sistema Hospital — Documento maestro del proyecto (v2)

Este documento actualiza el original con los hallazgos de la auditoría de diseño, seguridad y calidad. Compártelo completo con tus compañeros y usa los prompts de las secciones 9, 10 y 11 para retomar el trabajo con la IA de cada uno.

---

## 1. Descripción general

Sistema web de consultas médicas para el hospital Raúl Dávila Mena. Permite a recepcionistas registrar pacientes y agendar citas, a médicos registrar consultas y emitir recetas electrónicas, y a un administrador gestionar usuarios y ver reportes básicos.

**Stack tecnológico:** PHP (sin framework) + MySQL + HTML/CSS/JavaScript. Desarrollo local con XAMPP, despliegue final en hosting compartido con subdominio.

**Roles del sistema:** `admin`, `medico`, `recepcionista`. Cada uno tiene su propio panel tras iniciar sesión.

---

## 2. Requisitos funcionales

| ID | Requisito |
|---|---|
| RF-01 | El sistema debe permitir iniciar sesión con usuario y contraseña, redirigiendo a cada rol a su panel correspondiente. |
| RF-02 | La recepcionista debe poder registrar un paciente nuevo con sus datos básicos. |
| RF-03 | La recepcionista debe poder agendar una cita, eligiendo médico, fecha y hora. |
| RF-04 | La recepcionista debe poder registrar la llegada de un paciente sin cita (turno espontáneo). |
| RF-05 | El médico debe ver la cola de pacientes en espera del día. |
| RF-06 | El médico debe poder registrar el motivo y diagnóstico de una consulta. |
| RF-07 | El médico debe poder emitir una receta electrónica con uno o más medicamentos, indicando dosis, frecuencia y duración. |
| RF-08 | El administrador debe poder crear, editar y desactivar usuarios (médicos y recepcionistas). |
| RF-09 | El administrador debe poder ver reportes básicos: consultas del día, pacientes activos, recetas emitidas. |
| RF-10 | El sistema debe cerrar la sesión del usuario al usar "Cerrar sesión". |
| RF-11 | Cada panel debe estar protegido: si no hay sesión activa o el rol no coincide, redirige al login. |

## 3. Requisitos no funcionales (actualizados)

| ID | Requisito |
|---|---|
| RNF-01 | La interfaz debe ser **verdaderamente responsiva** mediante media queries reales (no solo diseño flexible por casualidad): debe verse y funcionar bien en celular, tablet, laptop y pantallas pequeñas de escritorio. |
| RNF-02 | Las contraseñas deben almacenarse hasheadas (bcrypt vía `password_hash`/`password_verify`), nunca en texto plano. |
| RNF-03 | Todas las consultas a la base de datos deben usar sentencias preparadas (PDO) para evitar inyección SQL, sin excepción. |
| RNF-04 | El sistema debe funcionar en XAMPP local durante desarrollo y ser desplegable en hosting compartido con PHP + MySQL. |
| RNF-05 | El código debe seguir una estructura de carpetas consistente: `css/`, `js/`, `config/`, `pages/`. |
| RNF-06 | Los textos de la interfaz deben estar en español. |
| RNF-07 *(nuevo)* | La sesión debe cerrarse automáticamente tras 5 minutos de inactividad, devolviendo al usuario al login. |
| RNF-08 *(nuevo)* | El sistema debe estar protegido contra XSS, CSRF, session fixation y fuerza bruta en login, además de inyección SQL. |
| RNF-09 *(nuevo)* | Ninguna acción debe eliminar físicamente registros históricos (consultas, recetas, citas); solo se permite desactivar/cambiar estado. |

---

## 4. Estructura de carpetas actual

```
sistema_hospital/
├── index.php              # Redirige a pages/login.php
├── config/
│   └── conexion.php        # Conexión PDO a MySQL (host, db, usuario, password)
├── css/
│   ├── estilo.css          # Variables de color, tipografía, componentes base (.btn, .campo, etc.)
│   └── login.css           # Layout específico del login (panel dividido, responsivo)
├── pages/
│   ├── login.php
│   ├── procesar_login.php  # Verifica usuario/contraseña, crea sesión, redirige por rol
│   ├── logout.php
│   ├── admin.php           # Placeholder — pendiente de desarrollar
│   ├── medico.php          # Placeholder — pendiente de desarrollar
│   └── recepcionista.php   # Placeholder — pendiente de desarrollar
└── base_de_datos/
    ├── hospital_db.sql     # Script completo de creación de tablas
    └── usuarios_prueba.sql # Usuarios de prueba (admin, medico1, recepcion1 / prueba123)
```

**Convención de sesión ya implementada** (disponible en cualquier página tras `session_start()`):
```php
$_SESSION['id_usuario']  // int
$_SESSION['nombre']      // string
$_SESSION['rol']         // 'admin' | 'medico' | 'recepcionista'
```

**Clases CSS reutilizables ya definidas en `css/estilo.css`:** `.btn`, `.campo` (envuelve label + input), `.mensaje-error`, `.barra-superior`, `.contenido-panel`. Variables de color en `:root` (`--color-primario`, `--color-fondo`, `--color-texto`, etc.). Usarlas mantiene el diseño consistente entre módulos.

---

## 5. Base de datos (`hospital_db`) — ya creada, 11 tablas, normalizada a 3FN

| Tabla | Campos |
|---|---|
| `roles` | id_rol (PK), nombre_rol |
| `usuarios` | id_usuario (PK), nombre, apellido, correo, usuario, password_hash, id_rol (FK), activo, fecha_creacion |
| `especialidades` | id_especialidad (PK), nombre_especialidad |
| `medicos` | id_medico (PK), id_usuario (FK), id_especialidad (FK), numero_colegiado |
| `pacientes` | id_paciente (PK), nombre, apellido, cedula, fecha_nacimiento, sexo, telefono, direccion, correo |
| `citas` | id_cita (PK), id_paciente (FK), id_medico (FK), fecha_cita, hora_cita, estado (pendiente/confirmada/cancelada/atendida), fecha_creacion |
| `turnos` | id_turno (PK), id_paciente (FK), id_cita (FK, nullable), numero_turno, tipo (con_cita/espontaneo), estado (en_espera/en_consulta/atendido), fecha |
| `consultas` | id_consulta (PK), id_turno (FK), id_paciente (FK), id_medico (FK), fecha_consulta, motivo, diagnostico, observaciones |
| `medicamentos` | id_medicamento (PK), nombre_medicamento, presentacion |
| `recetas` | id_receta (PK), id_consulta (FK, único), fecha_emision |
| `receta_detalle` | id_detalle (PK), id_receta (FK), id_medicamento (FK), dosis, frecuencia, duracion |

Relaciones clave: un `turno` puede o no venir de una `cita`. Una `consulta` nace de un `turno`. Una `receta` pertenece a una sola `consulta`, pero puede tener varios `receta_detalle` (varios medicamentos).

---

## 6. Flujo de trabajo en equipo (Git)

- Repositorio en GitHub, rama principal `main` ya tiene: login funcional, conexión a base de datos, y las 11 tablas.
- Cada persona trabaja en su propia rama (`feature-medico`, `feature-admin`, `feature-recepcionista`) y sube su avance con Pull Request hacia `main`.
- No editar directamente sobre `main`.

---

## 7. Hallazgos de la auditoría (motivo de esta actualización)

Al revisar el sistema en distintas computadoras se detectó que la interfaz **no es realmente responsiva**: en algunas máquinas se ve bien por casualidad (pantalla similar a la del desarrollador original), pero en otras se desordena o se corta contenido. Esto indica que falta CSS con media queries reales, y posiblemente el meta viewport.

También se identificó que el proyecto, al estar pensado para producción real (datos médicos sensibles), necesita un nivel de seguridad más alto del que normalmente se exige en un proyecto universitario: desde lo básico (inyección SQL) hasta protección contra ataques más sofisticados (CSRF, session fixation, fuerza bruta, XSS) y cierre de sesión automático por inactividad.

---

## 8. Las 11 mejoras obligatorias (aplican a los tres módulos)

Estas mejoras deben implementarse en **cada panel** (admin, médico, recepcionista), no solo en uno. Cada prompt de las secciones 9–11 las incluye ya adaptadas a su módulo.

1. **Responsive real** — Media queries en `estilo.css`/`login.css` (breakpoints ~480px, ~768px, ~1024px), meta viewport correcto, layout con flexbox/grid, tablas convertidas a tarjetas apiladas en pantallas pequeñas.
2. **Seguridad básica** — Sentencias preparadas (PDO) en el 100% de las consultas, validación server-side de todo input, `htmlspecialchars()` en toda salida a HTML.
3. **Seguridad intermedia/avanzada** — Protección CSRF (token en formularios), rate limiting en login (bloqueo tras 3 intentos fallidos), regeneración de `session_id()` tras login, cookies con `HttpOnly`/`Secure`/`SameSite`, errores de PHP ocultos en producción.
4. **Cierre de sesión por inactividad (5 min)** — Vía `$_SESSION['ultima_actividad']` comparado server-side en cada carga de página protegida; si expira, destruir sesión y redirigir a login.
5. **Control de acceso por acción** — No solo proteger la carga del panel, sino cada acción/endpoint individual (evitar acceso directo por URL adivinada).
6. **Integridad de datos** — Nunca borrar físicamente registros históricos; solo cambiar estados/flags de activo-inactivo; `FOREIGN KEY` con `ON DELETE` bien definido.
7. **Accesibilidad** — `<label for="">` correctamente asociado, contraste de color adecuado, navegación por teclado funcional, `alt` en íconos informativos.
8. **Rendimiento** — Índices en columnas de búsqueda frecuente, uso de `JOIN` en vez de N+1 queries, evitar cargas innecesarias.
9. **UX / feedback al usuario** — Confirmación antes de acciones irreversibles, mensajes claros de éxito/error, prevención de doble envío de formularios, manejo de estados vacíos ("No hay pacientes en espera").
10. **Documentación y mantenibilidad** — Comentarios en la lógica no obvia, nombres de variables/funciones consistentes en español.
11. **Testing básico manual** — Probar campos vacíos, fechas inválidas, caracteres especiales (tildes, ñ), e intentos de inyección SQL de prueba antes de dar el módulo por terminado.

---

## 9. Prompt para el compañero del panel MÉDICO

Copia y pega esto completo en tu propio Claude para que empiece con el contexto correcto:

```
Estoy trabajando en un proyecto de universidad en equipo: un sistema de consultas
médicas para un hospital, hecho en PHP + MySQL + HTML/CSS/JS, corriendo en XAMPP.

CONTEXTO DEL PROYECTO (ya existe y funciona, no lo modifiques a menos que te lo pida):
- Login con sesiones PHP ya funcional. Tras iniciar sesión, estas variables están
  disponibles: $_SESSION['id_usuario'], $_SESSION['nombre'], $_SESSION['rol']
  (rol puede ser 'admin', 'medico' o 'recepcionista').
- Conexión a base de datos ya existe en config/conexion.php, usa PDO y expone
  la variable $conexion. Úsala con sentencias preparadas, nunca concatenes SQL.
- Estructura de carpetas: css/, js/, config/, pages/. Los archivos de cada rol
  van en pages/ (ej: pages/medico.php).
- Ya existe pages/medico.php como placeholder protegido por sesión (redirige a
  login si no hay sesión activa o el rol no es 'medico'). Debo construir el
  contenido real ahí, respetando esa protección.
- CSS compartido en css/estilo.css con variables de color (--color-primario,
  --color-fondo, --color-texto, --color-borde, etc.) y clases reutilizables:
  .btn, .campo, .mensaje-error, .barra-superior, .contenido-panel. Debo usar
  estas clases y variables para mantener el mismo estilo visual del resto del
  sistema, no inventar un diseño distinto.
- La interfaz debe ser responsiva (celular, tablet, laptop) y estar en español.

BASE DE DATOS (MySQL, ya creada, no la modifiques):
- turnos: id_turno, id_paciente, id_cita (nullable), numero_turno,
  tipo ('con_cita'/'espontaneo'), estado ('en_espera'/'en_consulta'/'atendido'), fecha
- pacientes: id_paciente, nombre, apellido, cedula, fecha_nacimiento, sexo,
  telefono, direccion, correo
- consultas: id_consulta, id_turno, id_paciente, id_medico, fecha_consulta,
  motivo, diagnostico, observaciones
- medicamentos: id_medicamento, nombre_medicamento, presentacion
- recetas: id_receta, id_consulta (único), fecha_emision
- receta_detalle: id_detalle, id_receta, id_medicamento, dosis, frecuencia, duracion
- medicos: id_medico, id_usuario, id_especialidad, numero_colegiado

MI TAREA — módulo médico (pages/medico.php y lo que se necesite):
1. Mostrar la cola de pacientes en espera del día (turnos con estado 'en_espera'
   o 'en_consulta', ordenados por numero_turno), consultando la tabla turnos
   unida con pacientes.
2. Al seleccionar un turno, mostrar un formulario de consulta: motivo y
   diagnóstico, que se guarda en la tabla consultas (vinculado al turno,
   paciente y al médico que inició sesión — uso id_medico correspondiente
   a $_SESSION['id_usuario']).
3. Dentro de la misma consulta, un formulario de receta electrónica donde
   puedo agregar uno o varios medicamentos (buscando en la tabla medicamentos
   o agregando uno nuevo), con dosis, frecuencia y duración, que se guarda en
   recetas + receta_detalle.
4. Al guardar la consulta, actualizar el estado del turno a 'atendido'.
5. Todo debe seguir el estilo visual ya definido en css/estilo.css.

REQUISITOS OBLIGATORIOS ADICIONALES (auditoría de calidad — aplícalos desde
el primer paso, no los dejes para el final):

1. RESPONSIVE REAL: usa media queries en estilo.css/una hoja específica del
   módulo (breakpoints ~480px, ~768px, ~1024px), confirma que exista
   <meta name="viewport" content="width=device-width, initial-scale=1.0">,
   usa flexbox/grid con anchos fluidos, y convierte la tabla de cola de
   pacientes en tarjetas apiladas en pantallas pequeñas.
2. SEGURIDAD BÁSICA: sentencias preparadas PDO en el 100% de las consultas,
   valida TODO input en el servidor (no solo en JS), y usa htmlspecialchars()
   al imprimir cualquier dato del paciente/consulta en HTML.
3. SEGURIDAD AVANZADA: agrega token CSRF a los formularios de consulta y
   receta; no muestres errores de PHP/MySQL en pantalla.
4. CIERRE DE SESIÓN POR INACTIVIDAD: implementa (o reutiliza si ya existe en
   otro módulo) un control de $_SESSION['ultima_actividad'] que, si pasan
   5 minutos sin actividad, destruya la sesión y redirija al login.
5. CONTROL DE ACCESO POR ACCIÓN: verifica el rol y la sesión no solo al
   cargar medico.php, sino en cada acción (guardar consulta, guardar receta,
   cambiar estado de turno) por si alguien intenta llamarlas directo por URL.
6. INTEGRIDAD DE DATOS: nunca borres físicamente una consulta o receta;
   si algo se cancela, usa un campo de estado.
7. ACCESIBILIDAD: usa <label for=""> asociado a cada input, buen contraste
   de color, y que el formulario sea navegable con teclado (Tab).
8. RENDIMIENTO: usa JOIN para traer turno+paciente en una sola consulta,
   no una consulta por cada fila.
9. UX: confirma antes de guardar (evitar doble clic que duplique la
   consulta), muestra mensaje de éxito claro, y si no hay pacientes en
   espera muestra un mensaje amigable en vez de una tabla vacía.
10. Comenta el código en las partes no obvias (ej. por qué se actualiza el
    turno a 'atendido' en ese punto específico) y usa nombres de variables
    en español, consistentes con el resto del proyecto.
11. Antes de dar por terminado cada formulario, prueba manualmente: campos
    vacíos, texto con tildes/ñ, y un intento de inyección tipo ' OR '1'='1
    para confirmar que las sentencias preparadas lo bloquean.

Ayúdame a construir esto paso a paso, empezando por la consulta a la cola de
pacientes en espera, aplicando desde ya el punto 1 (responsive) y el punto 2
(seguridad básica) en cada pieza de código que generes.
```

---

## 10. Prompt para el compañero del panel ADMINISTRADOR

Copia y pega esto completo en tu propio Claude para que empiece con el contexto correcto:

```
Estoy trabajando en un proyecto de universidad en equipo: un sistema de consultas
médicas para un hospital, hecho en PHP + MySQL + HTML/CSS/JS, corriendo en XAMPP.

CONTEXTO DEL PROYECTO (ya existe y funciona, no lo modifiques a menos que te lo pida):
- Login con sesiones PHP ya funcional. Tras iniciar sesión, estas variables están
  disponibles: $_SESSION['id_usuario'], $_SESSION['nombre'], $_SESSION['rol']
  (rol puede ser 'admin', 'medico' o 'recepcionista').
- Conexión a base de datos ya existe en config/conexion.php, usa PDO y expone
  la variable $conexion. Úsala con sentencias preparadas, nunca concatenes SQL.
- Estructura de carpetas: css/, js/, config/, pages/. Los archivos de cada rol
  van en pages/ (ej: pages/admin.php).
- Ya existe pages/admin.php como placeholder protegido por sesión (redirige a
  login si no hay sesión activa o el rol no es 'admin'). Debo construir el
  contenido real ahí, respetando esa protección.
- CSS compartido en css/estilo.css con variables de color (--color-primario,
  --color-fondo, --color-texto, --color-borde, etc.) y clases reutilizables:
  .btn, .campo, .mensaje-error, .barra-superior, .contenido-panel. Debo usar
  estas clases y variables para mantener el mismo estilo visual del resto del
  sistema, no inventar un diseño distinto.
- Las contraseñas nuevas deben guardarse con password_hash() (bcrypt), nunca
  en texto plano.
- La interfaz debe ser responsiva (celular, tablet, laptop) y estar en español.

BASE DE DATOS (MySQL, ya creada, no la modifiques):
- usuarios: id_usuario, nombre, apellido, correo, usuario, password_hash,
  id_rol, activo, fecha_creacion
- roles: id_rol, nombre_rol (admin/medico/recepcionista)
- medicos: id_medico, id_usuario, id_especialidad, numero_colegiado
- especialidades: id_especialidad, nombre_especialidad
- consultas: id_consulta, id_turno, id_paciente, id_medico, fecha_consulta,
  motivo, diagnostico, observaciones
- recetas: id_receta, id_consulta, fecha_emision
- pacientes: id_paciente, nombre, apellido, cedula, fecha_nacimiento, sexo,
  telefono, direccion, correo

MI TAREA — módulo administrador (pages/admin.php y lo que se necesite):
1. Listar los usuarios existentes (nombre, correo, rol, activo/inactivo),
   consultando usuarios unida con roles.
2. Formulario para crear un usuario nuevo: nombre, apellido, correo, usuario,
   contraseña (hasheada con password_hash), y rol. Si el rol es 'medico',
   también pedir especialidad y numero_colegiado para insertar en la tabla
   medicos.
3. Poder editar un usuario existente y activar/desactivar (campo activo).
4. Panel de estadísticas básicas con tarjetas: consultas registradas hoy
   (COUNT sobre consultas con fecha_consulta = hoy), total de pacientes
   activos, recetas emitidas hoy (COUNT sobre recetas con fecha_emision = hoy).
5. Todo debe seguir el estilo visual ya definido en css/estilo.css (por ejemplo
   las tarjetas de estadísticas se ven bien con var(--color-primario-claro)
   como fondo).

REQUISITOS OBLIGATORIOS ADICIONALES (auditoría de calidad — aplícalos desde
el primer paso, no los dejes para el final):

1. RESPONSIVE REAL: usa media queries en estilo.css/una hoja específica del
   módulo (breakpoints ~480px, ~768px, ~1024px), confirma que exista
   <meta name="viewport" content="width=device-width, initial-scale=1.0">,
   usa flexbox/grid con anchos fluidos, y convierte la tabla de usuarios y
   las tarjetas de estadísticas en un layout apilado en pantallas pequeñas.
2. SEGURIDAD BÁSICA: sentencias preparadas PDO en el 100% de las consultas,
   valida TODO input en el servidor (no solo en JS), y usa htmlspecialchars()
   al imprimir cualquier dato de usuario en HTML.
3. SEGURIDAD AVANZADA: agrega token CSRF al formulario de crear/editar
   usuario; valida que solo un admin pueda ejecutar estas acciones aunque
   intente llamarlas directo por URL; no muestres errores de PHP/MySQL en
   pantalla; asegúrate de que el hash de contraseña nunca se muestre ni se
   envíe de vuelta al formulario de edición.
4. CIERRE DE SESIÓN POR INACTIVIDAD: implementa (o reutiliza si ya existe en
   otro módulo) un control de $_SESSION['ultima_actividad'] que, si pasan
   5 minutos sin actividad, destruya la sesión y redirija al login.
5. CONTROL DE ACCESO POR ACCIÓN: verifica el rol y la sesión en cada acción
   (crear usuario, editar usuario, desactivar usuario), no solo al cargar
   admin.php.
6. INTEGRIDAD DE DATOS: nunca elimines físicamente un usuario; "eliminar"
   siempre debe significar cambiar el campo activo a 0, para no romper el
   historial de consultas/recetas asociadas a ese médico.
7. ACCESIBILIDAD: usa <label for=""> asociado a cada input del formulario,
   buen contraste de color en las tarjetas de estadísticas, y que todo sea
   navegable con teclado.
8. RENDIMIENTO: usa JOIN para usuarios+roles en una sola consulta; para las
   estadísticas usa COUNT() en SQL en vez de traer todos los registros y
   contarlos en PHP.
9. UX: confirma antes de desactivar un usuario ("¿Seguro que deseas
   desactivar a este usuario?"), muestra mensaje de éxito claro tras crear/
   editar, y valida que el correo/usuario no estén duplicados antes de
   insertar.
10. Comenta el código en las partes no obvias (ej. por qué se inserta
    también en la tabla medicos cuando el rol es 'medico') y usa nombres de
    variables en español, consistentes con el resto del proyecto.
11. Antes de dar por terminado cada formulario, prueba manualmente: campos
    vacíos, correos inválidos, texto con tildes/ñ, y un intento de inyección
    tipo ' OR '1'='1 para confirmar que las sentencias preparadas lo
    bloquean.

Ayúdame a construir esto paso a paso, empezando por la lista de usuarios,
aplicando desde ya el punto 1 (responsive) y el punto 2 (seguridad básica)
en cada pieza de código que generes.
```

---

## 11. Prompt para el compañero del panel RECEPCIONISTA (o para refrescar tu propio contexto)

Copia y pega esto completo en tu propio Claude para que empiece con el contexto correcto:

```
Estoy trabajando en un proyecto de universidad en equipo: un sistema de consultas
médicas para un hospital, hecho en PHP + MySQL + HTML/CSS/JS, corriendo en XAMPP.

CONTEXTO DEL PROYECTO (ya existe y funciona, no lo modifiques a menos que te lo pida):
- Login con sesiones PHP ya funcional. Tras iniciar sesión, estas variables están
  disponibles: $_SESSION['id_usuario'], $_SESSION['nombre'], $_SESSION['rol']
  (rol puede ser 'admin', 'medico' o 'recepcionista').
- Conexión a base de datos ya existe en config/conexion.php, usa PDO y expone
  la variable $conexion. Úsala con sentencias preparadas, nunca concatenes SQL.
- Estructura de carpetas: css/, js/, config/, pages/. Los archivos de cada rol
  van en pages/ (ej: pages/recepcionista.php).
- Ya existe pages/recepcionista.php como placeholder protegido por sesión
  (redirige a login si no hay sesión activa o el rol no es 'recepcionista').
  Debo construir el contenido real ahí, respetando esa protección.
- CSS compartido en css/estilo.css con variables de color (--color-primario,
  --color-fondo, --color-texto, --color-borde, etc.) y clases reutilizables:
  .btn, .campo, .mensaje-error, .barra-superior, .contenido-panel. Debo usar
  estas clases y variables para mantener el mismo estilo visual del resto del
  sistema, no inventar un diseño distinto.
- La interfaz debe ser responsiva (celular, tablet, laptop) y estar en español.

BASE DE DATOS (MySQL, ya creada, no la modifiques):
- pacientes: id_paciente, nombre, apellido, cedula, fecha_nacimiento, sexo,
  telefono, direccion, correo
- citas: id_cita, id_paciente, id_medico, fecha_cita, hora_cita,
  estado ('pendiente'/'confirmada'/'cancelada'/'atendida'), fecha_creacion
- turnos: id_turno, id_paciente, id_cita (nullable), numero_turno,
  tipo ('con_cita'/'espontaneo'), estado ('en_espera'/'en_consulta'/'atendido'), fecha
- medicos: id_medico, id_usuario, id_especialidad, numero_colegiado
- especialidades: id_especialidad, nombre_especialidad
- usuarios: id_usuario, nombre, apellido (para mostrar el nombre del médico)

MI TAREA — módulo recepcionista (pages/recepcionista.php y lo que se necesite):
1. Formulario para registrar un paciente nuevo (nombre, apellido, cédula,
   fecha de nacimiento, sexo, teléfono, dirección, correo), validando que la
   cédula no esté duplicada.
2. Formulario para agendar una cita: buscar/seleccionar paciente existente,
   elegir médico (con su especialidad visible), fecha y hora, validando que
   no se dupliquen citas para el mismo médico en el mismo horario.
3. Registrar la llegada de un paciente: si tiene cita del día, generar un
   turno tipo 'con_cita' vinculado a esa cita; si no tiene cita, generar un
   turno tipo 'espontaneo'. En ambos casos con numero_turno consecutivo del
   día y estado 'en_espera'.
4. Vista de los turnos del día (con su estado) para seguimiento.
5. Todo debe seguir el estilo visual ya definido en css/estilo.css.

REQUISITOS OBLIGATORIOS ADICIONALES (auditoría de calidad — aplícalos desde
el primer paso, no los dejes para el final):

1. RESPONSIVE REAL: usa media queries en estilo.css/una hoja específica del
   módulo (breakpoints ~480px, ~768px, ~1024px), confirma que exista
   <meta name="viewport" content="width=device-width, initial-scale=1.0">,
   usa flexbox/grid con anchos fluidos, y convierte la vista de turnos del
   día en tarjetas apiladas en pantallas pequeñas.
2. SEGURIDAD BÁSICA: sentencias preparadas PDO en el 100% de las consultas,
   valida TODO input en el servidor (no solo en JS), y usa htmlspecialchars()
   al imprimir cualquier dato del paciente en HTML.
3. SEGURIDAD AVANZADA: agrega token CSRF a los formularios de paciente, cita
   y turno; no muestres errores de PHP/MySQL en pantalla.
4. CIERRE DE SESIÓN POR INACTIVIDAD: implementa (o reutiliza si ya existe en
   otro módulo) un control de $_SESSION['ultima_actividad'] que, si pasan
   5 minutos sin actividad, destruya la sesión y redirija al login.
5. CONTROL DE ACCESO POR ACCIÓN: verifica el rol y la sesión en cada acción
   (registrar paciente, agendar cita, registrar turno), no solo al cargar
   recepcionista.php.
6. INTEGRIDAD DE DATOS: nunca elimines físicamente una cita o un turno; usa
   el campo estado (ej. 'cancelada') si algo se anula.
7. ACCESIBILIDAD: usa <label for=""> asociado a cada input, buen contraste
   de color, y que los formularios sean navegables con teclado.
8. RENDIMIENTO: usa JOIN para traer médico+especialidad y paciente+cita en
   una sola consulta cuando aplique, no consultas separadas por cada fila.
9. UX: valida en tiempo real (o al enviar) que la cédula no esté duplicada
   antes de insertar, confirma antes de cancelar una cita, muestra mensaje
   de éxito claro tras cada acción, y evita doble envío del formulario
   (doble clic no debe crear dos pacientes o dos citas iguales).
10. Comenta el código en las partes no obvias (ej. por qué el numero_turno
    se calcula como consecutivo por día) y usa nombres de variables en
    español, consistentes con el resto del proyecto.
11. Antes de dar por terminado cada formulario, prueba manualmente: campos
    vacíos, cédulas repetidas, fechas de cita en el pasado, texto con
    tildes/ñ, y un intento de inyección tipo ' OR '1'='1 para confirmar que
    las sentencias preparadas lo bloquean.

Ayúdame a construir esto paso a paso, empezando por el formulario de registro
de pacientes, aplicando desde ya el punto 1 (responsive) y el punto 2
(seguridad básica) en cada pieza de código que generes.
```
