const CentralMaps = {
    instances: new Map(),

    config: {
        debug: true,
        version: 'v1.3.1',
        appName: 'Central-ModuleMap',

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
            borderWeight: 2,
            marginMeters: 250,
            singlePointRadiusMeters: 350
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
        if (!this.config.debug) {
            return;
        }

        console.log(
                `[${this.config.appName}-${this.config.version}]`,
                ...args
                );
    },

    init() {
        this.log('Init maps');

        if (typeof L === 'undefined') {
            this.log('Leaflet non disponible');
            return;
        }

        this.initVisibleMaps();

        if (typeof App !== 'undefined' && App.events) {
            App.events.on('tab:activated', (e) => {
                const content = e.detail?.content;

                if (!content) {
                    return;
                }

                this.initMaps(content);
                this.invalidateMaps(content);
            });
        }
    },

    initVisibleMaps() {
        document.querySelectorAll('[data-map]').forEach((mapElement) => {
            if (this.isVisible(mapElement)) {
                this.initMap(mapElement);
            }
        });
    },

    initMaps(scope = document) {
        scope.querySelectorAll('[data-map]').forEach((mapElement) => {
            this.initMap(mapElement);
        });
    },

    initMap(mapElement) {
        if (!mapElement || this.instances.has(mapElement)) {
            return;
        }

        if (!this.isVisible(mapElement)) {
            return;
        }

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
                this.log('Type de carte inconnu:', type);
        }
    },

    createMarker(latitude, longitude, options = {}) {
        const config = this.config.marker;

        return L.circleMarker([latitude, longitude], {
            radius: config.radius,
            color: config.borderColor,
            weight: config.borderWeight,
            fillColor: options.color ?? config.colors.default,
            fillOpacity: config.fillOpacity
        });
    },

    initPoint(mapElement) {
        const latitude = parseFloat(mapElement.dataset.latitude);
        const longitude = parseFloat(mapElement.dataset.longitude);

        if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) {
            this.log('Coordonnées invalides', {
                latitude,
                longitude
            });

            return;
        }

        const map = L.map(mapElement).setView(
                [latitude, longitude],
                this.config.singlePointZoom
                );

        this.instances.set(mapElement, map);

        L.tileLayer(
                this.config.tileLayer.url,
                this.config.tileLayer.options
                ).addTo(map);

        this.createMarker(
                latitude,
                longitude,
                {color: this.config.marker.colors.default}
        ).addTo(map);

        this.invalidateMap(mapElement);
    },

    initMultipoint(mapElement) {
        const pointElements = Array.from(
                mapElement.querySelectorAll('[data-map-point]')
                );

        const map = L.map(mapElement);
        this.instances.set(mapElement, map);

        L.tileLayer(
                this.config.tileLayer.url,
                this.config.tileLayer.options
                ).addTo(map);

        const markers = [];

        pointElements.forEach((pointElement) => {
            const latitude = parseFloat(pointElement.dataset.latitude);
            const longitude = parseFloat(pointElement.dataset.longitude);

            if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) {
                this.log(
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

            const name = pointElement.dataset.name || '';

            marker.bindPopup(`
                <div>
                    <strong class="${this.config.popup.titleClass}">
                        ${this.escapeHtml(name)}
                    </strong>

                    <div class="${this.config.popup.metaClass}">
                        ${
                    isPrincipale
                    ? this.config.popup.texts.principale
                    : this.config.popup.texts.secondaire
                    }
                    </div>
                </div>
            `);

            marker.addTo(map);
            markers.push(marker);
        });

        this.setMapView(map, markers);

        this.invalidateMap(mapElement);
    },

    initHierarchical(mapElement) {
        const currentElement = mapElement.querySelector(
                '[data-map-current]'
                );

        const map = L.map(mapElement);
        this.instances.set(mapElement, map);

        L.tileLayer(
                this.config.tileLayer.url,
                this.config.tileLayer.options
                ).addTo(map);

        const markers = [];
        const zoneLayers = [];

        this.addCurrentMarker(
                map,
                currentElement,
                markers
                );

        const zones = Array.from(
                mapElement.querySelectorAll('[data-map-zone]')
                );

        const colors = this.getHierarchicalColors(zones.length);

        zones.forEach((zoneElement, zoneIndex) => {
            const color = colors[zoneIndex];
            const zoneName = zoneElement.dataset.zoneName || '';

            const pointElements = Array.from(
                    zoneElement.querySelectorAll('[data-map-point]')
                    );

            const points = [];

            pointElements.forEach((pointElement) => {
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

                points.push([latitude, longitude]);

                const marker = this.createMarker(
                        latitude,
                        longitude,
                        {color}
                );

                const name = pointElement.dataset.name || '';
                const url = pointElement.dataset.url || '';

                marker.bindPopup(
                        this.createPointPopup(name, url, zoneName)
                        );

                marker.addTo(map);
                markers.push(marker);
            });

            if (points.length === 0) {
                return;
            }

            const zone = this.createZonePolygon(
                    points,
                    color,
                    zoneName
                    );

            if (!zone) {
                return;
            }

            zone.addTo(map);

            this.log('Zone : ajoutée à la carte', {
                name: zoneName,
                mapExists: !!zone._map,
                boundsValid: zone.getBounds().isValid()
            });

            zoneLayers.push(zone);
        });

        this.setMapView(map, markers, zoneLayers);
        this.invalidateMap(mapElement);
    },

    addCurrentMarker(map, element, markers) {
        if (!element) {
            return;
        }

        const latitude = parseFloat(element.dataset.latitude);
        const longitude = parseFloat(element.dataset.longitude);

        if (
                !Number.isFinite(latitude) ||
                !Number.isFinite(longitude)
                ) {
            return;
        }

        const marker = this.createMarker(
                latitude,
                longitude,
                {
                    color: this.config.marker.colors.current
                }
        );

        const name = element.dataset.name || '';
        const url = element.dataset.url || '';

        marker.bindPopup(
                this.createPointPopup(name, url)
                );

        marker.addTo(map);
        markers.push(marker);
    },

    createPointPopup(name, url = '', zoneName = '') {
        return `
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
        `;
    },

    setMapView(map, markers = [], zoneLayers = []) {
        if (!map) {
            return;
        }

        if (markers.length === 0 && zoneLayers.length === 0) {
            map.setView(
                    [
                        this.config.defaultView.latitude,
                        this.config.defaultView.longitude
                    ],
                    this.config.defaultView.zoom
                    );

            return;
        }

        if (markers.length === 1 && zoneLayers.length === 0) {
            map.setView(
                    markers[0].getLatLng(),
                    this.config.singlePointZoom
                    );

            return;
        }

        const bounds = this.getLayersBounds(
                markers,
                zoneLayers
                );

        if (!bounds.isValid()) {
            map.setView(
                    [
                        this.config.defaultView.latitude,
                        this.config.defaultView.longitude
                    ],
                    this.config.defaultView.zoom
                    );

            return;
        }

        map.fitBounds(
                bounds.pad(this.config.bounds.padding)
                );
    },

    getLayersBounds(markers = [], zoneLayers = []) {
        const bounds = L.latLngBounds([]);

        markers.forEach((marker) => {
            if (!marker || typeof marker.getLatLng !== 'function') {
                return;
            }

            const latLng = marker.getLatLng();

            if (latLng) {
                bounds.extend(latLng);
            }
        });

        zoneLayers.forEach((zone) => {
            if (!zone || typeof zone.getBounds !== 'function') {
                return;
            }

            const zoneBounds = zone.getBounds();

            if (zoneBounds && zoneBounds.isValid()) {
                bounds.extend(zoneBounds);
            }
        });

        return bounds;
    },

    createZonePolygon(points, color, zoneName) {
        if (!points || points.length === 0) {
            return null;
        }

        const config = this.config.zone;

        this.log('Zone : début création', {
            name: zoneName,
            points: points.length
        });

        let zone = null;

        if (points.length === 1) {
            const radius =
                    config.singlePointRadiusMeters +
                    config.marginMeters;

            const circlePoints =
                    this.createCirclePolygon(
                            points[0],
                            radius,
                            48
                            );

            zone = L.polygon(circlePoints, {
                color,
                weight: config.borderWeight,
                opacity: config.borderOpacity,
                fillColor: color,
                fillOpacity: config.fillOpacity
            });
        } else if (points.length === 2) {
            const rectangle = this.createTwoPointZone(
                    points,
                    config.marginMeters
                    );

            zone = L.polygon(rectangle, {
                color,
                weight: config.borderWeight,
                opacity: config.borderOpacity,
                fillColor: color,
                fillOpacity: config.fillOpacity
            });
        } else {
            const hull = this.convexHull(points);

            if (hull.length < 3) {
                return null;
            }

            const expandedHull = this.expandPolygon(
                    hull,
                    config.marginMeters
                    );

            zone = L.polygon(expandedHull, {
                color,
                weight: config.borderWeight,
                opacity: config.borderOpacity,
                fillColor: color,
                fillOpacity: config.fillOpacity
            });
        }

        if (!zone) {
            return null;
        }

        this.log('Zone : layer créé', {
            name: zoneName,
            type: zone.constructor?.name || 'unknown'
        });

        const surfaceM2 =
                this.calculateZoneSurface(zone);

        const surfaceHa =
                surfaceM2 / 10000;

        zone.centralSurfaceM2 = surfaceM2;
        zone.centralSurfaceHa = surfaceHa;
        zone.centralMarginMeters =
                config.marginMeters;

        if (zoneName) {
            zone.bindPopup(`
            <div>
                <strong class="${this.config.popup.titleClass}">
                    ${this.escapeHtml(zoneName)}
                </strong>

                <div class="${this.config.popup.metaClass}">
                    Surface approximative :
                    ${this.formatSurface(surfaceM2)}
                </div>

                <div class="${this.config.popup.metaClass}">
                    Marge :
                    ${this.formatDistance(config.marginMeters)}
                </div>
            </div>
        `);
        }

        this.log('Zone créée', {
            name: zoneName,
            points: points.length,
            marginMeters: config.marginMeters,
            surfaceM2: Math.round(surfaceM2),
            surfaceHa: Number(surfaceHa.toFixed(2))
        });

        return zone;
    },
    createCirclePolygon(center, radiusMeters, segments = 48) {
        const [latitude, longitude] = center;
        const points = [];

        const metersPerDegreeLat = 111320;

        const metersPerDegreeLng =
                111320 *
                Math.cos(latitude * Math.PI / 180);

        const radiusLat =
                radiusMeters / metersPerDegreeLat;

        const radiusLng =
                radiusMeters / metersPerDegreeLng;

        for (let index = 0; index < segments; index++) {
            const angle =
                    (index / segments) * Math.PI * 2;

            points.push([
                latitude + Math.sin(angle) * radiusLat,
                longitude + Math.cos(angle) * radiusLng
            ]);
        }

        return points;
    },

    createTwoPointZone(points, marginMeters = 0) {
        const [pointA, pointB] = points;

        const lat1 = pointA[0];
        const lng1 = pointA[1];
        const lat2 = pointB[0];
        const lng2 = pointB[1];

        const latDiff = lat2 - lat1;
        const lngDiff = lng2 - lng1;

        const length = Math.sqrt(
                latDiff * latDiff +
                lngDiff * lngDiff
                );

        const baseOffset = Math.max(
                length * 0.15,
                0.002
                );

        const centerLat = (lat1 + lat2) / 2;

        const marginLat = marginMeters / 111320;

        const marginLng =
                marginMeters /
                (
                        111320 *
                        Math.cos(centerLat * Math.PI / 180)
                        );

        const perpLat =
                -lngDiff / (length || 1) * baseOffset;

        const perpLng =
                latDiff / (length || 1) * baseOffset;

        const finalPerpLat =
                Math.abs(perpLat) + marginLat;

        const finalPerpLng =
                Math.abs(perpLng) + marginLng;

        return [
            [
                lat1 - finalPerpLat,
                lng1 - finalPerpLng
            ],
            [
                lat2 - finalPerpLat,
                lng2 - finalPerpLng
            ],
            [
                lat2 + finalPerpLat,
                lng2 + finalPerpLng
            ],
            [
                lat1 + finalPerpLat,
                lng1 + finalPerpLng
            ]
        ];
    },

    convexHull(points) {
        const sorted = points
                .map(([lat, lng]) => [
                        Number(lat),
                        Number(lng)
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

        const cross = (o, a, b) => (
                    (a[1] - o[1]) * (b[0] - o[0]) -
                    (a[0] - o[0]) * (b[1] - o[1])
                    );

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

    expandPolygon(points, marginMeters) {
        if (
                !points ||
                points.length < 3 ||
                marginMeters <= 0
                ) {
            return points;
        }

        let centerLat = 0;
        let centerLng = 0;

        points.forEach(([lat, lng]) => {
            centerLat += lat;
            centerLng += lng;
        });

        centerLat /= points.length;
        centerLng /= points.length;

        const marginLat = marginMeters / 111320;

        const marginLng =
                marginMeters /
                (
                        111320 *
                        Math.cos(centerLat * Math.PI / 180)
                        );

        return points.map(([lat, lng]) => {
            const deltaLat = lat - centerLat;
            const deltaLng = lng - centerLng;

            const distance = Math.sqrt(
                    deltaLat * deltaLat +
                    deltaLng * deltaLng
                    );

            if (distance === 0) {
                return [
                    lat + marginLat,
                    lng
                ];
            }

            const directionLat = deltaLat / distance;
            const directionLng = deltaLng / distance;

            return [
                lat + directionLat * marginLat,
                lng + directionLng * marginLng
            ];
        });
    },

    calculateZoneSurface(layer) {
        if (!layer) {
            return 0;
        }

        if (typeof layer.getRadius === 'function') {
            const radius = layer.getRadius();

            return Math.PI * radius * radius;
        }

        if (typeof layer.getLatLngs === 'function') {
            const latLngs = layer.getLatLngs();

            if (!latLngs || latLngs.length === 0) {
                return 0;
            }

            const ring = Array.isArray(latLngs[0])
                    ? latLngs[0]
                    : latLngs;

            if (ring.length < 3) {
                return 0;
            }

            return this.calculatePolygonSurface(ring);
        }

        return 0;
    },

    calculatePolygonSurface(latLngs) {
        if (!latLngs || latLngs.length < 3) {
            return 0;
        }

        const earthRadius = 6371000;

        const meanLatitude =
                latLngs.reduce(
                        (sum, point) => sum + point.lat,
                        0
                        ) / latLngs.length;

        const degreesToRadians = Math.PI / 180;

        const metersPerDegreeLat =
                earthRadius * degreesToRadians;

        const metersPerDegreeLng =
                earthRadius *
                Math.cos(meanLatitude * degreesToRadians) *
                degreesToRadians;

        const points = latLngs.map((point) => ({
                x: point.lng * metersPerDegreeLng,
                y: point.lat * metersPerDegreeLat
            }));

        let area = 0;

        for (let index = 0; index < points.length; index++) {
            const current = points[index];
            const next = points[(index + 1) % points.length];

            area +=
                    current.x * next.y -
                    next.x * current.y;
        }

        return Math.abs(area) / 2;
    },

    formatSurface(surfaceM2) {
        if (
                !Number.isFinite(surfaceM2) ||
                surfaceM2 <= 0
                ) {
            return '0 m²';
        }

        if (surfaceM2 < 10000) {
            return `${Math.round(surfaceM2).toLocaleString('fr-FR')} m²`;
        }

        return `${(surfaceM2 / 10000).toLocaleString('fr-FR', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        })} ha`;
    },

    formatDistance(meters) {
        if (
                !Number.isFinite(meters) ||
                meters < 0
                ) {
            return '0 m';
        }

        if (meters < 1000) {
            return `${Math.round(meters).toLocaleString('fr-FR')} m`;
        }

        return `${(meters / 1000).toLocaleString('fr-FR', {
            minimumFractionDigits: 1,
            maximumFractionDigits: 2
        })} km`;
    },

    getHierarchicalColors(count) {
        if (count <= 0) {
            return [];
        }

        if (count === 1) {
            return [this.config.marker.colors.default];
        }

        const colors = [];
        const saturationLevels = [70, 75, 65];
        const lightnessLevels = [50, 45, 55];

        for (let index = 0; index < count; index++) {
            const hue = Math.round(
                    (index * 360 * 1.7) / count
                    ) % 360;

            const level =
                    index % saturationLevels.length;

            colors.push(
                    `hsl(${hue}, ${saturationLevels[level]}%, ${lightnessLevels[level]}%)`
                    );
        }

        return colors;
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

    invalidateMap(mapElement) {
        if (!mapElement) {
            return;
        }

        requestAnimationFrame(() => {
            const map = this.instances.get(mapElement);

            if (!map || !mapElement.isConnected) {
                return;
            }

            map.invalidateSize();
        });
    },

    invalidateMaps(scope = document) {
        scope.querySelectorAll('[data-map]').forEach((mapElement) => {
            this.invalidateMap(mapElement);
        });
    },

    isVisible(element) {
        if (!element) {
            return false;
        }

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
};
