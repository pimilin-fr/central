/**
 * TEMPORAIRE — vérification du chantier « kit UI » (page /styleguide/verification).
 * Chaque étape : { id, titre, interdites: [classes qui ne doivent plus exister],
 *   requises: [classes qui doivent exister quelque part], manuel: [[texte, url?]] }.
 * À supprimer en fin de chantier.
 */
const CentralVerification = {
    config: { appName: 'CentralVerification', version: 'v2.5.0', debug: false },

    STEPS: [
        {
            id: 'e1', titre: 'Étape 1 — boutons et pastilles (.btn / .pill)',
            interdites: ['page-action', 'page-action-primary', 'page-action-neutral', 'page-action-info', 'page-action-danger',
                'page-action-success', 'page-action-warning', 'rc-btn', 'rc-linkbtn', 'rc-chip', 'rc-count', 'pv-badge',
                'pv-link-btn', 'pv-remove', 'entity-action'],
            requises: ['btn', 'btn-primary', 'pill'],
            feuilles: ['ui-kit.css'],
        },
        {
            id: 'e2', titre: 'Étape 2 — classes mortes supprimées',
            interdites: ['px-4', 'py-2', 'py-3', 'text-right', 'text-left', 'font-medium', 'rounded-full', 'inline-flex',
                'items-center', 'gap-2', 'gap-4', 'pr-3', 'py-1', 'text-xs', 'w-2', 'h-2', 'grid-cols-3', 'md:row-span-3',
                'rc-meta', 'rc-field-grow', 'categorie-page', 'categorie-toolbar', 'categorie-page-heading', 'form-grid-3',
                'form-grid-4', 'form-grid-5', 'form-span-2', 'form-span-3', 'form-section-grid-3', 'form-inline-btn',
                'form-legend', 'form-preview', 'geo-stack', 'is-grid', 'app-logo-core', 'theme-current-icon'],
            requises: ['pill-entity'],
        },
        {
            id: 'e3a', titre: 'Étape 3a — en-têtes de bloc (.block-head)',
            interdites: ['rc-panel-head', 'pv-month-head', 'pv-month-sums'],
            requises: ['block-head', 'block-head-lg', 'block-head-meta', 'is-bar', 'is-clickable'],
        },
        {
            id: 'e3b', titre: 'Étape 3b/3c — surfaces (.card, .card-bar, .card-inset, .tile)',
            interdites: [],
            requises: ['card', 'card-bar', 'card-inset', 'tile'],
            // chaque élément portant l'ancienne classe doit porter aussi la primitive
            couples: [['categorie-branch', 'card'], ['summary-card', 'card'], ['geo-card', 'card'], ['entity-card', 'card'],
                ['rc-panel', 'card'], ['group-bar', 'card-bar'], ['rc-recap', 'card-bar'], ['rc-actions', 'card-bar'],
                ['bulk-bar', 'card-inset'], ['inline-form', 'card-inset'], ['form-field-card', 'card-inset'],
                ['rc-line', 'tile'], ['rc-op', 'tile'], ['choice-card', 'tile']],
        },
    ],

    log(...a) { if (this.config.debug) console.log('[' + this.config.appName + '-' + this.config.version + ']', ...a); },

    async fetchText(url) {
        const r = await fetch(url, { credentials: 'same-origin' });
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.text();
    },

    tokensOfHtml(html) {
        const out = new Set();
        const re = /class\s*=\s*(["'])(.*?)\1/gs;
        let m;
        while ((m = re.exec(html))) {
            m[2].split(/\s+/).forEach(c => { if (/^[a-z][\w-]*(:[\w-]+)?$/i.test(c) && !c.includes('{') ) out.add(c); });
            // fragments Twig « {{ … }} » ignorés : on garde seulement les jetons simples
        }
        return out;
    },

    definedClasses() {
        const set = new Set(); const sheets = [];
        for (const s of document.styleSheets) {
            let rules; try { rules = s.cssRules; } catch (e) { continue; }
            sheets.push((s.href || '').split('/').pop().split('?')[0]);
            const walk = rs => { for (const r of rs) { if (r.selectorText) (r.selectorText.match(/\.[A-Za-z_][\w-]*/g) || []).forEach(x => set.add(x.slice(1))); if (r.cssRules) walk(r.cssRules); } };
            walk(rules);
        }
        return { set, sheets };
    },

    /** extrait de la 1re balise portant la classe (pour retrouver le fichier fautif) */
    snippet(sources, cls) {
        for (const s of sources) {
            const m = new RegExp('<[^<>]*class\\s*=\\s*(["\'])[^"\']*(?<![\\w-])' + cls.replace(/[-:]/g, '\\$&') + '(?![\\w-])[^"\']*\\1[^<>]*>', 's').exec(s.html);
            if (m) return m[0].replace(/\s+/g, ' ').slice(0, 140);
        }
        return '';
    },

    esc(s) { return String(s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c])); },

    row(ok, label, detail) {
        return '<div style="display:flex;gap:10px;align-items:baseline;padding:4px 0"><span class="pill ' + (ok ? 'pill-success' : 'pill-warning') + '">' + (ok ? 'OK' : 'À revoir') + '</span><span><strong>' + this.esc(label) + '</strong>' + (detail ? ' <span class="pv-range">' + detail + '</span>' : '') + '</span></div>';
    },

    async run(root) {
        const resBox = root.querySelector('[data-vf-results]'), undefBox = root.querySelector('[data-vf-undef]'), score = root.querySelector('[data-vf-score]');
        resBox.innerHTML = '<p class="pv-range">Analyse en cours…</p>';
        const pages = JSON.parse(root.dataset.pages), scripts = JSON.parse(root.dataset.scripts);

        const perSource = []; const errors = [];
        for (const p of pages) {
            try { const html = await this.fetchText(p.url); perSource.push({ label: p.label, url: p.url, html, tokens: this.tokensOfHtml(html), kind: 'page' }); }
            catch (e) { errors.push(p.label + ' (' + e.message + ')'); }
        }
        for (const s of scripts) {
            try { const txt = (await this.fetchText(s)).replace(/\\"/g, '"'); perSource.push({ label: s.split('/').pop().split('?')[0], url: s, html: txt, tokens: this.tokensOfHtml(txt), kind: 'js' }); }
            catch (e) { errors.push(s + ' (' + e.message + ')'); }
        }
        const all = new Set(); perSource.forEach(s => s.tokens.forEach(t => all.add(t)));
        const { set: defined, sheets } = this.definedClasses();

        let ok = 0, total = 0, html = '';
        for (const st of this.STEPS) {
            html += '<h3 style="margin:14px 0 4px">' + this.esc(st.titre) + '</h3>';
            (st.feuilles || []).forEach(f => { total++; const g = sheets.includes(f); ok += g; html += this.row(g, 'Feuille ' + f + ' chargée', g ? '' : 'absente : vérifier _head.html.twig'); });
            (st.requises || []).forEach(c => { total++; const g = all.has(c); ok += g; html += this.row(g, 'Classe .' + c + ' utilisée', g ? '' : 'introuvable dans les pages scannées'); });
            const bad = st.interdites.map(c => ({ c, where: perSource.filter(s => s.tokens.has(c)).map(s => s.label) })).filter(x => x.where.length);
            (st.couples || []).forEach(([old, prim]) => {
                const miss = [];
                perSource.filter(s => s.kind === 'page').forEach(s => {
                    const doc = new DOMParser().parseFromString(s.html, 'text/html');
                    const n = doc.querySelectorAll('.' + old + ':not(.' + prim + ')').length;
                    if (n) miss.push(s.label + ' ×' + n);
                });
                total++; ok += !miss.length;
                html += this.row(!miss.length, '.' + old + ' porte aussi .' + prim, miss.join(' · '));
            });
            total++; ok += !bad.length;
            html += this.row(!bad.length, 'Anciennes classes disparues (' + st.interdites.length + ' contrôlées)',
                bad.map(b => '<br><code>' + this.esc(b.c) + '</code> dans ' + this.esc(b.where.join(', ')) + ' <span style="opacity:.7">› ' + this.esc(this.snippet(perSource, b.c)) + '</span>').join(''));
        }
        html += '<h3 style="margin:14px 0 4px">Pages analysées</h3><p class="pv-range">' + perSource.filter(s => s.kind === 'page').length + ' page(s), ' + perSource.filter(s => s.kind === 'js').length + ' script(s)' + (errors.length ? ' — <strong>non lues :</strong> ' + this.esc(errors.join(' · ')) : '') + '</p>';
        // contrôle sur le DISQUE : fichier pas remplacé (→ liste) ou cache Twig périmé (→ rien sur disque)
        const allBad = [...new Set(this.STEPS.flatMap(s => s.interdites))];
        let disk = null, parasites = [];
        try { const j = await (await fetch(root.dataset.sources + '?classes=' + encodeURIComponent(allBad.join(',')), { credentials: 'same-origin' })).json(); disk = j.fichiers; parasites = j.parasites || []; } catch (e) {}
        const renderedBad = allBad.filter(c => perSource.some(s => s.tokens.has(c)));
        html += '<h3 style="margin:14px 0 4px">Sources sur le disque</h3>';
        if (disk === null) html += this.row(false, 'Lecture des fichiers impossible');
        else if (!disk.length) html += this.row(true, 'Aucune ancienne classe dans les gabarits ni les scripts du disque',
            renderedBad.length ? '— mais le HTML rendu en contient encore : <strong>cache Twig périmé</strong>. Lancez <code>php bin/console cache:clear</code> (ou videz <code>var/cache</code>).' : '');
        else {
            const byFile = {}; disk.forEach(h => { (byFile[h.fichier] = byFile[h.fichier] || new Set()).add(h.classe); });
            html += this.row(false, Object.keys(byFile).length + ' fichier(s) du disque contiennent encore d’anciennes classes (non remplacés ou à nettoyer)',
                '<br>' + Object.entries(byFile).map(([f, s]) => '<code>' + this.esc(f) + '</code> — ' + [...s].map(c => this.esc(c)).join(', ')).join('<br>'));
        }
        html += this.row(!parasites.length, 'Dossiers parasites (archives dézippées au mauvais endroit)',
            parasites.length ? parasites.map(p => '<code>' + this.esc(p) + '</code>').join(', ') + ' — à supprimer à la main (ils ne sont pas lus par l’application)' : 'aucun');
        resBox.innerHTML = html; score.textContent = ok + '/' + total;

        const undef = [...all].filter(c => !defined.has(c)).sort();
        const util = /^(sm:|md:|lg:)?(p[xytblr]?|m[xytblr]?|gap|w|h|text|font|rounded|flex|grid|items|justify|inline|block|hidden|row|col|space)-?/;
        undefBox.innerHTML = undef.length
            ? '<p>' + undef.map(c => '<code style="margin-right:8px;' + (util.test(c) && /-/.test(c) ? 'color:var(--danger)' : '') + '">' + this.esc(c) + '</code>').join('') + '</p><p class="pv-range">' + undef.length + ' classe(s) · en rouge : ressemblent à des utilitaires Tailwind.</p>'
            : '<p class="pv-range">Aucune.</p>';
    },

    initManual(root) {
        let saved = {}; try { saved = JSON.parse(localStorage.getItem('vf-checks') || '{}'); } catch (e) {}
        root.querySelectorAll('[data-vf-check]').forEach(c => { c.checked = !!saved[c.dataset.vfCheck]; });
        root.addEventListener('change', e => {
            const c = e.target.closest('[data-vf-check]'); if (!c) return;
            saved[c.dataset.vfCheck] = c.checked; try { localStorage.setItem('vf-checks', JSON.stringify(saved)); } catch (e2) {}
        });
    },

    init() {
        const root = document.getElementById('vf'); if (!root) return;
        this.initManual(root);
        root.querySelector('[data-vf-run]').addEventListener('click', () => this.run(root));
        this.log('init');
    },
};
document.addEventListener('DOMContentLoaded', () => CentralVerification.init());
