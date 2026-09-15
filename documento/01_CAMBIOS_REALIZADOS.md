# 01 — Cambios realizados en esta migración

**Público:** todos los integrantes del equipo.

Este documento describe las decisiones de contenido que vienen "heredadas" del
trabajo previo y que quedaron selladas en el commit inicial del repositorio
nuevo. Todo lo que se describe aquí se confirmó leyendo el estado actual del
código (ver evidencia al final).

## 1. Se revirtió por completo la integración con el Tribunal Electoral

En el proyecto anterior se había incorporado una integración externa que
consultaba la cédula del usuario contra un proveedor (Tribunal Electoral). Esa
integración **se quitó por completo** y **no debe reintroducirse** sin que el
equipo lo apruebe explícitamente de nuevo (ver también
`03_INSTRUCCIONES_PARA_IA.md`).

### Archivos que se eliminaron

| Archivo eliminado | Función que tenía |
|---|---|
| `config/api_tribunal.php` | Cliente/firma para consultar la API externa de cédulas |
| `componentes/selector_cedula.php` | Selector/buscador de cédula con verificación externa |
| `pages/ajax/consultar_cedula.php` | Endpoint AJAX que consultaba la cédula en el proveedor |

En el repositorio actual **ninguno de estos archivos existe** y no hay ninguna
referencia a `tribunal` en el código (confirmado por búsqueda en todo el
proyecto, fuera de `vendor/` y `.git/`).

### Cambio en la base de datos

Se quitó la columna `cedula` de la tabla `usuarios`.

Estado actual de la tabla `usuarios` en `Base_de_datos/hospital_db.sql`:

```sql
CREATE TABLE usuarios (
    id_usuario INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(60) NOT NULL,
    apellido VARCHAR(60) NOT NULL,
    correo VARCHAR(100) UNIQUE,
    usuario VARCHAR(40) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    id_rol INT NOT NULL,
    activo TINYINT(1) DEFAULT 1,
    fecha_creacion DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_rol) REFERENCES roles(id_rol)
);
```

Ya no hay columna `cedula` en `usuarios`. **Si una base de datos local todavía
la tiene** (por haber importado un esquema viejo), conviene quitarla o crear la
base desde el archivo `hospital_db.sql` actualizado.

### Cambio en `pages/crear_usuario.php`

- Se eliminó la **verificación externa de la cédula** al dar de alta a un usuario.
- El formulario de usuario **ya no pide cédula**. Los campos que recibe son:
  nombre, apellido, correo, usuario, contraseña, rol (`admin`, `medico`,
  `recepcionista`), y —solo cuando el rol es `medico`— especialidad y número de
  colegiado.
- La creación es 100 % interna al sistema: no consume ningún servicio externo.

### Cambio en `pages/recepcionista.php` (registro de pacientes)

En el registro de **pacientes** (panorama de recepcionista) la cédula sigue
existiendo, pero como **input manual**: ya no hay selección ni verificación
contra el Tribunal Electoral.

La cédula se valida con una expresión regular en servidor:

```php
if (!preg_match('/^(?:[A-Z]{1,2}-)?\d{1,4}-\d{1,4}(?:-\d{1,4})?$/', $cedula)) {
    $errores[] = 'La cédula no tiene un formato válido (ejemplo: 8-123-456).';
}
```

Y se verifica que no esté duplicada antes de insertar:

```php
$stmt = $conexion->prepare('SELECT COUNT(*) FROM pacientes WHERE cedula = :cedula');
$stmt->execute([':cedula' => $cedula]);
```

## 2. La tabla `pacientes` SÍ conserva su propia cédula (no cambió nada)

La columna `cedula` de la tabla `pacientes` **se mantiene tal como estaba**:

```sql
CREATE TABLE pacientes (
    id_paciente INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(60) NOT NULL,
    apellido VARCHAR(60) NOT NULL,
    cedula VARCHAR(20) NOT NULL UNIQUE,
    ...
);
```

Es una **regla de negocio distinta**: el paciente tiene su propia cédula en la
base del hospital (datos demográficos del paciente), por lo que NO fue tocada
por la reversión del Tribunal Electoral. Solo la cédula del **usuario del
sistema** (la que se verificaba externamente) fue la que se eliminó.

> Regla para el equipo: "cédula en `pacientes`" = OK (no tocar).
> "cédula en `usuarios`" = NO reintroducir (fue lo que se quitó).

## Evidencia usada para este documento

1. `git log --oneline -10` → un único commit: `51d28a0 Version inicial limpia del sistema hospital`.
2. Búsqueda de los archivos eliminados (`Test-Path`) → los tres dan `False`.
3. Búsqueda de la palabra `tribunal` en todo el proyecto (excluye `vendor/` y `.git/`) → **0 resultados**.
4. `Base_de_datos/hospital_db.sql` → tabla `usuarios` sin `cedula`; tabla `pacientes` con `cedula`.
5. Lectura directa de `pages/crear_usuario.php` y `pages/recepcionista.php` → estado actual descrito arriba.