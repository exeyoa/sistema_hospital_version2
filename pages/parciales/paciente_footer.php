<?php
/**
 * pages/parciales/paciente_footer.php
 *
 * Pie común a las páginas del panel del paciente. Cierra el
 * `<div class="paciente-main">` abierto por paciente_header.php
 * y emite el script del toggle del sidebar.
 *
 * Antes de incluir este archivo, las páginas que lo necesiten
 * pueden definir $scriptsExtra con JS adicional para que se
 * inserte dentro del <script> principal.
 */
?>
    </div>

    <script>
    // Toggle del sidebar (solo visible en móvil ≤768px)
    (function () {
        var btn = document.querySelector('.paciente-topbar__menu-btn');
        var sidebar = document.getElementById('sidebar-paciente');
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
