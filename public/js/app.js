const App = {

    config: {
        debug: true,
        version: "v1.7.0",
        appName: "Central"
    },

    log(...args) {
        if (this.config.debug) {
            console.log("[" + this.config.appName + "-" + this.config.version + "]", ...args);
        }
    },

    events: {
        on(event, callback) {
            document.addEventListener(event, callback);
        },

        emit(event, detail = {}) {
            document.dispatchEvent(
                    new CustomEvent(event, {
                        detail
                    })
                    );
        }
    },

    init() {
        this.log('🚀 Init app');
        this.log('config', this.config);

        this.search.init();
        this.autocomplete.init();
        this.tabs.init();
        this.bulk.init();
        this.grouper.init();
        this.depenses.init();
        this.selectAll.init();
        this.adresse.init();
        this.depenseForm.init();
        this.entitySelect.init();
        this.liveForm.init();
        this.coords.init();

        CentralMaps.init();
    },

    /* =========================================================
     * GENERIC SEARCH
     * ========================================================= */

    search: {

        /**
         * Recherche et onglets : chaque onglet affiche le nombre de résultats
         * qu'il contient, les onglets sans résultat sont atténués et, si
         * l'onglet actif est vide alors qu'un autre contient des résultats,
         * on bascule automatiquement sur le premier onglet concerné.
         */
        syncTabs(target, query) {
            target.querySelectorAll('[data-tabs]').forEach(container => {
                const filtering = query !== '';
                let activeButton = null;
                let firstMatch = null;

                container.classList.toggle('is-filtering', filtering);

                container.querySelectorAll('.tab-button').forEach(button => {
                    const panel = container.querySelector('#tab-' + button.dataset.tab);
                    const badge = button.querySelector('.tab-badge');

                    if (!panel) {
                        return;
                    }

                    const total = panel.querySelectorAll('[data-search-item]').length;
                    const visible = panel.querySelectorAll('[data-search-item]:not([hidden])').length;

                    if (badge) {
                        if (badge.dataset.total === undefined) {
                            badge.dataset.total = badge.textContent.trim();
                        }

                        badge.textContent = filtering ? `${visible}/${total}` : badge.dataset.total;
                    }

                    button.classList.toggle('has-match', filtering && visible > 0);
                    button.classList.toggle('is-empty', filtering && visible === 0);

                    if (button.classList.contains('is-active')) {
                        activeButton = button;
                    }

                    if (filtering && visible > 0 && !firstMatch) {
                        firstMatch = button;
                    }
                });

                if (filtering && firstMatch && activeButton && activeButton.classList.contains('is-empty')) {
                    firstMatch.click();
                }
            });
        },

        init(scope = document) {

            App.log('Init generic search');

            scope.querySelectorAll('[data-search]').forEach(searchBox => {

                if (searchBox.dataset.initialized) {
                    return;
                }

                searchBox.dataset.initialized = '1';

                const input = searchBox.querySelector('[data-search-input]');

                if (!input) {
                    return;
                }

                const targetSelector = input.dataset.searchTarget;
                const target = targetSelector ? document.querySelector(targetSelector) : searchBox.parentElement;

                if (!target) {
                    return;
                }

                const itemSelector = input.dataset.searchItem || '[data-search-item]';
                const items = Array.from(target.querySelectorAll(itemSelector));
                const emptySelector = input.dataset.searchEmpty;
                const emptyState = emptySelector ? document.querySelector(emptySelector) : null;
                const countSelector = input.dataset.searchCount;
                const countElement = countSelector ? document.querySelector(countSelector) : null;
                const normalize = value => value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();

                const filter = () => {
                    const query = normalize(input.value);
                    let visibleCount = 0;

                    items.forEach(item => {
                        const value = normalize(item.dataset.searchValue || item.textContent || '');
                        const visible = (query === '' || value.includes(query));
                        item.hidden = !visible;
                        if (visible) {
                            visibleCount += 1;
                        }
                    });

                    // Les éléments filtrables peuvent être contenus dans des
                    // branches hiérarchiques. Une branche reste visible uniquement
                    // si elle contient au moins une feuille visible. Cela rend le
                    // composant de recherche réutilisable pour les arbres.
                    target.querySelectorAll('[data-search-group]').forEach(group => {
                        const visibleChildren = group.querySelectorAll(
                                `${itemSelector}:not([hidden])`
                                );
                        group.hidden = visibleChildren.length === 0;
                    });

                    if (emptyState) {
                        emptyState.hidden = visibleCount !== 0;
                    }

                    if (countElement) {
                        countElement.textContent = visibleCount;
                    }

                    App.events.emit('search:filtered', {
                        searchBox,
                        input,
                        target,
                        query,
                        visibleCount,
                        totalCount: items.length
                    });

                    App.search.syncTabs(target, query);
                };

                input.addEventListener('input', filter);

                filter();
            });
        }
    },

    /* =========================================================
     * AUTOCOMPLETE
     * ========================================================= */

    autocomplete: {
        init(scope = document) {
            App.log('Init autocomplete');

            // Aucune classe utilitaire (Tailwind) n'est générée ici :
            // tout le rendu passe par css/autocomplete.css (multi-thème).
            scope.querySelectorAll('.autocomplete').forEach(input => {

                if (input.dataset.initialized) {
                    return;
                }

                input.dataset.initialized = "1";
                const form = input.closest('form');

                if (!form) {
                    return;
                }

                const resultsBox = document.createElement('div');
                resultsBox.className = 'ac-results';
                resultsBox.setAttribute('role', 'listbox');

                const wrapper = document.createElement('div');
                wrapper.className = 'ac-wrapper';

                input.parentNode.insertBefore(wrapper, input);
                wrapper.appendChild(input);
                wrapper.appendChild(resultsBox);

                let debounce;
                let activeIndex = -1;

                const openBox = () => resultsBox.classList.add('is-open');

                const closeBox = () => {
                    resultsBox.classList.remove('is-open');
                    activeIndex = -1;
                };

                const options = () => resultsBox.querySelectorAll('.ac-option');

                const setActive = (index) => {
                    const list = options();

                    list.forEach(o => o.classList.remove('is-active'));

                    if (list.length === 0) {
                        activeIndex = -1;
                        return;
                    }

                    activeIndex = (index + list.length) % list.length;
                    list[activeIndex].classList.add('is-active');
                    list[activeIndex].scrollIntoView({block: 'nearest'});
                };

                function getHiddenField() {

                    const inputName = input.name;

                    if (inputName.includes('[')) {

                        const hiddenName = inputName.replace(/\]$/, '_id]');

                        return form.querySelector(`input[name="${hiddenName}"]`);
                    }

                    return form.querySelector(`input[name="${inputName}_id"]`);
                }

                function selectItem(item, label) {
                    input.value = label;

                    const hidden = getHiddenField();

                    if (hidden) {
                        hidden.value = item.id;
                    }

                    closeBox();

                    App.events.emit('autocomplete:selected', {
                        form,
                        input,
                        hidden,
                        item
                    });
                }

                input.addEventListener('input', function () {
                    const query = this.value.trim();
                    clearTimeout(debounce);
                    const hidden = getHiddenField();

                    if (hidden) {
                        hidden.value = '';
                    }

                    if (query.length < 2) {
                        closeBox();
                        return;
                    }

                    debounce = setTimeout(async () => {
                        const endpoint = input.dataset.endpoint;

                        try {
                            const response = await fetch(
                                    `${endpoint}?q=${encodeURIComponent(query)}`
                                    );

                            const data = await response.json();

                            resultsBox.innerHTML = '';
                            activeIndex = -1;

                            if (!Array.isArray(data) || data.length === 0) {
                                const empty = document.createElement('div');
                                empty.className = 'ac-empty';
                                empty.textContent = 'Aucun résultat';
                                resultsBox.appendChild(empty);
                                openBox();
                                return;
                            }

                            data.forEach(item => {
                                const label = item.label ?? item.name ?? item.text ?? '';
                                const option = document.createElement('div');

                                option.className = 'ac-option';
                                option.setAttribute('role', 'option');
                                option.textContent = label;

                                option.addEventListener('mousedown', (e) => {
                                    e.preventDefault();
                                    selectItem(item, label);
                                });

                                option._item = item;
                                option._label = label;

                                resultsBox.appendChild(option);
                            });

                            openBox();

                        } catch (error) {
                            App.log('Autocomplete error', error);
                        }

                    }, 250);
                });

                // Navigation clavier : ↑ ↓ Entrée Échap
                input.addEventListener('keydown', (e) => {
                    if (!resultsBox.classList.contains('is-open')) {
                        return;
                    }

                    if (e.key === 'ArrowDown') {
                        e.preventDefault();
                        setActive(activeIndex + 1);
                    } else if (e.key === 'ArrowUp') {
                        e.preventDefault();
                        setActive(activeIndex - 1);
                    } else if (e.key === 'Enter' && activeIndex >= 0) {
                        e.preventDefault();
                        const option = options()[activeIndex];
                        selectItem(option._item, option._label);
                    } else if (e.key === 'Escape') {
                        closeBox();
                    }
                });

                document.addEventListener('click', function (e) {
                    if (!wrapper.contains(e.target)) {
                        closeBox();
                    }
                });
            });
        }
    },

    /* =========================================================
     * TABS
     * ========================================================= */

    tabs: {

        init() {
            App.log('Init tabs');

            document.querySelectorAll('[data-tabs]')
                    .forEach(container => {
                        const buttons = container.querySelectorAll('.tab-button');
                        const contents = container.querySelectorAll('.tab-content');
                        const defaultTab = (container.dataset.defaultTab || 'summary');

                        function activateTab(tab) {

                            contents.forEach(c =>
                                c.classList.add('hidden')
                            );

                            buttons.forEach(b => {
                                const isActive = b.dataset.tab === tab;

                                b.classList.toggle('is-active', isActive);
                                b.setAttribute('aria-selected', isActive ? 'true' : 'false');
                            });

                            const content = container.querySelector('#tab-' + tab);

                            if (!content) {
                                return;
                            }

                            content.classList.remove('hidden');
                            const btn = container.querySelector(`[data-tab="${tab}"]`);

                            if (btn) {
                                btn.classList.add('is-active');
                                btn.setAttribute('aria-selected', 'true');
                            }

                            const url = new URL(window.location);

                            url.searchParams.set('tab', tab);

                            history.replaceState({}, '', url);

                            App.events.emit('tab:activated', {
                                container,
                                tab,
                                content
                            });
                        }

                        buttons.forEach(btn => {
                            btn.addEventListener('click', e => {
                                e.preventDefault();
                                activateTab(btn.dataset.tab);
                            });
                        });

                        activateTab(defaultTab);
                    });
        }
    },

    /* =========================================================
     * BULK ACTIONS
     * ========================================================= */

    bulk: {

        init() {

            App.log('Init bulk');

            const bulkForm = document.querySelector('[data-bulk-form]');

            if (!bulkForm) {
                return;
            }

            const actionSelect = bulkForm.querySelector('#bulk-action');

            if (!actionSelect) {
                return;
            }

            const bar = bulkForm.querySelector('[data-bulk-bar]') || bulkForm;
            const fieldBlocks = bulkForm.querySelectorAll('[data-field]');

            // Affichage piloté par l'attribut [hidden] et data-tone :
            // le JS ne manipule plus aucune classe de style.
            function handleBulkChange() {

                fieldBlocks.forEach(block => {
                    block.hidden = true;
                });

                const selectedOption = actionSelect.options[actionSelect.selectedIndex];

                bar.dataset.tone = selectedOption?.dataset.tone || '';

                const fields = selectedOption?.dataset.fields;

                if (!fields) {
                    return;
                }

                fields.split(',').forEach(fieldName => {
                    const block = bulkForm.querySelector(`[data-field="${fieldName.trim()}"]`);

                    if (block) {
                        block.hidden = false;
                    }
                });
            }

            actionSelect.addEventListener('change', handleBulkChange);

            handleBulkChange();
        }
    },
    /* =========================================================
     * RELEVES
     * ========================================================= */

    grouper: {
        init() {

            App.log('Init Grouper');

            window.toggleGroup =
                    function (id) {
                        const all = document.querySelectorAll('[id^="group-"]');

                        all.forEach(el => {
                            if (el.id !== 'group-' + id) {
                                el.classList.add('hidden');
                            }
                        });

                        const target = document.getElementById('group-' + id);

                        if (target) {
                            target.classList.toggle('hidden');
                        }
                    };
        }
    },

    /* =========================================================
     * DEPENSES
     * ========================================================= */

    depenses: {

        init() {

            App.log('Init depenses');

            document.querySelectorAll('.depense-row').forEach(row => {
                row.addEventListener('click', () => {

                    const id = row.dataset.id;

                    const detail = document.getElementById('detail-' + id);

                    if (detail) {
                        detail.classList.toggle('hidden');
                    }
                }
                );
            });
        }
    },

    /* =========================================================
     * DEPENSES ADRESSE
     * ========================================================= */

    adresse: {

        init() {

            App.events.on('autocomplete:selected', async (e) => {

                const {
                    input,
                    form,
                    item
                } = e.detail;

                if (!input.name.endsWith('[tiers]')) {
                    return;
                }

                if (!item || !item.id) {
                    return;
                }

                await loadTiersAdresses(form, item.id);
            });

            async function loadTiersAdresses(form, tiersId) {

                if (!form || !tiersId) {
                    return;
                }

                const adresseField = form.querySelector('[name$="[adresse]"]');
                const hiddenAdresseId = form.querySelector('[name$="[adresse_id]"]');

                if (!adresseField) {
                    return;
                }

                adresseField.innerHTML = '<option>Chargement...</option>';

                try {

                    const response = await fetch(`/tiers/js/adresses/${tiersId}`);

                    if (!response.ok) {
                        throw new Error(`Erreur HTTP ${response.status}`);
                    }

                    const adresses = await response.json();

                    adresseField.innerHTML = '<option value="">Adresse</option>';

                    adresses.forEach(adresse => {

                        const option = document.createElement('option');
                        option.value = adresse.id;
                        option.textContent = adresse.label;

                        if (adresse.principale) {
                            option.selected = true;
                        }

                        adresseField.appendChild(option);
                    });

                    if (hiddenAdresseId) {
                        hiddenAdresseId.value = adresseField.value;
                    }

                } catch (error) {

                    App.log('Erreur chargement adresses du Tiers', error);

                    adresseField.innerHTML = '<option value="">Impossible de charger les adresses</option>';

                    if (hiddenAdresseId) {
                        hiddenAdresseId.value = '';
                    }
                }
            }

            document.querySelectorAll('#depense-form').forEach(form => {
                const tiersInput = form.querySelector('[name$="[tiers]"]');

                const tiersIdInput = form.querySelector('[name$="[tiers_id]"]');

                if (!tiersInput || !tiersIdInput || !tiersIdInput.value) {
                    return;
                }

                loadTiersAdresses(form, tiersIdInput.value);
            });
        }

    },

    /* =========================================================
     * ENTITY SELECT — liseré + pastille pilotés par data-color
     * <select data-entity-select> ; <option data-color data-text-color>
     * Aucun style n'est généré côté PHP : on pose --entity-color.
     * ========================================================= */

    entitySelect: {
        init(scope = document) {
            scope.querySelectorAll('select[data-entity-select]').forEach(select => {
                if (select.dataset.entityInit) {
                    return;
                }

                select.dataset.entityInit = '1';

                const wrapper = document.createElement('div');
                wrapper.className = 'entity-select';
                select.parentNode.insertBefore(wrapper, select);
                wrapper.appendChild(select);

                const dot = document.createElement('span');
                dot.className = 'entity-select-dot';
                dot.setAttribute('aria-hidden', 'true');
                wrapper.insertBefore(dot, select);

                const sync = () => {
                    const option = select.options[select.selectedIndex];
                    const color = option ? option.dataset.color : '';

                    wrapper.classList.toggle('has-value', !!color);

                    if (color) {
                        wrapper.style.setProperty('--entity-color', color);
                        wrapper.style.setProperty('--entity-text', option.dataset.textColor || '#fff');
                    } else {
                        wrapper.style.removeProperty('--entity-color');
                        wrapper.style.removeProperty('--entity-text');
                    }
                };

                select.addEventListener('change', sync);
                sync();
            });
        }
    },

    /* =========================================================
     * LIVE FORM — aperçu en direct (générique)
     *   <form data-live-form>
     *   [data-live="prop"] ou "propA,propB" : texte = valeur du champ
     *        name$="[prop]" (select → libellé, case → Oui/Non, date → jj/mm/aaaa)
     *        valeurs calculées : address, city, coords, period
     *        si vide → contenu initial de l'élément (ou data-live-empty)
     *   [data-live-color="champ"] : pose --entity-color (select → data-color
     *        de l'option, input couleur → sa valeur)
     *   [data-fill-address] : bouton qui compose le champ « adresse »
     * ========================================================= */

    liveForm: {
        init() {
            document.querySelectorAll('form[data-live-form]').forEach(form => {
                const scope = form.closest('[data-live-scope]') || document;
                const field = name => form.querySelector(`[name$="[${name}]"]`);

                const fmtDate = raw => {
                    const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(raw || '');
                    return m ? `${m[3]}/${m[2]}/${m[1]}` : '';
                };

                const read = name => {
                    const el = field(name);

                    if (!el) {
                        return '';
                    }

                    if (el.tagName === 'SELECT') {
                        const option = el.options[el.selectedIndex];
                        return option && option.value ? option.textContent.trim() : '';
                    }

                    if (el.type === 'checkbox') {
                        return el.checked ? 'Oui' : 'Non';
                    }

                    if (el.type === 'date' || el.type === 'datetime-local') {
                        return fmtDate(el.value);
                    }

                    return (el.value || '').trim();
                };

                const compose = () => {
                    const street = ['prefix', 'num', 'bisTer', 'typeVoie', 'nomVoie'].map(read).filter(Boolean).join(' ');
                    const city = ['codePostal', 'ville', 'cedex'].map(read).filter(Boolean).join(' ');

                    return [street, city, read('pays')].filter(Boolean).join(', ');
                };

                const MONTHS = ['JAN', 'FÉV', 'MAR', 'AVR', 'MAI', 'JUN', 'JUI', 'AOÛ', 'SEP', 'OCT', 'NOV', 'DÉC'];
                const beginParts = () => {
                    const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(field('beginAt')?.value || '');
                    return m ? {year: m[1].slice(2), month: MONTHS[parseInt(m[2], 10) - 1]} : null;
                };

                const computed = {
                    beginMonth: () => beginParts()?.month || '',
                    beginYear: () => beginParts()?.year || '',
                    address: () => read('adresse') || compose(),
                    city: () => read('ville'),
                    coords: () => read('adresseForcee'),
                    period: () => {
                        const begin = read('beginAt');
                        const end = read('endAt');

                        return begin ? (end ? `${begin} → ${end}` : `Depuis le ${begin}`) : '';
                    }
                };

                const resolve = key => {
                    if (computed[key]) {
                        return computed[key]();
                    }

                    return read(key === 'type' && field('adresseType') ? 'adresseType' : key);
                };

                const colorOf = name => {
                    const el = field(name);

                    if (!el) {
                        return {};
                    }

                    if (el.tagName === 'SELECT') {
                        const option = el.options[el.selectedIndex];
                        return option ? {color: option.dataset.color, text: option.dataset.textColor} : {};
                    }

                    return /^#[0-9a-f]{3,8}$/i.test(el.value) ? {color: el.value} : {};
                };

                scope.querySelectorAll('[data-live]').forEach(el => {
                    el.dataset.liveDefault = el.dataset.liveEmpty ?? el.textContent.trim();
                });

                // Couleurs d'origine (rendues côté serveur) restaurées si le champ est vidé.
                scope.querySelectorAll('[data-live-color]').forEach(el => {
                    el.dataset.baseColor = el.style.getPropertyValue('--entity-color');
                    el.dataset.baseText = el.style.getPropertyValue('--entity-text');
                });

                const applyColor = (el, color, text) => {
                    if (color) {
                        el.style.setProperty('--entity-color', color);
                        el.style.setProperty('--entity-text', text || '#fff');
                    } else if (el.dataset.baseColor) {
                        el.style.setProperty('--entity-color', el.dataset.baseColor);
                        el.style.setProperty('--entity-text', el.dataset.baseText || '#fff');
                    } else {
                        el.style.removeProperty('--entity-color');
                        el.style.removeProperty('--entity-text');
                    }
                };

                const render = () => {
                    scope.querySelectorAll('[data-live]').forEach(el => {
                        const value = el.dataset.live.split(',')
                                .map(key => resolve(key.trim()))
                                .filter(Boolean)
                                .join(' · ');

                        el.textContent = value || el.dataset.liveDefault;
                    });

                    scope.querySelectorAll('[data-live-color]').forEach(el => {
                        const {color, text} = colorOf(el.dataset.liveColor);
                        applyColor(el, color, text);
                    });
                };

                form.addEventListener('input', render);
                form.addEventListener('change', render);

                // Champ « autocomplete » : l'élément choisi peut porter une couleur.
                App.events.on('autocomplete:selected', e => {
                    const item = e.detail.item || {};
                    const color = item.couleur || item.color;

                    if (e.detail.form === form && color) {
                        scope.querySelectorAll('[data-live-color]').forEach(el => applyColor(el, color, item.textColor));
                    }

                    render();
                });

                form.querySelectorAll('[data-fill-address]').forEach(button => {
                    button.addEventListener('click', () => {
                        const target = field('adresse');

                        if (target) {
                            target.value = compose();
                            target.dispatchEvent(new Event('input', {bubbles: true}));
                        }
                    });
                });

                render();
            });
        }
    },

    /* =========================================================
     * COORDS — validation douce "latitude, longitude"
     * <input data-coords> ; <… data-coords-state> reçoit .is-valid/.is-invalid
     * ========================================================= */

    coords: {
        parse(raw) {
            const match = raw.trim().match(/^(-?\d{1,2}(?:[.,]\d+)?)\s*[;,\s]\s*(-?\d{1,3}(?:[.,]\d+)?)$/);

            if (!match) {
                return null;
            }

            const lat = parseFloat(match[1].replace(',', '.'));
            const lon = parseFloat(match[2].replace(',', '.'));

            return (Math.abs(lat) <= 90 && Math.abs(lon) <= 180) ? {lat, lon} : null;
        },

        init() {
            document.querySelectorAll('input[data-coords]').forEach(input => {
                const box = input.closest('[data-coords-state]') || input.parentElement;
                const hint = box.querySelector('[data-coords-hint]');

                const check = () => {
                    const raw = input.value.trim();
                    const parsed = raw === '' ? null : App.coords.parse(raw);

                    box.classList.toggle('is-valid', !!parsed);
                    box.classList.toggle('is-invalid', raw !== '' && !parsed);

                    if (hint) {
                        hint.textContent = raw === ''
                                ? 'Laisser vide pour une géolocalisation automatique.'
                                : (parsed
                                        ? `Latitude ${parsed.lat} · Longitude ${parsed.lon}`
                                        : 'Format attendu : latitude, longitude (ex. 48.8566, 2.3522).');
                    }
                };

                input.addEventListener('input', check);
                check();
            });
        }
    },

    /* =========================================================
     * SELECT ALL
     * ========================================================= */

    selectAll: {

        init() {

            App.log('Init select all');

            document.addEventListener('change', function (e) {
                if (!e.target.classList.contains('select-all')) {
                    return;
                }

                const table = e.target.closest('table');
                const checked = e.target.checked;

                if (!table) {
                    return;
                }

                table.querySelectorAll('.row-checkbox').forEach(cb => {
                    cb.checked = checked;

                    cb.addEventListener('click', e => e.stopPropagation());
                });
            });
        }
    },

    /* =========================================================
     * AJOUT DEPENSE
     * ========================================================= */

    depenseForm: {

        init(scope = document) {

            App.log('Init depenseForm');

            scope.querySelectorAll('#depense-form').forEach(form => {

                if (form.dataset.ajaxInit) {
                    return;
                }

                form.dataset.ajaxInit = "1";

                form.addEventListener('submit', async (e) => {

                    e.preventDefault();

                    const submitBtn = form.querySelector('button[type="submit"]');

                    if (submitBtn) {
                        submitBtn.disabled = true;
                    }

                    const formData = new FormData(form);

                    try {

                        const response = await fetch(
                                form.action,
                                {
                                    method: 'POST',
                                    headers: {
                                        'X-Requested-With':
                                                'XMLHttpRequest'
                                    },
                                    body: formData
                                }
                        );

                        const contentType = (response.headers.get('Content-Type') || '');

                        if (contentType.includes('application/json')) {

                            const data = await response.json();

                            if (data.success) {

                                App.events.emit('depense:created', data);

                                const url = new URL(window.location);
                                url.searchParams.set('tab', 'addoperation');
                                window.location = url;

                                return;
                            }
                        }

                        const html = await response.text();

                        const container = form.closest('#tab-addoperation') || form.parentNode;

                        container.innerHTML = html;

                        App.autocomplete.init(container);
                        App.depenseForm.init(container);

                    } catch (err) {

                        App.log('depenseForm submit error', err);

                        if (submitBtn) {
                            submitBtn.disabled = false;
                        }
                    }
                }
                );
            });
        }
    }
};

document.addEventListener(
        'DOMContentLoaded',
        () => App.init()
);
