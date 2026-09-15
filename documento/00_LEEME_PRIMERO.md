# 00 — LÉEME PRIMERO

**Público:** todos los integrantes del equipo (y cualquier asistente de IA que los ayude).

## ¿Por qué existe este repositorio?

El repositorio anterior (`sistema_hospital`) arrastraba un historial de git
enredado: múltiples ramas y merges hechos por varios compañeros generaron
errores de sincronización para algunos miembros del equipo (conflictos,
estados "divergentes" y dificultad para saber cuál versión era la buena).

Por eso se creó este repositorio nuevo: `sistema_hospital_version2`, partiendo
de una **copia limpia del estado actual** del proyecto, con un único commit
inicial y sin rastro del historial antiguo.

> # ⚠️ NO usar el repositorio anterior. Este es el repositorio oficial desde el 15 de septiembre de 2026.
>
> URL oficial: `https://github.com/exeyoa/sistema_hospital_version2.git`
>
> La rama por defecto es **`main`**. Si alguien seguía trabajando contra el repo
> viejo, debe cambiar de remoto: los commits futuros del equipo van aquí.

## Qué debe hacer cada compañero para ponerse al día

1. **Clonar este repositorio nuevo** (no el viejo):

   ```bash
   git clone https://github.com/exeyoa/sistema_hospital_version2.git
   cd sistema_hospital_version2
   ```

2. **Instalar las dependencias de Composer**. La carpeta `vendor/` NO está en el
   repositorio (ver `.gitignore`), así que PHPMailer y el autoload deben
   restaurarse localmente:

   ```bash
   composer install
   ```

   (Si no tienes Composer: instálalo desde https://getcomposer.org y vuelve a
   ejecutar el comando.)

3. **Crear tus propios archivos de credenciales**. Por seguridad, estos archivos
   NO vienen en el repositorio ni se suben jamás:
   - `config/entorno.php` → credenciales de base de datos y SMTP (usar de
     plantilla el archivo `config/entorno.php` que cada quien ya tuviera en su
     entorno local, o copiar el formato que se documenta en
     `02_ARQUITECTURA_Y_SEGURIDAD.md`).
   - `config/correo.php` → lee las constantes SMTP desde `entorno.php`.

   Sin `config/entorno.php`, la conexión a la base de datos fallará (a propósito):
   `config/conexion.php` lo requiere al inicio.

4. **Importar las bases de datos** de la carpeta `Base_de_datos/`, en este orden:

   ```bash
   # 1) Esquema principal
   mysql -u root -p < Base_de_datos/hospital_db.sql
   # 2) Tablas auxiliares (opcional, según lo que se necesite)
   mysql -u root -p < Base_de_datos/intentos_login.sql
   mysql -u root -p < Base_de_datos/codigos_recuperacion.sql
   # 3) Datos de prueba (opcional, solo si se quiere rellenar el sistema)
   mysql -u root -p < Base_de_datos/datos_prueba.sql
   mysql -u root -p < Base_de_datos/usuarios_prueba.sql
   ```

   Usa credenciales equivalentes a las de tu `config/entorno.php`. En XAMPP con
   MySQL corriendo, el usuario por defecto suele ser `root` sin contraseña.

5. **Verificar** que todo funciona:

   ```bash
   # servidor PHP local (XAMPP: http://localhost/sistema_hospital_version2)
   php -l index.php
   ```

---

## Después de estos 4 archivos

Ya con el entorno listo, **antes de tocar código**:

- Si vas a hacer cambios: lee `02_ARQUITECTURA_Y_SEGURIDAD.md` para no romper
  la seguridad.
- Si vas a modificar algo compartido: lee `01_CAMBIOS_REALIZADOS.md` para
  respetar las decisiones ya tomadas.
- Si te está ayudando un asistente de IA: que lea
  `03_INSTRUCCIONES_PARA_IA.md`.

## Regla de oro sobre credenciales

`config/entorno.php` y `config/correo.php` **nunca** deben aparecer en un commit.
Están en `.gitignore`, pero la regla real es de equipo: **no forzar su subida**
(`git add -f`) ni copiar sus contenidos a archivos con otro nombre que SÍ se
suban. Un filtro al hacer commit es obligatorio en cada push.