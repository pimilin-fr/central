const App = {

    config: {
        debug: true,
        version: "v1.5.3.0",
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
        this.maps.init();
        this.bulk.init();
        this.loadMore.init();
        this.grouper.init();
        this.depenses.init();
        this.selectAll.init();
        this.adresse.init();
        this.depenseForm.init();
    },

    /* =========================================================
     MAPS
     ========================================================= */
    maps: {

        instances: new Map(),

        config: {

            marker: {
                radius: 10,
                borderColor: '#FFFFFF',
                borderWeight: 2,
                fillOpacity: 0.8,

                colors: {
                    current: '#F97316',
                    principale: '#16A34A',
                    secondaire: '#64748B',
                    default: '#2563EB'
                }
            },

            zone: {
                fillOpacity: 0.18,
                borderOpacity: 0.7,
                borderWeight: 2
            },

            popup: {
                titleClass: 'font-semibold text-gray-900',
                metaClass: 'text-xs text-gray-500 mt-1',
                linkClass: 'inline-flex items-center mt-2 text-sm font-medium text-orange-600 hover:text-orange-700',

                texts: {
                    principale: 'Adresse principale',
                    secondaire: 'Adresse secondaire',
                    voirAdresse: 'Voir l’adresse'
                }
            },

            tileLayer: {
                url: 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
                options: {
                    maxZoom: 19,
                    attribution: '&copy; Central utilise OpenStreetMap'
                }
            },

            defaultView: {
                latitude: 46.6,
                longitude: 2.4,
                zoom: 6
            },

            singlePointZoom: 16,

            bounds: {
                padding: 0.15
            }
        },

        init() {
            App.log('Init maps');

            if (typeof L === 'undefined') {
                App.log('Leaflet non disponible');
                return;
            }

            this.initVisibleMaps();

            App.events.on('tab:activated', (e) => {
                const content = e.detail.content;

                if (!content)
                    return;

                this.initMaps(content);
                this.invalidateMaps(content);
            });
        },

        initVisibleMaps() {

            document.querySelectorAll('[data-map]').forEach(mapElement => {

                if (this.isVisible(mapElement)) {
                    this.initMap(mapElement);
                }

            });
        },

        initMaps(scope = document) {

            scope.querySelectorAll('[data-map]').forEach(mapElement => {
                this.initMap(mapElement);
            });
        },

        initMap(mapElement) {

            if (!mapElement)
                return;

            if (this.instances.has(mapElement))
                return;

            if (!this.isVisible(mapElement))
                return;

            const type = mapElement.dataset.mapType || 'point';

            switch (type) {

                case 'point':
                    this.initPoint(mapElement);
                    break;

                case 'multipoint':
                    this.initMultipoint(mapElement);
                    break;

                case 'polygon':
                case 'zoning':
                    this.initPolygon(mapElement);
                    break;

                case 'hierarchical':
                    this.initHierarchical(mapElement);
                    break;

                default:
                    App.log('Type de carte inconnu:', type);
            }
        },

        createMarker(latitude, longitude, options = {}) {

            const config = this.config.marker;

            return L.circleMarker(
                    [latitude, longitude],
                    {
                        radius: config.radius,
                        color: config.borderColor,
                        weight: config.borderWeight,
                        fillColor: options.color ?? config.colors.default,
                        fillOpacity: config.fillOpacity
                    }
            );
        },

        initPoint(mapElement) {

            const latitude = parseFloat(mapElement.dataset.latitude);
            const longitude = parseFloat(mapElement.dataset.longitude);

            if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) {

                App.log('Coordonnées invalides', {
                    latitude,
                    longitude
                });

                return;
            }

            const map = L.map(mapElement).setView(
                    [latitude, longitude],
                    this.config.singlePointZoom
                    );

            L.tileLayer(
                    this.config.tileLayer.url,
                    this.config.tileLayer.options
                    ).addTo(map);

            const marker = this.createMarker(
                    latitude,
                    longitude,
                    {
                        color: this.config.marker.colors.default
                    }
            );

            marker.addTo(map);

            this.instances.set(mapElement, map);

            requestAnimationFrame(() => {
                map.invalidateSize();
            });
        },

        initMultipoint(mapElement) {

            const pointElements = Array.from(
                    mapElement.querySelectorAll('[data-map-point]')
                    );

            const map = L.map(mapElement);

            L.tileLayer(
                    this.config.tileLayer.url,
                    this.config.tileLayer.options
                    ).addTo(map);

            const markers = [];

            pointElements.forEach(pointElement => {

                const latitude = parseFloat(
                        pointElement.dataset.latitude
                        );

                const longitude = parseFloat(
                        pointElement.dataset.longitude
                        );

                if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) {

                    App.log(
                            'Point multipoint ignoré : coordonnées invalides',
                            pointElement.dataset
                            );

                    return;
                }

                const isPrincipale =
                        pointElement.dataset.principale === '1';

                const marker = this.createMarker(
                        latitude,
                        longitude,
                        {
                            color: isPrincipale
                                    ? this.config.marker.colors.principale
                                    : this.config.marker.colors.secondaire
                        }
                );

                marker.addTo(map);

                markers.push(marker);
            });

            if (markers.length === 0) {

                map.setView(
                        [
                            this.config.defaultView.latitude,
                            this.config.defaultView.longitude
                        ],
                        this.config.defaultView.zoom
                        );

            } else if (markers.length === 1) {

                map.setView(
                        markers[0].getLatLng(),
                        this.config.singlePointZoom
                        );

            } else {

                const bounds = L.latLngBounds(
                        markers.map(marker => marker.getLatLng())
                        );

                map.fitBounds(
                        bounds.pad(this.config.bounds.padding)
                        );
            }

            this.instances.set(mapElement, map);

            requestAnimationFrame(() => {

                map.invalidateSize();

                if (markers.length > 1) {

                    const bounds = L.latLngBounds(
                            markers.map(marker => marker.getLatLng())
                            );

                    map.fitBounds(
                            bounds.pad(this.config.bounds.padding)
                            );
                }
            });
        },

        initPolygon(mapElement) {

            App.log(
                    'Polygon/Zoning : pas encore implémenté',
                    mapElement
                    );
        },

        /*
         * =========================================================
         * CARTE HIÉRARCHIQUE
         * =========================================================
         *
         * Le PHP fournit déjà :
         *
         * current
         * zones[]
         *   └── points[]
         *
         * Le JS ne fait donc aucune recherche dans la hiérarchie.
         * Il se contente de dessiner :
         *
         * - le point courant
         * - les points des rues
         * - une zone autour de chaque groupe
         */

        initHierarchical(mapElement) {

            const currentElement =
                    mapElement.querySelector('[data-map-current]');

            const map = L.map(mapElement);

            L.tileLayer(
                    this.config.tileLayer.url,
                    this.config.tileLayer.options
                    ).addTo(map);

            const markers = [];
            const zoneLayers = [];

            /*
             * =========================================================
             * POINT COURANT
             * =========================================================
             */

            if (currentElement) {

                const latitude = parseFloat(
                        currentElement.dataset.latitude
                        );

                const longitude = parseFloat(
                        currentElement.dataset.longitude
                        );

                if (
                        Number.isFinite(latitude) &&
                        Number.isFinite(longitude)
                        ) {

                    const marker = this.createMarker(
                            latitude,
                            longitude,
                            {
                                color: this.config.marker.colors.current
                            }
                    );

                    const name =
                            currentElement.dataset.name || '';

                    marker.bindPopup(`
                    <div>
                        <strong class="${this.config.popup.titleClass}">
                            ${this.escapeHtml(name)}
                        </strong>
                    </div>
                `);

                    marker.addTo(map);

                    markers.push(marker);
                }
            }

            /*
             * =========================================================
             * ZONES
             * =========================================================
             */

            const zones = Array.from(
                    mapElement.querySelectorAll('[data-map-zone]')
                    );

            const colors =
                    this.getHierarchicalColors(zones.length);

            zones.forEach((zoneElement, zoneIndex) => {

                const color = colors[zoneIndex];

                const zoneName =
                        zoneElement.dataset.zoneName || '';

                const pointElements = Array.from(
                        zoneElement.querySelectorAll('[data-map-point]')
                        );

                const points = [];

                /*
                 * -----------------------------------------------------
                 * POINTS DU GROUPE
                 * -----------------------------------------------------
                 */

                pointElements.forEach(pointElement => {

                    const latitude = parseFloat(
                            pointElement.dataset.latitude
                            );

                    const longitude = parseFloat(
                            pointElement.dataset.longitude
                            );

                    if (
                            !Number.isFinite(latitude) ||
                            !Number.isFinite(longitude)
                            ) {
                        return;
                    }

                    points.push([
                        latitude,
                        longitude
                    ]);

                    const marker = this.createMarker(
                            latitude,
                            longitude,
                            {
                                color
                            }
                    );

                    const name =
                            pointElement.dataset.name || '';

                    marker.bindPopup(`
                    <div>
                        <strong class="${this.config.popup.titleClass}">
                            ${this.escapeHtml(name)}
                        </strong>

                        ${
                            zoneName
                            ? `
                                    <div class="${this.config.popup.metaClass}">
                                        ${this.escapeHtml(zoneName)}
                                    </div>
                                  `
                            : ''
                            }
                    </div>
                `);

                    marker.addTo(map);

                    markers.push(marker);
                });

                /*
                 * -----------------------------------------------------
                 * ZONE VISUELLE
                 * -----------------------------------------------------
                 */

                if (points.length > 0) {

                    const zone = this.createZonePolygon(
                            points,
                            color,
                            zoneName
                            );

                    if (zone) {
                        zone.addTo(map);
                        zoneLayers.push(zone);
                    }
                }
            });

            /*
             * =========================================================
             * VUE DE LA CARTE
             * =========================================================
             */

            if (markers.length === 0) {

                map.setView(
                        [
                            this.config.defaultView.latitude,
                            this.config.defaultView.longitude
                        ],
                        this.config.defaultView.zoom
                        );

            } else if (markers.length === 1) {

                map.setView(
                        markers[0].getLatLng(),
                        this.config.singlePointZoom
                        );

            } else {

                const bounds = L.latLngBounds(
                        markers.map(marker => marker.getLatLng())
                        );

                map.fitBounds(
                        bounds.pad(this.config.bounds.padding)
                        );
            }

            this.instances.set(mapElement, map);

            requestAnimationFrame(() => {

                map.invalidateSize();

                if (markers.length > 1) {

                    const bounds = L.latLngBounds(
                            markers.map(marker => marker.getLatLng())
                            );

                    map.fitBounds(
                            bounds.pad(this.config.bounds.padding)
                            );
                }
            });
        },

        /*
         * =========================================================
         * CRÉATION D'UNE ZONE
         * =========================================================
         *
         * 3 points ou plus :
         *     → enveloppe convexe
         *
         * 2 points :
         *     → rectangle autour des deux points
         *
         * 1 point :
         *     → petit cercle
         */

        createZonePolygon(points, color, zoneName) {

            if (!points || points.length === 0) {
                return null;
            }

            const config = this.config.zone;

            /*
             * ---------------------------------------------------------
             * UN SEUL POINT
             * ---------------------------------------------------------
             */

            if (points.length === 1) {

                const center = points[0];

                const zone = L.circle(
                        center,
                        {
                            radius: 350,
                            color: color,
                            weight: config.borderWeight,
                            opacity: config.borderOpacity,
                            fillColor: color,
                            fillOpacity: config.fillOpacity
                        }
                );

                if (zoneName) {
                    zone.bindPopup(`
                    <strong class="${this.config.popup.titleClass}">
                        ${this.escapeHtml(zoneName)}
                    </strong>
                `);
                }

                return zone;
            }

            /*
             * ---------------------------------------------------------
             * DEUX POINTS
             * ---------------------------------------------------------
             */

            if (points.length === 2) {

                const rectangle =
                        this.createTwoPointZone(points);

                const zone = L.polygon(
                        rectangle,
                        {
                            color: color,
                            weight: config.borderWeight,
                            opacity: config.borderOpacity,
                            fillColor: color,
                            fillOpacity: config.fillOpacity
                        }
                );

                if (zoneName) {
                    zone.bindPopup(`
                    <strong class="${this.config.popup.titleClass}">
                        ${this.escapeHtml(zoneName)}
                    </strong>
                `);
                }

                return zone;
            }

            /*
             * ---------------------------------------------------------
             * TROIS POINTS OU PLUS
             * ---------------------------------------------------------
             */

            const hull = this.convexHull(points);

            if (hull.length < 3) {
                return null;
            }

            const zone = L.polygon(
                    hull,
                    {
                        color: color,
                        weight: config.borderWeight,
                        opacity: config.borderOpacity,
                        fillColor: color,
                        fillOpacity: config.fillOpacity
                    }
            );

            if (zoneName) {
                zone.bindPopup(`
                <strong class="${this.config.popup.titleClass}">
                    ${this.escapeHtml(zoneName)}
                </strong>
            `);
            }

            return zone;
        },

        /*
         * =========================================================
         * ENVELOPPE CONVEXE
         * =========================================================
         */

        convexHull(points) {

            const sorted = points
                    .map(point => [
                            Number(point[0]),
                            Number(point[1])
                        ])
                    .sort((a, b) => {

                        if (a[1] === b[1]) {
                            return a[0] - b[0];
                        }

                        return a[1] - b[1];
                    });

            if (sorted.length <= 2) {
                return sorted;
            }

            const cross = (o, a, b) => {

                return (
                        (a[1] - o[1]) * (b[0] - o[0]) -
                        (a[0] - o[0]) * (b[1] - o[1])
                        );
            };

            const lower = [];

            for (const point of sorted) {

                while (
                        lower.length >= 2 &&
                        cross(
                                lower[lower.length - 2],
                                lower[lower.length - 1],
                                point
                                ) <= 0
                        ) {
                    lower.pop();
                }

                lower.push(point);
            }

            const upper = [];

            for (let i = sorted.length - 1; i >= 0; i--) {

                const point = sorted[i];

                while (
                        upper.length >= 2 &&
                        cross(
                                upper[upper.length - 2],
                                upper[upper.length - 1],
                                point
                                ) <= 0
                        ) {
                    upper.pop();
                }

                upper.push(point);
            }

            lower.pop();
            upper.pop();

            return lower.concat(upper);
        },

        /*
         * =========================================================
         * ZONE POUR DEUX POINTS
         * =========================================================
         */

        createTwoPointZone(points) {

            const [pointA, pointB] = points;

            const lat1 = pointA[0];
            const lng1 = pointA[1];

            const lat2 = pointB[0];
            const lng2 = pointB[1];

            const latDiff = lat2 - lat1;
            const lngDiff = lng2 - lng1;

            const length =
                    Math.sqrt(
                            (latDiff * latDiff) +
                            (lngDiff * lngDiff)
                            );

            /*
             * Petit décalage perpendiculaire.
             *
             * La valeur est volontairement simple :
             * on cherche ici une zone de visualisation,
             * pas une frontière géographique réelle.
             */

            const offset = Math.max(
                    length * 0.15,
                    0.002
                    );

            const perpLat =
                    -lngDiff / (length || 1) * offset;

            const perpLng =
                    latDiff / (length || 1) * offset;

            return [
                [
                    lat1 + perpLat,
                    lng1 + perpLng
                ],
                [
                    lat2 + perpLat,
                    lng2 + perpLng
                ],
                [
                    lat2 - perpLat,
                    lng2 - perpLng
                ],
                [
                    lat1 - perpLat,
                    lng1 - perpLng
                ]
            ];
        },

        /*
         * =========================================================
         * COULEURS DES ZONES
         * =========================================================
         */

        getHierarchicalColors(count) {

            if (count <= 0) {
                return [];
            }

            /*
             * Une seule zone :
             * on utilise le bleu Central.
             */

            if (count === 1) {
                return [
                    this.config.marker.colors.default
                ];
            }

            /*
             * Plusieurs zones :
             * roue chromatique.
             */

            return Array.from(
                    {length: count},
                    (_, index) => {

                const hue =
                        Math.round(
                                (index * 360) / count
                                );

                return `hsl(${hue}, 70%, 50%)`;
            }
            );
        },

        escapeHtml(value) {

            return String(value)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#039;');
        },

        escapeAttribute(value) {

            return this.escapeHtml(value);
        },

        invalidateMaps(scope = document) {

            scope.querySelectorAll('[data-map]').forEach(mapElement => {

                const map = this.instances.get(mapElement);

                if (!map)
                    return;

                requestAnimationFrame(() => {
                    map.invalidateSize();
                });

            });
        },

        isVisible(element) {

            if (!element)
                return false;

            let current = element;

            while (
                    current &&
                    current !== document.body
                    ) {

                if (
                        current.classList &&
                        current.classList.contains('hidden')
                        ) {
                    return false;
                }

                current = current.parentElement;
            }

            return (
                    element.offsetWidth > 0 &&
                    element.offsetHeight > 0
                    );
        }
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

                    /*
                     * Signale aux modules qu'un onglet vient d'être affiché.
                     *
                     * Important pour Leaflet :
                     * une carte ne doit pas calculer sa taille lorsqu'elle
                     * se trouve encore dans un élément display:none.
                     */
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
    grouper: {
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