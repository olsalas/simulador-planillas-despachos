<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import axios from 'axios';
import maplibregl from 'maplibre-gl';
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import 'maplibre-gl/dist/maplibre-gl.css';

const props = defineProps({
    batches: {
        type: Array,
        required: true,
    },
    defaultBatchId: {
        type: Number,
        default: null,
    },
    routing: {
        type: Object,
        required: true,
    },
});

const form = ref({
    route_batch_id: props.defaultBatchId,
    return_to_depot: true,
});

const filters = ref({
    service_date: '',
    driver_query: '',
});

const loading = ref(false);
const errorMessage = ref('');
const comparisonData = ref(null);
const activeView = ref('historical');
const mapContainer = ref(null);
const mapErrorMessage = ref('');

let map = null;
let markers = [];

const availableDates = computed(() => [...new Set(props.batches.map((batch) => batch.service_date))]);
const filteredBatches = computed(() => {
    const serviceDate = filters.value.service_date;
    const driverQuery = filters.value.driver_query.trim().toLowerCase();

    return props.batches.filter((batch) => {
        if (serviceDate && batch.service_date !== serviceDate) {
            return false;
        }

        if (!driverQuery) {
            return true;
        }

        const haystack = [
            batch.driver_name ?? '',
            batch.driver_external_id ?? '',
            batch.label ?? '',
        ].join(' ').toLowerCase();

        return haystack.includes(driverQuery);
    });
});
const hasBatchOptions = computed(() => filteredBatches.value.length > 0);
const hasActiveFilters = computed(() => {
    return filters.value.service_date !== '' || filters.value.driver_query.trim() !== '';
});
const journey = computed(() => comparisonData.value?.journey ?? null);
const summary = computed(() => journey.value?.summary ?? null);
const historicalRoute = computed(() => comparisonData.value?.historical_route ?? null);
const suggestedRoute = computed(() => comparisonData.value?.suggested_route ?? null);
const activeRoute = computed(() => {
    if (!comparisonData.value) {
        return null;
    }

    return activeView.value === 'historical'
        ? historicalRoute.value
        : suggestedRoute.value;
});
const activeRouteProvider = computed(() => {
    return activeRoute.value?.provider ?? comparisonData.value?.historical_route?.provider ?? props.routing.effective_provider;
});
const isMockRouteActive = computed(() => activeRouteProvider.value === 'mock');
const mapOverlayMessage = computed(() => {
    if (mapErrorMessage.value) {
        return mapErrorMessage.value;
    }

    if (!comparisonData.value) {
        return 'Selecciona una jornada y ejecuta la comparación para visualizar el mapa.';
    }

    return '';
});
const distanceDeltaKm = computed(() => {
    if (!comparisonData.value) return null;

    return (comparisonData.value.delta.distance_meters / 1000).toFixed(2);
});
const durationDeltaMin = computed(() => {
    if (!comparisonData.value) return null;

    return (comparisonData.value.delta.duration_seconds / 60).toFixed(1);
});
const deltaLabel = computed(() => {
    if (!comparisonData.value) return '';

    const distanceDelta = comparisonData.value.delta.distance_meters;
    const durationDelta = comparisonData.value.delta.duration_seconds;

    if (distanceDelta < 0 || durationDelta < 0) {
        return 'Sugerido vs histórico';
    }

    return 'Diferencia vs histórico';
});

function routeDistanceKm(routeData) {
    if (!routeData) return '0.00';

    return (routeData.metrics.distance_meters / 1000).toFixed(2);
}

function routeDurationMin(routeData) {
    if (!routeData) return '0.0';

    return (routeData.metrics.duration_seconds / 60).toFixed(1);
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

function formatDepotSource(source) {
    const labels = {
        driver_depot: 'CEDIS asignado al conductor',
        first_active_depot: 'CEDIS activo por defecto',
        config_fallback_depot: 'CEDIS fallback de configuracion',
        first_stop_fallback: 'Primera parada como fallback',
        hardcoded_fallback: 'CEDIS temporal por defecto',
    };

    return labels[source] ?? source;
}

function buildDepotPopupHtml(depot) {
    return `
        <div style="min-width: 220px;">
            <p style="margin: 0; color: #0f172a; font-size: 12px; font-weight: 700; letter-spacing: 0.04em; text-transform: uppercase;">
                CEDIS
            </p>
            <p style="margin: 6px 0 0; color: #0f172a; font-size: 14px; font-weight: 700;">
                ${escapeHtml(depot.name)}
            </p>
            ${popupField('Codigo', depot.code)}
            ${popupField('Direccion', depot.address)}
            ${popupField('Origen', formatDepotSource(depot.source))}
        </div>
    `;
}

function buildStopPopupHtml(stop, routeLabel) {
    return `
        <div style="min-width: 240px;">
            <p style="margin: 0; color: #0f172a; font-size: 12px; font-weight: 700; letter-spacing: 0.04em; text-transform: uppercase;">
                ${escapeHtml(routeLabel)}
            </p>
            <p style="margin: 6px 0 0; color: #0f172a; font-size: 14px; font-weight: 700;">
                ${escapeHtml(`${stop.sequence}. ${stop.branch_name}`)}
            </p>
            ${popupField('Codigo', stop.branch_code)}
            ${popupField('Direccion', stop.branch_address)}
            ${popupField('Facturas', String(stop.invoice_count))}
            ${popupField('Secuencia actual', String(stop.sequence))}
            ${popupField('Secuencia historica', stop.historical_sequence !== null && stop.historical_sequence !== undefined ? `#${stop.historical_sequence}` : '')}
        </div>
    `;
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
        mapErrorMessage.value = 'Este navegador no pudo inicializar el mapa. Puedes seguir revisando métricas y secuencias mientras habilitamos una alternativa.';
        return false;
    }

    try {
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
    } catch (error) {
        console.error(error);
        mapErrorMessage.value = 'No fue posible inicializar el mapa en este navegador. La comparación sigue disponible en métricas y secuencias.';
        return false;
    }

    return true;
}

function clearMarkers() {
    for (const marker of markers) {
        marker.remove();
    }

    markers = [];
}

function clearMapPresentation() {
    clearMarkers();

    if (!map) {
        return;
    }

    const routeSource = map.getSource('route-line');
    if (routeSource) {
        routeSource.setData({
            type: 'Feature',
            geometry: {
                type: 'LineString',
                coordinates: [],
            },
        });
    }
}

function renderMap() {
    if (!activeRoute.value) {
        return;
    }

    mapErrorMessage.value = '';

    const routeData = activeRoute.value;
    const geometry = routeData.geometry ?? [];
    const depot = routeData.depot;
    const center = geometry.length > 0
        ? [geometry[0].lng, geometry[0].lat]
        : [depot.lng, depot.lat];

    const mapReady = ensureMap(center);
    if (!mapReady || !map) {
        return;
    }

    const lineColor = activeView.value === 'historical' ? '#2563eb' : '#166534';
    const stopColor = activeView.value === 'historical' ? '#1d4ed8' : '#15803d';
    const isMockRoute = routeData.provider === 'mock';

    const draw = () => {
        const routeGeoJson = {
            type: 'Feature',
            geometry: {
                type: 'LineString',
                coordinates: geometry.map((point) => [point.lng, point.lat]),
            },
        };

        if (map.getSource('route-line')) {
            map.getSource('route-line').setData(routeGeoJson);
            map.setPaintProperty('route-line', 'line-color', lineColor);
            map.setPaintProperty('route-line', 'line-opacity', isMockRoute ? 0.45 : 0.85);
            map.setPaintProperty('route-line', 'line-dasharray', isMockRoute ? [2, 2] : [1, 0.0001]);
        } else {
            map.addSource('route-line', {
                type: 'geojson',
                data: routeGeoJson,
            });
            map.addLayer({
                id: 'route-line',
                type: 'line',
                source: 'route-line',
                paint: {
                    'line-color': lineColor,
                    'line-width': 4,
                    'line-opacity': isMockRoute ? 0.45 : 0.85,
                    'line-dasharray': isMockRoute ? [2, 2] : [1, 0.0001],
                },
            });
        }

        clearMarkers();

        const depotMarker = new maplibregl.Marker({
            element: markerElement('D', '#0f172a'),
        })
            .setLngLat([depot.lng, depot.lat])
            .setPopup(
                new maplibregl.Popup({ offset: 18 }).setHTML(buildDepotPopupHtml(depot)),
            )
            .addTo(map);
        markers.push(depotMarker);

        for (const stop of routeData.stops) {
            const stopMarker = new maplibregl.Marker({
                element: markerElement(String(stop.sequence), stopColor),
            })
                .setLngLat([stop.lng, stop.lat])
                .setPopup(
                    new maplibregl.Popup({ offset: 18 }).setHTML(
                        buildStopPopupHtml(stop, routeData.label ?? 'Parada'),
                    ),
                )
                .addTo(map);
            markers.push(stopMarker);
        }

        const bounds = new maplibregl.LngLatBounds();
        for (const point of geometry) {
            bounds.extend([point.lng, point.lat]);
        }

        if (!bounds.isEmpty()) {
            map.fitBounds(bounds, { padding: 56, maxZoom: 14 });
        } else {
            map.flyTo({ center: [depot.lng, depot.lat], zoom: 12 });
        }
    };

    if (map.loaded()) {
        draw();
        return;
    }

    map.once('load', draw);
}

async function compareJourney() {
    errorMessage.value = '';
    comparisonData.value = null;
    mapErrorMessage.value = '';
    clearMapPresentation();

    if (!form.value.route_batch_id) {
        errorMessage.value = 'Selecciona una jornada para comparar.';
        return;
    }

    loading.value = true;

    try {
        const response = await axios.post(route('simulation.compare'), form.value);
        comparisonData.value = response.data;
        activeView.value = 'historical';
        await nextTick();
        renderMap();
    } catch (error) {
        const backendMessage = error?.response?.data?.message;
        errorMessage.value = backendMessage || 'No se pudo comparar la jornada.';
    } finally {
        loading.value = false;
    }
}

function clearFilters() {
    filters.value.service_date = '';
    filters.value.driver_query = '';
}

watch(filteredBatches, (batches) => {
    if (!form.value.route_batch_id) {
        return;
    }

    const selectedBatchStillVisible = batches.some((batch) => batch.id === form.value.route_batch_id);
    if (selectedBatchStillVisible) {
        return;
    }

    form.value.route_batch_id = null;
    comparisonData.value = null;
    errorMessage.value = '';
    mapErrorMessage.value = '';
    clearMapPresentation();
});

watch(() => form.value.route_batch_id, (newBatchId, oldBatchId) => {
    if (newBatchId === oldBatchId) {
        return;
    }

    comparisonData.value = null;
    errorMessage.value = '';
    mapErrorMessage.value = '';
    clearMapPresentation();
});

watch(activeRoute, async (routeData) => {
    if (!routeData) {
        return;
    }

    await nextTick();
    renderMap();
});

onBeforeUnmount(() => {
    clearMarkers();
    if (map) {
        map.remove();
        map = null;
    }
});
</script>

<template>
    <Head title="Comparar Planillado" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Comparar planillado (conductor + día)
            </h2>
        </template>

        <div class="py-10">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="grid gap-4 xl:grid-cols-[360px,1fr]">
                    <div class="space-y-4">
                        <div class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                            <h3 class="text-base font-semibold text-gray-900">Parámetros</h3>
                            <p class="mt-1 text-xs text-gray-500">
                                Proveedor configurado: {{ routing.configured_provider }} |
                                activo: {{ routing.effective_provider }}
                            </p>
                            <p
                                v-if="routing.effective_provider === 'mock'"
                                class="mt-3 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900"
                            >
                                El provider activo es `mock`. El mapa conectará puntos en orden, pero no seguirá calles reales hasta configurar un provider vial.
                            </p>

                            <div class="mt-4 space-y-4">
                                <div class="grid gap-3 sm:grid-cols-2">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700">
                                            Fecha
                                        </label>
                                        <select
                                            v-model="filters.service_date"
                                            class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-gray-900 focus:ring-gray-900"
                                        >
                                            <option value="">Todas</option>
                                            <option
                                                v-for="serviceDate in availableDates"
                                                :key="serviceDate"
                                                :value="serviceDate"
                                            >
                                                {{ serviceDate }}
                                            </option>
                                        </select>
                                    </div>

                                    <div>
                                        <label class="block text-sm font-medium text-gray-700">
                                            Conductor o cédula
                                        </label>
                                        <input
                                            v-model="filters.driver_query"
                                            type="text"
                                            placeholder="Nombre o documento"
                                            class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-gray-900 focus:ring-gray-900"
                                        >
                                    </div>
                                </div>

                                <div class="flex items-center justify-between text-xs text-gray-500">
                                    <span>
                                        {{ filteredBatches.length }} jornadas visibles de {{ batches.length }}
                                    </span>

                                    <button
                                        v-if="hasActiveFilters"
                                        type="button"
                                        class="font-medium text-gray-700 hover:text-gray-900"
                                        @click="clearFilters"
                                    >
                                        Limpiar filtros
                                    </button>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700">
                                        Jornada (conductor + fecha)
                                    </label>
                                    <select
                                        v-model.number="form.route_batch_id"
                                        class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-gray-900 focus:ring-gray-900"
                                    >
                                        <option :value="null">Selecciona una jornada</option>
                                        <option
                                            v-for="batch in filteredBatches"
                                            :key="batch.id"
                                            :value="batch.id"
                                        >
                                            {{ batch.label }} · {{ batch.total_stops }} paradas
                                        </option>
                                    </select>
                                    <p
                                        v-if="!hasBatchOptions"
                                        class="mt-2 text-xs text-amber-700"
                                    >
                                        No hay jornadas disponibles con los filtros actuales.
                                    </p>
                                </div>

                                <label class="flex items-center gap-2 text-sm text-gray-700">
                                    <input
                                        v-model="form.return_to_depot"
                                        type="checkbox"
                                        class="rounded border-gray-300 text-gray-900 shadow-sm focus:ring-gray-900"
                                    >
                                    Regresar al CEDIS al finalizar
                                </label>

                                <button
                                    type="button"
                                    :disabled="loading || !form.route_batch_id"
                                    class="inline-flex w-full justify-center rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800 disabled:cursor-not-allowed disabled:opacity-50"
                                    @click="compareJourney"
                                >
                                    {{ loading ? 'Comparando jornada...' : 'Comparar jornada' }}
                                </button>
                            </div>

                            <p
                                v-if="errorMessage"
                                class="mt-4 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700"
                            >
                                {{ errorMessage }}
                            </p>
                        </div>

                        <div v-if="journey" class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                            <h3 class="text-base font-semibold text-gray-900">Resumen de jornada</h3>
                            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                                <div class="rounded-md bg-gray-50 p-3">
                                    <p class="text-xs uppercase tracking-wide text-gray-500">Conductor</p>
                                    <p class="mt-1 text-sm font-semibold text-gray-900">
                                        {{ journey.driver.name || 'Sin conductor' }}
                                    </p>
                                    <p class="text-xs text-gray-500">
                                        {{ journey.driver.external_id || '-' }}
                                    </p>
                                </div>
                                <div class="rounded-md bg-gray-50 p-3">
                                    <p class="text-xs uppercase tracking-wide text-gray-500">Fecha</p>
                                    <p class="mt-1 text-sm font-semibold text-gray-900">{{ journey.service_date }}</p>
                                </div>
                                <div class="rounded-md bg-gray-50 p-3">
                                    <p class="text-xs uppercase tracking-wide text-gray-500">Facturas</p>
                                    <p class="mt-1 text-lg font-semibold text-gray-900">{{ summary.total_invoices }}</p>
                                </div>
                                <div class="rounded-md bg-gray-50 p-3">
                                    <p class="text-xs uppercase tracking-wide text-gray-500">Paradas</p>
                                    <p class="mt-1 text-lg font-semibold text-gray-900">{{ summary.total_stops }}</p>
                                </div>
                                <div class="rounded-md bg-blue-50 p-3">
                                    <p class="text-xs uppercase tracking-wide text-blue-700">Comparables</p>
                                    <p class="mt-1 text-lg font-semibold text-blue-900">{{ summary.comparable_stops }}</p>
                                </div>
                                <div class="rounded-md bg-amber-50 p-3">
                                    <p class="text-xs uppercase tracking-wide text-amber-700">No comparables</p>
                                    <p class="mt-1 text-lg font-semibold text-amber-900">{{ summary.non_comparable_stops }}</p>
                                </div>
                            </div>
                        </div>

                        <div v-if="comparisonData" class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                            <h3 class="text-base font-semibold text-gray-900">Lectura operativa</h3>
                            <div class="mt-3 space-y-3 text-sm">
                                <div class="rounded-md border border-blue-200 bg-blue-50 p-3">
                                    <p class="font-medium text-blue-900">Cómo fue</p>
                                    <p class="mt-1 text-blue-800">
                                        Usa el orden histórico reconstruido desde `historical_sequence`.
                                    </p>
                                </div>
                                <div class="rounded-md border border-emerald-200 bg-emerald-50 p-3">
                                    <p class="font-medium text-emerald-900">Cómo pudo ser</p>
                                    <p class="mt-1 text-emerald-800">
                                        Reordena las mismas paradas comparables con una heurística determinista.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="space-y-4">
                        <div v-if="comparisonData" class="grid gap-4 md:grid-cols-3">
                            <div class="rounded-lg border border-blue-200 bg-white p-4 shadow-sm">
                                <p class="text-xs uppercase tracking-wide text-blue-700">Cómo fue</p>
                                <p class="mt-2 text-sm text-gray-600">Distancia</p>
                                <p class="text-2xl font-semibold text-gray-900">
                                    {{ routeDistanceKm(historicalRoute) }} km
                                </p>
                                <p class="mt-2 text-sm text-gray-600">Tiempo</p>
                                <p class="text-lg font-semibold text-gray-900">
                                    {{ routeDurationMin(historicalRoute) }} min
                                </p>
                            </div>

                            <div class="rounded-lg border border-emerald-200 bg-white p-4 shadow-sm">
                                <p class="text-xs uppercase tracking-wide text-emerald-700">Cómo pudo ser</p>
                                <p class="mt-2 text-sm text-gray-600">Distancia</p>
                                <p class="text-2xl font-semibold text-gray-900">
                                    {{ routeDistanceKm(suggestedRoute) }} km
                                </p>
                                <p class="mt-2 text-sm text-gray-600">Tiempo</p>
                                <p class="text-lg font-semibold text-gray-900">
                                    {{ routeDurationMin(suggestedRoute) }} min
                                </p>
                            </div>

                            <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                                <p class="text-xs uppercase tracking-wide text-gray-500">{{ deltaLabel }}</p>
                                <p
                                    class="mt-2 text-2xl font-semibold"
                                    :class="comparisonData.delta.distance_meters < 0 ? 'text-emerald-700' : 'text-gray-900'"
                                >
                                    {{ distanceDeltaKm }} km
                                </p>
                                <p
                                    class="text-lg font-semibold"
                                    :class="comparisonData.delta.duration_seconds < 0 ? 'text-emerald-700' : 'text-gray-900'"
                                >
                                    {{ durationDeltaMin }} min
                                </p>
                                <p class="mt-2 text-xs text-gray-500">
                                    Valores negativos indican mejora del sugerido frente al histórico.
                                </p>
                            </div>
                        </div>

                        <div class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <h3 class="text-base font-semibold text-gray-900">Mapa de comparación</h3>
                                    <p class="text-sm text-gray-500">
                                        Alterna entre la reconstrucción histórica y la ruta sugerida.
                                    </p>
                                </div>

                                <div
                                    v-if="comparisonData"
                                    class="inline-flex rounded-md border border-gray-200 bg-gray-50 p-1"
                                >
                                    <button
                                        type="button"
                                        class="rounded-md px-3 py-1.5 text-sm font-medium"
                                        :class="activeView === 'historical' ? 'bg-blue-600 text-white' : 'text-gray-700'"
                                        @click="activeView = 'historical'"
                                    >
                                        Cómo fue
                                    </button>
                                    <button
                                        type="button"
                                        class="rounded-md px-3 py-1.5 text-sm font-medium"
                                        :class="activeView === 'suggested' ? 'bg-emerald-600 text-white' : 'text-gray-700'"
                                        @click="activeView = 'suggested'"
                                    >
                                        Cómo pudo ser
                                    </button>
                                </div>
                            </div>

                            <div
                                v-if="comparisonData && isMockRouteActive"
                                class="mt-4 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900"
                            >
                                Estás viendo un conector aproximado entre paradas. Sirve para comparar secuencias, no como recorrido vial final.
                            </div>

                            <div class="relative mt-4 overflow-hidden rounded-md border border-gray-200 bg-slate-50">
                                <div ref="mapContainer" class="h-[520px] w-full" />

                                <div
                                    v-if="mapOverlayMessage"
                                    class="absolute inset-0 flex items-center justify-center bg-white/90 p-6 text-center text-sm text-gray-600"
                                >
                                    <p>{{ mapOverlayMessage }}</p>
                                </div>
                            </div>
                        </div>

                        <div v-if="comparisonData" class="grid gap-4 xl:grid-cols-2">
                            <div class="rounded-lg border border-blue-200 bg-white p-4 shadow-sm">
                                <h3 class="text-sm font-semibold text-blue-900">Secuencia histórica</h3>
                                <ol class="mt-3 space-y-2">
                                    <li
                                        v-for="stop in historicalRoute.stops"
                                        :key="`historical-${stop.stop_key}`"
                                        class="rounded-md border border-blue-100 bg-blue-50 px-3 py-2 text-sm"
                                    >
                                        <div class="flex items-start justify-between gap-3">
                                            <div>
                                                <p class="font-medium text-blue-950">
                                                    {{ stop.sequence }}. {{ stop.branch_name }}
                                                </p>
                                                <p class="text-xs text-blue-700">
                                                    {{ stop.branch_code }} · {{ stop.invoice_count }} facturas
                                                </p>
                                            </div>
                                            <span class="rounded-full bg-white px-2 py-1 text-xs font-medium text-blue-800">
                                                Histórica #{{ stop.historical_sequence }}
                                            </span>
                                        </div>
                                    </li>
                                </ol>
                            </div>

                            <div class="rounded-lg border border-emerald-200 bg-white p-4 shadow-sm">
                                <h3 class="text-sm font-semibold text-emerald-900">Secuencia sugerida</h3>
                                <ol class="mt-3 space-y-2">
                                    <li
                                        v-for="stop in suggestedRoute.stops"
                                        :key="`suggested-${stop.stop_key}`"
                                        class="rounded-md border border-emerald-100 bg-emerald-50 px-3 py-2 text-sm"
                                    >
                                        <div class="flex items-start justify-between gap-3">
                                            <div>
                                                <p class="font-medium text-emerald-950">
                                                    {{ stop.sequence }}. {{ stop.branch_name }}
                                                </p>
                                                <p class="text-xs text-emerald-700">
                                                    {{ stop.branch_code }} · {{ stop.invoice_count }} facturas
                                                </p>
                                            </div>
                                            <span class="rounded-full bg-white px-2 py-1 text-xs font-medium text-emerald-800">
                                                Histórica #{{ stop.historical_sequence }}
                                            </span>
                                        </div>
                                    </li>
                                </ol>
                            </div>
                        </div>

                        <div
                            v-if="comparisonData?.non_comparable_stops?.length || comparisonData?.excluded_stops?.length"
                            class="grid gap-4 xl:grid-cols-2"
                        >
                            <div
                                v-if="comparisonData.non_comparable_stops.length"
                                class="rounded-lg border border-amber-200 bg-amber-50 p-4"
                            >
                                <h3 class="text-sm font-semibold text-amber-900">
                                    Paradas no comparables
                                </h3>
                                <ul class="mt-2 space-y-2 text-sm text-amber-900">
                                    <li
                                        v-for="stop in comparisonData.non_comparable_stops"
                                        :key="stop.stop_key"
                                        class="rounded-md border border-amber-200 bg-white px-3 py-2"
                                    >
                                        {{ stop.branch_name }} ({{ stop.branch_code }}):
                                        {{ stop.reason }} · {{ stop.invoice_count }} facturas
                                    </li>
                                </ul>
                            </div>

                            <div
                                v-if="comparisonData.excluded_stops.length"
                                class="rounded-lg border border-rose-200 bg-rose-50 p-4"
                            >
                                <h3 class="text-sm font-semibold text-rose-900">
                                    Paradas excluidas
                                </h3>
                                <ul class="mt-2 space-y-2 text-sm text-rose-900">
                                    <li
                                        v-for="stop in comparisonData.excluded_stops"
                                        :key="stop.stop_key"
                                        class="rounded-md border border-rose-200 bg-white px-3 py-2"
                                    >
                                        {{ stop.branch?.name || 'Sin sucursal' }}
                                        <span v-if="stop.branch?.code">({{ stop.branch.code }})</span>:
                                        {{ stop.reason }} · {{ stop.invoice_count }} facturas
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>

                <Link
                    :href="route('dashboard')"
                    class="mt-4 inline-flex rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                >
                    Volver al Dashboard
                </Link>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
