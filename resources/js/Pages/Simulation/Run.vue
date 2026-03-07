<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import axios from 'axios';
import maplibregl from 'maplibre-gl';
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
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

const loading = ref(false);
const errorMessage = ref('');
const routePreview = ref(null);
const mapContainer = ref(null);

let map = null;
let markers = [];

const hasBatchOptions = computed(() => props.batches.length > 0);
const distanceKm = computed(() => {
    if (!routePreview.value) return null;
    return (routePreview.value.metrics.distance_meters / 1000).toFixed(2);
});
const durationMin = computed(() => {
    if (!routePreview.value) return null;
    return (routePreview.value.metrics.duration_seconds / 60).toFixed(1);
});

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
    node.innerText = label;

    return node;
}

function ensureMap(center) {
    if (map || !mapContainer.value) {
        return;
    }

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
}

function clearMarkers() {
    for (const marker of markers) {
        marker.remove();
    }

    markers = [];
}

function renderMap() {
    if (!routePreview.value) {
        return;
    }

    const geometry = routePreview.value.geometry ?? [];
    const depot = routePreview.value.depot;
    const center = geometry.length > 0
        ? [geometry[0].lng, geometry[0].lat]
        : [depot.lng, depot.lat];

    ensureMap(center);
    if (!map) {
        return;
    }

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
                    'line-color': '#0f172a',
                    'line-width': 4,
                    'line-opacity': 0.85,
                },
            });
        }

        clearMarkers();

        const depotMarker = new maplibregl.Marker({
            element: markerElement('D', '#1d4ed8'),
        }).setLngLat([depot.lng, depot.lat]).addTo(map);
        markers.push(depotMarker);

        for (const stop of routePreview.value.stops) {
            const stopMarker = new maplibregl.Marker({
                element: markerElement(String(stop.sequence), '#111827'),
            }).setLngLat([stop.lng, stop.lat]).addTo(map);
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

async function previewRoute() {
    errorMessage.value = '';
    routePreview.value = null;

    if (!form.value.route_batch_id) {
        errorMessage.value = 'Selecciona un batch para simular.';
        return;
    }

    loading.value = true;

    try {
        const response = await axios.post(route('simulation.preview'), form.value);
        routePreview.value = response.data;
        await nextTick();
        renderMap();
    } catch (error) {
        const backendMessage = error?.response?.data?.message;
        errorMessage.value = backendMessage || 'No se pudo generar la ruta del batch.';
    } finally {
        loading.value = false;
    }
}

onMounted(() => {
    if (form.value.route_batch_id) {
        previewRoute();
    }
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
    <Head title="Simular" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Simular (conductor + día)
            </h2>
        </template>

        <div class="py-10">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="grid gap-4 lg:grid-cols-3">
                    <div class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm lg:col-span-1">
                        <h3 class="text-base font-semibold text-gray-900">Parámetros</h3>
                        <p class="mt-1 text-xs text-gray-500">
                            Proveedor configurado: {{ routing.configured_provider }} |
                            activo: {{ routing.effective_provider }}
                        </p>

                        <div class="mt-4 space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700">
                                    Batch (conductor + fecha)
                                </label>
                                <select
                                    v-model.number="form.route_batch_id"
                                    class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-gray-900 focus:ring-gray-900"
                                >
                                    <option :value="null">Selecciona un batch</option>
                                    <option
                                        v-for="batch in batches"
                                        :key="batch.id"
                                        :value="batch.id"
                                    >
                                        {{ batch.label }}
                                    </option>
                                </select>
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
                                :disabled="loading || !hasBatchOptions"
                                class="inline-flex w-full justify-center rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800 disabled:cursor-not-allowed disabled:opacity-50"
                                @click="previewRoute"
                            >
                                {{ loading ? 'Calculando ruta...' : 'Generar ruta' }}
                            </button>
                        </div>

                        <p
                            v-if="errorMessage"
                            class="mt-4 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700"
                        >
                            {{ errorMessage }}
                        </p>

                        <div v-if="routePreview" class="mt-4 rounded-md border border-gray-200 bg-gray-50 p-3 text-sm">
                            <p class="text-gray-700">
                                Provider: <span class="font-semibold">{{ routePreview.provider }}</span>
                                <span class="ml-2 text-xs text-gray-500">
                                    (cache: {{ routePreview.cache_hit ? 'hit' : 'miss' }})
                                </span>
                            </p>
                            <p class="mt-1 text-gray-700">
                                Distancia: <span class="font-semibold">{{ distanceKm }} km</span>
                            </p>
                            <p class="text-gray-700">
                                Tiempo estimado: <span class="font-semibold">{{ durationMin }} min</span>
                            </p>
                            <p class="mt-1 text-gray-700">
                                Paradas en mapa: <span class="font-semibold">{{ routePreview.stops.length }}</span>
                            </p>
                        </div>
                    </div>

                    <div class="rounded-lg border border-gray-200 bg-white p-3 shadow-sm lg:col-span-2">
                        <div ref="mapContainer" class="h-[520px] w-full rounded-md" />
                    </div>
                </div>

                <div
                    v-if="routePreview?.excluded_stops?.length"
                    class="mt-4 rounded-lg border border-yellow-200 bg-yellow-50 p-4"
                >
                    <h3 class="text-sm font-semibold text-yellow-800">
                        Paradas excluidas de la ruta
                    </h3>
                    <ul class="mt-2 space-y-1 text-sm text-yellow-800">
                        <li
                            v-for="excluded in routePreview.excluded_stops"
                            :key="excluded.invoice_stop_id"
                        >
                            {{ excluded.branch?.name || 'Sin sucursal' }}:
                            {{ excluded.reason }} ({{ excluded.invoice_count }} facturas)
                        </li>
                    </ul>
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
