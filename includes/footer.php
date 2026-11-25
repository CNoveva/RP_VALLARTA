            </div>
    </main>
</div>

<script>
    function toggleMenu() {
        const menu = document.getElementById('mobile-menu');
        const iconOpen = document.getElementById('menu-icon-open');
        const iconClose = document.getElementById('menu-icon-close');
        
        menu.classList.toggle('menu-open');
        iconOpen.classList.toggle('block');
        iconOpen.classList.toggle('hidden');
        iconClose.classList.toggle('hidden');
        iconClose.classList.toggle('block');
    }

    // Tablas anchas: barra de desplazamiento fija abajo de la pantalla y arrastre con el mouse,
    // para no tener que bajar hasta el final de la tabla para moverla de lado.
    document.querySelectorAll('.overflow-x-auto').forEach(function (wrap) {
        const table = wrap.querySelector('table');
        if (!table) return;

        // La barra va después de la tarjeta de la tabla, dentro de un contenedor que limita
        // hasta dónde se queda pegada (deja de verse cuando la tabla sale de pantalla).
        const tarjeta = wrap.closest('.glass-card') || wrap;
        const contenedor = document.createElement('div');
        tarjeta.parentNode.insertBefore(contenedor, tarjeta);
        contenedor.appendChild(tarjeta);

        const bar = document.createElement('div');
        bar.className = 'scroll-sync';
        bar.setAttribute('aria-hidden', 'true');
        bar.appendChild(document.createElement('div'));
        contenedor.appendChild(bar);
        wrap.classList.add('has-scroll-sync');

        function ajustar() {
            bar.firstChild.style.width = table.scrollWidth + 'px';
            bar.hidden = table.scrollWidth <= wrap.clientWidth + 1;
        }
        ajustar();
        window.addEventListener('resize', ajustar);
        if (window.ResizeObserver) new ResizeObserver(ajustar).observe(table);

        let sincronizando = false;
        bar.addEventListener('scroll', function () {
            if (sincronizando) return;
            sincronizando = true; wrap.scrollLeft = bar.scrollLeft; sincronizando = false;
        });
        wrap.addEventListener('scroll', function () {
            if (sincronizando) return;
            sincronizando = true; bar.scrollLeft = wrap.scrollLeft; sincronizando = false;
        });

        // Arrastrar la tabla con el mouse (no interfiere con botones, enlaces ni campos)
        let inicioX = 0, inicioScroll = 0, arrastrando = false, movio = false;
        wrap.addEventListener('mousedown', function (e) {
            if (e.button !== 0 || e.target.closest('a, button, input, select, textarea, label')) return;
            arrastrando = true; movio = false;
            inicioX = e.pageX; inicioScroll = wrap.scrollLeft;
        });
        window.addEventListener('mousemove', function (e) {
            if (!arrastrando) return;
            const dx = e.pageX - inicioX;
            if (!movio && Math.abs(dx) < 5) return;
            movio = true;
            wrap.classList.add('arrastrando');
            wrap.scrollLeft = inicioScroll - dx;
        });
        window.addEventListener('mouseup', function () {
            arrastrando = false;
            wrap.classList.remove('arrastrando');
        });
        // Si se arrastró, el clic al soltar no selecciona ni abre nada
        wrap.addEventListener('click', function (e) {
            if (movio) { e.preventDefault(); e.stopPropagation(); movio = false; }
        }, true);
    });
</script>

</body>
</html>