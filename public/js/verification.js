/**
 * TEMPORAIRE — vérification du chantier « kit UI » (page /styleguide/verification).
 * Chaque étape : { id, titre, interdites: [classes qui ne doivent plus exister],
 *   requises: [classes qui doivent exister quelque part], manuel: [[texte, url?]] }.
 * À supprimer en fin de chantier.
 */
const CentralVerification = {
    config: { appName: 'CentralVerification', version: 'v1.0.0', debug: false },

    STEPS: [
        {
            id: 'e1', titre: 'Étape 1 — boutons et pastilles (.btn / .pill)',
            interdites: ['page-action', 'page-action-primary', 'page-action-neutral', 'page-action-info', 'page-action-danger',
                'page-action-success', 'page-action-warning', 'rc-btn', 'rc-linkbtn', 'rc-chip', 'rc-count', 'pv-badge',
                'pv-link-btn', 'pv-remove', 'entity-action'],
            requises: ['btn', 'btn-primary', 'pill'],
            feuilles: ['ui-kit.css'],
            manuel: [
                ['Styleguide › Boutons & actions et Pastilles : tous les exemples sont stylés', '/styleguide'],
                ['Relevé (composeur) : flèches ↑ ↓, « + », « ✕ », puce « Détail × n », compteurs, « Tout pointer »', '/releve/composer/1'],
                ['Prévisions : pastilles Certain/Probable/Estimé, liens « Ajuster » / « Ignorer »', '/prevision'],
                ['Formulaire de règle : le « ✕ » d’une tranche fonctionne', '/prevision/regle/new'],
                ['Portefeuille : boutons du haut, compteur rond des onglets, boutons en bas des cartes', '/portefeuille'],
                ['Listes (tiers, adresses, catégories, projets) : boutons de fin de ligne', '/tiers'],
            ],
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

    esc(s) { return String(s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c])); },

    row(ok, label, detail) {
        return '<div style="display:flex;gap:10px;align-items:baseline;padding:4px 0"><span class="pill ' + (ok ? 'pill-success' : 'pill-warning') + '">' + (ok ? 'OK' : 'À revoir') + '</span><span><strong>' + this.esc(label) + '</strong>' + (detail ? ' <span class="pv-range">' + detail + '</span>' : '') + '</span></div>';
    },

    async run(root) {
        const resBox = root.querySelector('[data-vf-results]'), undefBox = root.querySelector('[data-vf-undef]'), score = root.querySelector('[data-vf-score]');
        resBox.innerHTML = '<p class="pv-range">Analyse en cours…</p>';
        const pages = JSON.parse(root.dataset.pages), scripts = JSON.parse(root.dataset.scripts);
        const extra = root.querySelector('[data-vf-extra]').value.split('\n').map(s => s.trim()).filter(Boolean);
        try { localStorage.setItem('vf-extra', extra.join('\n')); } catch (e) {}
        extra.forEach(u => pages.push({ label: u, url: u }));

        const perSource = []; const errors = [];
        for (const p of pages) {
            try { perSource.push({ label: p.label, url: p.url, tokens: this.tokensOfHtml(await this.fetchText(p.url)), kind: 'page' }); }
            catch (e) { errors.push(p.label + ' (' + e.message + ')'); }
        }
        for (const s of scripts) {
            try { perSource.push({ label: s.split('/').pop().split('?')[0], url: s, tokens: this.tokensOfHtml((await this.fetchText(s)).replace(/\\"/g, '"')), kind: 'js' }); }
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
            total++; ok += !bad.length;
            html += this.row(!bad.length, 'Anciennes classes disparues (' + st.interdites.length + ' contrôlées)',
                bad.map(b => '<code>' + this.esc(b.c) + '</code> dans ' + this.esc(b.where.join(', '))).join(' · '));
        }
        html += '<h3 style="margin:14px 0 4px">Pages analysées</h3><p class="pv-range">' + perSource.filter(s => s.kind === 'page').length + ' page(s), ' + perSource.filter(s => s.kind === 'js').length + ' script(s)' + (errors.length ? ' — <strong>non lues :</strong> ' + this.esc(errors.join(' · ')) : '') + '</p>';
        resBox.innerHTML = html; score.textContent = ok + '/' + total;

        const undef = [...all].filter(c => !defined.has(c)).sort();
        const util = /^(sm:|md:|lg:)?(p[xytblr]?|m[xytblr]?|gap|w|h|text|font|rounded|flex|grid|items|justify|inline|block|hidden|row|col|space)-?/;
        undefBox.innerHTML = undef.length
            ? '<p>' + undef.map(c => '<code style="margin-right:8px;' + (util.test(c) && /-/.test(c) ? 'color:var(--danger)' : '') + '">' + this.esc(c) + '</code>').join('') + '</p><p class="pv-range">' + undef.length + ' classe(s) · en rouge : ressemblent à des utilitaires Tailwind.</p>'
            : '<p class="pv-range">Aucune.</p>';
    },

    renderManual(root) {
        const body = root.querySelector('[data-vf-manual-body]');
        let saved = {}; try { saved = JSON.parse(localStorage.getItem('vf-checks') || '{}'); } catch (e) {}
        body.innerHTML = this.STEPS.map(st => '<h3 style="margin:10px 0 4px">' + this.esc(st.titre) + '</h3>' +
            (st.manuel || []).map(([txt, url], i) => {
                const id = st.id + '-' + i;
                return '<label style="display:flex;gap:8px;align-items:baseline;padding:3px 0"><input type="checkbox" data-vf-check="' + id + '"' + (saved[id] ? ' checked' : '') + '><span>' + this.esc(txt) + (url ? ' — <a class="btn-link" href="' + url + '" target="_blank" rel="noopener">ouvrir</a>' : '') + '</span></label>';
            }).join('')).join('');
        body.addEventListener('change', e => {
            const c = e.target.closest('[data-vf-check]'); if (!c) return;
            saved[c.dataset.vfCheck] = c.checked; try { localStorage.setItem('vf-checks', JSON.stringify(saved)); } catch (e2) {}
        });
    },

    init() {
        const root = document.getElementById('vf'); if (!root) return;
        try { root.querySelector('[data-vf-extra]').value = localStorage.getItem('vf-extra') || ''; } catch (e) {}
        this.renderManual(root);
        root.querySelector('[data-vf-run]').addEventListener('click', () => this.run(root));
        this.log('init');
    },
};
document.addEventListener('DOMContentLoaded', () => CentralVerification.init());
