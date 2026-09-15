# Sistema Hospital — Documento maestro del proyecto

Este documento resume todo lo que ya existe en el proyecto y sirve para poner al día a cualquier IA o persona nueva que se una. Compártelo completo con tus compañeros.

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

## 3. Requisitos no funcionales

| ID | Requisito |
|---|---|
| RNF-01 | La interfaz debe ser responsiva: debe verse y funcionar bien en celular, tablet y laptop. |
| RNF-02 | Las contraseñas deben almacenarse hasheadas (bcrypt vía `password_hash`/`password_verify`), nunca en texto plano. |
| RNF-03 | Todas las consultas a la base de datos deben usar sentencias preparadas (PDO) para evitar inyección SQL. |
| RNF-04 | El sistema debe funcionar en XAMPP local durante desarrollo y ser desplegable en hosting compartido con PHP + MySQL. |
| RNF-05 | El código debe seguir una estructura de carpetas consistente: `css/`, `js/`, `config/`, `pages/`. |
| RNF-06 | Los textos de la interfaz deben estar en español. |

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
- Cada persona trabaja en su propia rama (`feature-medico`, `feature-admin`) y sube su avance con Pull Request hacia `main`.
- No editar directamente sobre `main`.

---

## 7. Prompt para el compañero del panel MÉDICO

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

Ayúdame a construir esto paso a paso, empezando por la consulta a la cola de
pacientes en espera.
```

---

## 8. Prompt para el compañero del panel ADMINISTRADOR

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

Ayúdame a construir esto paso a paso, empezando por la lista de usuarios.
```

---

## 9. Prompt para refrescar tu propio contexto (recepcionista + base)

```
Estoy trabajando en un proyecto de universidad: un sistema de consultas médicas
para un hospital, en PHP + MySQL + HTML/CSS/JS sobre XAMPP. Yo armé la base del
proyecto: repositorio en GitHub, conexión a base de datos, las 11 tablas
normalizadas, y el login con sesiones por rol (admin/medico/recepcionista) ya
funcionando y probado.

Mi parte pendiente es el módulo de recepcionista (pages/recepcionista.php):
registrar pacientes nuevos, agendar citas (eligiendo médico, fecha y hora), y
manejar los turnos del día (con cita o espontáneos), guardando todo en las
tablas pacientes, citas y turnos. Debo seguir el mismo estilo visual ya
definido en css/estilo.css (variables de color y clases .btn, .campo,
.barra-superior, .contenido-panel) y mantener la protección por sesión que
ya tienen los demás paneles.

Ayúdame a construirlo paso a paso, empezando por el formulario de registro
de pacientes.
```
