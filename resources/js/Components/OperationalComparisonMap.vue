<script setup>
import maplibregl from 'maplibre-gl';
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import 'maplibre-gl/dist/maplibre-gl.css';

const props = defineProps({
    journeys: {
        type: Array,
        required: true,
    },
    driverColors: {
        type: Object,
        required: true,
    },
    selectedDriverKey: {
        type: String,
        default: 'all',
    },
    world: {
        type: String,
        required: true,
    },
});

const mapContainer = ref(null);
const mapErrorMessage = ref('');

let map = null;
let markerEntries = [];
let refreshFrame = null;

const highlightedJourneys = computed(() => {
    if (props.selectedDriverKey === 'all') {
        return [];
    }

    return props.journeys.filter((journey) => journey.driver_key === props.selectedDriverKey);
});

const highlightedDriverName = computed(() => highlightedJourneys.value[0]?.driver?.name ?? null);

const mapOverlayMessage = computed(() => {
    if (mapErrorMessage.value) {
        return mapErrorMessage.value;
    }

    if (props.journeys.length === 0) {
        return 'No hay rutas visibles para este mundo del corte operativo.';
    }

    if (highlightedJourneys.value.length === 0) {
        if (props.world === 'overlay') {
            return 'Cada conductor usa colores complementarios: real en tono profundo y propuesta en un color opuesto. Usa la leyenda para resaltar un conductor.';
        }

        return 'Cada color representa un conductor. Usa la leyenda debajo del mapa para resaltar una ruta y abrir su detalle.';
    }

    if (props.world === 'overlay') {
        return `Conductor resaltado: ${highlightedDriverName.value}. Se muestran sus rutas real y propuesta al mismo tiempo.`;
    }

    return `Ruta resaltada: ${highlightedDriverName.value}. Las paradas numeradas muestran la secuencia visible de este conductor.`;
});

function driverColor(driverKey) {
    return props.driverColors?.[driverKey] ?? '#0f172a';
}

function clampColorChannel(value) {
    return Math.max(0, Math.min(255, Math.round(value)));
}

function parseHexColor(color) {
    const normalized = String(color ?? '').replace('#', '');

    if (normalized.length !== 6) {
        return null;
    }

    return {
        r: Number.parseInt(normalized.slice(0, 2), 16),
        g: Number.parseInt(normalized.slice(2, 4), 16),
        b: Number.parseInt(normalized.slice(4, 6), 16),
    };
}

function toHexColor({ r, g, b }) {
    return `#${[r, g, b]
        .map((channel) => clampColorChannel(channel).toString(16).padStart(2, '0'))
        .join('')}`;
}

function mixHexColors(color, target, ratio) {
    const sourceRgb = parseHexColor(color);
    const targetRgb = parseHexColor(target);

    if (!sourceRgb || !targetRgb) {
        return color;
    }

    return toHexColor({
        r: sourceRgb.r + ((targetRgb.r - sourceRgb.r) * ratio),
        g: sourceRgb.g + ((targetRgb.g - sourceRgb.g) * ratio),
        b: sourceRgb.b + ((targetRgb.b - sourceRgb.b) * ratio),
    });
}

function rgbToHsl({ r, g, b }) {
    const red = r / 255;
    const green = g / 255;
    const blue = b / 255;
    const max = Math.max(red, green, blue);
    const min = Math.min(red, green, blue);
    const lightness = (max + min) / 2;
    const delta = max - min;

    if (delta === 0) {
        return { h: 0, s: 0, l: lightness };
    }

    const saturation = lightness > 0.5
        ? delta / (2 - max - min)
        : delta / (max + min);

    let hue;

    if (max === red) {
        hue = ((green - blue) / delta) + (green < blue ? 6 : 0);
    } else if (max === green) {
        hue = ((blue - red) / delta) + 2;
    } else {
        hue = ((red - green) / delta) + 4;
    }

    return { h: hue / 6, s: saturation, l: lightness };
}

function hueToRgb(p, q, t) {
    let value = t;

    if (value < 0) {
        value += 1;
    }

    if (value > 1) {
        value -= 1;
    }

    if (value < (1 / 6)) {
        return p + ((q - p) * 6 * value);
    }

    if (value < (1 / 2)) {
        return q;
    }

    if (value < (2 / 3)) {
        return p + ((q - p) * ((2 / 3) - value) * 6);
    }

    return p;
}

function hslToRgb({ h, s, l }) {
    if (s === 0) {
        const channel = l * 255;

        return { r: channel, g: channel, b: channel };
    }

    const q = l < 0.5 ? l * (1 + s) : l + s - (l * s);
    const p = (2 * l) - q;

    return {
        r: hueToRgb(p, q, h + (1 / 3)) * 255,
        g: hueToRgb(p, q, h) * 255,
        b: hueToRgb(p, q, h - (1 / 3)) * 255,
    };
}

function worldStrokeColor(driverKey, world) {
    const baseColor = driverColor(driverKey);

    if (props.world !== 'overlay') {
        return baseColor;
    }

    const baseRgb = parseHexColor(baseColor);

    if (!baseRgb) {
        return baseColor;
    }

    const { h, s } = rgbToHsl(baseRgb);

    if (world === 'historical') {
        return toHexColor(hslToRgb({
            h,
            s: Math.max(0.7, s),
            l: 0.34,
        }));
    }

    return toHexColor(hslToRgb({
        h: (h + 0.5) % 1,
        s: 0.9,
        l: 0.56,
    }));
}

function journeyWorld(journey) {
    return journey.world ?? props.world;
}

function journeyWorldLabel(journey) {
    return journeyWorld(journey) === 'historical' ? 'Cómo fue' : 'Cómo lo hubiéramos planillado';
}

function supportsWebGl() {
    const canvas = document.createElement('canvas');

    return Boolean(canvas.getContext('webgl') || canvas.getContext('experimental-webgl'));
}

function ensureMap(center) {
    if (map || !mapContainer.value) {
        return Boolean(map);
    }

    if (!supportsWebGl()) {
        mapErrorMessage.value = 'Este navegador no pudo inicializar el mapa. La comparación sigue disponible en la leyenda y el detalle por conductor.';
        return false;
    }

    try {
        mapErrorMessage.value = '';

        map = new maplibregl.Map({
            container: mapContainer.value,
            style: {
                version: 8,
                sources: {
                    osm: {
                        type: 'raster',
                        tiles: ['https://tile.openstreetmap.org/{z}/{x}/{y}.png'],
                        tileSize: 256,
                        attribution: '© OpenStreetMap contributors',
                    },
                },
                layers: [
                    {
                        id: 'osm-raster',
                        type: 'raster',
                        source: 'osm',
                    },
                ],
            },
            center,
            zoom: 11,
        });

        map.addControl(new maplibregl.NavigationControl({ showCompass: false }), 'top-right');
        map.once('load', () => {
            map?.resize();
        });
    } catch (error) {
        console.error(error);
        mapErrorMessage.value = 'No fue posible inicializar el mapa comparativo.';
        return false;
    }

    return true;
}

function markerElement(label, backgroundColor) {
    const node = document.createElement('div');
    node.style.width = '34px';
    node.style.height = '34px';
    node.style.borderRadius = '9999px';
    node.style.backgroundColor = backgroundColor;
    node.style.color = 'white';
    node.style.display = 'flex';
    node.style.alignItems = 'center';
    node.style.justifyContent = 'center';
    node.style.fontSize = '11px';
    node.style.fontWeight = '700';
    node.style.border = '2px solid white';
    node.style.boxShadow = '0 1px 4px rgba(0,0,0,0.35)';
    node.style.cursor = 'pointer';
    node.innerText = label;

    return node;
}

function escapeHtml(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#39;');
}

function popupField(label, value) {
    if (value === null || value === undefined || value === '') {
        return '';
    }

    return `
        <p style="margin: 4px 0 0; color: #334155; font-size: 12px; line-height: 1.45;">
            <strong>${escapeHtml(label)}:</strong> ${escapeHtml(value)}
        </p>
    `;
}

function markerLabelFor(journey, stop) {
    const sequence = String(stop.sequence ?? stop.suggested_sequence ?? '?');

    if (props.world !== 'overlay') {
        return sequence;
    }

    return `${journeyWorld(journey) === 'historical' ? 'R' : 'P'}${sequence}`;
}

function buildStopPopupHtml(journey, stop) {
    return `
        <div style="min-width: 240px;">
            <p style="margin: 0; color: #0f172a; font-size: 12px; font-weight: 700; letter-spacing: 0.04em; text-transform: uppercase;">
                ${escapeHtml(journeyWorldLabel(journey))}
            </p>
            <p style="margin: 6px 0 0; color: #0f172a; font-size: 14px; font-weight: 700;">
                ${escapeHtml(`${stop.sequence}. ${stop.branch_name}`)}
            </p>
            ${popupField('Conductor', journey.driver?.name)}
            ${popupField('Código', stop.branch_code)}
            ${popupField('Dirección', stop.branch_address)}
            ${popupField('Facturas', String(stop.invoice_count))}
        </div>
    `;
}

function clearMarkers() {
    for (const entry of markerEntries) {
        entry.popup.remove();
        entry.marker.remove();
    }

    markerEntries = [];
}

function removeRouteLayers() {
    if (!map) {
        return;
    }

    for (const layerId of [
        'comparison-routes-base',
        'comparison-routes-highlighted',
        'comparison-routes-historical-base',
        'comparison-routes-proposed-base',
        'comparison-routes-historical-highlighted',
        'comparison-routes-proposed-highlighted',
    ]) {
        if (map.getLayer(layerId)) {
            map.removeLayer(layerId);
        }
    }

    if (map.getSource('comparison-routes')) {
        map.removeSource('comparison-routes');
    }
}

function clearMapPresentation() {
    clearMarkers();

    if (!map || !map.isStyleLoaded()) {
        return;
    }

    removeRouteLayers();
}

function routeFeatures() {
    return props.journeys
        .filter((journey) => Array.isArray(journey.route_preview?.geometry) && journey.route_preview.geometry.length > 1)
        .map((journey) => ({
            type: 'Feature',
            properties: {
                driver_key: journey.driver_key,
                world: journeyWorld(journey),
                color: worldStrokeColor(journey.driver_key, journeyWorld(journey)),
                highlighted: highlightedJourneys.value.some((entry) => entry.world === journey.world && entry.driver_key === journey.driver_key) ? 1 : 0,
            },
            geometry: {
                type: 'LineString',
                coordinates: journey.route_preview.geometry.map((point) => [point.lng, point.lat]),
            },
        }));
}

function addSingleWorldLayers() {
    map.addLayer({
        id: 'comparison-routes-base',
        type: 'line',
        source: 'comparison-routes',
        paint: {
            'line-color': ['coalesce', ['get', 'color'], '#0f172a'],
            'line-width': 3.4,
            'line-opacity': props.selectedDriverKey === 'all' ? 0.8 : 0.16,
        },
    });

    if (props.selectedDriverKey !== 'all') {
        map.addLayer({
            id: 'comparison-routes-highlighted',
            type: 'line',
            source: 'comparison-routes',
            filter: ['==', ['get', 'highlighted'], 1],
            paint: {
                'line-color': ['coalesce', ['get', 'color'], '#0f172a'],
                'line-width': 5.6,
                'line-opacity': 0.98,
            },
        });
    }
}

function addOverlayLayers() {
    map.addLayer({
        id: 'comparison-routes-historical-base',
        type: 'line',
        source: 'comparison-routes',
        filter: ['==', ['get', 'world'], 'historical'],
        paint: {
            'line-color': ['coalesce', ['get', 'color'], '#0f172a'],
            'line-width': 3.2,
            'line-opacity': props.selectedDriverKey === 'all' ? 0.82 : 0.14,
        },
    });

    map.addLayer({
        id: 'comparison-routes-proposed-base',
        type: 'line',
        source: 'comparison-routes',
        filter: ['==', ['get', 'world'], 'proposed'],
        paint: {
            'line-color': ['coalesce', ['get', 'color'], '#0f172a'],
            'line-width': 4.8,
            'line-opacity': props.selectedDriverKey === 'all' ? 0.96 : 0.22,
            'line-dasharray': [2.4, 1.2],
        },
    });

    if (props.selectedDriverKey !== 'all') {
        map.addLayer({
            id: 'comparison-routes-historical-highlighted',
            type: 'line',
            source: 'comparison-routes',
            filter: ['all', ['==', ['get', 'world'], 'historical'], ['==', ['get', 'highlighted'], 1]],
            paint: {
                'line-color': ['coalesce', ['get', 'color'], '#0f172a'],
                'line-width': 5.8,
                'line-opacity': 0.98,
            },
        });

        map.addLayer({
            id: 'comparison-routes-proposed-highlighted',
            type: 'line',
            source: 'comparison-routes',
            filter: ['all', ['==', ['get', 'world'], 'proposed'], ['==', ['get', 'highlighted'], 1]],
            paint: {
                'line-color': ['coalesce', ['get', 'color'], '#0f172a'],
                'line-width': 7.2,
                'line-opacity': 1,
                'line-dasharray': [2.4, 1.2],
            },
        });
    }
}

function addRouteLayers() {
    if (!map || !map.isStyleLoaded()) {
        return;
    }

    const features = routeFeatures();
    if (features.length === 0) {
        return;
    }

    map.addSource('comparison-routes', {
        type: 'geojson',
        data: {
            type: 'FeatureCollection',
            features,
        },
    });

    if (props.world === 'overlay') {
        addOverlayLayers();
        return;
    }

    addSingleWorldLayers();
}

function addDepotMarker() {
    if (!map || props.journeys.length === 0) {
        return;
    }

    const depot = props.journeys[0]?.route_preview?.depot;
    if (!depot) {
        return;
    }

    const element = markerElement('D', '#111827');
    const popup = new maplibregl.Popup({ offset: 18 }).setHTML(`
        <div style="min-width: 220px;">
            <p style="margin: 0; color: #0f172a; font-size: 12px; font-weight: 700; letter-spacing: 0.04em; text-transform: uppercase;">
                Depot
            </p>
            <p style="margin: 6px 0 0; color: #0f172a; font-size: 14px; font-weight: 700;">
                ${escapeHtml(depot.name)}
            </p>
            ${popupField('Código', depot.code)}
            ${popupField('Dirección', depot.address)}
        </div>
    `);

    const marker = new maplibregl.Marker({ element })
        .setLngLat([depot.lng, depot.lat])
        .setPopup(popup)
        .addTo(map);

    markerEntries.push({ marker, popup });
}

function addStopMarkers() {
    if (!map || highlightedJourneys.value.length === 0) {
        return;
    }

    for (const journey of highlightedJourneys.value) {
        const color = worldStrokeColor(journey.driver_key, journeyWorld(journey));

        for (const stop of journey.stops ?? []) {
            const element = markerElement(markerLabelFor(journey, stop), color);
            const popup = new maplibregl.Popup({ offset: 18 }).setHTML(buildStopPopupHtml(journey, stop));

            const marker = new maplibregl.Marker({ element })
                .setLngLat([stop.lng, stop.lat])
                .setPopup(popup)
                .addTo(map);

            markerEntries.push({ marker, popup });
        }
    }
}

function fitBounds() {
    if (!map || props.journeys.length === 0) {
        return;
    }

    const journeys = highlightedJourneys.value.length > 0 ? highlightedJourneys.value : props.journeys;
    const coordinates = [];

    for (const journey of journeys) {
        for (const point of journey.route_preview?.geometry ?? []) {
            coordinates.push([point.lng, point.lat]);
        }
    }

    if (coordinates.length === 0) {
        return;
    }

    const bounds = coordinates.reduce(
        (currentBounds, coordinate) => currentBounds.extend(coordinate),
        new maplibregl.LngLatBounds(coordinates[0], coordinates[0]),
    );

    map.fitBounds(bounds, { padding: 56, maxZoom: highlightedJourneys.value.length > 0 ? 13 : 12, duration: 0 });
}

function refreshMap() {
    if (refreshFrame !== null) {
        cancelAnimationFrame(refreshFrame);
    }

    refreshFrame = requestAnimationFrame(async () => {
        refreshFrame = null;

        await nextTick();

        const firstJourney = props.journeys[0];
        const defaultCenter = firstJourney?.route_preview?.geometry?.[0]
            ? [firstJourney.route_preview.geometry[0].lng, firstJourney.route_preview.geometry[0].lat]
            : [-74.1007, 4.6762];

        if (!ensureMap(defaultCenter)) {
            return;
        }

        map.resize();
        clearMapPresentation();

        if (!map.isStyleLoaded()) {
            map.once('load', refreshMap);
            return;
        }

        addRouteLayers();
        addDepotMarker();
        addStopMarkers();
        fitBounds();
    });
}

watch(() => props.journeys, refreshMap, { deep: true, immediate: true });
watch(() => props.selectedDriverKey, refreshMap);
watch(() => props.world, refreshMap);
watch(() => props.driverColors, refreshMap, { deep: true });

onBeforeUnmount(() => {
    if (refreshFrame !== null) {
        cancelAnimationFrame(refreshFrame);
    }

    clearMarkers();
    map?.remove();
    map = null;
});
</script>

<template>
    <section class="rounded-[2rem] border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.24em] text-sky-600">
                    {{ world === 'historical' ? 'Cómo fue' : world === 'proposed' ? 'Cómo lo hubiéramos planillado' : 'Vista superpuesta' }}
                </p>
                <p class="mt-1 text-sm text-slate-600">
                    {{ world === 'overlay' ? 'Mismo color por conductor en ambos mundos. Sólido = real, punteado = propuesta.' : 'Mapa de rutas del corte completo. Cada color corresponde a un conductor.' }}
                </p>
            </div>

            <div
                v-if="selectedDriverKey !== 'all' && highlightedDriverName"
                class="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-medium text-slate-700"
            >
                <span class="h-2.5 w-2.5 rounded-full" :style="{ backgroundColor: driverColor(selectedDriverKey) }" />
                {{ highlightedDriverName }} resaltado
            </div>
        </div>

        <div class="relative mt-5 overflow-hidden rounded-[1.5rem] border border-slate-200 bg-slate-50">
            <div ref="mapContainer" class="h-[540px] w-full" />
            <div
                v-if="mapOverlayMessage"
                class="pointer-events-none absolute inset-x-4 top-4 rounded-2xl border border-white/80 bg-white/95 px-4 py-3 text-sm text-slate-600 shadow-sm backdrop-blur"
            >
                {{ mapOverlayMessage }}
            </div>
        </div>
    </section>
</template>
