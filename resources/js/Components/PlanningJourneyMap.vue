<script setup>
import maplibregl from 'maplibre-gl';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import 'maplibre-gl/dist/maplibre-gl.css';

const props = defineProps({
    journeys: {
        type: Array,
        required: true,
    },
});

const mapContainer = ref(null);
const mapErrorMessage = ref('');
const selectedJourneyId = ref(props.journeys[0]?.id ?? null);
const selectedJourneyStopId = ref(props.journeys[0]?.stops[0]?.id ?? null);

let map = null;
let markerEntries = [];
let refreshFrame = null;

const activeJourney = computed(() => {
    if (!selectedJourneyId.value) {
        return props.journeys[0] ?? null;
    }

    return props.journeys.find((journey) => journey.id === selectedJourneyId.value) ?? props.journeys[0] ?? null;
});

const activeRoutePreview = computed(() => activeJourney.value?.route_preview ?? null);
const activeJourneyStops = computed(() => activeJourney.value?.stops ?? []);
const activeProvider = computed(() => activeRoutePreview.value?.provider ?? null);
const isMockProvider = computed(() => activeProvider.value === 'mock');

function selectJourney(journeyId) {
    selectedJourneyId.value = journeyId;
    selectedJourneyStopId.value = activeJourney.value?.stops[0]?.id ?? null;
}

function summaryValue(summary, key) {
    return summary?.[key] ?? 0;
}

function supportsWebGl() {
    const canvas = document.createElement('canvas');

    return Boolean(
        canvas.getContext('webgl') || canvas.getContext('experimental-webgl'),
    );
}

function ensureMap(center) {
    if (map || !mapContainer.value) {
        return Boolean(map);
    }

    if (!supportsWebGl()) {
        mapErrorMessage.value = 'Este navegador no pudo inicializar el mapa. La propuesta sigue disponible en tablas y métricas.';
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
            zoom: 12,
        });

        map.addControl(new maplibregl.NavigationControl({ showCompass: false }), 'top-right');
        map.on('click', () => {
            closePopups();
        });
        map.once('load', () => {
            map?.resize();
        });
    } catch (error) {
        console.error(error);
        mapErrorMessage.value = 'No fue posible inicializar el mapa de la propuesta en este navegador.';
        return false;
    }

    return true;
}

function markerElement(label, backgroundColor) {
    const node = document.createElement('div');
    node.style.width = '30px';
    node.style.height = '30px';
    node.style.borderRadius = '9999px';
    node.style.backgroundColor = backgroundColor;
    node.style.color = 'white';
    node.style.display = 'flex';
    node.style.alignItems = 'center';
    node.style.justifyContent = 'center';
    node.style.fontSize = '12px';
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

function buildDepotPopupHtml(depot) {
    return `
        <div style="min-width: 220px;">
            <p style="margin: 0; color: #0f172a; font-size: 12px; font-weight: 700; letter-spacing: 0.04em; text-transform: uppercase;">
                CEDIS
            </p>
            <p style="margin: 6px 0 0; color: #0f172a; font-size: 14px; font-weight: 700;">
                ${escapeHtml(depot?.name ?? 'Depot')}
            </p>
            ${popupField('Codigo', depot?.code)}
            ${popupField('Direccion', depot?.address)}
        </div>
    `;
}

function buildStopPopupHtml(stop) {
    return `
        <div style="min-width: 240px;">
            <p style="margin: 0; color: #0f172a; font-size: 12px; font-weight: 700; letter-spacing: 0.04em; text-transform: uppercase;">
                Jornada propuesta
            </p>
            <p style="margin: 6px 0 0; color: #0f172a; font-size: 14px; font-weight: 700;">
                ${escapeHtml(`${stop.suggested_sequence}. ${stop.branch_name}`)}
            </p>
            ${popupField('Codigo', stop.branch_code)}
            ${popupField('Direccion', stop.branch_address)}
            ${popupField('Facturas', String(stop.invoice_count))}
            ${popupField('Hist. min', stop.historical_sequence_min ?? '')}
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

function clearMapPresentation() {
    clearMarkers();

    if (!map || !map.isStyleLoaded()) {
        return;
    }

    const routeSource = map.getSource('planning-route-line');
    if (routeSource) {
        routeSource.setData({
            type: 'FeatureCollection',
            features: [],
        });
    }
}

function closePopups(except = null) {
    for (const entry of markerEntries) {
        if (entry !== except) {
            entry.popup.remove();
        }
    }
}

function setMarkerSelectedState(entry, isSelected) {
    entry.element.style.boxShadow = isSelected
        ? '0 0 0 4px rgba(59, 130, 246, 0.18), 0 4px 10px rgba(15, 23, 42, 0.35)'
        : '0 1px 4px rgba(0,0,0,0.35)';
    entry.element.style.borderColor = isSelected ? '#dbeafe' : '#ffffff';
    entry.element.style.borderWidth = isSelected ? '3px' : '2px';
}

function applySelectedMarkerState() {
    for (const entry of markerEntries) {
        const isSelected = entry.kind === 'stop' && entry.stopId === selectedJourneyStopId.value;
        setMarkerSelectedState(entry, isSelected);
    }
}

function selectJourneyStop(stopId) {
    selectedJourneyStopId.value = stopId;
    const entry = markerEntries.find((marker) => marker.kind === 'stop' && marker.stopId === stopId);
    if (!entry || !map) {
        return;
    }

    closePopups(entry);
    entry.popup.addTo(map);
    map.flyTo({
        center: [entry.lng, entry.lat],
        zoom: Math.max(map.getZoom(), 13),
        essential: true,
    });
    applySelectedMarkerState();
}

function updateMapForJourney() {
    const journey = activeJourney.value;
    const routePreview = activeRoutePreview.value;

    clearMapPresentation();

    if (!journey || !routePreview?.depot) {
        return;
    }

    const depot = routePreview.depot;
    const center = [Number(depot.lng), Number(depot.lat)];
    if (!ensureMap(center)) {
        return;
    }

    const draw = () => {
        if (!map) {
            return;
        }

        map.resize();

        const routeGeoJson = {
            type: 'FeatureCollection',
            features: routePreview.geometry?.length
                ? [{
                    type: 'Feature',
                    geometry: {
                        type: 'LineString',
                        coordinates: routePreview.geometry.map((point) => [Number(point.lng), Number(point.lat)]),
                    },
                }]
                : [],
        };

        if (!map.getSource('planning-route-line')) {
            map.addSource('planning-route-line', {
                type: 'geojson',
                data: routeGeoJson,
            });

            map.addLayer({
                id: 'planning-route-line',
                type: 'line',
                source: 'planning-route-line',
                layout: {
                    'line-cap': 'round',
                    'line-join': 'round',
                },
                paint: {
                    'line-color': isMockProvider.value ? '#64748b' : '#0f766e',
                    'line-width': 5,
                    'line-opacity': isMockProvider.value ? 0.72 : 0.9,
                    'line-dasharray': isMockProvider.value ? [1, 1.6] : [1, 0.001],
                },
            });
        } else {
            map.getSource('planning-route-line').setData(routeGeoJson);
            map.setPaintProperty('planning-route-line', 'line-color', isMockProvider.value ? '#64748b' : '#0f766e');
            map.setPaintProperty('planning-route-line', 'line-opacity', isMockProvider.value ? 0.72 : 0.9);
            map.setPaintProperty('planning-route-line', 'line-dasharray', isMockProvider.value ? [1, 1.6] : [1, 0.001]);
        }

        const depotElement = markerElement('D', '#111827');
        const depotPopup = new maplibregl.Popup({
            closeButton: false,
            closeOnClick: false,
            offset: 16,
        }).setHTML(buildDepotPopupHtml(depot));

        const depotMarker = new maplibregl.Marker({ element: depotElement, anchor: 'bottom' })
            .setLngLat([Number(depot.lng), Number(depot.lat)])
            .setPopup(depotPopup)
            .addTo(map);

        depotElement.addEventListener('click', () => {
            closePopups();
            depotPopup.addTo(map);
        });

        markerEntries.push({
            kind: 'depot',
            element: depotElement,
            marker: depotMarker,
            popup: depotPopup,
            lat: Number(depot.lat),
            lng: Number(depot.lng),
        });

        for (const stop of activeJourneyStops.value) {
            const latitude = Number(stop.latitude);
            const longitude = Number(stop.longitude);
            if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) {
                continue;
            }

            const element = markerElement(String(stop.suggested_sequence), '#0284c7');
            const popup = new maplibregl.Popup({
                closeButton: false,
                closeOnClick: false,
                offset: 16,
            }).setHTML(buildStopPopupHtml(stop));

            const marker = new maplibregl.Marker({ element, anchor: 'bottom' })
                .setLngLat([longitude, latitude])
                .setPopup(popup)
                .addTo(map);

            const entry = {
                kind: 'stop',
                stopId: stop.id,
                element,
                marker,
                popup,
                lat: latitude,
                lng: longitude,
            };

            element.addEventListener('click', () => {
                selectedJourneyStopId.value = stop.id;
                closePopups(entry);
                popup.addTo(map);
                applySelectedMarkerState();
            });

            markerEntries.push(entry);
        }

        applySelectedMarkerState();

        const bounds = routePreview.bounds;
        if (bounds) {
            map.fitBounds(
                [
                    [Number(bounds.min_lng), Number(bounds.min_lat)],
                    [Number(bounds.max_lng), Number(bounds.max_lat)],
                ],
                { padding: 60, duration: 0 },
            );
        } else {
            map.setCenter(center);
            map.setZoom(12);
        }
    };

    if (map.loaded()) {
        draw();
        return;
    }

    map.once('load', draw);
}

function scheduleMapRefresh() {
    if (!mapContainer.value || typeof window === 'undefined') {
        return;
    }

    if (refreshFrame) {
        window.cancelAnimationFrame(refreshFrame);
    }

    refreshFrame = window.requestAnimationFrame(async () => {
        refreshFrame = null;
        await nextTick();
        updateMapForJourney();
    });
}

watch(
    () => props.journeys,
    (journeys) => {
        if (journeys.length === 0) {
            selectedJourneyId.value = null;
            selectedJourneyStopId.value = null;
            clearMapPresentation();
            return;
        }

        if (!journeys.some((journey) => journey.id === selectedJourneyId.value)) {
            selectedJourneyId.value = journeys[0].id;
        }

        const currentJourney = journeys.find((journey) => journey.id === selectedJourneyId.value) ?? journeys[0];
        if (!currentJourney.stops.some((stop) => stop.id === selectedJourneyStopId.value)) {
            selectedJourneyStopId.value = currentJourney.stops[0]?.id ?? null;
        }

        scheduleMapRefresh();
    },
    { immediate: true },
);

watch(
    () => activeJourney.value?.id,
    () => {
        scheduleMapRefresh();
    },
);

watch(selectedJourneyStopId, () => {
    applySelectedMarkerState();
});

watch(mapContainer, (container) => {
    if (!container) {
        return;
    }

    scheduleMapRefresh();
});

onMounted(() => {
    scheduleMapRefresh();
});

onBeforeUnmount(() => {
    if (refreshFrame && typeof window !== 'undefined') {
        window.cancelAnimationFrame(refreshFrame);
    }

    clearMarkers();

    if (map) {
        map.remove();
        map = null;
    }
});
</script>

<template>
    <section class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
        <div class="flex items-center justify-between gap-3">
            <div>
                <h3 class="text-lg font-semibold text-gray-900">Mapa de la propuesta</h3>
                <p class="mt-1 text-sm text-gray-600">
                    Revisa una jornada propuesta a la vez. El mapa toma la geometría persistida al generar la asignación.
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <span
                    v-if="activeRoutePreview?.provider"
                    class="rounded-full bg-gray-100 px-3 py-1 text-xs font-semibold text-gray-600"
                >
                    Provider: {{ activeRoutePreview.provider }}
                </span>
                <span
                    v-if="activeRoutePreview?.cache_hit"
                    class="rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-700"
                >
                    Cache hit
                </span>
            </div>
        </div>

        <div v-if="journeys.length === 0" class="mt-6 rounded-xl border border-dashed border-gray-300 p-5 text-sm text-gray-500">
            Aun no hay propuesta generada. Usa el boton superior para distribuir las paradas candidatas entre los conductores activos del depot.
        </div>

        <template v-else>
            <div
                v-if="isMockProvider"
                class="mt-4 rounded-xl border border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-700"
            >
                Esta jornada está usando provider `mock`. La línea del mapa representa una aproximación entre puntos, no una ruta vial real.
            </div>

            <div class="mt-6 grid gap-6 xl:grid-cols-[0.95fr_1.05fr]">
                <div class="space-y-3">
                    <button
                        v-for="journey in journeys"
                        :key="journey.id"
                        type="button"
                        class="w-full rounded-2xl border p-4 text-left transition"
                        :class="journey.id === activeJourney?.id
                            ? 'border-sky-300 bg-sky-50 shadow-sm'
                            : 'border-gray-200 bg-gray-50 hover:border-gray-300 hover:bg-white'"
                        @click="selectJourney(journey.id)"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-[0.22em] text-sky-600">
                                    {{ journey.driver.external_id ?? 'Sin external_id' }}
                                </p>
                                <h4 class="mt-2 text-base font-semibold text-gray-900">
                                    {{ journey.driver.name }}
                                </h4>
                                <p class="mt-1 text-sm text-gray-600">{{ journey.name }}</p>
                            </div>
                            <span class="rounded-full bg-white px-2.5 py-1 text-[11px] font-semibold text-gray-700 shadow-sm">
                                {{ journey.total_stops }} paradas
                            </span>
                        </div>
                    </button>
                </div>

                <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white">
                    <div
                        ref="mapContainer"
                        class="h-[460px] w-full bg-slate-100"
                    />
                    <div
                        v-if="mapErrorMessage"
                        class="border-t border-gray-200 px-4 py-3 text-sm text-amber-700"
                    >
                        {{ mapErrorMessage }}
                    </div>
                </div>
            </div>

            <div v-if="activeJourney" class="mt-6 rounded-2xl bg-gray-50 p-5">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.22em] text-sky-600">
                            Jornada activa
                        </p>
                        <h4 class="mt-2 text-lg font-semibold text-gray-900">
                            {{ activeJourney.driver.name }}
                        </h4>
                        <p class="mt-1 text-sm text-gray-600">{{ activeJourney.name }}</p>
                    </div>
                    <div class="grid grid-cols-2 gap-3 text-sm">
                        <div class="rounded-xl bg-white px-4 py-3">
                            <p class="text-gray-500">Distancia</p>
                            <p class="mt-1 font-semibold text-gray-900">
                                {{ (summaryValue(activeJourney.summary, 'distance_meters') / 1000).toFixed(2) }} km
                            </p>
                        </div>
                        <div class="rounded-xl bg-white px-4 py-3">
                            <p class="text-gray-500">Duracion</p>
                            <p class="mt-1 font-semibold text-gray-900">
                                {{ (summaryValue(activeJourney.summary, 'duration_seconds') / 60).toFixed(1) }} min
                            </p>
                        </div>
                    </div>
                </div>

                <ol class="mt-5 grid gap-3 md:grid-cols-2">
                    <li
                        v-for="stop in activeJourneyStops"
                        :key="stop.id"
                        class="rounded-xl border px-4 py-3 text-sm transition"
                        :class="stop.id === selectedJourneyStopId
                            ? 'border-sky-300 bg-sky-50'
                            : 'border-gray-200 bg-white hover:border-gray-300'"
                    >
                        <button type="button" class="w-full text-left" @click="selectJourneyStop(stop.id)">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <p class="font-medium text-gray-900">
                                        {{ stop.suggested_sequence }}. {{ stop.branch_code }} · {{ stop.branch_name }}
                                    </p>
                                    <p class="text-xs text-gray-500">{{ stop.branch_address }}</p>
                                </div>
                                <div class="text-right">
                                    <p class="font-semibold text-gray-800">{{ stop.invoice_count }} facturas</p>
                                    <p class="text-xs text-gray-500">Hist. min {{ stop.historical_sequence_min ?? 'n/a' }}</p>
                                </div>
                            </div>
                        </button>
                    </li>
                </ol>
            </div>
        </template>
    </section>
</template>
