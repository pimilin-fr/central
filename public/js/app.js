const App = {

    config: {
        debug: true,
        version: "v1.4.1.4",
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
            //App.log('eventListener', event, callback);
        },

        emit(event, detail = {}) {
            document.dispatchEvent(
                    new CustomEvent(event, {
                        detail
                    })
                    );
            //App.log('event emit', event, detail)
        }

    },

    init() {
        this.log('🚀 Init app');
        this.log('config', this.config);

        this.autocomplete.init();
        this.tabs.init();
        this.bulk.init();
        this.loadMore.init();
        this.releves.init();
        this.depenses.init();
        this.selectAll.init();
        this.adresse.init();
        this.depenseForm.init();
    },

    /* =========================================================
     AUTOCOMPLETE
     ========================================================= */
    autocomplete: {
        init(scope = document) {
            App.log('Init autocomplete');

            scope.querySelectorAll('.autocomplete').forEach(input => {

                // 🔴 éviter double init
                if (input.dataset.initialized)
                    return;
                input.dataset.initialized = "1";

                const form = input.closest('form');
                if (!form)
                    return;

                const resultsBox = document.createElement('div');
                resultsBox.className = `
                absolute left-0 right-0 mt-1
                bg-white border border-gray-200
                rounded-md shadow-lg
                max-h-60 overflow-y-auto
                z-50 hidden
            `;

                const wrapper = document.createElement('div');
                wrapper.classList.add(
                        'relative',
                        'flex-1',
                        'min-w-0',
                        'w-full'
                        );

                input.parentNode.insertBefore(wrapper, input);
                wrapper.appendChild(input);
                wrapper.appendChild(resultsBox);

                input.classList.add('w-full');

                let debounce;

                function getHiddenField() {
                    const inputName = input.name;

                    if (inputName.includes('[')) {
                        const hiddenName = inputName.replace(/\]$/, '_id]');
                        return form.querySelector(`input[name="${hiddenName}"]`);
                    }

                    return form.querySelector(`input[name="${inputName}_id"]`);
                }

                input.addEventListener('input', function () {

                    const query = this.value.trim();
                    clearTimeout(debounce);

                    const hidden = getHiddenField();
                    if (hidden)
                        hidden.value = '';

                    if (query.length < 2) {
                        resultsBox.classList.add('hidden');
                        return;
                    }

                    debounce = setTimeout(async () => {

                        const endpoint = input.dataset.endpoint;

                        try {
                            const response = await fetch(`${endpoint}?q=${encodeURIComponent(query)}`);
                            const data = await response.json();

                            resultsBox.innerHTML = '';

                            if (!Array.isArray(data) || data.length === 0) {
                                resultsBox.innerHTML =
                                        '<div class="px-3 py-2 text-sm text-gray-400">Aucun résultat</div>';
                                resultsBox.classList.remove('hidden');
                                return;
                            }

                            data.forEach(item => {

                                const option = document.createElement('div');
                                option.className = 'px-3 py-2 hover:bg-gray-100 cursor-pointer';

                                const label = item.label ?? item.name ?? item.text ?? '';
                                option.textContent = label;

                                option.addEventListener('mousedown', (e) => {
                                    e.preventDefault();

                                    input.value = label;

                                    const hidden = getHiddenField();
                                    if (hidden)
                                        hidden.value = item.id;

                                    resultsBox.classList.add('hidden');

                                    App.events.emit('autocomplete:selected', {
                                        form,
                                        input,
                                        hidden,
                                        item
                                    });
                                });

                                resultsBox.appendChild(option);
                            });

                            resultsBox.classList.remove('hidden');

                        } catch (error) {
                            App.log('Autocomplete error', error);
                        }

                    }, 250);
                });

                document.addEventListener('click', function (e) {
                    if (!wrapper.contains(e.target)) {
                        resultsBox.classList.add('hidden');
                    }
                });

            });
        }
    },

    /* =========================================================
     TABS
     ========================================================= */
    tabs: {

        init() {
            App.log('Init tabs');

            document.querySelectorAll('[data-tabs]').forEach(container => {

                const buttons = container.querySelectorAll('.tab-button');
                const contents = container.querySelectorAll('.tab-content');
                const defaultTab = container.dataset.defaultTab || 'summary';

                function activateTab(tab) {

                    contents.forEach(c => c.classList.add('hidden'));

                    buttons.forEach(b => {
                        b.classList.remove('border-orange-500', 'text-orange-600');
                        b.classList.add('border-transparent', 'text-gray-500');
                    });

                    const content = container.querySelector('#tab-' + tab);
                    if (!content)
                        return;

                    content.classList.remove('hidden');

                    const btn = container.querySelector(`[data-tab="${tab}"]`);
                    if (btn) {
                        btn.classList.add('border-orange-500', 'text-orange-600');
                        btn.classList.remove('border-transparent', 'text-gray-500');
                    }

                    const url = new URL(window.location);
                    url.searchParams.set('tab', tab);
                    history.replaceState({}, '', url);
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
     BULK ACTIONS
     ========================================================= */
    bulk: {

        init() {
            App.log('Init bulk');

            const bulkForm = document.querySelector('[data-bulk-form]');
            if (!bulkForm)
                return;

            const actionSelect = bulkForm.querySelector('#bulk-action');
            if (!actionSelect)
                return;

            const fieldBlocks = bulkForm.querySelectorAll('[data-field]');

            function hideAllFields() {
                fieldBlocks.forEach(block => block.classList.add('hidden'));
            }

            function handleBulkChange() {

                hideAllFields();

                const selectedOption = actionSelect.options[actionSelect.selectedIndex];
                const fields = selectedOption.dataset.fields;

                if (!fields)
                    return;

                fields.split(',').forEach(fieldName => {
                    const block = bulkForm.querySelector(`[data-field="${fieldName.trim()}"]`);
                    if (block)
                        block.classList.remove('hidden');
                });
            }

            actionSelect.addEventListener('change', handleBulkChange);
            handleBulkChange();
        }
    },

    /* =========================================================
     LOAD MORE (version lien)
     ========================================================= */
    loadMore: {

        init() {
            App.log('Init load more');

            const link = document.getElementById('load-more');
            if (!link)
                return;

            const container = document.getElementById('releves-container');
            const endMessage = document.getElementById('load-more-end');

            link.addEventListener('click', async (e) => {
                e.preventDefault();

                const offset = parseInt(link.dataset.offset);
                const limit = parseInt(link.dataset.limit || 12);
                const url = link.dataset.url;

                link.innerText = 'Chargement...';
                link.classList.add('pointer-events-none', 'opacity-50');

                try {
                    const response = await fetch(`${url}?offset=${offset}`);
                    const html = await response.text();

                    if (!html.trim()) {
                        link.classList.add('hidden');
                        if (endMessage)
                            endMessage.classList.remove('hidden');
                        return;
                    }

                    container.insertAdjacentHTML('beforeend', html);

                    link.dataset.offset = offset + limit;
                    link.innerText = 'Voir plus';
                    link.classList.remove('pointer-events-none', 'opacity-50');

                } catch (e) {
                    App.log('Load more error', e);
                    link.innerText = 'Erreur...';
                }
            });
        }
    },

    /* =========================================================
     RELEVES (toggle)
     ========================================================= */
    releves: {

        init() {
            App.log('Init relevés');

            window.toggleGroup = function (id) {

                const all = document.querySelectorAll('[id^="group-"]');

                all.forEach(el => {
                    if (el.id !== 'group-' + id) {
                        el.classList.add('hidden');
                    }
                });

                const target = document.getElementById('group-' + id);
                if (target)
                    target.classList.toggle('hidden');
            };
        }
    },

    /* =========================================================
     DEPENSES (toggle détail)
     ========================================================= */
    depenses: {

        init() {
            App.log('Init depenses');

            document.querySelectorAll('.depense-row').forEach(row => {
                row.addEventListener('click', () => {
                    const id = row.dataset.id;
                    const detail = document.getElementById('detail-' + id);
                    if (detail)
                        detail.classList.toggle('hidden');
                });
            });
        }
    },

    /* =========================================================
     DEPENSES addresse
     ========================================================= */
    adresse: {

        init() {

            App.events.on('autocomplete:selected', async (e) => {

                const {input, form, item} = e.detail;

                if (!input.name.endsWith('[tiers]'))
                    return;

                const select = form.querySelector('[name$="[adresse]"]');
                const hiddenAdresseId = form.querySelector('[name$="[adresse_id]"]');

                if (!select)
                    return;

                select.innerHTML = '<option>Chargement...</option>';

                const response = await fetch(`/tiers/js/adresses/${item.id}`);
                const adresses = await response.json();

                select.innerHTML = '<option value="">Adresse</option>';

                adresses.forEach(adresse => {

                    const option = document.createElement('option');

                    option.value = adresse.id;
                    option.textContent = adresse.label;

                    if (adresse.principale) {
                        option.selected = true;
                    }

                    select.appendChild(option);

                });

                // 👇 synchronise adresse_id avec la sélection initiale (principale)
                if (hiddenAdresseId) {
                    hiddenAdresseId.value = select.value;
                }

                // 👇 synchronise adresse_id à chaque changement manuel
                select.addEventListener('change', () => {
                    if (hiddenAdresseId) {
                        hiddenAdresseId.value = select.value;
                    }
                });

            });

        }

    },

    /* =========================================================
     SELECT ALL
     ========================================================= */
    selectAll: {

        init() {
            App.log('Init select all');

            document.addEventListener('change', function (e) {

                if (!e.target.classList.contains('select-all'))
                    return;

                const table = e.target.closest('table');
                const checked = e.target.checked;

                table.querySelectorAll('.row-checkbox').forEach(cb => {
                    cb.checked = checked;
                    cb.addEventListener('click', e => e.stopPropagation());
                });

            });
        }
    },

    /* =========================================================
     ADD Depenses
     ========================================================= */
    depenseForm: {

        init(scope = document) {
            App.log('Init depenseForm');

            scope.querySelectorAll('#depense-form').forEach(form => {

                if (form.dataset.ajaxInit)
                    return;
                form.dataset.ajaxInit = "1";

                form.addEventListener('submit', async (e) => {
                    e.preventDefault();

                    const submitBtn = form.querySelector('button[type="submit"]');
                    if (submitBtn)
                        submitBtn.disabled = true;

                    const formData = new FormData(form);

                    try {
                        const response = await fetch(form.action, {
                            method: 'POST',
                            headers: {
                                'X-Requested-With': 'XMLHttpRequest'
                            },
                            body: formData
                        });

                        const contentType = response.headers.get('Content-Type') || '';

                        if (contentType.includes('application/json')) {
                            const data = await response.json();

                            if (data.success) {
                                App.events.emit('depense:created', data);
                                // Recharge la page en gardant l'onglet actif
                                const url = new URL(window.location);
                                url.searchParams.set('tab', 'addoperation');
                                window.location = url;
                                return;
                            }
                        }

                        // Formulaire invalide -> le serveur renvoie le HTML du formulaire (avec erreurs)
                        const html = await response.text();
                        const container = form.closest('#tab-addoperation') || form.parentNode;
                        container.innerHTML = html;

                        // ré-initialiser autocomplete + submit sur le nouveau markup injecté
                        App.autocomplete.init(container);
                        App.depenseForm.init(container);

                    } catch (err) {
                        App.log('depenseForm submit error', err);
                        if (submitBtn)
                            submitBtn.disabled = false;
                    }
                });
            });
        }
    }
};

document.addEventListener('DOMContentLoaded', () => App.init());