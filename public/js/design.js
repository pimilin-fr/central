document.addEventListener('DOMContentLoaded', () => {
    const shell = document.getElementById('app-shell');

    /*
     * =====================================================
     * THEME
     * =====================================================
     */

    const themeButton = document.querySelector('[data-theme-toggle]');

    const applyTheme = (theme) => {
        document.documentElement.classList.toggle(
                'dark',
                theme === 'dark'
                );

        localStorage.setItem('central-theme', theme);
    };

    themeButton?.addEventListener('click', () => {
        const isDark = document.documentElement.classList.contains('dark');

        applyTheme(isDark ? 'light' : 'dark');
    });

    /*
     * =====================================================
     * SIDEBAR COMPACT / TABLET
     * =====================================================
     */

    const sidebarToggle = document.querySelector(
            '[data-sidebar-toggle]'
            );

    const sidebar = document.querySelector('[data-sidebar]');

    if (sidebarToggle && sidebar && shell) {
        sidebarToggle.addEventListener('click', () => {
            shell.classList.toggle('sidebar-compact');

            const compact = shell.classList.contains(
                    'sidebar-compact'
                    );

            localStorage.setItem(
                    'central-sidebar-compact',
                    compact ? '1' : '0'
                    );
        });

        if (
                localStorage.getItem('central-sidebar-compact') === '1' &&
                window.innerWidth > 820
                ) {
            shell.classList.add('sidebar-compact');
        }
    }

    /*
     * =====================================================
     * MOBILE SIDEBAR
     * =====================================================
     */

    const mobileSidebarToggle = document.querySelector(
            '[data-mobile-sidebar-toggle]'
            );

    const sidebarOverlay = document.querySelector(
            '[data-sidebar-overlay]'
            );

    const closeMobileSidebar = () => {
        shell?.classList.remove('mobile-sidebar-open');
    };

    mobileSidebarToggle?.addEventListener(
            'click',
            () => {
        shell?.classList.toggle('mobile-sidebar-open');
    }
    );

    sidebarOverlay?.addEventListener(
            'click',
            closeMobileSidebar
            );

    document
            .querySelectorAll('.sidebar-link')
            .forEach((link) => {
                link.addEventListener(
                        'click',
                        closeMobileSidebar
                        );
            });

    window.addEventListener('resize', () => {
        if (window.innerWidth > 820) {
            closeMobileSidebar();
        }
    });

    /*
     * =====================================================
     * FLASH MESSAGES
     * =====================================================
     */

    const removeFlash = (flash) => {
        if (!flash || flash.classList.contains('is-leaving')) {
            return;
        }

        flash.classList.add('is-leaving');

        window.setTimeout(() => {
            flash.remove();
        }, 180);
    };

    document
            .querySelectorAll('[data-flash]')
            .forEach((flash) => {
                const closeButton = flash.querySelector(
                        '[data-flash-close]'
                        );

                closeButton?.addEventListener(
                        'click',
                        () => removeFlash(flash)
                );

                window.setTimeout(() => {
                    removeFlash(flash);
                }, 5000);
            });

    /*
     * =====================================================
     * TIERS SEARCH
     * =====================================================
     */

    const searchInput = document.querySelector(
            '[data-tier-search]'
            );

    const tierCards = Array.from(
            document.querySelectorAll('[data-tier-card]')
            );

    const searchEmpty = document.querySelector(
            '[data-tier-search-empty]'
            );

    if (searchInput && tierCards.length > 0) {
        const filterTiers = () => {
            const query = searchInput.value
                    .trim()
                    .toLowerCase();

            let visibleCount = 0;

            tierCards.forEach((card) => {
                const value =
                        card.dataset.tierSearchValue ?? '';

                const visible =
                        query === '' ||
                        value.includes(query);

                card.hidden = !visible;

                if (visible) {
                    visibleCount += 1;
                }
            });

            if (searchEmpty) {
                searchEmpty.hidden =
                        visibleCount !== 0;
            }
        };

        searchInput.addEventListener(
                'input',
                filterTiers
                );
    }


});
