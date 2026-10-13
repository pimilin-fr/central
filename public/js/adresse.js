/**
 * Module Adresse (comme maps.js / releve.js / prevision.js) : saisie assistée d'une adresse.
 *  1. L'utilisateur saisit l'adresse complète (et, en option, une position connue) puis lance la recherche
 *     (bouton, Ctrl+Entrée, ou en quittant le champ) : pas d'appel à chaque frappe (interdit par Nominatim).
 *  2. Le serveur renvoie des candidats ; le choix remplit les champs détaillés (selon le pays, voir AdresseParser.php).
 *  3. Rattachement par « tiroirs » successifs (quartier → ville → département → région → pays → zone → planète) :
 *     chaque tiroir montre sur une carte les N adresses existantes les plus proches (la nouvelle position en couleur distincte) ;
 *     on en choisit une (la chaîne s'arrête : ses parents existent déjà), on en crée une nouvelle (saisie « comme une adresse »,
 *     puis le tiroir suivant s'ouvre autour de cette nouvelle position) ou on passe le niveau.
 * Le choix part dans le champ caché chain_json ; le serveur crée ce qui manque (AdresseAssistant::applyChain).
 */
const CentralAdresse = {
    LEVELS: [
        {key: 'quartier', label: 'Quartier', locate: true},
        {key: 'ville', label: 'Ville', locate: true},
        {key: 'departement', label: 'Département', locate: true},
        {key: 'region', label: 'Région', locate: true},
        {key: 'pays', label: 'Pays', locate: true},
        {key: 'zone', label: 'Zone géographique', locate: false},
        {key: 'planete', label: 'Planète', locate: false}
    ],

    config: {
        debug: true,
        version: 'v2.1.0',
        nearbyLimit: 5,
        appName: 'Central-ModuleAdresse'
    },

    log(...args) {
        if (this.config.debug) {
            console.log(`[${this.config.appName}]`, ...args);
        }
    },

    init(scope = document) {
        const root = scope.querySelector('[data-ad-assist]');
        if (!root || root.dataset.adReady) {
            return;
        }
        root.dataset.adReady = '1';
        this.log('Init adresse (saisie assistée)');
        this.assist(root);
    },

    assist(root) {
        const f = {};
        root.querySelectorAll('[data-ad-field]').forEach(el => { f[el.dataset.adField] = el; });
        const status = root.querySelector('[data-ad-status]');
        const results = root.querySelector('[data-ad-results]');
        const box = root.querySelector('[data-ad-drawers]');
        const jsonField = root.querySelector('[data-ad-chain-json]');
        const chainHint = root.querySelector('[data-ad-chain-hint]');
        const geoFlag = root.querySelector('[data-ad-geoflag]');
        const url = (path) => (window.App && App.url) ? App.url(path) : path;

        const state = {lastQuery: '', autoName: '', picked: null, seq: 0};

        const say = (text, kind = '') => {
            status.textContent = text || '';
            status.dataset.kind = kind;
        };
        const val = el => (el ? el.value.trim() : '');
        const setVal = (el, v) => {
            if (el) {
                el.value = v ?? '';
                el.dispatchEvent(new Event('input', {bubbles: true}));
            }
        };
        const norm = s => (s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').trim();

        const getJson = async (endpoint, params) => {
            const qs = new URLSearchParams();
            Object.entries(params).forEach(([k, v]) => { if (v !== null && v !== undefined && v !== '') { qs.set(k, v); } });
            const response = await fetch(`${url(endpoint)}?${qs}`, {headers: {Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest'}});
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
            return response.json();
        };

        /* ---------- étape 1 : recherche ---------- */

        const search = async () => {
            const q = val(f.adresse);
            const coords = val(f.forcee);
            if (q === '' && coords === '') {
                say('Saisissez une adresse (ou une position) à rechercher.', 'warn');
                return;
            }
            const seq = ++state.seq;
            state.lastQuery = q + '|' + coords;
            say('Recherche en cours…', 'busy');
            results.hidden = true;
            try {
                const data = await getJson(root.dataset.adLocateUrl, {q, coords});
                if (seq !== state.seq) {
                    return;
                }
                if (!data.ok) {
                    say(data.error || 'Recherche impossible.', 'error');
                    renderCandidates([]);
                    return;
                }
                renderCandidates(data.candidates || []);
            } catch (error) {
                this.log('Recherche en échec', error);
                say('Recherche indisponible : vous pouvez saisir les champs à la main.', 'error');
            }
        };

        /** Liste de candidats cliquables (rue ou lieu) dans `target` ; retourne les boutons. */
        const fillCandidates = (target, list, onPick, place = false) => {
            target.replaceChildren();
            const buttons = list.map(c => {
                const b = document.createElement('button');
                b.type = 'button';
                b.className = 'ad-cand';
                const main = document.createElement('strong');
                main.textContent = place ? c.name : (c.adresse || c.label);
                const sub = document.createElement('small');
                sub.textContent = c.label + (c.lat !== null && c.lat !== undefined ? `  ·  ${Number(c.lat).toFixed(5)}, ${Number(c.lng).toFixed(5)}` : '');
                b.append(main, sub);
                b.addEventListener('click', () => {
                    buttons.forEach(x => x.classList.toggle('is-picked', x === b));
                    onPick(c);
                });
                target.appendChild(b);
                return b;
            });
            target.hidden = list.length === 0;
            return buttons;
        };

        const renderCandidates = (list) => {
            if (list.length === 0) {
                results.replaceChildren();
                results.hidden = true;
                say('Aucune adresse trouvée : précisez la saisie ou renseignez les champs à la main.', 'warn');
                return;
            }
            say(list.length === 1 ? '1 adresse trouvée.' : `${list.length} adresses trouvées : choisissez la bonne.`, 'ok');
            const buttons = fillCandidates(results, list, pick);
            if (list.length === 1) {
                buttons[0].click();
            }
        };

        /* ---------- étape 2 : remplissage ---------- */

        const pick = (c) => {
            state.picked = c;
            setVal(f.adresse, c.adresse || f.adresse.value);
            Object.entries(c.fields || {}).forEach(([k, v]) => {
                if (f[k]) {
                    setVal(f[k], v);
                }
            });
            if (c.lat !== null && c.lng !== null) {
                setVal(f.latitude, String(c.lat));
                setVal(f.longitude, String(c.lng));
                geoFlag.value = '1';
            }
            // type « Rue » par défaut, nom proposé (sans écraser une saisie manuelle)
            if (f.type && f.type.value === '') {
                const rue = [...f.type.options].find(o => norm(o.textContent) === 'rue');
                if (rue) {
                    f.type.value = rue.value;
                    f.type.dispatchEvent(new Event('change', {bubbles: true}));
                }
            }
            if (f.name && (val(f.name) === '' || val(f.name) === state.autoName)) {
                setVal(f.name, c.name || '');
            }
            state.autoName = c.name || '';
            state.lastQuery = val(f.adresse) + '|' + val(f.forcee);
            say('Champs remplis : vérifiez-les, puis choisissez le rattachement.', 'ok');
            startChain({lat: c.lat, lng: c.lng, anc: c.ancestors || {}}, 'Nouvelle adresse');
        };

        /* ---------- étape 3 : tiroirs de rattachement ---------- */

        const LEVELS = this.LEVELS;
        const steps = [];
        const fmtKm = km => (km === null || km === undefined) ? '' : String(km).replace('.', ',') + ' km';
        const el = (tag, cls, text) => {
            const n = document.createElement(tag);
            if (cls) { n.className = cls; }
            if (text !== undefined) { n.textContent = text; }
            return n;
        };
        const hasPoint = o => o && typeof o.lat === 'number' && typeof o.lng === 'number';

        const syncJson = () => {
            const out = [];
            for (const s of steps) {
                if (s.choice && s.choice.mode !== 'skip') {
                    out.push(s.choice);
                    if (s.choice.mode === 'existing') { break; }
                }
            }
            jsonField.value = out.length ? JSON.stringify(out) : '';
        };

        const clearChain = () => {
            while (steps.length) { destroy(steps.pop()); }
            box.replaceChildren();
            jsonField.value = '';
        };

        const destroy = (s) => {
            if (s.map) { s.map.remove(); s.map = null; }
            if (s.el) { s.el.remove(); }
        };

        /** (Re)démarre la chaîne depuis la rue : ctx = position + noms des niveaux parents connus. */
        const startChain = (ctx, pinLabel) => {
            clearChain();
            if (chainHint) {
                chainHint.textContent = 'Choisissez le quartier sur la carte (ou créez-le) : la chaîne remonte ensuite niveau par niveau.';
            }
            openStep(0, ctx, hasPoint(ctx) ? {lat: ctx.lat, lng: ctx.lng, label: pinLabel} : null);
        };

        const openStep = (li, ctx, pin) => {
            const s = {li, level: LEVELS[li], ctx, pin, choice: null, el: null, map: null, items: [], markers: [], shapes: [], bounds: [], all: false, preselect: null, hasMore: false};
            steps.push(s);
            buildDrawer(s);
            box.appendChild(s.el);
            // le tiroir naît fermé puis s'ouvre (transition)
            requestAnimationFrame(() => requestAnimationFrame(() => setOpen(s, true)));
            loadNearby(s);
            syncJson();
            setTimeout(() => s.el.scrollIntoView({behavior: 'smooth', block: 'nearest'}), 200);
        };

        const setOpen = (s, open) => {
            s.el.classList.toggle('is-open', open);
            s.body.inert = !open;
            if (open && s.map) {
                setTimeout(() => { s.map.invalidateSize(); fit(s); }, 320);
            }
        };

        const summary = (s) => {
            const c = s.choice;
            if (!c) { return ''; }
            if (c.mode === 'existing') { return '✓ ' + c.label + (c.parent ? ' · ' + c.parent : '') + ' (existant)'; }
            if (c.mode === 'new') { return '✚ ' + c.name + ' (à créer)'; }
            return 'Aucun rattachement à ce niveau';
        };

        const finish = (s, choice) => {
            s.choice = choice;
            s.sum.textContent = summary(s);
            s.el.classList.add('is-done');
            s.el.dataset.mode = choice.mode;
            setOpen(s, false);
            // la suite : un parent existant termine la chaîne
            const next = s.li + 1;
            if (choice.mode !== 'existing' && next < LEVELS.length) {
                const ctx = choice.mode === 'new'
                        ? {lat: hasPoint(choice) ? choice.lat : s.ctx.lat, lng: hasPoint(choice) ? choice.lng : s.ctx.lng, anc: choice.anc || s.ctx.anc}
                        : s.ctx;
                const pin = choice.mode === 'new' && hasPoint(ctx) ? {lat: ctx.lat, lng: ctx.lng, label: choice.name + ' (nouveau)'} : s.pin;
                openStep(next, ctx, pin);
            }
            syncJson();
        };

        const reopen = (s) => {
            const i = steps.indexOf(s);
            while (steps.length > i + 1) { destroy(steps.pop()); }
            s.choice = null;
            s.sum.textContent = '';
            s.el.classList.remove('is-done');
            delete s.el.dataset.mode;
            s.create.hidden = true;
            s.actions.hidden = false;
            setOpen(s, true);
            syncJson();
        };

        const buildDrawer = (s) => {
            const key = s.level.key;
            const lower = s.level.label.toLowerCase();
            const sec = el('section', 'ad-drawer');
            sec.dataset.level = key;
            const head = el('button', 'ad-drawer-head');
            head.type = 'button';
            head.append(el('span', 'ad-drawer-level', s.level.label));
            s.sum = el('span', 'ad-drawer-sum');
            head.append(s.sum, el('span', 'ad-drawer-edit', 'Modifier'));
            head.addEventListener('click', () => { if (s.choice) { reopen(s); } });

            const body = el('div', 'ad-drawer-body');
            const inner = el('div', 'ad-drawer-inner');
            body.appendChild(inner);

            s.lead = el('p', 'form-hint', 'Recherche des ' + lower + 's existants les plus proches…');
            s.mapDiv = el('div', 'ad-map');
            s.list = el('ol', 'ad-list');
            s.actions = el('div', 'ad-actions');
            const bCreate = el('button', 'btn btn-primary btn-sm', '➕ Créer ' + (key === 'quartier' || key === 'departement' || key === 'pays' ? 'un nouveau ' : (key === 'planete' ? 'une nouvelle ' : 'une nouvelle ')) + lower);
            bCreate.type = 'button';
            bCreate.addEventListener('click', () => openCreate(s));
            const bSkip = el('button', 'btn btn-ghost btn-sm', 'Passer ce niveau');
            bSkip.type = 'button';
            bSkip.addEventListener('click', () => finish(s, {level: key, mode: 'skip'}));
            s.more = el('button', 'btn btn-ghost btn-sm', 'Voir des zones plus éloignées');
            s.more.type = 'button';
            s.more.hidden = true;
            s.more.addEventListener('click', () => { s.all = true; s.more.hidden = true; s.lead.textContent = 'Recherche élargie…'; loadNearby(s); });
            s.actions.append(bCreate, bSkip, s.more);

            s.create = buildCreate(s);
            s.create.hidden = true;
            inner.append(s.lead, s.mapDiv, s.list, s.actions, s.create);
            sec.append(head, body);
            s.el = sec;
            s.body = body;
            body.inert = true;
        };

        const loadNearby = async (s) => {
            try {
                const data = await getJson(root.dataset.adNearbyUrl, {
                    level: s.level.key, lat: s.ctx.lat, lng: s.ctx.lng,
                    name: (s.ctx.anc || {})[s.level.key] || '',
                    limit: s.all ? 12 : this.config.nearbyLimit, all: s.all ? 1 : ''
                });
                s.items = data.items || [];
                s.preselect = data.preselect || null;
                s.hasMore = !!data.hasMore;
            } catch (error) {
                this.log('Proches en échec', error);
                s.items = [];
                s.preselect = null;
                s.hasMore = false;
                s.lead.textContent = 'Liste indisponible : vous pouvez tout de même créer ce niveau ou le passer.';
            }
            renderItems(s);
        };

        const fmtDist = (m) => m < 1000 ? m + ' m' : (m / 1000).toFixed(m < 10000 ? 1 : 0).replace('.', ',') + ' km';

        const renderItems = (s) => {
            const lower = s.level.label.toLowerCase();
            const pre = s.items.find(it => it.id === s.preselect);
            s.list.replaceChildren();
            if (s.items.length === 0) {
                s.lead.textContent = `Aucun ${lower} existant à proximité : créez-le (ou passez ce niveau).`;
            } else if (pre) {
                s.lead.textContent = `La position est dans la zone « ${pre.label} » : présélectionné. Confirmez, ou choisissez / créez autre chose.`;
            } else {
                s.lead.textContent = `Aucun ${lower} existant ne contient cette position. Les plus proches (distance à la zone, repère rouge = nouvelle position) :`;
            }
            s.items.forEach((it, i) => {
                const li = el('li', 'ad-item' + (it.id === s.preselect ? ' is-sel' : ''));
                li.append(el('span', 'ad-rank', String(i + 1)));
                const nm = el('span', 'ad-name', it.label);
                if (it.parent) { nm.append(el('small', '', it.parent)); }
                li.append(nm);
                if (it.inside) {
                    li.append(el('span', 'ad-badge is-in', 'dans la zone'));
                } else if (it.dist !== null && it.dist !== undefined) {
                    li.append(el('span', 'ad-km', 'à ' + fmtDist(it.dist) + ' de la zone'));
                }
                if (it.exact) { li.append(el('span', 'ad-badge', 'même nom')); }
                const b = el('button', 'btn btn-xs ' + (it.id === s.preselect ? 'btn-primary' : 'btn-neutral'), it.id === s.preselect ? '✓ Confirmer' : 'Choisir');
                b.type = 'button';
                b.addEventListener('click', () => choose(s, it));
                li.append(b);
                li.addEventListener('mouseenter', () => hilite(s, i, true));
                li.addEventListener('mouseleave', () => hilite(s, i, false));
                s.list.appendChild(li);
            });
            s.more.hidden = !s.hasMore || s.all;
            drawMap(s);
        };

        const choose = (s, it) => finish(s, {level: s.level.key, mode: 'existing', id: it.id, label: it.label, parent: it.parent || ''});

        const hilite = (s, i, on) => {
            const m = s.markers[i];
            if (m && m.getElement()) { m.getElement().classList.toggle('is-hot', on); }
            const z = s.shapes[i];
            if (z && z.setStyle) { z.setStyle({weight: on ? 4 : 2}); }
        };

        const drawMap = (s) => {
            const pts = s.items.filter(hasPoint);
            if (typeof L === 'undefined' || (!hasPoint(s.pin) && pts.length === 0)) {
                s.mapDiv.hidden = true;
                return;
            }
            s.mapDiv.hidden = false;
            if (s.map) { s.map.remove(); }
            const tiles = (window.CentralMaps && CentralMaps.config.tileLayer) || {url: 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', options: {maxZoom: 19}};
            const color = (window.CentralMaps && CentralMaps.themeColor) ? CentralMaps.themeColor('--accent') : '#e67e22';
            s.map = L.map(s.mapDiv, {scrollWheelZoom: false});
            L.tileLayer(tiles.url, tiles.options).addTo(s.map);
            s.bounds = [];
            // zones : enveloppe des adresses situées en dessous (+ marge), comme sur la carte de visualisation
            s.shapes = s.items.map((it) => {
                const sh = it.shape;
                if (!sh) { return null; }
                const style = {color, weight: 2, fillColor: color, fillOpacity: it.inside ? 0.3 : 0.12, dashArray: it.inside ? null : '5 4'};
                let layer;
                if (sh.type === 'polygon') {
                    layer = L.polygon(sh.pts, style).addTo(s.map);
                    sh.pts.forEach(pt => s.bounds.push(pt));
                } else {
                    layer = L.circle(sh.center, Object.assign({radius: sh.r}, style)).addTo(s.map);
                    s.bounds.push(sh.center);
                }
                layer.on('click', () => choose(s, it));
                return layer;
            });
            s.markers = s.items.map((it, i) => {
                if (!hasPoint(it)) { return null; }
                s.bounds.push([it.lat, it.lng]);
                const m = L.marker([it.lat, it.lng], {icon: L.divIcon({className: 'ad-mk' + (it.id === s.preselect ? ' is-sel' : ''), html: `<span>${i + 1}</span>`, iconSize: [26, 26], iconAnchor: [13, 13]})}).addTo(s.map);
                m.bindTooltip(it.label + (it.inside ? ' · dans la zone' : (it.dist !== null && it.dist !== undefined ? ' · à ' + fmtDist(it.dist) : '')), {direction: 'top', offset: [0, -12]});
                m.on('click', () => choose(s, it));
                return m;
            });
            if (hasPoint(s.pin)) {
                s.bounds.push([s.pin.lat, s.pin.lng]);
                // trait pointillé jusqu'au point le plus proche de chaque zone voisine (non englobante)
                s.items.forEach((it) => {
                    if (!it.inside && it.nearest) {
                        L.polyline([[s.pin.lat, s.pin.lng], it.nearest], {color: '#c0392b', weight: 1.5, dashArray: '3 5', opacity: .8}).addTo(s.map);
                    }
                });
                L.marker([s.pin.lat, s.pin.lng], {zIndexOffset: 1000, icon: L.divIcon({className: 'ad-pin-wrap', html: '<span class="ad-pin"></span>', iconSize: [30, 30], iconAnchor: [15, 28]})})
                        .addTo(s.map).bindTooltip(s.pin.label, {permanent: true, direction: 'right', offset: [14, -14], className: 'ad-pin-tip'});
            }
            fit(s);
        };

        const fit = (s) => {
            if (!s.map || !s.bounds) { return; }
            if (s.bounds.length === 1) { s.map.setView(s.bounds[0], 14); } else if (s.bounds.length > 1) { s.map.fitBounds(s.bounds, {padding: [34, 34], maxZoom: 16}); }
        };

        /* création d'un niveau : saisie « comme une adresse » */

        const hintFor = (s) => {
            const anc = s.ctx.anc || {};
            const keys = ['quartier', 'ville', 'departement', 'region', 'pays'];
            const name = anc[s.level.key];
            if (!name) { return ''; }
            const above = keys.slice(keys.indexOf(s.level.key) + 1).map(k => anc[k]).find(Boolean);
            const parts = [name];
            [above, anc.pays].forEach(p => { if (p && !parts.some(x => norm(x) === norm(p))) { parts.push(p); } });
            return parts.join(', ');
        };

        const buildCreate = (s) => {
            const lower = s.level.label.toLowerCase();
            const box2 = el('div', 'ad-create');
            s.draft = null;
            if (s.level.locate) {
                const lab = el('label', 'modern-form-label', 'Adresse du ' + lower + ' (comme une adresse)');
                s.cAddr = el('input', 'modern-form-control');
                s.cAddr.type = 'text';
                s.cAddr.placeholder = 'Ex. ' + (s.level.key === 'pays' ? 'France' : 'Nom, ville, pays');
                s.cAddr.autocomplete = 'off';
                const row = el('div', 'ad-actions');
                const bs = el('button', 'btn btn-primary btn-sm', 'Rechercher');
                bs.type = 'button';
                s.cStatus = el('span', 'ad-status');
                row.append(bs, s.cStatus);
                s.cRes = el('div', 'ad-cands');
                s.cRes.hidden = true;
                const run = async () => {
                    const q = s.cAddr.value.trim();
                    if (!q) { s.cStatus.textContent = 'Saisissez un nom ou une adresse.'; return; }
                    s.cStatus.textContent = 'Recherche en cours…';
                    try {
                        const data = await getJson(root.dataset.adLocateUrl, {q, level: s.level.key});
                        const list = data.ok ? (data.candidates || []) : [];
                        s.cStatus.textContent = data.ok ? (list.length ? 'Choisissez le bon lieu :' : 'Aucun résultat : vous pouvez saisir le nom à la main.') : (data.error || 'Recherche impossible.');
                        const btns = fillCandidates(s.cRes, list, (c) => setDraft(s, c), true);
                        if (list.length === 1) { btns[0].click(); }
                    } catch (error) {
                        s.cStatus.textContent = 'Recherche indisponible : saisissez le nom à la main.';
                    }
                };
                bs.addEventListener('click', run);
                s.cAddr.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); run(); } });
                s.runCreateSearch = run;
                box2.append(lab, s.cAddr, row, s.cRes);
            }
            const labN = el('label', 'modern-form-label', 'Nom du ' + lower + ' à créer');
            s.cName = el('input', 'modern-form-control');
            s.cName.type = 'text';
            s.cPos = el('p', 'form-hint');
            const row2 = el('div', 'ad-actions');
            const ok = el('button', 'btn btn-primary btn-sm', 'Valider');
            ok.type = 'button';
            const cancel = el('button', 'btn btn-ghost btn-sm', 'Annuler');
            cancel.type = 'button';
            row2.append(ok, cancel);
            ok.addEventListener('click', () => {
                const name = s.cName.value.trim();
                if (!name) { s.cName.focus(); s.cPos.textContent = 'Le nom est obligatoire.'; return; }
                const d = s.draft || {};
                const anc = s.ctx.anc || {};
                finish(s, {
                    level: s.level.key, mode: 'new', name,
                    adresse: (d.adresse && norm(d.name) === norm(name)) ? d.adresse : name,
                    lat: hasPoint(d) ? d.lat : (hasPoint(s.ctx) ? s.ctx.lat : null),
                    lng: hasPoint(d) ? d.lng : (hasPoint(s.ctx) ? s.ctx.lng : null),
                    ville: (d.fields && d.fields.ville) || anc.ville || '',
                    pays: (d.fields && d.fields.pays) || anc.pays || '',
                    anc: d.ancestors || null
                });
            });
            cancel.addEventListener('click', () => { s.create.hidden = true; s.actions.hidden = false; });
            box2.append(labN, s.cName, s.cPos, row2);
            return box2;
        };

        const setDraft = (s, c) => {
            s.draft = c;
            s.cName.value = c.name || '';
            s.cPos.textContent = hasPoint(c) ? `Position : ${c.lat.toFixed(5)}, ${c.lng.toFixed(5)}` : '';
            // la nouvelle position apparaît sur la carte du tiroir (repère distinct)
            if (hasPoint(c)) {
                s.pin = {lat: c.lat, lng: c.lng, label: c.name + ' (nouveau)'};
                s.ctx = Object.assign({}, s.ctx, {lat: s.ctx.lat, lng: s.ctx.lng});
                drawMap(s);
            }
        };

        const openCreate = (s) => {
            s.actions.hidden = true;
            s.create.hidden = false;
            if (s.level.locate && s.cAddr.value === '') {
                s.cAddr.value = hintFor(s);
                if (s.cAddr.value) { s.runCreateSearch(); } else { s.cAddr.focus(); }
            } else {
                s.cName.focus();
            }
        };

        /* ---------- déclencheurs ---------- */

        root.querySelector('[data-ad-search]').addEventListener('click', () => {
            const key = val(f.adresse) + '|' + val(f.forcee);
            if (status.dataset.kind !== 'busy' && !(state.picked && key === state.lastQuery)) {
                search();
            }
        });
        f.adresse.addEventListener('keydown', e => {
            if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
                e.preventDefault();
                search();
            }
        });
        if (f.forcee) {
            f.forcee.addEventListener('keydown', e => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    search();
                }
            });
        }
        // en quittant le champ après une saisie « complète » nouvelle : recherche automatique (une fois)
        f.adresse.addEventListener('blur', () => {
            const q = val(f.adresse);
            if (root.dataset.adEdit !== '1' && q.length >= 8 && q + '|' + val(f.forcee) !== state.lastQuery && !state.picked) {
                search();
            }
        });
        // toute retouche manuelle de l'adresse invalide la position issue de l'assistant
        f.adresse.addEventListener('input', () => {
            if (state.picked && f.adresse.value !== state.picked.adresse) {
                geoFlag.value = '';
            }
        });

        // proposition de rattachement à la demande (modification d'une adresse existante, ou champs saisis à la main)
        root.querySelector('[data-ad-parents]').addEventListener('click', () => {
            const anc = Object.assign({}, state.picked ? state.picked.ancestors : {});
            anc.ville = val(f.ville) || anc.ville || null;
            anc.pays = val(f.pays) || anc.pays || null;
            const parsed = App.coords && val(f.forcee) ? App.coords.parse(val(f.forcee)) : null;
            const lat = val(f.latitude) !== '' ? parseFloat(val(f.latitude)) : (parsed ? parsed.lat : null);
            const lng = val(f.longitude) !== '' ? parseFloat(val(f.longitude)) : (parsed ? parsed.lon : null);
            startChain({lat: Number.isFinite(lat) ? lat : null, lng: Number.isFinite(lng) ? lng : null, anc}, 'Cette adresse');
        });
    }
};

if (typeof module !== 'undefined' && module.exports) {
    module.exports = CentralAdresse;
}
