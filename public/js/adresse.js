/**
 * Module Adresse (comme maps.js / releve.js / prevision.js) : saisie assistée d'une adresse.
 *  1. L'utilisateur saisit l'adresse complète (et, en option, une position connue) puis lance la recherche
 *     (bouton, Ctrl+Entrée, ou en quittant le champ) : pas d'appel à chaque frappe (interdit par Nominatim).
 *  2. Le serveur renvoie des candidats ; le choix remplit les champs détaillés (selon le pays, voir AdresseParser.php).
 *  3. Les parents proches sont proposés niveau par niveau (quartier, ville, département, région, pays) :
 *     adresse existante, création (nom modifiable) ou aucun rattachement. Un parent existant arrête la chaîne.
 * Les choix partent dans le formulaire sous chain[niveau][choice|name] ; le serveur crée ce qui manque (AdresseAssistant::applyChain).
 */
const CentralAdresse = {
    config: {
        debug: true,
        version: 'v1.0.0',
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
        const chainBox = root.querySelector('[data-ad-chain]');
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

        const renderCandidates = (list) => {
            results.replaceChildren();
            if (list.length === 0) {
                say('Aucune adresse trouvée : précisez la saisie ou renseignez les champs à la main.', 'warn');
                results.hidden = true;
                return;
            }
            say(list.length === 1 ? '1 adresse trouvée.' : `${list.length} adresses trouvées : choisissez la bonne.`, 'ok');
            list.forEach((c, i) => {
                const b = document.createElement('button');
                b.type = 'button';
                b.className = 'ad-cand';
                const main = document.createElement('strong');
                main.textContent = c.adresse || c.label;
                const sub = document.createElement('small');
                sub.textContent = c.label + (c.lat !== null ? `  ·  ${Number(c.lat).toFixed(5)}, ${Number(c.lng).toFixed(5)}` : '');
                b.append(main, sub);
                b.addEventListener('click', () => pick(c, b));
                results.appendChild(b);
            });
            results.hidden = false;
            if (list.length === 1) {
                pick(list[0], results.firstChild);
            }
        };

        /* ---------- étape 2 : remplissage ---------- */

        const pick = (c, button) => {
            state.picked = c;
            results.querySelectorAll('.ad-cand').forEach(el => el.classList.toggle('is-picked', el === button));
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
            loadParents(c.ancestors || {}, c.lat, c.lng);
        };

        /* ---------- étape 3 : parents ---------- */

        const loadParents = async (anc, lat, lng) => {
            chainBox.replaceChildren();
            const wait = document.createElement('p');
            wait.className = 'form-hint';
            wait.textContent = 'Recherche des adresses parentes proches…';
            chainBox.appendChild(wait);
            try {
                const data = await getJson(root.dataset.adParentsUrl, {
                    quartier: anc.quartier, ville: anc.ville, departement: anc.departement,
                    region: anc.region, pays: anc.pays, lat, lng
                });
                renderChain(data.levels || []);
            } catch (error) {
                this.log('Parents en échec', error);
                chainBox.replaceChildren();
                if (chainHint) {
                    chainHint.textContent = 'Parents indisponibles : choisissez-en un à la main ci-dessous.';
                }
            }
        };

        const renderChain = (levels) => {
            chainBox.replaceChildren();
            if (chainHint) {
                chainHint.textContent = 'Pour chaque niveau : choisir une adresse existante, en créer une, ou ne pas rattacher. Une adresse existante suffit : ses propres parents sont déjà connus.';
            }
            const rows = [];
            levels.forEach(l => {
                const row = document.createElement('div');
                row.className = 'ad-level';
                const label = document.createElement('label');
                label.className = 'modern-form-label';
                label.textContent = l.label;

                const select = document.createElement('select');
                select.className = 'modern-form-control';
                select.name = `chain[${l.key}][choice]`;
                (l.options || []).forEach(o => {
                    const opt = document.createElement('option');
                    opt.value = 'id:' + o.id;
                    opt.textContent = o.label + (o.km !== null ? ` · ${String(o.km).replace('.', ',')} km` : '') + (o.exact ? ' ✓' : '');
                    select.appendChild(opt);
                });
                const optNew = document.createElement('option');
                optNew.value = 'new';
                optNew.textContent = '➕ Créer un nouveau ' + l.label.toLowerCase();
                const optNone = document.createElement('option');
                optNone.value = 'none';
                optNone.textContent = '— Ne pas rattacher à ce niveau —';
                select.append(optNew, optNone);
                select.value = [...select.options].some(o => o.value === l.choice) ? l.choice : 'none';

                const name = document.createElement('input');
                name.type = 'text';
                name.className = 'modern-form-control';
                name.name = `chain[${l.key}][name]`;
                name.value = l.name || '';
                name.placeholder = 'Nom du ' + l.label.toLowerCase() + ' à créer';

                row.append(label, select, name);
                chainBox.appendChild(row);
                rows.push({row, select, name});
            });

            const refresh = () => {
                let stop = false;
                rows.forEach(r => {
                    r.row.hidden = stop;
                    r.select.disabled = stop;
                    r.name.disabled = stop || r.select.value !== 'new';
                    r.name.hidden = r.select.value !== 'new';
                    r.row.classList.toggle('is-off', stop);
                    if (!stop && r.select.value.startsWith('id:')) {
                        stop = true;
                    }
                });
            };
            rows.forEach(r => r.select.addEventListener('change', refresh));
            refresh();
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

        // proposition de rattachement à la demande (adresse existante ou saisie manuelle des champs)
        root.querySelector('[data-ad-parents]').addEventListener('click', () => {
            const anc = state.picked ? state.picked.ancestors : {};
            const parsed = App.coords && val(f.forcee) ? App.coords.parse(val(f.forcee)) : null;
            loadParents({
                quartier: anc.quartier || '',
                ville: val(f.ville) || anc.ville,
                departement: anc.departement || '',
                region: anc.region || '',
                pays: val(f.pays) || anc.pays
            }, val(f.latitude) || (parsed ? parsed.lat : ''), val(f.longitude) || (parsed ? parsed.lon : ''));
        });
    }
};

if (typeof module !== 'undefined' && module.exports) {
    module.exports = CentralAdresse;
}
