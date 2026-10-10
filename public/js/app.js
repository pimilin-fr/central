const App = {

    config: {
        debug: true,
        version: "v1.8.0",
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
        this.releveComposer.init();
        this.depenses.init();
        this.selectAll.init();
        this.adresse.init();
        this.depenseForm.init();
        this.entitySelect.init();
        this.colorPicker.init();
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
     * FAIRE LE RELEVÉ (templates/releve/composer.html.twig)
     * Deux listes : « À pointer » (opérations hors relevé) et « Relevé » (lignes du relevé de compte, dans l'ordre).
     * Une LIGNE du relevé = une opération, ou un « détail » : plusieurs opérations du même tiers et de la même
     * date pointées ensemble (ex. une commande de 50 € éclatée en plusieurs catégories) ; elles partagent le rang.
     * Chaque opération d'une ligne porte un champ lines[i][] (i = rang − 1) : l'ordre du DOM EST l'ordre envoyé.
     * ========================================================= */

    releveComposer: {
        init() {
            const root = document.querySelector('[data-releve-composer]');
            if (!root) {
                return;
            }

            App.log('Init releveComposer');

            const pool = root.querySelector('[data-rc-pool]');
            const list = root.querySelector('[data-rc-list]');
            const startInput = root.querySelector('[data-rc-start]');
            const filterInput = root.querySelector('[data-rc-filter]');
            const message = root.querySelector('[data-rc-msg]');
            const isNew = root.hasAttribute('data-rc-new');
            const money = new Intl.NumberFormat('fr-FR', {minimumFractionDigits: 2, maximumFractionDigits: 2});

            let cursor = null;   // ligne après laquelle iront les prochaines lignes (null = fin)
            let dirty = false;
            let drag = null;     // {part, line} en cours de glissement
            let msgTimer = null;
            let hideDone = false; // masquer les lignes déjà pointées

            const esc = text => String(text ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
            const signed = value => (value < 0 ? '− ' : '+ ') + money.format(Math.abs(value)) + ' €';
            const lines = () => [...list.querySelectorAll(':scope > [data-rc-line]')];
            const partsOf = line => [...line.querySelectorAll(':scope > [data-rc-parts] > [data-rc-op]')];
            const poolRows = () => [...pool.querySelectorAll(':scope > [data-rc-op]')];
            const sortKey = li => li.dataset.date + String(li.dataset.id).padStart(12, '0');
            const groupKey = li => li.dataset.tiers + '|' + li.dataset.date;
            const lineKey = line => sortKey(partsOf(line)[0]);
            const setInput = (li, enabled) => {
                const input = li.querySelector('[data-rc-input]');
                if (input) {
                    input.disabled = !enabled;
                }
            };

            const say = (text, tone = 'danger') => {
                if (!message) {
                    return;
                }
                message.textContent = text;
                message.dataset.tone = tone;
                message.hidden = false;
                clearTimeout(msgTimer);
                msgTimer = setTimeout(() => { message.hidden = true; }, 6000);
            };

            /** Les opérations peuvent-elles former UN détail ? (même date ; les tiers peuvent différer) */
            const sameGroup = parts => parts.length > 0 && parts.every(part => part.dataset.date === parts[0].dataset.date);
            const NOT_SAME = 'Un détail regroupe des opérations de la MÊME date.';

            /* « pointé » = ligne vérifiée sur le relevé de compte. Jamais envoyé au serveur : gardé dans ce navigateur. */
            const doneKey = 'central-releve-pointe:' + (root.dataset.rcPtf || '0');
            const loadDone = () => {
                try {
                    return new Set(JSON.parse(window.localStorage.getItem(doneKey) || '[]').map(String));
                } catch (error) {
                    return new Set();
                }
            };
            const saveDone = () => {
                try {
                    const set = loadDone();
                    lines().forEach(line => partsOf(line).forEach(p => (line.dataset.done === '1' ? set.add(p.dataset.id) : set.delete(p.dataset.id))));
                    poolRows().forEach(row => set.delete(row.dataset.id));
                    window.localStorage.setItem(doneKey, JSON.stringify([...set]));
                } catch (error) {
                    /* stockage indisponible : le pointage reste valable le temps de la page */
                }
            };
            const isDone = line => line.dataset.done === '1';
            const setDone = (line, value = true) => { line.dataset.done = value ? '1' : '0'; };

            const newLine = (parts, done = true) => {
                const line = document.createElement('li');
                line.className = 'rc-line';
                line.setAttribute('data-rc-line', '');
                line.draggable = true;
                setDone(line, done); // une ligne placée à la main est considérée pointée
                line.innerHTML = '<div class="rc-line-head" data-rc-head></div><ul class="rc-parts" data-rc-parts></ul>';
                const ul = line.querySelector('[data-rc-parts]');
                parts.forEach(part => {
                    setInput(part, true);
                    part.draggable = false;
                    part.hidden = false;
                    ul.append(part);
                });

                return line;
            };

            const insertLine = (line, before) => {
                if (before !== undefined) {
                    list.insertBefore(line, before);
                } else if (cursor && list.contains(cursor)) {
                    cursor.after(line);
                    cursor = line; // les ajouts suivants se placent à la suite
                } else {
                    list.append(line);
                }
            };

            const dropLine = line => {
                if (cursor === line) {
                    cursor = line.previousElementSibling || null;
                }
                line.remove();
            };

            const toPool = part => {
                const line = part.closest('[data-rc-line]');
                setInput(part, false);
                part.draggable = true;
                const checkbox = part.querySelector('[data-rc-check]');
                if (checkbox) {
                    checkbox.checked = false;
                }
                const key = sortKey(part);
                pool.insertBefore(part, poolRows().find(row => row !== part && sortKey(row) > key) || null);
                if (line && partsOf(line).length === 0) {
                    dropLine(line);
                }
            };

            const addParts = (parts, before, done = true) => {
                parts.sort((a, b) => (sortKey(a) < sortKey(b) ? -1 : 1));
                insertLine(newLine(parts, done), before);
            };

            const renderHead = (line, index, running) => {
                const parts = partsOf(line);
                const head = line.querySelector('[data-rc-head]');
                const keepChecked = head.querySelector('[data-rc-check-line]')?.checked || false;
                const total = parts.reduce((sum, part) => sum + (parseFloat(part.dataset.amount) || 0), 0);
                const first = parts[0];
                const categories = [...new Set(parts.map(part => part.dataset.category))].join(' · ');
                const tiersNames = [...new Set(parts.map(part => part.dataset.tiersName))];
                line.dataset.multiTiers = tiersNames.length > 1 ? '1' : '';

                line.dataset.parts = parts.length;
                line.dataset.total = total;
                head.innerHTML =
                        '<label class="rc-pick" title="Cocher pour regrouper des lignes en un détail"><input type="checkbox" data-rc-check-line' + (keepChecked ? ' checked' : '') + '></label>' +
                        '<span class="rc-grip" aria-hidden="true">⋮⋮</span>' +
                        '<span class="rc-rank">' + (index + 1) + '</span>' +
                        '<button type="button" class="rc-done' + (isDone(line) ? ' is-on' : '') + '" data-rc-done aria-pressed="' + (isDone(line) ? 'true' : 'false') + '" title="' + (isDone(line) ? 'Pointée : cliquer pour dépointer' : 'À pointer : cliquer quand la ligne est vérifiée sur le relevé de compte') + '">✓</button>' +
                        '<span class="rc-date">' + esc(first.dataset.dateFr) + '</span>' +
                        '<span class="rc-main"><strong>' + esc(tiersNames.join(' + ')) + '</strong><small>' + esc(categories) + '</small></span>' +
                        (parts.length > 1 ? '<span class="rc-chip is-detail" title="Plusieurs opérations pointées ensemble">Détail × ' + parts.length + '</span>' : '<span></span>') +
                        '<span class="rc-amount ' + (total < 0 ? 'amount-expense' : 'amount-income') + '">' + signed(total) + '</span>' +
                        '<span class="rc-balance" title="Solde cumulé après cette ligne">' + money.format(running) + ' €</span>' +
                        '<span class="rc-tools">' +
                        '<button type="button" class="rc-btn" data-rc-upto title="Pointer toutes les lignes jusqu\'ici (là où j\'en suis)" aria-label="Pointer jusqu\'ici">✓↑</button>' +
                        '<button type="button" class="rc-btn' + (line === cursor ? ' is-on' : '') + '" data-rc-here title="Insérer les prochaines lignes juste après celle-ci" aria-label="Insérer après cette ligne">⤓</button>' +
                        '<button type="button" class="rc-btn" data-rc-up title="Monter" aria-label="Monter">▲</button>' +
                        '<button type="button" class="rc-btn" data-rc-down title="Descendre" aria-label="Descendre">▼</button>' +
                        (parts.length > 1 ? '<button type="button" class="rc-btn" data-rc-split title="Dégrouper : une ligne par opération" aria-label="Dégrouper">⧉</button>' : '') +
                        '<button type="button" class="rc-btn" data-rc-remove-line title="Retirer la ligne du relevé" aria-label="Retirer la ligne">✕</button>' +
                        '</span>';
            };

            const refresh = () => {
                const all = lines();
                const cumulBefore = parseFloat(root.dataset.rcCumulBefore) || 0;
                // solde avant ce relevé : saisi à la main, sinon calculé (somme des relevés précédents)
                const typed = startInput && startInput.value !== '' ? parseFloat(String(startInput.value).replace(',', '.')) : NaN;
                const start = Number.isNaN(typed) ? cumulBefore : typed;
                let running = start;
                let opsCount = 0;

                all.forEach((line, index) => {
                    running += partsOf(line).reduce((sum, part) => sum + (parseFloat(part.dataset.amount) || 0), 0);
                    renderHead(line, index, running);
                    line.classList.toggle('is-cursor', line === cursor);
                    line.classList.toggle('is-done', isDone(line));
                    partsOf(line).forEach(part => {
                        part.querySelector('[data-rc-input]').name = 'lines[' + index + '][]';
                        opsCount++;
                    });
                });

                // opérations du même tiers et de la même date dans « À pointer » : proposer de les pointer ensemble
                const rows = poolRows();
                const sizes = {};
                rows.forEach(row => { sizes[groupKey(row)] = (sizes[groupKey(row)] || 0) + 1; });
                rows.forEach(row => {
                    const chip = row.querySelector('[data-rc-siblings]');
                    const n = sizes[groupKey(row)];
                    chip.hidden = n < 2;
                    chip.textContent = n > 1 ? '× ' + n : '';
                    chip.title = n > 1 ? 'Même tiers et même date : ' + n + ' opérations. Cliquer pour les cocher ensemble.' : '';
                });

                root.querySelector('[data-rc-count]').textContent = all.length;
                root.querySelector('[data-rc-ops-count]').textContent = opsCount !== all.length ? '(' + opsCount + ' opérations)' : '';
                root.querySelector('[data-rc-pool-count]').textContent = rows.length;
                const movement = running - start;
                const totalBox = root.querySelector('[data-rc-total]');
                totalBox.textContent = money.format(movement) + ' €';
                totalBox.classList.toggle('amount-expense', movement < 0);
                totalBox.classList.toggle('amount-income', movement > 0);
                root.querySelector('[data-rc-cumul]').textContent = money.format(running) + ' €';
                root.querySelector('[data-rc-empty]').hidden = all.length > 0;
                root.querySelector('[data-rc-pool-empty]').hidden = rows.length > 0;

                root.querySelectorAll('[data-rc-submit]').forEach(btn => {
                    btn.toggleAttribute('disabled', isNew && all.length === 0);
                });
                root.querySelector('[data-rc-finalize]').toggleAttribute('disabled', all.length === 0);

                // avancement du pointage
                const doneCount = all.filter(isDone).length;
                const pending = all.length - doneCount;
                const box = root.querySelector('[data-rc-progress-box]');
                if (box) {
                    box.hidden = all.length === 0;
                    box.querySelector('[data-rc-progress-text]').textContent = 'Pointé : ' + doneCount + ' / ' + all.length;
                    box.querySelector('[data-rc-progress-bar]').style.width = (all.length ? Math.round(100 * doneCount / all.length) : 0) + '%';
                    box.querySelector('[data-rc-done-all]').textContent = pending === 0 ? 'Tout dépointer' : 'Tout pointer';
                    box.classList.toggle('is-complete', all.length > 0 && pending === 0);
                    list.classList.toggle('is-hide-done', hideDone);
                    box.querySelector('[data-rc-hide-done]').setAttribute('aria-pressed', hideDone ? 'true' : 'false');
                }
                const finalizeBtn = root.querySelector('[data-rc-finalize]');
                if (!finalizeBtn.dataset.confirmBase) {
                    finalizeBtn.dataset.confirmBase = finalizeBtn.dataset.confirm || '';
                }
                finalizeBtn.dataset.confirm = (pending > 0
                        ? pending + ' ligne' + (pending > 1 ? 's ne sont pas pointées' : ' n\'est pas pointée') + ' (vérifiées sur le relevé de compte). Finaliser quand même ?\n\n'
                        : '') + finalizeBtn.dataset.confirmBase;
                saveDone();
            };

            const changed = () => {
                dirty = true;
                refresh();
            };

            const checkedLines = () => lines().filter(line => line.querySelector('[data-rc-check-line]')?.checked);

            /* ---- mini formulaire « Nouvelle opération » (panneau replié dans « À pointer ») ---- */
            const panel = root.querySelector('[data-rc-op-panel]');
            if (panel) {
                const field = name => panel.querySelector('[name="op[' + name + ']"]');
                const opError = panel.querySelector('[data-rc-op-error]');
                const direct = panel.querySelector('[data-rc-op-direct]');
                const mainDate = root.querySelector('input[name="date"]');
                const oops = text => { opError.textContent = text; opError.hidden = !text; };
                const clear = (...names) => names.forEach(name => {
                    field(name).value = '';
                    const hidden = field(name + '_id');
                    if (hidden) {
                        hidden.value = '';
                    }
                });

                root.addEventListener('click', event => {
                    if (event.target.closest('[data-rc-open-op]')) {
                        panel.hidden = !panel.hidden;
                        if (!panel.hidden) {
                            field('date').value = (mainDate && mainDate.value) || panel.dataset.defaultDate;
                            oops('');
                            field('montant').focus();
                        }
                    } else if (event.target.closest('[data-rc-op-cancel]')) {
                        panel.hidden = true;
                    }
                });

                field('adresse').addEventListener('change', event => {
                    field('adresse_id').value = event.target.value;
                });

                const create = async () => {
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

                    const body = new FormData();
                    body.append('_token', panel.dataset.token);
                    panel.querySelectorAll('[name]').forEach(el => body.append(el.name, el.value));
                    if (!field('adresse_id').value) {
                        body.set('op[adresse_id]', field('adresse').value);
                    }

                    const submit = panel.querySelector('[data-rc-op-submit]');
                    submit.disabled = true;
                    try {
                        const response = await fetch(panel.dataset.url, {
                            method: 'POST',
                            body,
                            headers: {'X-Requested-With': 'XMLHttpRequest'}
                        });
                        const data = await response.json();
                        if (!data.ok) {
                            return oops(data.error || 'Enregistrement impossible.');
                        }

                        const holder = document.createElement('ul');
                        holder.innerHTML = data.html.trim();
                        const row = holder.firstElementChild;
                        pool.insertBefore(row, poolRows().find(other => sortKey(other) > sortKey(row)) || null);
                        if (direct.checked) {
                            addParts([row]);
                        }
                        // prêt pour une autre opération (même date) ; le panneau reste ouvert
                        clear('categorie', 'tiers');
                        field('montant').value = '';
                        field('numCommande').value = '';
                        field('note').value = '';
                        clear('projet');
                        field('adresse').innerHTML = '<option value="">Choisir d\'abord le tiers</option>';
                        field('adresse_id').value = '';
                        say('Opération créée' + (direct.checked ? ' et ajoutée au relevé.' : ' : elle est dans « À pointer ».'), 'info');
                        changed();
                        field('montant').focus();
                    } catch (error) {
                        oops('Erreur réseau : ' + error.message);
                    } finally {
                        submit.disabled = false;
                    }
                };

                panel.querySelector('[data-rc-op-submit]').addEventListener('click', create);
                panel.addEventListener('keydown', event => {
                    // Entrée = créer (sauf si la liste d'autocomplétion vient de la consommer, ou dans la note)
                    if (event.key === 'Enter' && !event.defaultPrevented && event.target.tagName !== 'TEXTAREA') {
                        event.preventDefault();
                        create();
                    }
                });
            }

            /* ---- clics ---- */
            root.addEventListener('click', event => {
                const target = event.target;
                const part = target.closest('[data-rc-op]');
                const line = target.closest('[data-rc-line]');

                if (target.closest('[data-rc-done-all]')) {
                    const all = lines();
                    const value = !all.every(isDone);
                    all.forEach(l => setDone(l, value));
                    return refresh();
                }

                if (target.closest('[data-rc-hide-done]')) {
                    hideDone = !hideDone;
                    return refresh();
                }

                if (target.closest('[data-rc-done]') && line) {
                    setDone(line, !isDone(line));
                    return refresh();
                }

                if (target.closest('[data-rc-upto]') && line) {
                    const all = lines();
                    all.slice(0, all.indexOf(line) + 1).forEach(l => setDone(l, true));
                    return refresh();
                }

                if (target.closest('[data-rc-add]') && part) {
                    addParts([part]);
                    return changed();
                }

                if (target.closest('[data-rc-siblings]') && part) {
                    poolRows().filter(row => groupKey(row) === groupKey(part)).forEach(row => {
                        row.querySelector('[data-rc-check]').checked = true;
                    });
                    return;
                }

                if (target.closest('[data-rc-add-group]')) {
                    const picked = poolRows().filter(row => !row.hidden && row.querySelector('[data-rc-check]')?.checked);
                    if (picked.length === 0) {
                        return say('Cochez d\'abord les opérations à pointer ensemble.', 'info');
                    }
                    if (!sameGroup(picked)) {
                        return say(NOT_SAME);
                    }
                    addParts(picked);
                    return changed();
                }

                if (target.closest('[data-rc-add-all]')) {
                    poolRows().filter(row => !row.hidden).forEach(row => addParts([row], undefined, false)); // ajout en bloc : à vérifier
                    return changed();
                }

                if (target.closest('[data-rc-merge]')) {
                    const picked = checkedLines();
                    if (picked.length < 2) {
                        return say('Cochez au moins deux lignes du relevé à regrouper.', 'info');
                    }
                    const parts = picked.flatMap(partsOf);
                    if (!sameGroup(parts)) {
                        return say(NOT_SAME);
                    }
                    const keep = picked[0];
                    const ul = keep.querySelector('[data-rc-parts]');
                    parts.filter(p => !ul.contains(p)).forEach(p => ul.append(p));
                    picked.slice(1).forEach(dropLine);
                    keep.querySelector('[data-rc-check-line]').checked = false;
                    setDone(keep, true);
                    return changed();
                }

                if (target.closest('[data-rc-remove-part]') && part) {
                    toPool(part);
                    return changed();
                }

                if (target.closest('[data-rc-detach]') && part) {
                    const owner = part.closest('[data-rc-line]');
                    const alone = newLine([part]);
                    owner.after(alone);
                    return changed();
                }

                if (!line) {
                    const sortBtn = target.closest('[data-rc-sort]');
                    if (sortBtn) {
                        const sign = sortBtn.dataset.rcSort === 'desc' ? -1 : 1;
                        lines().sort((a, b) => sign * (lineKey(a) < lineKey(b) ? -1 : 1)).forEach(l => list.append(l));
                        cursor = null;
                        return changed();
                    }
                    return;
                }

                if (target.closest('[data-rc-remove-line]')) {
                    partsOf(line).forEach(toPool);
                    return changed();
                }
                if (target.closest('[data-rc-up]')) {
                    const prev = line.previousElementSibling;
                    if (prev) {
                        list.insertBefore(line, prev);
                        setDone(line, true); // déplacée à la main : pointée
                    }
                    return changed();
                }
                if (target.closest('[data-rc-down]')) {
                    const next = line.nextElementSibling;
                    if (next) {
                        list.insertBefore(next, line);
                        setDone(line, true);
                    }
                    return changed();
                }
                if (target.closest('[data-rc-here]')) {
                    cursor = cursor === line ? null : line;
                    return refresh();
                }
                if (target.closest('[data-rc-split]')) {
                    setDone(line, true);
                    const parts = partsOf(line);
                    let after = line;
                    parts.slice(1).forEach(p => {
                        const alone = newLine([p]);
                        after.after(alone);
                        after = alone;
                    });
                    return changed();
                }
            });

            const sortBtns = root.querySelectorAll('[data-rc-sort]');
            sortBtns.forEach(btn => btn.addEventListener('click', event => {
                const sign = btn.dataset.rcSort === 'desc' ? -1 : 1;
                lines().sort((a, b) => sign * (lineKey(a) < lineKey(b) ? -1 : 1)).forEach(l => list.append(l));
                cursor = null;
                event.stopPropagation();
                changed();
            }));

            /* ---- filtre de la liste « À pointer » ---- */
            if (filterInput) {
                filterInput.addEventListener('input', () => {
                    const query = filterInput.value.trim().toLowerCase();
                    poolRows().forEach(row => {
                        row.hidden = query !== '' && !(row.dataset.search || '').includes(query);
                    });
                });
            }

            if (startInput) {
                startInput.addEventListener('input', refresh);
            }

            // Entrée dans un champ de filtre / de solde ne doit pas envoyer le formulaire
            [filterInput, startInput].forEach(input => input && input.addEventListener('keydown', event => {
                if (event.key === 'Enter') {
                    event.preventDefault();
                }
            }));

            /* ---- glisser-déposer : lignes du relevé et opérations de « À pointer » ---- */
            root.addEventListener('dragstart', event => {
                const line = event.target.closest('[data-rc-line]');
                const part = event.target.closest('[data-rc-op]');
                if (line && event.target === line) {
                    drag = {part: null, line, from: lines().indexOf(line)};
                    line.classList.add('is-dragging');
                } else if (part && pool.contains(part)) {
                    drag = {part, line: null};
                    part.classList.add('is-dragging');
                } else {
                    return;
                }
                event.dataTransfer.effectAllowed = 'move';
                event.dataTransfer.setData('text/plain', 'rc');
            });

            root.addEventListener('dragend', () => {
                if (!drag) {
                    return;
                }
                drag.part?.classList.remove('is-dragging');
                drag.line?.classList.remove('is-dragging');
                // ligne déplacée (ou venue de « À pointer ») : pointée automatiquement
                if (drag.line && drag.line.parentElement === list && (drag.from === undefined || lines().indexOf(drag.line) !== drag.from)) {
                    setDone(drag.line, true);
                }
                drag = null;
                changed();
            });

            list.addEventListener('dragover', event => {
                if (!drag) {
                    return;
                }
                event.preventDefault();
                if (!drag.line) { // une opération de « À pointer » entre dans le relevé : elle devient une ligne
                    drag.line = newLine([drag.part]);
                    drag.line.classList.add('is-dragging');
                    list.append(drag.line);
                }
                const after = lines().filter(line => line !== drag.line).find(line => {
                    const box = line.getBoundingClientRect();
                    return event.clientY < box.top + box.height / 2;
                });
                if (after !== drag.line.nextElementSibling || drag.line.parentElement !== list) {
                    list.insertBefore(drag.line, after || null);
                }
            });

            pool.addEventListener('dragover', event => {
                if (!drag) {
                    return;
                }
                event.preventDefault();
                if (drag.line && drag.line.parentElement === list) { // la ligne repart dans « À pointer »
                    const parts = partsOf(drag.line);
                    parts.forEach(toPool);
                    if (drag.part === null) { // c'était une ligne du relevé : fin du glissement
                        drag.line = null;
                    } else {
                        drag.line = null;
                    }
                }
            });

            [list, pool].forEach(zone => zone.addEventListener('drop', event => event.preventDefault()));

            /* ---- envoi ---- */
            root.addEventListener('submit', event => {
                const submitter = event.submitter;
                if (submitter && submitter.dataset.confirm && !window.confirm(submitter.dataset.confirm)) {
                    event.preventDefault();
                    return;
                }
                if (submitter && submitter.hasAttribute('data-rc-finalize')) {
                    lines().forEach(l => setDone(l, false)); // relevé finalisé : on oublie le pointage
                }
                refresh();
                dirty = false;
            });

            window.addEventListener('beforeunload', event => {
                if (dirty) {
                    event.preventDefault();
                    event.returnValue = '';
                }
            });

            const savedDone = loadDone();
            lines().forEach(line => {
                line.draggable = true;
                // lignes déjà en base : pointées seulement si on les avait pointées dans ce navigateur
                const parts = partsOf(line);
                setDone(line, parts.length > 0 && parts.every(p => savedDone.has(String(p.dataset.id))));
            });
            refresh();
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
     * COULEURS D'ENTITÉ — valeurs liées au thème
     *   ''        → accent du thème
     *   '@a3'/'@h60-2' → nuance d'une famille du thème (var(--palette-a3)) ; anciens '@N' acceptés
     *   '#rrggbb' → couleur libre (figée)
     * colors.resolve(raw) → {color, text} prêts pour --entity-color / --entity-text
     * ========================================================= */

    colors: {
        resolve(raw) {
            const value = (raw || '').trim();
            const slot = /^@(\d{1,2}|[aisdwn][1-5]|h(?:60|120|180|240|300)-[1-5]|info|ok|warn|danger|text|soft|muted|line)$/.exec(value);

            if (slot) {
                return {color: `var(--palette-${slot[1]})`, text: `var(--palette-${slot[1]}-ink)`};
            }

            if (/^#[0-9a-f]{6}$/i.test(value)) {
                const r = parseInt(value.slice(1, 3), 16);
                const g = parseInt(value.slice(3, 5), 16);
                const b = parseInt(value.slice(5, 7), 16);

                return {color: value, text: (0.299 * r + 0.587 * g + 0.114 * b) > 165 ? '#1F2937' : '#FFFFFF'};
            }

            if (/^#[0-9a-f]{3,8}$/i.test(value) || value.startsWith('var(')) {
                return {color: value, text: ''};
            }

            return {};
        }
    },

    /* =========================================================
     * SÉLECTEUR DE COULEUR (_color_picker.html.twig)
     * Pastilles : défaut (accent) / 12 couleurs du thème / couleur libre.
     * La valeur est écrite dans le champ masqué, puis `input` + `change` sont émis
     * pour que l'aperçu en direct (liveForm) se mette à jour.
     * ========================================================= */

    colorPicker: {
        init(scope = document) {
            scope.querySelectorAll('[data-color-picker]').forEach(picker => {
                if (picker.dataset.pickerInit) {
                    return;
                }

                picker.dataset.pickerInit = '1';

                const input = picker.querySelector('input[type="hidden"], input[data-color-input]');
                const custom = picker.querySelector('[data-color-custom]');
                const hint = picker.querySelector('[data-color-hint]');
                const swatches = Array.from(picker.querySelectorAll('.color-swatch'));
                const customSwatch = custom ? custom.closest('.color-swatch') : null;

                const HINTS = {
                    default: 'Par défaut : accent du thème.',
                    slot: 'Couleur du thème : elle s\'adapte au thème choisi.',
                    custom: 'Couleur libre : elle ne change pas avec le thème.'
                };

                const refresh = () => {
                    const value = input.value || '';
                    const kind = /^@[a-z0-9-]+$/.test(value) ? 'slot' : (/^#[0-9a-f]{6}$/i.test(value) ? 'custom' : 'default');

                    swatches.forEach(swatch => {
                        const own = swatch.dataset.value;
                        swatch.classList.toggle('is-selected', swatch === customSwatch ? kind === 'custom' : own === value);
                    });

                    if (customSwatch && kind === 'custom') {
                        customSwatch.style.setProperty('--sw', value);
                    }

                    if (hint) {
                        hint.textContent = HINTS[kind];
                    }
                };

                const emit = () => {
                    input.dispatchEvent(new Event('input', {bubbles: true}));
                    input.dispatchEvent(new Event('change', {bubbles: true}));
                };

                picker.addEventListener('click', event => {
                    const swatch = event.target.closest('button.color-swatch');

                    if (swatch && picker.contains(swatch)) {
                        input.value = swatch.dataset.value || '';
                        refresh();
                        emit();
                    }
                });

                if (custom) {
                    custom.addEventListener('input', () => {
                        input.value = custom.value;
                        refresh();
                        emit();
                    });
                }

                refresh();
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

                    if (el.type === 'radio') {
                        const checked = form.querySelector(`[name$="[${name}]"]:checked`);
                        const label = checked && checked.value !== '' ? checked.closest('label') : null;
                        return label ? label.textContent.trim() : '';
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

                    // Champ couleur d'entité : vide = accent du thème
                    if (el.matches('[data-entity-color]') && !el.value) {
                        return {color: 'var(--accent)', text: 'var(--accent-contrast, #fff)'};
                    }

                    return App.colors.resolve(el.value);
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
                    const resolved = App.colors.resolve(item.couleur || item.color);

                    if (e.detail.form === form && resolved.color) {
                        scope.querySelectorAll('[data-live-color]').forEach(el => applyColor(el, resolved.color, resolved.text || item.textColor));
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
