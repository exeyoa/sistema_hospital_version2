<?php
require_once __DIR__ . '/../config/sesion.php';
iniciarSesionSegura();

// Si ya inició sesión, lo mandamos directo a su panel
if (isset($_SESSION['id_usuario'])) {
    header("Location: " . $_SESSION['rol'] . ".php");
    exit;
}

$error = $_SESSION['error_login'] ?? null;
unset($_SESSION['error_login']);

// Mensaje cuando el usuario llega aquí por cierre de sesión automático
if (!$error && isset($_GET['expirada'])) {
    $error = 'Tu sesión expiró por inactividad. Inicia sesión de nuevo.';
}

// Token CSRF para el formulario de login (RNF-08)
$csrfToken = generarTokenCSRF();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Iniciar sesión - Hospital Raúl Dávila Mena</title>
    <link rel="stylesheet" href="../css/estilo.css">
    <link rel="stylesheet" href="../css/login.css">
</head>
<body>
    <div class="pantalla-login">
        <div class="panel-marca">
            <!-- Carrusel de fondo con crossfade (solo en login.php) -->
            <div class="carrusel-fondo" aria-hidden="true">
                <div class="carrusel-slide activo" style="background-image: url('../imagenes/foto_1.jpeg');"></div>
                <div class="carrusel-slide" style="background-image: url('../imagenes/foto_2.jpeg');"></div>
                <div class="carrusel-slide" style="background-image: url('../imagenes/foto_3.jpeg');"></div>
            </div>

            <!-- Degradado celeste/blanco superpuesto sobre las fotos -->
            <div class="carrusel-velo" aria-hidden="true"></div>

            <!-- Puntos decorativos -->
            <div class="patron-decorativo" aria-hidden="true"></div>

            <div class="contenido-marca">
                <div class="marca-encabezado">
                    <div class="marca-titulo">
                        <svg class="icono-cruz" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M11 2H13V11H22V13H13V22H11V13H2V11H11V2Z" fill="#fff"/>
                        </svg>
                        <h1>Hospital Raúl Dávila Mena</h1>
                    </div>
                    <img class="logo-cssp" src="../imagenes/logo_cssp.webp" alt="Logo de la Caja de Seguro Social">
                </div>

                <p class="mensaje-marca">Consultas, citas y recetas en un solo lugar para tu equipo médico.</p>

                <div class="marca-seguridad">
                    <svg class="icono-escudo" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M12 2L20 5V11C20 16.05 16.63 20.48 12 22C7.37 20.48 4 16.05 4 11V5L12 2Z" stroke="currentColor" stroke-width="1.6"/>
                        <path d="M9 11.5L11 13.5L15.5 9" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <span>Sistema seguro para la gestión de información hospitalaria.</span>
                </div>
            </div>
        </div>

        <div class="panel-formulario">
            <div class="tarjeta-login">
                <h2>Iniciar sesión</h2>

                <?php if ($error): ?>
                    <div class="mensaje-error"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <form action="procesar_login.php" method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                    <div class="campo">
                        <label for="usuario">Usuario</label>
                        <div class="input-con-icono">
                            <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M12 12C14.7614 12 17 9.76142 17 7C17 4.23858 14.7614 2 12 2C9.23858 2 7 4.23858 7 7C7 9.76142 9.23858 12 12 12Z" stroke="currentColor" stroke-width="1.6"/>
                                <path d="M3 21C3 16.5817 7.02944 13 12 13C16.9706 13 21 16.5817 21 21" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
                            </svg>
                            <input type="text" id="usuario" name="usuario" required autofocus autocomplete="username">
                        </div>
                    </div>

                    <div class="campo">
                        <div class="fila-label">
                            <label for="password">Contraseña</label>
                            <a href="recuperar.php" class="link-recuperar">¿Olvidaste tu contraseña?</a>
                        </div>
                        <div class="input-con-icono">
                            <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <rect x="4" y="10" width="16" height="10" rx="2" stroke="currentColor" stroke-width="1.6"/>
                                <path d="M8 10V7C8 4.79086 9.79086 3 12 3C14.2091 3 16 4.79086 16 7V10" stroke="currentColor" stroke-width="1.6"/>
                            </svg>
                            <input type="password" id="password" name="password" required autocomplete="current-password">
                            <button type="button" class="boton-ver-password" id="botonVerPassword" aria-label="Mostrar contraseña">
                                <svg id="iconoOjo" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <path d="M2 12C2 12 5.5 5 12 5C18.5 5 22 12 22 12C22 12 18.5 19 12 19C5.5 19 2 12 2 12Z" stroke="currentColor" stroke-width="1.6"/>
                                    <circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.6"/>
                                </svg>
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="btn">Entrar</button>
                </form>
            </div>
        </div>
    </div>

    <script>
        // --- Carrusel de fotos del panel de marca ---
        const carruselFondo = document.querySelector('.carrusel-fondo');
        if (carruselFondo) {
            const slides = carruselFondo.querySelectorAll('.carrusel-slide');
            let indice = 0;
            setInterval(() => {
                slides[indice].classList.remove('activo');
                indice = (indice + 1) % slides.length;
                slides[indice].classList.add('activo');
            }, 15000);
        }

        const botonVer = document.getElementById('botonVerPassword');
        const campoPassword = document.getElementById('password');
        const iconoOjo = document.getElementById('iconoOjo');

        const ojoAbierto = iconoOjo.innerHTML;
        const ojoCerrado = `
            <path d="M3 3L21 21" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
            <path d="M10.6 5.1C11.06 5.03 11.53 5 12 5C18.5 5 22 12 22 12C21.4 13.2 20.5 14.5 19.3 15.7M6.5 6.6C4 8.3 2 12 2 12C2 12 5.5 19 12 19C13.9 19 15.5 18.5 16.8 17.8" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
            <path d="M9.9 10C9.3 10.6 9 11.3 9 12C9 13.7 10.3 15 12 15C12.7 15 13.4 14.7 14 14.1" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
        `;

        botonVer.addEventListener('click', () => {
            const oculto = campoPassword.type === 'password';
            campoPassword.type = oculto ? 'text' : 'password';
            iconoOjo.innerHTML = oculto ? ojoCerrado : ojoAbierto;
            botonVer.setAttribute('aria-label', oculto ? 'Ocultar contraseña' : 'Mostrar contraseña');
        });
    </script>
</body>
</html>