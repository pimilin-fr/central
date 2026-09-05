const CentralMaps = {
    instances: new Map(),

    config: {
        debug: true,
        version: 'v1.3.8',
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
            marginMeters: 100,
            singlePointRadiusMeters: 150
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
        const pointElements = Array.from(mapElement.querySelectorAll('[data-map-point]'));
        const map = L.map(mapElement);
        this.instances.set(mapElement, map);

        L.tileLayer(this.config.tileLayer.url, this.config.tileLayer.options).addTo(map);

        const markers = [];

        pointElements.forEach((pointElement) => {
            const latitude = parseFloat(pointElement.dataset.latitude);
            const longitude = parseFloat(pointElement.dataset.longitude);

            if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) {
                this.log('Point multipoint ignoré : coordonnées invalides', pointElement.dataset);
                return;
            }

            const isPrincipale = pointElement.dataset.principale === '1';
            const name = pointElement.dataset.name || '';
            const url = pointElement.dataset.url || '';
            const meta = isPrincipale
                    ? this.config.popup.texts.principale
                    : this.config.popup.texts.secondaire;

            this.log('Point multipoint', {
                id: pointElement.dataset.id,
                name,
                url,
                principale: isPrincipale,
                latitude,
                longitude
            });

            const marker = this.createMarker(latitude, longitude, {
                color: isPrincipale
                        ? this.config.marker.colors.principale
                        : this.config.marker.colors.secondaire
            });

            marker.bindPopup(this.createPointPopup(name, url, meta));
            marker.addTo(map);
            markers.push(marker);
        });

        this.setMapView(map, markers);
        this.invalidateMap(mapElement);
    },

    initHierarchical(mapElement) {
        const currentElement = mapElement.querySelector('[data-map-current]');
        const map = L.map(mapElement);
        this.instances.set(mapElement, map);

        L.tileLayer(
                this.config.tileLayer.url,
                this.config.tileLayer.options
                ).addTo(map);

        const markers = [];
        const zoneLayers = [];
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
                    this.log(
                            'Point hiérarchique ignoré : coordonnées invalides',
                            pointElement.dataset
                            );

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

                this.log('Point hiérarchique', {
                    id: pointElement.dataset.id,
                    name,
                    url,
                    zoneName,
                    latitude,
                    longitude
                });

                marker.bindPopup(
                        this.createPointPopup(
                                name,
                                url,
                                zoneName
                                )
                        );

                marker.addTo(map);
                markers.push(marker);
            });

            if (points.length === 0) {
                this.log(
                        'Zone sans point géolocalisé',
                        {name: zoneName}
                );

                return;
            }

            const zone = this.createZonePolygon(
                    points,
                    color,
                    zoneName
                    );

            if (!zone) {
                this.log('Zone non créée', {
                    name: zoneName,
                    points: points.length
                });

                return;
            }

            zone.addTo(map);

            this.log('Zone : ajoutée à la carte', {
                name: zoneName,
                mapHasLayer: map.hasLayer(zone),
                boundsValid: zone.getBounds().isValid(),
                markersCount: markers.length
            });

            zoneLayers.push({
                layer: zone,
                color,
                name: zoneName
            });
        });

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
                let currentZone = null;

                for (const zone of zoneLayers) {
                    const latLngs = zone.layer.getLatLngs();
                    if (!latLngs || latLngs.length === 0) {
                        continue;
                    }

                    const polygon = Array.isArray(latLngs[0])
                            ? latLngs[0]
                            : latLngs;

                    if (
                            this.isPointInPolygon(
                                    [latitude, longitude],
                                    polygon
                                    )
                            ) {
                        currentZone = zone;
                        break;
                    }
                }

                if (currentZone) {
                    this.log(
                            'Adresse courante située dans sa zone : marqueur masqué',
                            {
                                name: currentElement.dataset.name || '',
                                zoneName: currentZone.name
                            }
                    );
                } else {
                    this.addCurrentMarker(
                            map,
                            currentElement,
                            markers,
                            currentZone
                            );
                }
            }
        }

        const layers = zoneLayers.map(
                (zone) => zone.layer
        );

        this.setMapView(
                map,
                markers,
                layers
                );

        this.invalidateMap(mapElement);
    },

    addCurrentMarker(map, element, markers, currentZone = null) {
        if (!element) {
            return;
        }

        const latitude = parseFloat(element.dataset.latitude);
        const longitude = parseFloat(element.dataset.longitude);

        if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) {
            return;
        }

        // Toujours utiliser la couleur de la zone
        const color = currentZone
                ? currentZone.color
                : this.config.marker.colors.default;

        // Forme différente pour le point du groupe
        const marker = L.circleMarker([latitude, longitude], {
            radius: this.config.marker.radius + 4, // plus gros
            color: '#000000', // bordure noire pour le distinguer
            weight: 3,
            fillColor: color,
            fillOpacity: 1
        });

        const name = element.dataset.name || '';
        const url = element.dataset.url || '';

        marker.bindPopup(
                this.createPointPopup(
                        name,
                        url
                        )
                );

        marker.addTo(map);
        markers.push(marker);

        this.log('Adresse courante : marqueur ajouté', {
            name,
            latitude,
            longitude,
            color
        });
    },

    createPointPopup(name, url = '', zoneName = '') {
        const safeName = this.escapeHtml(name || 'Adresse');
        const safeZoneName = this.escapeHtml(zoneName || '');
        const safeUrl = url ? this.escapeAttribute(url) : '';

        this.log('Popup point', {
            name,
            url,
            zoneName,
            safeUrl
        });

        return `
        <div>
            <strong class="${this.config.popup.titleClass}">
                ${safeName}
            </strong>
            ${safeZoneName ? `
                <div class="${this.config.popup.metaClass}">
                    ${safeZoneName}
                </div>
            ` : ''}
            ${safeUrl ? `
                <div>
                    <a href="${safeUrl}" class="${this.config.popup.linkClass}">
                        ${this.config.popup.texts.voirAdresse}
                    </a>
                </div>
            ` : ''}
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

        const polygonOptions = {
            color,
            weight: config.borderWeight,
            opacity: config.borderOpacity,
            fillColor: color,
            fillOpacity: config.fillOpacity,
            interactive: false
        };

        let zone = null;

        if (points.length === 1) {
            const radius =
                    config.singlePointRadiusMeters +
                    config.marginMeters;

            const circlePoints = this.createCirclePolygon(
                    points[0],
                    radius,
                    48
                    );

            zone = L.polygon(
                    circlePoints,
                    polygonOptions
                    );
        } else if (points.length === 2) {
            const rectangle = this.createTwoPointZone(
                    points,
                    config.marginMeters
                    );

            zone = L.polygon(
                    rectangle,
                    polygonOptions
                    );
        } else {
            const hull = this.convexHull(points);

            if (hull.length < 3) {
                return null;
            }

            const expandedHull = this.expandPolygon(
                    hull,
                    config.marginMeters
                    );

            zone = L.polygon(
                    expandedHull,
                    polygonOptions
                    );
        }

        if (!zone) {
            return null;
        }

        this.log('Zone : layer créé', {
            name: zoneName,
            type: zone.constructor?.name || 'unknown'
        });

        this.log('Zone créée', {
            name: zoneName,
            points: points.length,
            marginMeters: config.marginMeters
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
        const MAX = 30;
        const total = Math.min(count, MAX);

        if (total <= 0) {
            return [];
        }

        const colors = [];
        const step = 360 / total;

        for (let i = 0; i < total; i++) {
            const hue = (i * step) % 360;

            let saturation, lightness;

            // 1/3 normales
            if (i < total / 3) {
                saturation = 70;
                lightness = 50;
            }
            // 1/3 sombres
            else if (i < (2 * total) / 3) {
                saturation = 70;
                lightness = 35;
            }
            // 1/3 pastel
            else {
                saturation = 40;
                lightness = 75;
            }

            colors.push(`hsl(${hue}, ${saturation}%, ${lightness}%)`);
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
    },

    isPointInPolygon(point, polygon) {
        if (!point || !polygon || polygon.length < 3) {
            return false;
        }

        const [latitude, longitude] = point;
        let inside = false;

        for (
                let index = 0, previous = polygon.length - 1;
                index < polygon.length;
                previous = index++
                ) {
            const currentPoint = polygon[index];
            const previousPoint = polygon[previous];

            const currentLatitude = currentPoint.lat;
            const currentLongitude = currentPoint.lng;
            const previousLatitude = previousPoint.lat;
            const previousLongitude = previousPoint.lng;

            const intersects =
                    (
                            currentLongitude > longitude
                            ) !== (
                    previousLongitude > longitude
                    ) &&
                    latitude <
                    (
                            (
                                    previousLatitude -
                                    currentLatitude
                                    ) *
                            (
                                    longitude -
                                    currentLongitude
                                    ) /
                            (
                                    previousLongitude -
                                    currentLongitude
                                    ) +
                            currentLatitude
                            );

            if (intersects) {
                inside = !inside;
            }
        }

        return inside;
    },

};
