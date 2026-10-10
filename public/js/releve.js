/* =========================================================
 * MODULE RELEVÉ — écran « Faire le relevé » (templates/releve/composer.html.twig)
 * Module autonome, comme maps.js : chargé avant app.js, initialisé par App.init() via CentralReleve.init().
 * Styles : public/css/releve.css. Serveur : ReleveController / ReleveManager (src/).
 *
 * Deux listes : « À pointer » (opérations hors relevé) et « Relevé » (lignes du relevé de compte, dans l'ordre).
 * Une LIGNE du relevé = une opération, ou un « détail » : plusieurs opérations de la même date (tiers libres)
 * pointées ensemble (ex. une commande de 50 € éclatée en plusieurs catégories) ; elles partagent le rang.
 * Chaque opération d'une ligne porte un champ lines[i][] (i = rang − 1) : l'ordre du DOM EST l'ordre envoyé.
 * Le « pointage » (✓) n'est jamais envoyé au serveur : il reste dans ce navigateur (localStorage).
 * Dépend uniquement de l'autocomplétion générique d'app.js (champs .autocomplete du mini formulaire).
 * ========================================================= */

const CentralReleve = {

    config: {
        debug: true,
        version: 'v1.0.0',
        appName: 'Central-ModuleReleve'
    },

    log(...args) {
        if (this.config.debug) {
            console.log('[' + this.config.appName + '-' + this.config.version + ']', ...args);
        }
    },

        init() {
            const root = document.querySelector('[data-releve-composer]');
            if (!root) {
                return;
            }

            this.log('Init releve');

            const pool = root.querySelector('[data-rc-pool]');
            const list = root.querySelector('[data-rc-list]');
            const startInput = root.querySelector('[data-rc-start]');
            const filterInput = root.querySelector('[data-rc-filter]');
            const message = root.querySelector('[data-rc-msg]');
            const isNew = root.hasAttribute('data-rc-new');
            const money = new Intl.NumberFormat('fr-FR', {minimumFractionDigits: 2, maximumFractionDigits: 2});

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
                } else {
                    list.append(line);
                }
            };

            const dropLine = line => {
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
                        '<button type="button" class="rc-done' + (isDone(line) ? ' is-on' : '') + '" data-rc-done aria-pressed="' + (isDone(line) ? 'true' : 'false') + '" title="' + (isDone(line) ? 'Pointée : cliquer pour dépointer' : 'Cliquer quand la ligne est vérifiée sur le relevé de compte (les lignes au-dessus sont pointées aussi)') + '">✓</button>' +
                        '<span class="rc-date">' + esc(first.dataset.dateFr) + '</span>' +
                        '<span class="rc-main"><strong>' + esc(tiersNames.join(' + ')) + '</strong><small>' + esc(categories) + '</small></span>' +
                        (parts.length > 1 ? '<span class="rc-chip is-detail" title="Plusieurs opérations pointées ensemble">Détail × ' + parts.length + '</span>' : '<span></span>') +
                        '<span class="rc-amount ' + (total < 0 ? 'amount-expense' : 'amount-income') + '">' + signed(total) + '</span>' +
                        '<span class="rc-balance" title="Solde cumulé après cette ligne">' + money.format(running) + ' €</span>' +
                        '<span class="rc-tools">' +
                                                '<button type="button" class="rc-btn" data-rc-bring title="Placer cette ligne juste après la dernière ligne pointée (en premier si aucune n\'est pointée)" aria-label="Placer après la dernière ligne pointée">⤒</button>' +
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
                updateSelection();
            };

            /* ---- sélection multiple : nombre et montant cumulé des éléments cochés ---- */
            const selectedPool = () => poolRows().filter(row => !row.hidden && row.querySelector('[data-rc-check]')?.checked);
            const updateSelection = () => {
                const fill = (box, count, label, amount) => {
                    if (!box) {
                        return;
                    }
                    box.hidden = count === 0;
                    if (count > 0) {
                        const text = box.querySelector('[data-rc-sel-text]');
                        text.innerHTML = esc(count + ' ' + label + ' : ') + '<strong class="' + (amount < 0 ? 'amount-expense' : 'amount-income') + '">' + esc(signed(amount)) + '</strong>';
                    }
                };
                const rows = selectedPool();
                fill(root.querySelector('[data-rc-sel-pool]'), rows.length, rows.length > 1 ? 'sélectionnées' : 'sélectionnée',
                        rows.reduce((sum, row) => sum + (parseFloat(row.dataset.amount) || 0), 0));
                const chosen = checkedLines();
                const parts = chosen.flatMap(partsOf);
                fill(root.querySelector('[data-rc-sel-lines]'), chosen.length, chosen.length > 1 ? 'lignes sélectionnées' : 'ligne sélectionnée',
                        parts.reduce((sum, part) => sum + (parseFloat(part.dataset.amount) || 0), 0));
            };

            const changed = () => {
                dirty = true;
                refresh();
            };

            const checkedLines = () => lines().filter(line => line.querySelector('[data-rc-check-line]')?.checked);

            /* ---- prévision concrétisée depuis le panneau latéral : l'opération est créée, on la place dans le relevé ---- */
            document.addEventListener('prevision:operation-created', event => {
                const holder = document.createElement('ul');
                holder.innerHTML = String(event.detail.html || '').trim();
                const row = holder.firstElementChild;
                if (!row) {
                    return;
                }
                pool.insertBefore(row, poolRows().find(other => sortKey(other) > sortKey(row)) || null);
                addParts([row]);
                say('Prévision ajoutée au relevé.', 'info');
                changed();
            });

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

            /* ---- cases à cocher : Maj+clic coche une plage ; le total des éléments cochés se met à jour ---- */
            const lastPicked = {pool: null, line: null};
            root.addEventListener('click', event => {
                const box = event.target.closest('input[data-rc-check], input[data-rc-check-line]');
                if (!box) {
                    const clear = event.target.closest('[data-rc-sel-clear]');
                    if (clear) {
                        const scope = clear.closest('[data-rc-sel-pool]') ? 'pool' : 'line';
                        (scope === 'pool' ? poolRows().map(r => r.querySelector('[data-rc-check]')) : lines().map(l => l.querySelector('[data-rc-check-line]')))
                                .forEach(cb => { if (cb) { cb.checked = false; } });
                        updateSelection();
                    }
                    return;
                }
                const kind = box.hasAttribute('data-rc-check') ? 'pool' : 'line';
                const item = box.closest(kind === 'pool' ? '[data-rc-op]' : '[data-rc-line]');
                const items = kind === 'pool' ? poolRows().filter(r => !r.hidden) : lines();
                const last = lastPicked[kind];
                if (event.shiftKey && last && items.includes(last) && items.includes(item)) {
                    const [from, to] = [items.indexOf(last), items.indexOf(item)].sort((a, b) => a - b);
                    items.slice(from, to + 1).forEach(it => {
                        const cb = it.querySelector(kind === 'pool' ? '[data-rc-check]' : '[data-rc-check-line]');
                        if (cb) {
                            cb.checked = box.checked;
                        }
                    });
                }
                lastPicked[kind] = item;
                updateSelection();
            });

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
                    if (isDone(line)) {
                        setDone(line, false);
                    } else { // pointer une ligne = pointer aussi toutes celles au-dessus
                        const all = lines();
                        all.slice(0, all.indexOf(line) + 1).forEach(l => setDone(l, true));
                    }
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
                    return updateSelection();
                }

                if (target.closest('[data-rc-add-group]')) {
                    const picked = poolRows().filter(row => !row.hidden && row.querySelector('[data-rc-check]')?.checked);
                    if (picked.length === 0) {
                        return say('Cochez d\'abord les opérations à pointer ensemble.', 'info');
                    }
                    if (!sameGroup(picked)) {
                        return say(NOT_SAME);
                    }
                    addParts(picked, undefined, false); // regrouper ne pointe pas
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
                    setDone(keep, picked.every(isDone)); // pointé seulement si toutes les lignes l'étaient
                    return changed();
                }

                if (target.closest('[data-rc-remove-part]') && part) {
                    toPool(part);
                    return changed();
                }

                if (target.closest('[data-rc-detach]') && part) {
                    const owner = part.closest('[data-rc-line]');
                    const alone = newLine([part], isDone(owner)); // garde l'état de la ligne d'origine
                    owner.after(alone);
                    return changed();
                }

                if (!line) {
                    return;
                }

                if (target.closest('[data-rc-remove-line]')) {
                    partsOf(line).forEach(toPool);
                    return changed();
                }
                if (target.closest('[data-rc-bring]')) {
                    // « remonter » : juste après la dernière ligne pointée (hors celle-ci), ou en tête si aucune
                    const others = lines().filter(l => l !== line);
                    let lastDone = -1;
                    others.forEach((l, i) => { if (isDone(l)) { lastDone = i; } });
                    if (lastDone >= 0) {
                        others[lastDone].after(line);
                    } else {
                        list.prepend(line);
                    }
                    setDone(line, true); // placée à la main : pointée
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
                if (target.closest('[data-rc-split]')) {
                    const parts = partsOf(line);
                    let after = line;
                    parts.slice(1).forEach(p => {
                        const alone = newLine([p], isDone(line)); // dégrouper ne pointe pas
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
                    updateSelection();
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
};

if (typeof module !== 'undefined' && module.exports) {
    module.exports = CentralReleve;
}
