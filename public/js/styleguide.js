/**
 * Styleguide : accordéon (une thématique à la fois), copie du code, valeurs des variables du thème.
 * Module autonome, chargé uniquement par la page /styleguide.
 */
const CentralStyleguide = {

    config: {
        appName: 'Styleguide',
        version: 'v1.0.0',
        debug: false,
        storageKey: 'central-styleguide-open'
    },

    log(...args) {
        if (this.config.debug) {
            console.log('[' + this.config.appName + '-' + this.config.version + ']', ...args);
        }
    },

    init() {
        const root = document.querySelector('[data-sg-root]');
        if (!root) {
            return;
        }
        this.log('Init');
        this.accordion(root);
        this.copy(root);
        this.tokens();
    },

    /** Une seule thématique ouverte ; ancre #id et dernier choix mémorisé. */
    accordion(root) {
        const sections = [...root.querySelectorAll('[data-sg-section]')];
        const remember = id => {
            try { localStorage.setItem(this.config.storageKey, id || ''); } catch (e) { /* ignoré */ }
        };
        const recall = () => {
            try { return localStorage.getItem(this.config.storageKey) || ''; } catch (e) { return ''; }
        };

        const set = (section, open) => {
            const head = section.querySelector('[data-sg-toggle]');
            const panel = section.querySelector('.sg-acc-panel');
            head.setAttribute('aria-expanded', open ? 'true' : 'false');
            panel.hidden = !open;
            section.classList.toggle('is-open', open);
        };

        const openOnly = (id, scroll) => {
            sections.forEach(section => set(section, section.id === id));
            remember(id);
            if (id && scroll) {
                const target = document.getElementById(id);
                target && target.scrollIntoView({block: 'start', behavior: 'smooth'});
            }
        };

        sections.forEach(section => {
            const examples = section.querySelectorAll('.sg-example').length;
            const count = section.querySelector('[data-sg-count]');
            if (count) {
                count.textContent = examples ? examples + (examples > 1 ? ' exemples' : ' exemple') : '';
            }
            section.querySelector('[data-sg-toggle]').addEventListener('click', () => {
                const isOpen = section.classList.contains('is-open');
                openOnly(isOpen ? '' : section.id, !isOpen);
            });
        });

        const fromHash = decodeURIComponent(location.hash.replace('#', ''));
        const initial = sections.some(s => s.id === fromHash) ? fromHash : (recall() || sections[0]?.id || '');
        openOnly(sections.some(s => s.id === initial) ? initial : '', false);

        window.addEventListener('hashchange', () => {
            const id = decodeURIComponent(location.hash.replace('#', ''));
            if (sections.some(s => s.id === id)) {
                openOnly(id, true);
            }
        });
    },

    /** Bouton « Copier le code ». */
    copy(root) {
        root.addEventListener('click', async event => {
            const button = event.target.closest('[data-sg-copy]');
            if (!button) {
                return;
            }
            const code = button.closest('.sg-code')?.querySelector('code');
            if (!code) {
                return;
            }
            const text = code.textContent;
            let done = false;
            try {
                await navigator.clipboard.writeText(text);
                done = true;
            } catch (e) {
                const area = document.createElement('textarea');
                area.value = text;
                area.style.position = 'fixed';
                area.style.opacity = '0';
                document.body.appendChild(area);
                area.select();
                try { done = document.execCommand('copy'); } catch (err) { done = false; }
                area.remove();
            }
            const label = button.textContent;
            button.textContent = done ? 'Copié ✓' : 'Copie impossible';
            setTimeout(() => { button.textContent = label; }, 1600);
        });
    },

    /** Valeurs calculées des variables du thème + nom du thème courant (mis à jour à chaque changement). */
    tokens() {
        const paint = () => {
            const cs = getComputedStyle(document.documentElement);
            document.querySelectorAll('[data-sg-var]').forEach(el => {
                el.textContent = cs.getPropertyValue(el.dataset.sgVar).trim() || '— (non défini)';
            });
            const name = document.querySelector('[data-sg-theme]');
            if (name) {
                name.textContent = document.documentElement.dataset.theme || 'light-orange';
            }
        };
        paint();
        new MutationObserver(paint).observe(document.documentElement, {attributes: true});
    }
};

if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => CentralStyleguide.init());
    } else {
        CentralStyleguide.init();
    }
}
