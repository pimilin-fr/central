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
    const menuProgress = root.querySelector('[data-env-progress]');
    const menuProgressBar = root.querySelector('[data-env-progress-bar]');

    /** Barre d'avancement + phase courante dans le menu pendant la reconstruction. */
    const updateMenuProgress = (data) => {
        const p = data && data.progress;
        if (menuProgress && menuProgressBar) {
            menuProgress.hidden = !(data && data.building);
            menuProgressBar.style.width = (p && typeof p.percent === 'number' ? Math.max(3, p.percent) : 3) + '%';
        }
        if (data && data.building && rebuildHint) {
            rebuildHint.textContent = p ? ((p.phase || 'En cours') + (typeof p.percent === 'number' ? ' — ' + Math.round(p.percent) + ' %' : '')) : 'En cours…';
        }
    };
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
            '<button type="button" class="flash-close" aria-label="Fermer">' + (window.CentralIcons ? CentralIcons.get('close', '&times;') : '&times;') + '</button>';
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
            : 'La reconstruction de la démo a échoué. Détails : menu Environnement → « Suivi de la démo » ; la production n\'a pas été modifiée.');
    };

    const poll = async () => {
        try {
            const response = await fetch(stateUrl, {
                headers: { Accept: 'application/json' },
                cache: 'no-store',
                credentials: 'same-origin'
            });
            const data = await response.json();

            updateMenuProgress(data);

            if (data.building) {
                window.setTimeout(poll, 2500);
                return;
            }

            if (menuProgress) {
                menuProgress.hidden = true;
            }

            showFinished(data);
        } catch (error) {
            window.setTimeout(poll, 8000); // serveur momentanément injoignable : on réessaie
        }
    };

    if (stateUrl && root.dataset.envBuilding === '1') {
        window.setTimeout(poll, 800);
    }
});

/* ---------------------------------------------------------------
 * Page « Suivi de la démo » : avancement, étapes, erreur détaillée, journal (rafraîchis en direct).
 * --------------------------------------------------------------- */
document.addEventListener('DOMContentLoaded', () => {
    const page = document.querySelector('[data-env-page]');
    if (!page) {
        return;
    }
    const $ = (name) => page.querySelector('[data-envp-' + name + ']');
    const badge = $('badge'), phase = $('phase'), percent = $('percent'), bar = $('bar'), barWrap = $('bar-wrap');
    const meta = $('meta'), warn = $('warn'), errBox = $('error'), steps = $('steps'), logBox = $('log');
    const unlock = $('unlock'), rebuild = $('rebuild');
    const LABELS = {starting: 'Démarrage', running: 'En cours', ok: 'Terminée', failed: 'Échec', idle: 'Aucun suivi'};
    const MARK = {ok: '✓', failed: '✗', running: '●', pending: '○'};
    let logOffset = null;
    let timer = null;

    // confirmations des formulaires de la page (le menu gère les siens)
    page.querySelectorAll('form[data-env-confirm]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (!window.confirm(form.dataset.envConfirm)) {
                event.preventDefault();
            }
        });
    });

    const dur = (sec) => {
        sec = Math.max(0, Math.round(sec));
        const m = Math.floor(sec / 60), s = sec % 60;
        return m > 0 ? m + ' min ' + String(s).padStart(2, '0') + ' s' : s + ' s';
    };
    const clock = (ts) => ts ? new Date(ts * 1000).toLocaleTimeString('fr-FR') : '—';

    const render = (data) => {
        const p = data.progress;
        const building = !!data.building;
        const state = building ? (p && p.state === 'starting' ? 'starting' : 'running') : (p ? p.state : (data.state || 'idle'));
        badge.textContent = LABELS[state] || state;
        badge.dataset.state = state;

        const pct = p && typeof p.percent === 'number' ? p.percent : 0;
        phase.textContent = p ? (p.phase || LABELS[state]) : (building ? 'Reconstruction lancée hors de l\'interface (pas de suivi détaillé)' : 'Aucune reconstruction suivie pour l\'instant.');
        percent.textContent = p ? Math.round(pct) + ' %' : '';
        bar.style.width = (building ? Math.max(3, pct) : pct) + '%';
        barWrap.setAttribute('aria-valuenow', String(Math.round(pct)));
        barWrap.classList.toggle('is-busy', building);
        barWrap.classList.toggle('is-ok', state === 'ok');
        barWrap.classList.toggle('is-failed', state === 'failed');

        if (p) {
            const end = building ? data.now : (p.updatedAt || data.now);
            const bits = ['Démarrée à ' + clock(p.startedAt), (building ? 'écoulé : ' : 'durée : ') + dur(end - p.startedAt)];
            if (building) { bits.push('dernière activité il y a ' + dur(data.now - p.updatedAt)); }
            if (p.pid) { bits.push('pid ' + p.pid); }
            if (p.detail && building) { bits.push(p.detail); }
            if (!building && p.message) { bits.push(p.message); }
            meta.textContent = bits.join(' · ');
        } else {
            meta.textContent = data.date ? 'Dernier statut : ' + (data.state || '?') + ' (' + data.date + ')' : '';
        }

        // avertissements : processus absent / silence prolongé
        let warning = '';
        if (building && data.processAlive === false) {
            warning = 'Aucun processus de reconstruction n\'a été trouvé sur la machine : elle est probablement bloquée. Utilisez « Débloquer », puis consultez le journal.';
        } else if (building && p && data.now - p.updatedAt > 120) {
            warning = 'Aucune nouvelle information depuis ' + dur(data.now - p.updatedAt) + ' : une opération SQL longue est peut-être en cours (sauvegarde, tables volumineuses). Si cela dure, consultez le journal ci-dessous.';
        } else if (building && !p) {
            warning = 'Statut « en cours » sans suivi détaillé : lancement ancien ou hors interface. « Débloquer » est possible s\'il n\'y a plus de processus.';
        }
        warn.hidden = warning === '';
        warn.textContent = warning;

        // erreur détaillée
        const err = p && p.error;
        errBox.hidden = !err;
        if (err) {
            page.querySelector('[data-envp-error-msg]').textContent = (p.message ? p.message + ' — ' : '') + (err.message && err.message !== p.message ? err.message : '');
            page.querySelector('[data-envp-error-where]').textContent = [err.class, err.where].filter(Boolean).join(' · ');
            page.querySelector('[data-envp-error-trace]').textContent = [(err.chain || []).join('\n  ↳ '), err.trace || ''].filter(Boolean).join('\n\n');
        }

        // étapes
        steps.replaceChildren();
        const list = (p && p.steps) || [];
        if (list.length === 0) {
            const li = document.createElement('li');
            li.className = 'envp-muted';
            li.textContent = p ? 'En attente de la première étape…' : 'Aucune reconstruction suivie pour l\'instant.';
            steps.appendChild(li);
        }
        list.forEach((st) => {
            const li = document.createElement('li');
            li.className = 'is-' + st.status;
            const mark = document.createElement('span');
            mark.className = 'st';
            mark.textContent = MARK[st.status] || '•';
            const label = document.createElement('span');
            label.textContent = st.label;
            const detail = document.createElement('small');
            detail.textContent = st.detail || '';
            li.append(mark, label, detail);
            steps.appendChild(li);
        });

        if (unlock) { unlock.hidden = !building; }
        if (rebuild) { rebuild.disabled = building || data.mode === 'demo'; }
    };

    const appendLog = (text, reset) => {
        if (reset) { logBox.replaceChildren(); }
        if (text === '' && logBox.childNodes.length === 0) {
            logBox.textContent = '(journal vide : rien n\'a encore été écrit dans var/demo-build.log)';
            return;
        }
        if (text === '') { return; }
        if (logBox.firstChild && logBox.firstChild.nodeType === 3 && /^\(journal vide/.test(logBox.firstChild.textContent)) { logBox.replaceChildren(); }
        const atBottom = logBox.scrollHeight - logBox.scrollTop - logBox.clientHeight < 40;
        text.split(/(?<=\n)/).forEach((line) => {
            if (/error|erreur|exception|échec|failed|fatal|refus|denied|not found|introuvable/i.test(line)) {
                const span = document.createElement('span');
                span.className = 'is-err';
                span.textContent = line;
                logBox.appendChild(span);
            } else {
                logBox.appendChild(document.createTextNode(line));
            }
        });
        if (atBottom || reset) { logBox.scrollTop = logBox.scrollHeight; }
    };

    const fetchLog = async (reset = false) => {
        try {
            const url = page.dataset.logUrl + (!reset && logOffset !== null ? '?offset=' + logOffset : '');
            const response = await fetch(url, {headers: {Accept: 'application/json'}, cache: 'no-store', credentials: 'same-origin'});
            const data = await response.json();
            appendLog(data.text || '', reset || logOffset === null);
            logOffset = data.offset;
        } catch (error) {
            appendLog('\n[impossible de lire le journal : ' + error.message + ']\n', false);
        }
    };

    const tick = async () => {
        let building = false;
        try {
            const response = await fetch(page.dataset.stateUrl, {headers: {Accept: 'application/json'}, cache: 'no-store', credentials: 'same-origin'});
            const data = await response.json();
            render(data);
            building = !!data.building;
        } catch (error) {
            meta.textContent = 'Serveur injoignable, nouvel essai… (' + error.message + ')';
            building = true;
        }
        await fetchLog(false);
        timer = window.setTimeout(tick, building ? 2000 : 15000);
    };

    const refresh = page.querySelector('[data-envp-log-refresh]');
    if (refresh) {
        refresh.addEventListener('click', () => { window.clearTimeout(timer); logOffset = null; fetchLog(true).then(tick); });
    }
    fetchLog(true).then(tick);
});
