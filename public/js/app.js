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

        CentralMaps.init();
    },

    /* =========================================================
     * GENERIC SEARCH
     * ========================================================= */

    search: {
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
