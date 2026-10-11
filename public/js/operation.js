/**
 * Module Opération (comme maps.js / releve.js / prevision.js) : tiroir « Nouvelle opération ».
 * Un seul formulaire pour le relevé, la fiche portefeuille et la fiche tiers.
 *  - ouverture : languette, ou tout élément [data-op-open] ; fermeture : [data-op-toggle], Échap
 *  - envoi en AJAX vers app_operation_new
 *      · relevé : la page ne se recharge pas, l'événement « operation:created » place la ligne dans « À pointer »
 *      · autres pages : rechargement (la liste est à jour), le tiroir se rouvre avec la même date
 *  - l'autocomplétion et le chargement des adresses du tiers viennent d'app.js (champs op[categorie], op[tiers], op[projet])
 */
const CentralOperation = {
    config: {
        debug: true,
        version: 'v1.1.1',
        appName: 'Central-ModuleOperation'
    },

    REOPEN_KEY: 'central-operation-reopen',

    log(...args) {
        if (this.config.debug) {
            console.log(`[${this.config.appName}]`, ...args);
        }
    },

    /**
     * Languettes des tiroirs (prévisions, opération) : placées juste sous l'en-tête de la page, donc sous les boutons d'action,
     * et qui restent sous la barre du haut quand on défile. Expose --handles-top (px, repère fenêtre) lu par prevision.css / operation.css.
     */
    placeHandles() {
        const root = document.documentElement;
        const place = () => {
            const bar = parseFloat(getComputedStyle(root).getPropertyValue('--topbar-height')) || 72;
            const heading = document.querySelector('.page-heading');
            const under = heading ? heading.getBoundingClientRect().bottom + 12 : bar + 16;
            root.style.setProperty('--handles-top', Math.round(Math.max(under, bar + 16)) + 'px');
        };
        place();
        window.addEventListener('scroll', place, {passive: true});
        window.addEventListener('resize', place);
    },

    init() {
        const drawer = document.querySelector('[data-op-drawer]');
        if (document.querySelector('[data-pv-drawer], [data-op-drawer]')) {
            this.placeHandles();
        }
        if (!drawer) {
            return;
        }
        // la languette « Opération » se place sous celle des prévisions quand elle existe
        if (document.querySelector('[data-pv-drawer]')) {
            drawer.style.setProperty('--op-offset', '5.4rem');
        }
        this.log('Init operation', this.config.version);

        const form = drawer.querySelector('[data-op-form]');
        const isReleve = drawer.dataset.opMode === 'releve';
        const field = name => form.querySelector('[name="op[' + name + ']"]');
        const error = form.querySelector('[data-op-error]');
        const direct = form.querySelector('[data-op-direct]');
        const oops = text => {
            error.textContent = text;
            error.hidden = !text;
        };

        const setOpen = (open, focus = true) => {
            if (open === drawer.classList.contains('is-open')) {
                return;
            }
            drawer.classList.toggle('is-open', open);
            drawer.querySelector('.op-drawer-handle').setAttribute('aria-expanded', open ? 'true' : 'false');
            if (open) {
                // un seul tiroir à la fois
                document.dispatchEvent(new CustomEvent('central:drawer-open', {detail: 'operation'}));
                if (isReleve) {
                    const main = document.querySelector('[data-releve-composer] input[name="date"]');
                    if (main && main.value) {
                        field('date').value = main.value;
                    }
                }
                oops('');
                if (focus) {
                    setTimeout(() => field('montant').focus(), 260);
                }
            }
        };

        document.addEventListener('central:drawer-open', event => {
            if (event.detail !== 'operation') {
                setOpen(false);
            }
        });
        document.addEventListener('click', event => {
            if (event.target.closest('[data-op-open]')) {
                event.preventDefault();
                setOpen(true);
            } else if (drawer.contains(event.target) && event.target.closest('[data-op-toggle]')) {
                setOpen(!drawer.classList.contains('is-open'));
            }
        });
        document.addEventListener('keydown', event => {
            if (event.key === 'Escape' && drawer.classList.contains('is-open')) {
                setOpen(false);
            }
        });

        // adresse choisie → champ caché lu par le serveur
        field('adresse').addEventListener('change', event => {
            field('adresse_id').value = event.target.value;
        });

        // après un rechargement suivant un ajout : le tiroir reste ouvert, même date et même portefeuille
        try {
            const raw = sessionStorage.getItem(this.REOPEN_KEY);
            if (raw) {
                sessionStorage.removeItem(this.REOPEN_KEY);
                const kept = JSON.parse(raw);
                if (kept.date) {
                    field('date').value = kept.date;
                }
                const ptf = form.querySelector('select[name="op[portefeuille_id]"]');
                if (ptf && kept.portefeuille) {
                    ptf.value = kept.portefeuille;
                }
                setOpen(true);
            }
        } catch (e) {
        }

        const reset = () => {
            ['categorie', 'projet'].forEach(name => {
                field(name).value = '';
                field(name + '_id').value = '';
            });
            ['montant', 'numCommande', 'note'].forEach(name => field(name).value = '');
            if (!field('tiers').readOnly) {
                field('tiers').value = '';
                field('tiers_id').value = '';
                field('adresse').innerHTML = '<option value="">Choisir d\'abord le tiers</option>';
                field('adresse_id').value = '';
            }
        };

        form.addEventListener('submit', async event => {
            event.preventDefault();
            oops('');
            if (!field('date').value || !field('montant').value.trim()) {
                return oops('Renseignez la date et le montant.');
            }
            if (!field('categorie_id').value) {
                return oops('Choisissez la catégorie dans la liste proposée.');
            }
            if (!field('tiers_id').value) {
                return oops('Choisissez le tiers dans la liste proposée.');
            }

            const body = new FormData(form);
            if (!field('adresse_id').value) {
                body.set('op[adresse_id]', field('adresse').value);
            }

            const submit = form.querySelector('button[type="submit"]');
            submit.disabled = true;
            try {
                const response = await fetch(form.action, {method: 'POST', body, headers: {'X-Requested-With': 'XMLHttpRequest'}});
                const data = await response.json();
                if (!data.ok) {
                    return oops(data.error || 'Enregistrement impossible.');
                }

                if (isReleve) {
                    document.dispatchEvent(new CustomEvent('operation:created', {
                        detail: {id: data.id, html: data.html, direct: !direct || direct.checked}
                    }));
                    reset();
                    field('montant').focus();
                } else {
                    const ptf = form.querySelector('select[name="op[portefeuille_id]"]');
                    try {
                        sessionStorage.setItem(this.REOPEN_KEY, JSON.stringify({date: field('date').value, portefeuille: ptf ? ptf.value : ''}));
                    } catch (e) {
                    }
                    window.location.reload();
                }
            } catch (err) {
                oops('Erreur réseau : ' + err.message);
            } finally {
                submit.disabled = false;
            }
        });
    }
};

if (typeof module !== 'undefined' && module.exports) {
    module.exports = CentralOperation;
}
