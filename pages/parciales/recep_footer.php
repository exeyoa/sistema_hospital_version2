<?php
/**
 * pages/parciales/recep_footer.php
 *
 * Pie común a las 3 páginas del panel de recepcionista. Cierra el
 * `<div class="recepcionista-main">` abierto por recep_header.php
 * y emite los scripts comunes (toggle del sidebar).
 *
 * Antes de incluir este archivo, las páginas que lo necesiten
 * pueden definir $scriptsExtra con HTML/JS adicional para que se
 * inserte dentro del <script> principal.
 */
?>
    </div>

    <script>
    // Toggle del sidebar (solo visible en móvil ≤768px)
    (function () {
        var btn = document.querySelector('.recepcionista-topbar__menu-btn');
        var sidebar = document.getElementById('sidebar');
        if (!btn || !sidebar) { return; }
        btn.addEventListener('click', function () {
            var abierta = sidebar.classList.toggle('abierta');
            btn.setAttribute('aria-expanded', abierta ? 'true' : 'false');
        });
        sidebar.querySelectorAll('a').forEach(function (enlace) {
            enlace.addEventListener('click', function () {
                if (window.innerWidth <= 768) {
                    sidebar.classList.remove('abierta');
                    btn.setAttribute('aria-expanded', 'false');
                }
            });
        });
    })();
    <?php if (!empty($scriptsExtra)) { echo $scriptsExtra; } ?>
    </script>
</body>
</html>
