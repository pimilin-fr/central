/**
 * Module Prévisions (comme maps.js / releve.js) : formulaire de règle prévisionnelle.
 *  - montants par tranches (ajout / retrait de lignes)
 *  - saisie assistée : au choix d'un tiers, le formulaire se remplit d'après l'historique
 *    (dernière catégorie, portefeuille, projet, montant, jour, rythme) sans écraser ce qui est déjà saisi.
 */
const CentralPrevision = {
    config: {
        debug: true,
        version: 'v1.0.0',
        appName: 'Central-ModulePrevision'
    },

    log(...args) {
        if (this.config.debug) {
            console.log(`[${this.config.appName}]`, ...args);
        }
    },

    init() {
        const form = document.querySelector('[data-pv-form]');
        if (form) {
            this.log('Init prevision (formulaire)');
            this.tranches(form);
            this.assist(form);
        }
        const list = document.querySelector('[data-pv-suggestions]');
        if (list) {
            this.log('Init prevision (suggestions)');
            this.suggestions(list);
        }
    },

    /** Suggestions : « Masquer » est mémorisé dans ce navigateur uniquement (rien n'est écrit en base). */
    suggestions(list) {
        const KEY = 'central-prevision-masquees';
        const load = () => {
            try {
                return new Set(JSON.parse(localStorage.getItem(KEY) || '[]'));
            } catch (e) {
                return new Set();
            }
        };
        const save = set => {
            try {
                localStorage.setItem(KEY, JSON.stringify([...set]));
            } catch (e) {
            }
        };
        const hidden = load();
        const count = list.querySelector('[data-pv-count]');
        const refresh = () => {
            let visible = 0;
            list.querySelectorAll('[data-pv-suggestion]').forEach(row => {
                const off = hidden.has(row.dataset.pvSuggestion);
                row.classList.toggle('hidden', off);
                visible += off ? 0 : 1;
            });
            if (count) {
                count.textContent = visible;
            }
            const reset = list.querySelector('[data-pv-reset]');
            if (reset) {
                reset.classList.toggle('hidden', hidden.size === 0);
            }
        };
        list.addEventListener('click', e => {
            const hide = e.target.closest('[data-pv-hide]');
            if (hide) {
                hidden.add(hide.closest('[data-pv-suggestion]').dataset.pvSuggestion);
                save(hidden);
                refresh();
            }
            if (e.target.closest('[data-pv-reset]')) {
                hidden.clear();
                save(hidden);
                refresh();
            }
        });
        refresh();
    },

    /** Lignes « montant à partir d'une date ». */
    tranches(form) {
        const box = form.querySelector('[data-pv-tranches]');
        const add = form.querySelector('[data-pv-add]');
        if (!box || !add) {
            return;
        }

        add.addEventListener('click', () => {
            const index = parseInt(box.dataset.index, 10);
            box.dataset.index = index + 1;
            const row = document.createElement('div');
            row.className = 'pv-tranche';
            row.setAttribute('data-pv-tranche', '');
            row.innerHTML = box.dataset.prototype.replace(/__tranche__/g, index)
                    + '<button type="button" class="pv-remove" data-pv-remove title="Retirer ce montant">✕</button>';
            row.querySelectorAll('input').forEach(i => i.classList.add('modern-form-control'));
            row.querySelectorAll('label').forEach(l => l.classList.add('modern-form-label'));
            box.append(row);
        });

        box.addEventListener('click', e => {
            const button = e.target.closest('[data-pv-remove]');
            if (button) {
                button.closest('[data-pv-tranche]').remove();
            }
        });
    },

    /** Remplissage d'après l'historique du tiers choisi. */
    assist(form) {
        const msg = form.querySelector('[data-pv-assist]');
        const field = suffix => form.querySelector(`[name$="[${suffix}]"]`);
        const isEmpty = el => !el || el.value === '' || el.value === '0' || el.value === '0.00';

        // « jour » vaut 1 par défaut : on ne le remplace que s'il n'a pas été touché
        ['jour', 'frequence', 'estime'].forEach(name => {
            const el = field(name);
            if (el) {
                el.addEventListener('input', () => el.dataset.touched = '1');
                el.addEventListener('change', () => el.dataset.touched = '1');
            }
        });

        document.addEventListener('autocomplete:selected', async e => {
            const {input, item} = e.detail;
            if (!form.contains(input) || !input.name.endsWith('[tiers]') || !item || !item.id) {
                return;
            }

            let data;
            try {
                const response = await fetch(`/prevision/assist/tiers/${encodeURIComponent(item.id)}`, {headers: {Accept: 'application/json'}});
                data = await response.json();
            } catch (err) {
                this.log('assist indisponible', err);
                return;
            }
            if (!data || data.vide) {
                if (msg) {
                    msg.textContent = 'Aucune opération connue pour ce tiers : à saisir à la main.';
                }
                return;
            }

            const filled = [];
            const setAuto = (name, value, hiddenValue) => {
                const el = field(name);
                const hidden = field(name + '_id');
                if (el && hidden && isEmpty(hidden) && value) {
                    el.value = value;
                    hidden.value = hiddenValue;
                    filled.push(name);
                }
            };
            if (data.categorie) {
                setAuto('categorie', data.categorie.label, data.categorie.id);
            }
            if (data.projet) {
                setAuto('projet', data.projet.label, data.projet.id);
            }
            const ptf = field('portefeuille');
            if (ptf && isEmpty(ptf) && data.portefeuille) {
                ptf.value = String(data.portefeuille);
                filled.push('portefeuille');
            }
            const libelle = field('libelle');
            if (libelle && isEmpty(libelle)) {
                libelle.value = input.value;
            }
            const jour = field('jour');
            if (jour && !jour.dataset.touched && data.jour) {
                jour.value = data.jour;
                filled.push('jour');
            }
            const freq = field('frequence');
            if (freq && !freq.dataset.touched && data.frequence) {
                freq.value = data.frequence;
                filled.push('rythme');
            }
            const estime = field('estime');
            if (estime && !estimeTouched(estime) && data.frequence) {
                estime.checked = !!data.estime;
            }
            const first = form.querySelector('[data-pv-tranche] input[name$="[montant]"]');
            if (first && isEmpty(first) && data.montant) {
                first.value = Number(data.montant).toFixed(2);
                filled.push('montant');
                const row = first.closest('[data-pv-tranche]');
                const min = row.querySelector('input[name$="[montantMin]"]');
                const max = row.querySelector('input[name$="[montantMax]"]');
                if (data.estime && min && max && isEmpty(min) && isEmpty(max) && data.min != null) {
                    min.value = Number(data.min).toFixed(2);
                    max.value = Number(data.max).toFixed(2);
                }
            }

            if (msg) {
                msg.textContent = filled.length
                        ? `Rempli d'après l'historique (${filled.join(', ')})${data.resume ? ' — ' + data.resume : ''}. Vérifiez avant d'enregistrer.`
                        : 'Rien à compléter : les champs sont déjà renseignés.';
            }
            this.log('assist', data);
        });

        function estimeTouched(el) {
            return !!el.dataset.touched;
        }
    }
};

if (typeof module !== 'undefined' && module.exports) {
    module.exports = CentralPrevision;
}
