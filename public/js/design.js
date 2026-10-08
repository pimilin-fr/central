document.addEventListener('DOMContentLoaded', () => {
    const shell = document.getElementById('app-shell');


    /* =====================================================
     * THEMES
     * ===================================================== */

    const themeSwitcher = document.querySelector('[data-theme-switcher]');
    const themeMenuToggle = document.querySelector('[data-theme-menu-toggle]');
    const themeMenu = document.querySelector('[data-theme-menu]');
    const themeOptions = document.querySelectorAll('[data-theme-option]');
    const themeCurrentName = document.querySelector('[data-theme-current-name]');

    // Les thèmes viennent du menu (généré depuis le registre Twig) : rien à déclarer ici.
    const themes = {};
    themeOptions.forEach((option) => {
        themes[option.dataset.themeOption] = option.dataset.themeLabel || option.dataset.themeOption;
    });
    const defaultTheme = themeOptions[0]?.dataset.themeOption || 'light-orange';

    const applyTheme = (theme) => {
        if (!Object.prototype.hasOwnProperty.call(themes, theme)) {
            theme = defaultTheme;
        }

        document.documentElement.dataset.theme = theme;

        localStorage.setItem('central-theme', theme);

        if (themeCurrentName) {
            themeCurrentName.textContent = themes[theme];
        }

        themeOptions.forEach((option) => {
            const active = option.dataset.themeOption === theme;

            option.classList.toggle('is-active', active);
            option.setAttribute('aria-checked', active ? 'true' : 'false');
        });
    };

    const savedTheme = localStorage.getItem('central-theme');

    applyTheme(Object.prototype.hasOwnProperty.call(themes, savedTheme) ? savedTheme : defaultTheme);

    const closeThemeMenu = () => {
        themeSwitcher?.classList.remove('is-open');
        themeMenu?.setAttribute('aria-hidden', 'true');
        themeMenuToggle?.setAttribute('aria-expanded', 'false');
    };

    const openThemeMenu = () => {
        themeSwitcher?.classList.add('is-open');
        themeMenu?.setAttribute('aria-hidden', 'false');
        themeMenuToggle?.setAttribute('aria-expanded', 'true');
    };

    themeMenuToggle?.addEventListener('click', (event) => {
        event.stopPropagation();

        if (themeSwitcher?.classList.contains('is-open')) {
            closeThemeMenu();
        } else {
            openThemeMenu();
        }
    });

    themeOptions.forEach((option) => {
        option.addEventListener('click', () => {
            applyTheme(option.dataset.themeOption);
            closeThemeMenu();
        });
    });

    document.addEventListener('click', (event) => {
        if (
                themeSwitcher &&
                !themeSwitcher.contains(event.target)
                ) {
            closeThemeMenu();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeThemeMenu();
        }
    });

    /* =====================================================
     * APPLICATION SWITCHER
     * ===================================================== */

    const appSwitcher = document.querySelector('[data-app-switcher]');
    const appSwitcherToggle = document.querySelector('[data-app-switcher-toggle]');

    const closeAppSwitcher = () => {
        appSwitcher?.classList.remove('is-open');
        appSwitcherToggle?.setAttribute('aria-expanded', 'false');
    };

    appSwitcherToggle?.addEventListener('click', (event) => {
        event.stopPropagation();

        const open = appSwitcher?.classList.toggle('is-open') ?? false;
        appSwitcherToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });

    document.addEventListener('click', (event) => {
        if (appSwitcher && !appSwitcher.contains(event.target)) {
            closeAppSwitcher();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeAppSwitcher();
        }
    });

    /* =====================================================
     * SIDEBAR COMPACT / TABLET
     * ===================================================== */

    const sidebarToggle = document.querySelector('[data-sidebar-toggle]');

    const sidebar = document.querySelector('[data-sidebar]');

    if (sidebarToggle && sidebar && shell) {
        sidebarToggle.addEventListener('click', () => {
            shell.classList.toggle('sidebar-compact');

            const compact = shell.classList.contains('sidebar-compact');

            localStorage.setItem('central-sidebar-compact', compact ? '1' : '0');
        });

        if (localStorage.getItem('central-sidebar-compact') === '1' && window.innerWidth > 820) {
            shell.classList.add('sidebar-compact');
        }
    }

    /* =====================================================
     * MOBILE SIDEBAR
     * ===================================================== */

    const mobileSidebarToggle = document.querySelector('[data-mobile-sidebar-toggle]');
    const sidebarOverlay = document.querySelector('[data-sidebar-overlay]');

    const closeMobileSidebar = () => {
        shell?.classList.remove('mobile-sidebar-open');
    };

    mobileSidebarToggle?.addEventListener('click', () => {
        shell?.classList.toggle('mobile-sidebar-open');
    }
    );

    sidebarOverlay?.addEventListener('click', closeMobileSidebar);

    document.querySelectorAll('.sidebar-link')
            .forEach((link) => {
                link.addEventListener('click', closeMobileSidebar);
            });

    window.addEventListener('resize', () => {
        if (window.innerWidth > 820) {
            closeMobileSidebar();
        }
    });

    /* =====================================================
     * FLASH MESSAGES
     * ===================================================== */

    const removeFlash = (flash) => {
        if (!flash || flash.classList.contains('is-leaving')) {
            return;
        }

        flash.classList.add('is-leaving');

        window.setTimeout(() => {
            flash.remove();
        }, 180);
    };

    document.querySelectorAll('[data-flash]').forEach((flash) => {
        const delay = parseInt(flash.dataset.flashDelay || '5000', 10);
        const closeButton = flash.querySelector('[data-flash-close]');
        const progress = flash.querySelector('.flash-progress');
        let remaining = delay;
        let startedAt = 0;
        let timer = null;

        const start = () => {
            startedAt = Date.now();
            timer = window.setTimeout(() => removeFlash(flash), remaining);
            if (progress) {
                progress.style.animationDuration = remaining + 'ms';
                progress.style.animationPlayState = 'running';
            }
        };

        const pause = () => {
            window.clearTimeout(timer);
            remaining -= Date.now() - startedAt;
            if (progress) {
                progress.style.animationPlayState = 'paused';
            }
        };

        closeButton?.addEventListener('click', () => {
            window.clearTimeout(timer);
            removeFlash(flash);
        });

        flash.addEventListener('mouseenter', pause);
        flash.addEventListener('mouseleave', start);

        start();
    });

});
