document.addEventListener('DOMContentLoaded', function () {

    const sidebar = document.getElementById('bajamaSidebar');
    const toggle = document.getElementById('sidebarToggle');
    const overlay = document.getElementById('sidebarOverlay');

    if (!sidebar || !toggle || !overlay) {
        return;
    }

    function closeSidebar() {
        sidebar.classList.remove('show');
        overlay.classList.remove('show');
    }

    toggle.addEventListener('click', function () {
        sidebar.classList.toggle('show');
        overlay.classList.toggle('show');
    });

    overlay.addEventListener('click', closeSidebar);

    window.addEventListener('resize', function () {
        if (window.innerWidth > 991) {
            closeSidebar();
        }
    });

});

/* ==========================================================================
   BAJAMA RESPONSIVE TABLE SYSTEM
   ========================================================================== */

(function () {

    'use strict';

    const SELECTOR =
        '.bajama-table-responsive';

    function updateTableOverflow(wrapper) {

        const table =
            wrapper.querySelector('table');

        if (!table) {
            return;
        }

        const isOverflowing =
            table.scrollWidth > wrapper.clientWidth + 1;

        wrapper.classList.toggle(
            'is-overflowing',
            isOverflowing
        );

    }

    function initBajamaTables() {

        document
            .querySelectorAll(SELECTOR)
            .forEach(function (wrapper) {

                const table =
                    wrapper.querySelector('table');

                if (!table) {
                    return;
                }

                let hint =
                    wrapper.querySelector(
                        '.bajama-table-scroll-hint'
                    );

                if (!hint) {

                    hint =
                        document.createElement('div');

                    hint.className =
                        'bajama-table-scroll-hint';

                    hint.innerHTML =
                        '<i class="bi bi-arrow-left-right"></i>' +
                        '<span>' +
                        'Geser tabel ke kiri/kanan untuk melihat data lainnya' +
                        '</span>';

                    wrapper.appendChild(hint);

                }

                updateTableOverflow(wrapper);

            });

    }

    function updateAllTables() {

        document
            .querySelectorAll(SELECTOR)
            .forEach(function (wrapper) {

                updateTableOverflow(wrapper);

            });

    }

    if (document.readyState === 'loading') {

        document.addEventListener(
            'DOMContentLoaded',
            initBajamaTables
        );

    } else {

        initBajamaTables();

    }

    window.addEventListener(
        'resize',
        updateAllTables
    );

})();
