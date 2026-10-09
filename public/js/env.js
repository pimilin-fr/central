/* Sélecteur d'environnement : ouverture / fermeture du menu, confirmation de la reconstruction. */
document.addEventListener('DOMContentLoaded', () => {
    const root = document.querySelector('[data-env-switcher]');
    const toggle = document.querySelector('[data-env-toggle]');
    const menu = document.querySelector('[data-env-menu]');

    if (!root || !toggle) {
        return;
    }

    const setOpen = (open) => {
        root.classList.toggle('is-open', open);
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        menu?.setAttribute('aria-hidden', open ? 'false' : 'true');
    };

    toggle.addEventListener('click', (event) => {
        event.stopPropagation();
        setOpen(!root.classList.contains('is-open'));
    });

    document.addEventListener('click', (event) => {
        if (!root.contains(event.target)) {
            setOpen(false);
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            setOpen(false);
        }
    });

    root.querySelectorAll('form').forEach((form) => {
        form.addEventListener('submit', (event) => {
            const message = form.dataset.envConfirm;

            if (message && !window.confirm(message)) {
                event.preventDefault();
                return;
            }

            // évite le double envoi
            form.querySelectorAll('button').forEach((button) => {
                button.disabled = true;
            });
        });
    });

    /* ---------------------------------------------------------------
     * Suivi de la reconstruction de la démo : l'indicateur du bouton reste actif tant qu'elle tourne,
     * puis une notification annonce la fin (ou l'échec) sans recharger la page.
     * --------------------------------------------------------------- */
    const stateUrl = root.dataset.envStateUrl;
    const rebuildButton = root.querySelector('[data-env-rebuild]');
    const rebuildTitle = root.querySelector('[data-env-rebuild-title]');
    const rebuildHint = root.querySelector('[data-env-rebuild-hint]');
    const ICONS = {
        ok: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>',
        failed: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6M9 9l6 6"/></svg>'
    };

    const notify = (ok, message) => {
        let stack = document.querySelector('.flash-stack');

        if (!stack) {
            stack = document.createElement('div');
            stack.className = 'flash-stack';
            stack.setAttribute('role', 'region');
            stack.setAttribute('aria-label', 'Notifications');
            document.body.appendChild(stack);
        }

        const toast = document.createElement('div');
        toast.className = 'flash-message ' + (ok ? 'flash-success' : 'flash-error');
        toast.setAttribute('role', ok ? 'status' : 'alert');
        toast.innerHTML =
            '<div class="flash-icon">' + (ok ? ICONS.ok : ICONS.failed) + '</div>' +
            '<div class="flash-content"><span class="flash-label">' + (ok ? 'Démo prête' : 'Échec') + '</span>' +
            '<span class="flash-text"></span></div>' +
            '<button type="button" class="flash-close" aria-label="Fermer">&times;</button>';
        toast.querySelector('.flash-text').textContent = message;

        const remove = () => toast.remove();
        toast.querySelector('.flash-close').addEventListener('click', remove);
        stack.appendChild(toast);
        window.setTimeout(remove, ok ? 12000 : 20000);
    };

    const showFinished = (data) => {
        root.classList.remove('is-building');
        root.dataset.envBuilding = '0';

        const ok = data.state === 'ok';
        const inDemo = root.dataset.envMode === 'demo';

        if (rebuildTitle) {
            rebuildTitle.textContent = 'Reconstruire la démo';
        }
        if (rebuildHint) {
            rebuildHint.textContent = inDemo
                ? 'Revenez d\'abord sur Central'
                : (ok ? 'Dernière copie : ' + (data.date || '') : 'Dernier essai en échec (' + (data.date || '') + ')');
        }
        if (rebuildButton) {
            rebuildButton.disabled = inDemo;
        }
        // Central / Démo redeviennent cliquables, sauf l'environnement actif
        root.querySelectorAll('.env-option:not(.env-option-rebuild)').forEach((button) => {
            button.disabled = button.classList.contains('is-active');
        });

        notify(ok, ok
            ? 'La base démo est reconstruite (' + (data.date || '') + '). Vous pouvez basculer dessus depuis ce menu.'
            : 'La reconstruction de la démo a échoué. Consultez var/demo-build.log ; la production n\'a pas été modifiée.');
    };

    const poll = async () => {
        try {
            const response = await fetch(stateUrl, {
                headers: { Accept: 'application/json' },
                cache: 'no-store',
                credentials: 'same-origin'
            });
            const data = await response.json();

            if (data.building) {
                window.setTimeout(poll, 4000);
                return;
            }

            showFinished(data);
        } catch (error) {
            window.setTimeout(poll, 8000); // serveur momentanément injoignable : on réessaie
        }
    };

    if (stateUrl && root.dataset.envBuilding === '1') {
        window.setTimeout(poll, 3000);
    }
});
