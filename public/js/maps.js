const CentralMaps = {
    instances: new Map(),

    config: {
        debug: true,
        version: "v1.0",
        appName: "Central-ModuleMap",
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
            fillOpacity: 0.12,
            borderOpacity: 0.85,
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

    log(...args) {
        if (this.config.debug) {
            console.log(
                    "[" + this.config.appName + "-" + this.config.version + "]",
                    ...args
                    );
        }
    },

    init() {

        CentralMaps.log(`Init maps`);
        if (typeof L === 'undefined') {
            CentralMaps.log('Leaflet non disponible');
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

            case 'hierarchical':
                this.initHierarchical(mapElement);
                break;

            default:
                CentralMaps.log('Type de carte inconnu:', type);
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

    /* =========================================================
     * CARTE SIMPLE
     * ========================================================= */

    initPoint(mapElement) {

        const latitude = parseFloat(mapElement.dataset.latitude);
        const longitude = parseFloat(mapElement.dataset.longitude);

        if (
                !Number.isFinite(latitude) ||
                !Number.isFinite(longitude)
                ) {

            CentralMaps.log('Coordonnées invalides', {
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

    /* =========================================================
     * CARTE MULTIPOINT
     * ========================================================= */

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

            if (
                    !Number.isFinite(latitude) ||
                    !Number.isFinite(longitude)
                    ) {

                CentralMaps.log(
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


    /* =========================================================
     * CARTE HIÉRARCHIQUE
     * =========================================================
     *
     * Le PHP fournit :
     *
     * current
     * zones[]
     *   └── points[]
     *
     * Le JS ne recherche donc rien dans la hiérarchie.
     *
     * Il affiche :
     *
     * - le point courant
     * - les points des rues
     * - une zone visuelle autour de chaque groupe
     * ========================================================= */

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

        /* =====================================================
         * POINT COURANT
         * ===================================================== */

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

                const url =
                        currentElement.dataset.url || '';

                marker.bindPopup(`
                        <div>
                            <strong class="${this.config.popup.titleClass}">
                                ${this.escapeHtml(name)}
                            </strong>

                            ${
                        url
                        ? `
                                        <div>
                                            <a
                                                href="${this.escapeAttribute(url)}"
                                                class="${this.config.popup.linkClass}"
                                            >
                                                ${this.config.popup.texts.voirAdresse}
                                            </a>
                                        </div>
                                      `
                        : ''
                        }
                        </div>
                    `);

                marker.addTo(map);

                markers.push(marker);
            }
        }

        /* =====================================================
         * ZONES
         * ===================================================== */

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

            /* =================================================
             * POINTS DE LA ZONE
             * ================================================= */

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

                const url =
                        pointElement.dataset.url || '';

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

                            ${
                        url
                        ? `
                                        <div>
                                            <a
                                                href="${this.escapeAttribute(url)}"
                                                class="${this.config.popup.linkClass}"
                                            >
                                                ${this.config.popup.texts.voirAdresse}
                                            </a>
                                        </div>
                                      `
                        : ''
                        }

                        </div>
                    `);

                marker.addTo(map);

                markers.push(marker);
            });

            /* =================================================
             * ZONE VISUELLE
             * ================================================= */

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

        /* =====================================================
         * VUE DE LA CARTE
         * ===================================================== */

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

    /* =========================================================
     * CRÉATION D'UNE ZONE VISUELLE
     * =========================================================
     *
     * 1 point
     *     → cercle autour du point
     *
     * 2 points
     *     → rectangle autour du segment
     *
     * 3+ points
     *     → enveloppe convexe
     *
     * Attention :
     * ces zones sont des zones de visualisation.
     * Elles ne représentent pas les limites géographiques
     * officielles du quartier.
     * ========================================================= */

    createZonePolygon(points, color, zoneName) {

        if (!points || points.length === 0) {
            return null;
        }

        const config = this.config.zone;

        /* =====================================================
         * UN SEUL POINT
         * ===================================================== */

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

        /* =====================================================
         * DEUX POINTS
         * ===================================================== */

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

        /* =====================================================
         * TROIS POINTS OU PLUS
         * ===================================================== */

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

    /* =========================================================
     * ENVELOPPE CONVEXE
     * ========================================================= */

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

        for (
                let i = sorted.length - 1;
                i >= 0;
                i--
                ) {

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

    /* =========================================================
     * ZONE POUR DEUX POINTS
     * ========================================================= */

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
         * Cette valeur sert uniquement à donner une zone
         * visuelle autour des deux points.
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

    /* =========================================================
     * COULEURS DES ZONES
     * =========================================================
     *
     * Une seule zone :
     *     → bleu Central
     *
     * Plusieurs zones :
     *     → rotation des teintes HSL
     *
     * La rotation > 360° permet d'éviter que les couleurs
     * voisines soient toujours trop proches lorsqu'il y a
     * beaucoup de zones.
     * ========================================================= */

    getHierarchicalColors(count) {

        if (count <= 0) {
            return [];
        }

        /*
         * Une seule zone :
         * couleur Central par défaut.
         */

        if (count === 1) {
            return [
                this.config.marker.colors.default
            ];
        }

        const colors = [];

        const saturationLevels = [
            70,
            75,
            65
        ];

        const lightnessLevels = [
            50,
            45,
            55
        ];

        for (
                let index = 0;
                index < count;
                index++
                ) {

            /*
             * Rotation sur plusieurs tours.
             *
             * Exemple :
             * 20 zones → couleurs fortement espacées
             * 30 zones → toujours une bonne variété
             */

            const hue = Math.round(
                    (index * 360 * 1.7) / count
                    ) % 360;

            const level =
                    index % saturationLevels.length;

            const saturation =
                    saturationLevels[level];

            const lightness =
                    lightnessLevels[level];

            colors.push(
                    `hsl(${hue}, ${saturation}%, ${lightness}%)`
                    );
        }

        return colors;
    },

    /* =========================================================
     * SÉCURITÉ HTML
     * ========================================================= */

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

    /* =========================================================
     * INVALIDATION DES CARTES
     * ========================================================= */

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

    /* =========================================================
     * VISIBILITÉ
     * ========================================================= */

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
}