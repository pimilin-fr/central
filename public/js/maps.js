const CentralMaps = {
    instances: new Map(),

    // État "recolorisable" de chaque carte (marqueurs, zones) : permet de
    // réappliquer les couleurs quand le thème change, sans recréer la carte.
    states: new Map(),

    // Couleur lue dans les variables du thème courant (aucune couleur en dur).
    themeColor(name, fallback = '#808080') {
        const value = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
        return value || fallback;
    },

    config: {
        debug: true,
        version: 'v1.4.0',
        appName: 'Central-ModuleMap',

        marker: {
            radius: 10,
            currentRadiusBonus: 4,
            borderWeight: 2,
            currentBorderWeight: 3,
            fillOpacity: 0.8,
            get borderColor() { return CentralMaps.themeColor('--surface'); },
            get currentBorderColor() { return CentralMaps.themeColor('--text'); },
            colors: {
                get default() { return CentralMaps.themeColor('--accent'); },
                get current() { return CentralMaps.themeColor('--accent'); },
                get principale() { return CentralMaps.themeColor('--accent'); },
                get secondaire() { return CentralMaps.themeColor('--text-soft'); }
            }
        },

        zone: {
            fillOpacityLight: 0.14,
            fillOpacityDark: 0.20,
            borderOpacity: 0.9,
            borderWeight: 2,
            // Marge autour de l'enveloppe des points (zones de 3 points et plus).
            marginMeters: 100,
            // Zones de 1 ou 2 points : demi-largeur du rectangle (1 point = carré de 2 × cette valeur).
            minHalfMeters: 150,
            // Angle des pointes au-delà duquel un coin est biseauté (limite de "mitre").
            miterLimit: 2,
            // Point central non rattaché explicitement : rattaché à la zone la plus proche
            // si elle est à moins de cette distance (sinon marqueur isolé).
            attachMaxMeters: 5000,
            // Un point central à moins de cette distance d'un point de la zone est le même point.
            sameSpotMeters: 5
        },

        // Génération des couleurs de zones (voir buildPalette).
        palette: {
            chroma: {min: 0.11, max: 0.17},
            lightness: {light: [0.48, 0.64], dark: [0.66, 0.82]},
            // Variantes utilisées quand il y a beaucoup de zones (par "étage" de 8 couleurs).
            tiers: [
                {dl: 0, c: 1},
                {dl: 0.11, c: 0.8},
                {dl: -0.11, c: 1},
                {dl: 0.055, c: 0.6}
            ],
            perTier: 8
        },

        popup: {
            titleClass: 'map-popup-title',
            metaClass: 'map-popup-meta',
            linkClass: 'map-popup-link',
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

        this.watchTheme();
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

    // Réapplique les couleurs quand le thème change (attribut data-theme de <html>).
    watchTheme() {
        if (this.themeObserver || typeof MutationObserver === 'undefined') {
            return;
        }

        let pending = false;

        this.themeObserver = new MutationObserver(() => {
            if (pending) {
                return;
            }

            pending = true;

            requestAnimationFrame(() => {
                pending = false;
                this.restyleAll();
            });
        });

        this.themeObserver.observe(document.documentElement, {
            attributes: true,
            attributeFilter: ['data-theme']
        });
    },

    restyleAll() {
        this.states.forEach((state, mapElement) => {
            if (!mapElement.isConnected) {
                this.states.delete(mapElement);
                this.instances.delete(mapElement);
                return;
            }

            try {
                state.restyle();
            } catch (error) {
                console.error(`[${this.config.appName}] Recoloration impossible`, error);
            }
        });
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

    // ------------------------------------------------------------------
    // Marqueurs
    // ------------------------------------------------------------------

    markerStyle(color, central = false) {
        const config = this.config.marker;

        if (central) {
            return {
                radius: config.radius + config.currentRadiusBonus,
                color: config.currentBorderColor,
                weight: config.currentBorderWeight,
                fillColor: color,
                fillOpacity: 1
            };
        }

        return {
            radius: config.radius,
            color: config.borderColor,
            weight: config.borderWeight,
            fillColor: color,
            fillOpacity: config.fillOpacity
        };
    },

    createMarker(latitude, longitude, options = {}) {
        return L.circleMarker(
                [latitude, longitude],
                this.markerStyle(
                        options.color ?? this.config.marker.colors.default,
                        options.central === true
                        )
                );
    },

    newMap(mapElement) {
        const map = L.map(mapElement);
        this.instances.set(mapElement, map);

        L.tileLayer(
                this.config.tileLayer.url,
                this.config.tileLayer.options
                ).addTo(map);

        return map;
    },

    // ------------------------------------------------------------------
    // Cartes simples
    // ------------------------------------------------------------------

    initPoint(mapElement) {
        const latitude = parseFloat(mapElement.dataset.latitude);
        const longitude = parseFloat(mapElement.dataset.longitude);

        if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) {
            this.log('Coordonnées invalides', {latitude, longitude});
            return;
        }

        const map = this.newMap(mapElement);
        map.setView([latitude, longitude], this.config.singlePointZoom);

        const marker = this.createMarker(latitude, longitude).addTo(map);

        this.states.set(mapElement, {
            restyle: () => marker.setStyle(
                        this.markerStyle(this.config.marker.colors.default)
                        )
        });

        this.invalidateMap(mapElement);
    },

    initMultipoint(mapElement) {
        const pointElements = Array.from(mapElement.querySelectorAll('[data-map-point]'));
        const map = this.newMap(mapElement);

        const markers = [];
        const roles = [];

        pointElements.forEach((pointElement) => {
            const latitude = parseFloat(pointElement.dataset.latitude);
            const longitude = parseFloat(pointElement.dataset.longitude);

            if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) {
                this.log('Point multipoint ignoré : coordonnées invalides', pointElement.dataset);
                return;
            }

            const isPrincipale = pointElement.dataset.principale === '1';
            const role = isPrincipale ? 'principale' : 'secondaire';
            const name = pointElement.dataset.name || '';
            const url = pointElement.dataset.url || '';
            const meta = this.config.popup.texts[role];

            const marker = this.createMarker(latitude, longitude, {
                color: this.config.marker.colors[role]
            });

            marker.bindPopup(this.createPointPopup(name, url, meta));
            marker.addTo(map);
            markers.push(marker);
            roles.push(role);
        });

        this.states.set(mapElement, {
            restyle: () => markers.forEach((marker, index) => marker.setStyle(
                        this.markerStyle(this.config.marker.colors[roles[index]])
                        ))
        });

        this.setMapView(map, markers);
        this.invalidateMap(mapElement);
    },

    // ------------------------------------------------------------------
    // Carte hiérarchique : zones + point central
    // ------------------------------------------------------------------

    readPoint(element) {
        const latitude = parseFloat(element.dataset.latitude);
        const longitude = parseFloat(element.dataset.longitude);

        if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) {
            return null;
        }

        return {
            latitude,
            longitude,
            name: element.dataset.name || '',
            url: element.dataset.url || '',
            id: element.dataset.id || ''
        };
    },

    /**
     * Rattache le point central à sa zone. Ordre :
     *  1. le point est DANS un élément [data-map-zone] ;
     *  2. il porte data-zone-name = nom d'une zone ;
     *  3. il tombe dans la forme d'une zone ;
     *  4. sinon la zone la plus proche (config.zone.attachMaxMeters).
     * Retourne l'index de la zone, ou -1.
     */
    findCurrentZone(currentElement, current, zones) {
        if (zones.length === 0) {
            return -1;
        }

        const parent = currentElement.closest('[data-map-zone]');
        let index = zones.findIndex((zone) => zone.element === parent);

        if (index >= 0) {
            return index;
        }

        const wanted = currentElement.dataset.zoneName;

        if (wanted) {
            index = zones.findIndex((zone) => zone.name === wanted);

            if (index >= 0) {
                return index;
            }
        }

        const target = [current.latitude, current.longitude];

        for (let i = 0; i < zones.length; i++) {
            const ring = this.buildZoneShape(zones[i].points.map((p) => [p.latitude, p.longitude]));

            if (ring && this.isPointInPolygon(target, ring.map(([lat, lng]) => ({lat, lng})))) {
                return i;
            }
        }

        let best = -1;
        let bestDistance = Infinity;

        zones.forEach((zone, i) => {
            zone.points.forEach((p) => {
                const distance = this.distanceMeters(target, [p.latitude, p.longitude]);

                if (distance < bestDistance) {
                    bestDistance = distance;
                    best = i;
                }
            });
        });

        return bestDistance <= this.config.zone.attachMaxMeters ? best : -1;
    },

    // Garde-fou : quoi qu'il arrive dans le calcul des zones/couleurs, la carte
    // reste affichée (marqueurs simples) et l'erreur est visible dans la console.
    initHierarchical(mapElement) {
        const map = this.newMap(mapElement);

        try {
            this.buildHierarchical(mapElement, map);
        } catch (error) {
            console.error(`[${this.config.appName}] Carte hiérarchique : mode dégradé`, error);

            const markers = [];

            mapElement.querySelectorAll('[data-map-point], [data-map-current]').forEach((element) => {
                const point = this.readPoint(element);

                if (point) {
                    markers.push(this.createMarker(point.latitude, point.longitude).addTo(map));
                }
            });

            this.setMapView(map, markers);
            this.invalidateMap(mapElement);
        }
    },

    buildHierarchical(mapElement, map) {
        // 1. Lecture des zones et de leurs points
        const zones = [];

        Array.from(mapElement.querySelectorAll('[data-map-zone]')).forEach((zoneElement) => {
            const name = zoneElement.dataset.zoneName || '';
            const points = [];

            Array.from(zoneElement.querySelectorAll('[data-map-point]')).forEach((pointElement) => {
                const point = this.readPoint(pointElement);

                if (point) {
                    points.push(point);
                } else {
                    this.log('Point hiérarchique ignoré : coordonnées invalides', pointElement.dataset);
                }
            });

            if (points.length === 0) {
                this.log('Zone sans point géolocalisé', {name});
                return;
            }

            zones.push({element: zoneElement, name, points, central: null});
        });

        // 2. Point central : inclus dans sa zone (même couleur, forme englobante)
        const currentElement = mapElement.querySelector('[data-map-current]');
        const current = currentElement ? this.readPoint(currentElement) : null;
        let loose = null; // point central sans zone

        if (current) {
            const zoneIndex = this.findCurrentZone(currentElement, current, zones);

            if (zoneIndex >= 0) {
                const zone = zones[zoneIndex];
                const target = [current.latitude, current.longitude];
                const same = zone.points.find((p) => this.distanceMeters(
                                    target, [p.latitude, p.longitude]
                                    ) <= this.config.zone.sameSpotMeters);

                if (same) {
                    zone.central = same; // déjà présent : il devient le point central
                } else {
                    zone.points.push(current);
                    zone.central = current;
                }

                this.log('Point central rattaché à la zone', {zone: zone.name, name: current.name});
            } else {
                loose = current;
                this.log('Point central sans zone : marqueur isolé', {name: current.name});
            }
        }

        // 3. Création des couches
        const markers = [];
        const layers = [];

        // Couleurs dès la création (on ne restyle qu'après chargement de la carte)
        const colors = this.buildPalette(zones.length);
        const zoneFillOpacity = this.zoneFillOpacity();

        // Zones d'abord (elles passent ainsi sous les marqueurs), puis les marqueurs
        zones.forEach((zone, index) => {
            zone.color = colors[index];
            zone.layer = this.createZonePolygon(
                    zone.points.map((p) => [p.latitude, p.longitude]),
                    zone.color,
                    zone.name,
                    zoneFillOpacity
                    );

            if (zone.layer) {
                zone.layer.addTo(map);
                layers.push(zone.layer);
            }
        });

        zones.forEach((zone) => {
            zone.markers = zone.points.map((point) => {
                const isCentral = zone.central === point;
                const marker = this.createMarker(point.latitude, point.longitude, {color: zone.color, central: isCentral});

                marker.bindPopup(this.createPointPopup(point.name, point.url, zone.name));
                marker.addTo(map);
                markers.push(marker);

                return {marker, central: isCentral};
            });
        });

        let looseMarker = null;

        if (loose) {
            looseMarker = this.createMarker(loose.latitude, loose.longitude, {color: this.config.marker.colors.current, central: true});
            looseMarker.bindPopup(this.createPointPopup(loose.name, loose.url));
            looseMarker.addTo(map);
            markers.push(looseMarker);
        }

        // 4. Couleurs (calculées depuis le thème, réappliquées à chaque changement de thème)
        const restyle = () => {
            const palette = this.buildPalette(zones.length);
            const fillOpacity = this.zoneFillOpacity();

            zones.forEach((zone, index) => {
                const color = palette[index];

                if (zone.layer) {
                    zone.layer.setStyle({
                        color,
                        fillColor: color,
                        fillOpacity,
                        opacity: this.config.zone.borderOpacity
                    });
                }

                zone.markers.forEach(({marker, central}) => {
                    marker.setStyle(this.markerStyle(color, central));
                });
            });

            if (looseMarker) {
                looseMarker.setStyle(this.markerStyle(this.config.marker.colors.current, true));
            }
        };

        this.states.set(mapElement, {restyle});

        this.setMapView(map, markers, layers);
        this.invalidateMap(mapElement);
    },

    createPointPopup(name, url = '', zoneName = '') {
        const safeName = this.escapeHtml(name || 'Adresse');
        const safeZoneName = this.escapeHtml(zoneName || '');
        const safeUrl = url ? this.escapeAttribute(url) : '';

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

    // ------------------------------------------------------------------
    // Géométrie des zones (calculée en mètres, dans un plan local)
    // ------------------------------------------------------------------

    zoneFillOpacity() {
        return this.isDarkTheme()
                ? this.config.zone.fillOpacityDark
                : this.config.zone.fillOpacityLight;
    },

    createZonePolygon(points, color, zoneName, fillOpacity = null) {
        const ring = this.buildZoneShape(points);

        if (!ring) {
            this.log('Zone non créée', {name: zoneName, points: points ? points.length : 0});
            return null;
        }

        const config = this.config.zone;

        return L.polygon(ring, {
            color,
            weight: config.borderWeight,
            opacity: config.borderOpacity,
            fillColor: color,
            fillOpacity: fillOpacity ?? config.fillOpacityLight,
            lineJoin: 'round',
            interactive: false
        });
    },

    /**
     * Forme de la zone, en [lat, lng] :
     *  - 1 point            : carré ;
     *  - 2 points / alignés : rectangle orienté le long des points ;
     *  - 3 points et plus   : enveloppe convexe élargie d'une marge (coins biseautés si trop pointus).
     */
    buildZoneShape(points) {
        if (!points || points.length === 0) {
            return null;
        }

        const config = this.config.zone;
        const origin = this.centroid(points);
        const plane = points.map((point) => this.project(point, origin));

        // Points confondus : inutile de les compter deux fois
        const unique = [];

        plane.forEach((p) => {
            if (!unique.some((q) => Math.hypot(p.x - q.x, p.y - q.y) < 1)) {
                unique.push(p);
            }
        });

        let shape;

        if (unique.length === 1) {
            shape = this.paddedSegment(unique[0], unique[0], config.minHalfMeters);
        } else {
            const hull = this.convexHullPlane(unique);

            if (hull.length < 3) {
                shape = this.paddedSegment(hull[0], hull[1], config.minHalfMeters);
            } else {
                shape = this.offsetConvex(hull, config.marginMeters, config.miterLimit);
            }
        }

        return shape.map((p) => this.unproject(p, origin));
    },

    centroid(points) {
        const sum = points.reduce((acc, [lat, lng]) => [acc[0] + lat, acc[1] + lng], [0, 0]);

        return [sum[0] / points.length, sum[1] / points.length];
    },

    project([lat, lng], [lat0, lng0]) {
        return {
            x: (lng - lng0) * 111320 * Math.cos(lat0 * Math.PI / 180),
            y: (lat - lat0) * 111320
        };
    },

    unproject({x, y}, [lat0, lng0]) {
        return [
            lat0 + y / 111320,
            lng0 + x / (111320 * Math.cos(lat0 * Math.PI / 180))
        ];
    },

    distanceMeters(a, b) {
        const p = this.project(b, a);

        return Math.hypot(p.x, p.y);
    },

    // Rectangle englobant le segment a-b, élargi de `half` de chaque côté et aux extrémités.
    // a == b donne un carré de côté 2 × half.
    paddedSegment(a, b, half) {
        const length = Math.hypot(b.x - a.x, b.y - a.y);
        const ux = length > 0 ? (b.x - a.x) / length : 1;
        const uy = length > 0 ? (b.y - a.y) / length : 0;
        const vx = -uy;
        const vy = ux;

        const corner = (base, su, sv) => ({
            x: base.x + ux * half * su + vx * half * sv,
            y: base.y + uy * half * su + vy * half * sv
        });

        return [
            corner(a, -1, -1),
            corner(b, 1, -1),
            corner(b, 1, 1),
            corner(a, -1, 1)
        ];
    },

    // Enveloppe convexe (sens anti-horaire) de points {x, y}.
    convexHullPlane(points) {
        const sorted = points.slice().sort((a, b) => a.x - b.x || a.y - b.y);

        if (sorted.length <= 2) {
            return sorted;
        }

        const cross = (o, a, b) => (a.x - o.x) * (b.y - o.y) - (a.y - o.y) * (b.x - o.x);
        const build = (list) => {
            const chain = [];

            for (const point of list) {
                while (chain.length >= 2 && cross(chain[chain.length - 2], chain[chain.length - 1], point) <= 1) {
                    chain.pop();
                }

                chain.push(point);
            }

            chain.pop();

            return chain;
        };

        return build(sorted).concat(build(sorted.slice().reverse()));
    },

    // Décale un polygone convexe (anti-horaire) vers l'extérieur de `distance` mètres.
    offsetConvex(hull, distance, miterLimit = 2) {
        const count = hull.length;
        const result = [];

        const normal = (a, b) => {
            const length = Math.hypot(b.x - a.x, b.y - a.y) || 1;

            return {x: (b.y - a.y) / length, y: -(b.x - a.x) / length};
        };

        for (let i = 0; i < count; i++) {
            const previous = hull[(i + count - 1) % count];
            const point = hull[i];
            const next = hull[(i + 1) % count];

            const n1 = normal(previous, point);
            const n2 = normal(point, next);
            const dot = n1.x * n2.x + n1.y * n2.y;
            const denominator = 1 + dot;

            const mx = denominator > 1e-6 ? (n1.x + n2.x) * distance / denominator : 0;
            const my = denominator > 1e-6 ? (n1.y + n2.y) * distance / denominator : 0;

            if (denominator > 1e-6 && Math.hypot(mx, my) <= miterLimit * distance) {
                result.push({x: point.x + mx, y: point.y + my});
            } else {
                // coin trop pointu : biseau
                result.push({x: point.x + n1.x * distance, y: point.y + n1.y * distance});
                result.push({x: point.x + n2.x * distance, y: point.y + n2.y * distance});
            }
        }

        return result;
    },

    // ------------------------------------------------------------------
    // Couleurs (OKLCH) : palette dérivée de l'accent du thème
    // ------------------------------------------------------------------

    isDarkTheme() {
        const bg = this.parseColor(this.themeColor('--bg', '#ffffff'));

        return this.rgbToOklab(bg).L < 0.6;
    },

    parseColor(value) {
        let match = /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(value.trim());

        if (match) {
            let hex = match[1];

            if (hex.length === 3) {
                hex = hex.split('').map((c) => c + c).join('');
            }

            return [0, 2, 4].map((i) => parseInt(hex.slice(i, i + 2), 16) / 255);
        }

        match = /^rgba?\(\s*([\d.]+)[ ,]+([\d.]+)[ ,]+([\d.]+)/i.exec(value.trim());

        if (match) {
            return [1, 2, 3].map((i) => parseFloat(match[i]) / 255);
        }

        // Autre notation CSS (hsl, nom…) : on laisse le navigateur la convertir en rgb()
        if (typeof document !== 'undefined') {
            const probe = document.createElement('span');
            probe.style.color = value;
            document.body.appendChild(probe);
            const computed = getComputedStyle(probe).color;
            probe.remove();

            if (computed && computed !== value) {
                return this.parseColor(computed);
            }
        }

        return [0.5, 0.5, 0.5];
    },

    rgbToOklab([r, g, b]) {
        const lin = (c) => c <= 0.04045 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
        const [lr, lg, lb] = [lin(r), lin(g), lin(b)];

        const l = Math.cbrt(0.4122214708 * lr + 0.5363325363 * lg + 0.0514459929 * lb);
        const m = Math.cbrt(0.2119034982 * lr + 0.6806995451 * lg + 0.1073969566 * lb);
        const s = Math.cbrt(0.0883024619 * lr + 0.2817188376 * lg + 0.6299787005 * lb);

        return {
            L: 0.2104542553 * l + 0.7936177850 * m - 0.0040720468 * s,
            a: 1.9779984951 * l - 2.4285922050 * m + 0.4505937099 * s,
            b: 0.0259040371 * l + 0.7827717662 * m - 0.8086757660 * s
        };
    },

    oklabToLinear({L, a, b}) {
        const l = Math.pow(L + 0.3963377774 * a + 0.2158037573 * b, 3);
        const m = Math.pow(L - 0.1055613458 * a - 0.0638541728 * b, 3);
        const s = Math.pow(L - 0.0894841775 * a - 1.2914855480 * b, 3);

        return [
            4.0767416621 * l - 3.3077115913 * m + 0.2309699292 * s,
            -1.2684380046 * l + 2.6097574011 * m - 0.3413193965 * s,
            -0.0041960863 * l - 0.7034186147 * m + 1.7076147010 * s
        ];
    },

    // OKLCH -> "#rrggbb" ; la chroma est réduite jusqu'à entrer dans le gamut sRGB.
    oklchToHex(L, C, hueDegrees) {
        const h = hueDegrees * Math.PI / 180;
        const inGamut = (chroma) => this.oklabToLinear({
                L, a: chroma * Math.cos(h), b: chroma * Math.sin(h)
            }).every((v) => v >= -0.0005 && v <= 1.0005);

        let chroma = C;

        if (!inGamut(chroma)) {
            let low = 0;
            let high = C;

            for (let i = 0; i < 20; i++) {
                const middle = (low + high) / 2;

                if (inGamut(middle)) {
                    low = middle;
                } else {
                    high = middle;
                }
            }

            chroma = low;
        }

        const linear = this.oklabToLinear({L, a: chroma * Math.cos(h), b: chroma * Math.sin(h)});
        const gamma = (v) => {
            const c = Math.min(1, Math.max(0, v));

            return c <= 0.0031308 ? 12.92 * c : 1.055 * Math.pow(c, 1 / 2.4) - 0.055;
        };

        return '#' + linear
                .map((v) => Math.round(gamma(v) * 255).toString(16).padStart(2, '0'))
                .join('');
    },

    colorDistance(hexA, hexB) {
        const a = this.rgbToOklab(this.parseColor(hexA));
        const b = this.rgbToOklab(this.parseColor(hexB));

        return Math.hypot(a.L - b.L, a.a - b.a, a.b - b.b);
    },

    /**
     * Palette de `count` couleurs distinctes, liée au thème :
     *  - la 1re est exactement l'accent ;
     *  - les autres reprennent sa teinte de départ, la répartissent régulièrement
     *    sur le cercle chromatique et gardent une luminosité adaptée au fond
     *    (plus claire sur thème sombre, plus soutenue sur thème clair) ;
     *  - au-delà de 8 zones, des "étages" de luminosité/saturation différents s'ajoutent ;
     *  - l'ordre est choisi pour que deux zones consécutives soient toujours très différentes.
     */
    buildPalette(count, accentValue = null, dark = null) {
        if (count <= 0) {
            return [];
        }

        const config = this.config.palette;
        const accentColor = accentValue ?? this.themeColor('--accent', '#f97316');
        const accentLab = this.rgbToOklab(this.parseColor(accentColor));
        const isDark = dark ?? this.isDarkTheme();

        const accentHue = (Math.atan2(accentLab.b, accentLab.a) * 180 / Math.PI + 360) % 360;
        const accentChroma = Math.hypot(accentLab.a, accentLab.b);
        const accentHex = this.oklchToHex(accentLab.L, accentChroma, accentHue);

        if (count === 1) {
            return [accentHex];
        }

        const [lMin, lMax] = isDark ? config.lightness.dark : config.lightness.light;
        const baseL = Math.min(lMax, Math.max(lMin, accentLab.L));
        const baseC = Math.min(config.chroma.max, Math.max(config.chroma.min, accentChroma));

        const tierCount = Math.min(config.tiers.length, Math.ceil(count / config.perTier));
        const candidates = [];

        for (let tier = 0; tier < tierCount; tier++) {
            const slots = Math.floor(count / tierCount) + (tier < count % tierCount ? 1 : 0);
            const spec = config.tiers[tier];
            const stagger = tier * (360 / Math.max(slots, 1)) / tierCount;

            for (let slot = 0; slot < slots; slot++) {
                const hue = accentHue + stagger + slot * 360 / slots;

                candidates.push(
                        tier === 0 && slot === 0
                        ? accentHex
                        : this.oklchToHex(baseL + spec.dl, baseC * spec.c, hue)
                        );
            }
        }

        // Ordre "point le plus éloigné d'abord" en partant de l'accent
        const ordered = [candidates.shift()];

        while (candidates.length > 0) {
            let bestIndex = 0;
            let bestScore = -1;

            candidates.forEach((candidate, index) => {
                const score = Math.min(...ordered.map((c) => this.colorDistance(c, candidate)));

                if (score > bestScore) {
                    bestScore = score;
                    bestIndex = index;
                }
            });

            ordered.push(candidates.splice(bestIndex, 1)[0]);
        }

        return ordered;
    },

    // Conservé pour compatibilité : même résultat que buildPalette.
    getHierarchicalColors(count) {
        return this.buildPalette(count);
    },

    // ------------------------------------------------------------------
    // Surfaces / distances / utilitaires
    // ------------------------------------------------------------------

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

    // point = [lat, lng] ; polygon = tableau de {lat, lng}
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
            const a = polygon[index];
            const b = polygon[previous];

            const intersects =
                    (a.lng > longitude) !== (b.lng > longitude) &&
                    latitude < (b.lat - a.lat) * (longitude - a.lng) / (b.lng - a.lng) + a.lat;

            if (intersects) {
                inside = !inside;
            }
        }

        return inside;
    }
};

if (typeof module !== 'undefined' && module.exports) {
    module.exports = CentralMaps;
}
