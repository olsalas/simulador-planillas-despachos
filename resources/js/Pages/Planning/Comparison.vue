<script setup>
import Modal from '@/Components/Modal.vue';
import OperationalComparisonMap from '@/Components/OperationalComparisonMap.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    scenario: {
        type: Object,
        required: true,
    },
    comparison: {
        type: Object,
        required: true,
    },
});

const activeWorldTab = ref('historical');
const selectedDriverKey = ref('all');
const detailModalOpen = ref(false);
const detailJourney = ref(null);

const worldTabs = [
    {
        key: 'historical',
        label: 'Cómo fue',
        description: 'Rutas reales del corte y paradas asignadas a cada conductor.',
    },
    {
        key: 'proposed',
        label: 'Cómo lo hubiéramos planillado',
        description: 'Rutas propuestas con la heurística actual sobre las mismas facturas de Bogotá.',
    },
    {
        key: 'overlay',
        label: 'Superpuesta',
        description: 'Ambos mundos a la vez: sólido para real y punteado para propuesta.',
    },
];

const driverPalette = [
    '#0f766e',
    '#0284c7',
    '#ea580c',
    '#65a30d',
    '#dc2626',
    '#1d4ed8',
    '#b45309',
    '#0891b2',
    '#15803d',
    '#be123c',
    '#475569',
    '#ca8a04',
];

function sortJourneys(journeys) {
    return [...journeys].sort((left, right) => {
        if (left.total_invoices !== right.total_invoices) {
            return right.total_invoices - left.total_invoices;
        }

        return left.driver.name.localeCompare(right.driver.name);
    });
}

const driverColors = computed(() => {
    const colors = {};

    props.comparison.driver_comparisons.forEach((row, index) => {
        colors[row.driver_key] = driverPalette[index % driverPalette.length];
    });

    return colors;
});

const historicalJourneys = computed(() => sortJourneys(
    props.comparison.historical_journeys.map((journey) => ({ ...journey, world: 'historical' })),
));

const proposedJourneys = computed(() => sortJourneys(
    props.comparison.proposed_journeys.map((journey) => ({ ...journey, world: 'proposed' })),
));

const historicalJourneyLookup = computed(() => Object.fromEntries(
    historicalJourneys.value.map((journey) => [journey.driver_key, journey]),
));

const proposedJourneyLookup = computed(() => Object.fromEntries(
    proposedJourneys.value.map((journey) => [journey.driver_key, journey]),
));

const comparisonByDriverKey = computed(() => Object.fromEntries(
    props.comparison.driver_comparisons.map((row) => [row.driver_key, row]),
));

const activeWorldMeta = computed(() => worldTabs.find((tab) => tab.key === activeWorldTab.value) ?? worldTabs[0]);

const activeMapJourneys = computed(() => {
    if (activeWorldTab.value === 'historical') {
        return historicalJourneys.value;
    }

    if (activeWorldTab.value === 'proposed') {
        return proposedJourneys.value;
    }

    return [...historicalJourneys.value, ...proposedJourneys.value];
});

const overlayDriverRows = computed(() => {
    return [...props.comparison.driver_comparisons].sort((left, right) => {
        const rightVolume = Math.max(right.historical.total_invoices, right.proposed.total_invoices);
        const leftVolume = Math.max(left.historical.total_invoices, left.proposed.total_invoices);

        if (leftVolume !== rightVolume) {
            return rightVolume - leftVolume;
        }

        return left.driver.name.localeCompare(right.driver.name);
    });
});

const detailComparisonRow = computed(() => {
    if (!detailJourney.value) {
        return null;
    }

    return comparisonByDriverKey.value[detailJourney.value.driver_key] ?? null;
});

const detailCounterpartJourney = computed(() => {
    if (!detailJourney.value) {
        return null;
    }

    return detailJourney.value.world === 'historical'
        ? proposedJourneyLookup.value[detailJourney.value.driver_key] ?? null
        : historicalJourneyLookup.value[detailJourney.value.driver_key] ?? null;
});

watch(activeMapJourneys, (journeys) => {
    if (selectedDriverKey.value === 'all') {
        return;
    }

    const currentExists = journeys.some((journey) => journey.driver_key === selectedDriverKey.value);

    if (!currentExists) {
        selectedDriverKey.value = 'all';
    }
}, { deep: true, immediate: true });

watch(activeWorldTab, () => {
    detailModalOpen.value = false;
    detailJourney.value = null;
});

function formatKilometers(value) {
    return `${(Number(value ?? 0) / 1000).toFixed(2)} km`;
}

function formatMinutes(value) {
    return `${(Number(value ?? 0) / 60).toFixed(1)} min`;
}

function signedKilometers(value) {
    const kilometers = Number(value ?? 0) / 1000;
    const prefix = kilometers > 0 ? '+' : '';

    return `${prefix}${kilometers.toFixed(2)} km`;
}

function signedMinutes(value) {
    const minutes = Number(value ?? 0) / 60;
    const prefix = minutes > 0 ? '+' : '';

    return `${prefix}${minutes.toFixed(1)} min`;
}

function journeyColor(driverKey) {
    return driverColors.value[driverKey] ?? '#0f172a';
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

function overlayWorldColor(driverKey, world) {
    const baseColor = journeyColor(driverKey);
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

function comparisonHint(journey) {
    const row = comparisonByDriverKey.value[journey.driver_key];

    if (!row) {
        return 'Sin contraste disponible.';
    }

    if (activeWorldTab.value === 'historical') {
        return row.proposed.present
            ? `En propuesta: ${row.proposed.total_invoices} facturas · ${row.proposed.total_stops} paradas`
            : 'No aparece en la propuesta.';
    }

    return row.historical.present
        ? `En real: ${row.historical.total_invoices} facturas · ${row.historical.total_stops} paradas`
        : 'No aparece en la operación real visible.';
}

function journeyFor(driverKey, world) {
    return world === 'historical'
        ? historicalJourneyLookup.value[driverKey] ?? null
        : proposedJourneyLookup.value[driverKey] ?? null;
}

function openJourneyDetail(journey, forcedWorld = null) {
    detailJourney.value = {
        ...journey,
        world: forcedWorld ?? journey.world ?? activeWorldTab.value,
    };
    detailModalOpen.value = true;
    selectedDriverKey.value = journey.driver_key;
}

function openJourneyDetailFor(driverKey, world) {
    const journey = journeyFor(driverKey, world);

    if (!journey) {
        return;
    }

    openJourneyDetail(journey, world);
}

function closeJourneyDetail() {
    detailModalOpen.value = false;
    detailJourney.value = null;
}

function toggleDriverFocus(driverKey) {
    selectedDriverKey.value = selectedDriverKey.value === driverKey ? 'all' : driverKey;
}

function assignmentLabel(assignments) {
    if (!assignments?.length) {
        return 'Sin histórico visible';
    }

    return assignments
        .map((assignment) => `${assignment.driver.name} (${assignment.invoice_count})`)
        .join(', ');
}

function detailStopContext(stop) {
    if (!detailJourney.value) {
        return '';
    }

    if (detailJourney.value.world === 'historical') {
        return stop.proposed_driver?.name
            ? `En propuesta: ${stop.proposed_driver.name}`
            : 'No aparece en la propuesta';
    }

    return `En real: ${assignmentLabel(stop.historical_assignments)}`;
}

function counterpartWorldLabel() {
    if (!detailJourney.value) {
        return '';
    }

    return detailJourney.value.world === 'historical'
        ? 'Cómo lo hubiéramos planillado'
        : 'Cómo fue';
}

function activeTabClass(tabKey) {
    return activeWorldTab.value === tabKey
        ? 'bg-slate-900 text-white shadow-sm'
        : 'bg-white text-slate-600 hover:bg-slate-50';
}
</script>

<template>
    <Head :title="`Comparación Bogotá · ${scenario.name}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.24em] text-sky-600">
                        MVP Bogotá
                    </p>
                    <h2 class="mt-2 text-2xl font-semibold leading-tight text-slate-900">
                        Cómo fue vs cómo lo hubiéramos planillado
                    </h2>
                    <p class="mt-1 max-w-3xl text-sm text-slate-600">
                        {{ comparison.cut.definition }}
                    </p>
                </div>

                <div class="flex flex-wrap gap-3">
                    <Link
                        :href="route('planning.scenarios.show', scenario.id)"
                        class="inline-flex items-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
                    >
                        Volver al escenario
                    </Link>
                    <Link
                        :href="route('planning.scenarios.index')"
                        class="inline-flex items-center rounded-xl bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800"
                    >
                        Ver otros cortes
                    </Link>
                </div>
            </div>
        </template>

        <div class="py-10">
            <div class="mx-auto flex max-w-7xl flex-col gap-6 px-4 sm:px-6 lg:px-8">
                <section class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    <div class="rounded-[1.75rem] border border-slate-200 bg-white p-5 shadow-sm">
                        <p class="text-sm text-slate-500">Facturas Bogotá comparables</p>
                        <p class="mt-2 text-3xl font-semibold text-slate-900">{{ comparison.summary.historical.total_invoices }}</p>
                    </div>
                    <div class="rounded-[1.75rem] border border-slate-200 bg-white p-5 shadow-sm">
                        <p class="text-sm text-slate-500">Conductores reales</p>
                        <p class="mt-2 text-3xl font-semibold text-slate-900">{{ comparison.summary.historical.driver_count }}</p>
                    </div>
                    <div class="rounded-[1.75rem] border border-slate-200 bg-white p-5 shadow-sm">
                        <p class="text-sm text-slate-500">Conductores propuestos</p>
                        <p class="mt-2 text-3xl font-semibold text-slate-900">{{ comparison.summary.proposed.driver_count }}</p>
                    </div>
                    <div class="rounded-[1.75rem] border border-slate-200 bg-white p-5 shadow-sm">
                        <p class="text-sm text-slate-500">Paradas redistribuidas</p>
                        <p class="mt-2 text-3xl font-semibold text-slate-900">{{ comparison.summary.redistributed_stops }}</p>
                    </div>
                    <div class="rounded-[1.75rem] border border-slate-200 bg-white p-5 shadow-sm">
                        <p class="text-sm text-slate-500">Facturas reasignadas</p>
                        <p class="mt-2 text-3xl font-semibold text-slate-900">{{ comparison.summary.reassigned_invoices }}</p>
                    </div>
                    <div class="rounded-[1.75rem] border border-slate-200 bg-white p-5 shadow-sm">
                        <p class="text-sm text-slate-500">Fuera de Bogotá</p>
                        <p class="mt-2 text-3xl font-semibold text-slate-900">{{ comparison.summary.outside_bogota_stops }}</p>
                    </div>
                    <div class="rounded-[1.75rem] border border-slate-200 bg-white p-5 shadow-sm">
                        <p class="text-sm text-slate-500">Delta distancia</p>
                        <p class="mt-2 text-3xl font-semibold" :class="comparison.summary.delta.distance_meters <= 0 ? 'text-emerald-700' : 'text-amber-700'">
                            {{ signedKilometers(comparison.summary.delta.distance_meters) }}
                        </p>
                    </div>
                    <div class="rounded-[1.75rem] border border-slate-200 bg-white p-5 shadow-sm">
                        <p class="text-sm text-slate-500">Delta duración</p>
                        <p class="mt-2 text-3xl font-semibold" :class="comparison.summary.delta.duration_seconds <= 0 ? 'text-emerald-700' : 'text-amber-700'">
                            {{ signedMinutes(comparison.summary.delta.duration_seconds) }}
                        </p>
                    </div>
                </section>

                <section class="grid gap-6 xl:grid-cols-[1.15fr_0.85fr]">
                    <div class="rounded-[2rem] border border-slate-200 bg-white p-6 shadow-sm">
                        <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-[0.24em] text-sky-600">Vista general</p>
                                <h3 class="mt-2 text-lg font-semibold text-slate-900">Comparación del corte operativo</h3>
                                <p class="mt-1 text-sm text-slate-600">
                                    Mismas entregas de Bogotá, dos lecturas: así se repartieron realmente y así las repartiríamos con la heurística actual.
                                </p>
                            </div>

                            <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700">
                                <p class="font-semibold text-slate-900">{{ comparison.cut.label }}</p>
                                <p class="mt-1">{{ comparison.cut.service_date }} · {{ comparison.cut.depot.code }} · {{ comparison.cut.depot.name }}</p>
                            </div>
                        </div>

                        <div class="mt-6 grid gap-4 lg:grid-cols-2">
                            <article class="rounded-[1.5rem] border border-slate-200 bg-slate-50 p-5">
                                <p class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-600">Cómo fue</p>
                                <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
                                    <div class="rounded-xl bg-white p-3">
                                        <dt class="text-slate-500">Conductores</dt>
                                        <dd class="mt-1 text-lg font-semibold text-slate-900">{{ comparison.summary.historical.driver_count }}</dd>
                                    </div>
                                    <div class="rounded-xl bg-white p-3">
                                        <dt class="text-slate-500">Paradas</dt>
                                        <dd class="mt-1 text-lg font-semibold text-slate-900">{{ comparison.summary.historical.total_stops }}</dd>
                                    </div>
                                    <div class="rounded-xl bg-white p-3">
                                        <dt class="text-slate-500">Facturas</dt>
                                        <dd class="mt-1 text-lg font-semibold text-slate-900">{{ comparison.summary.historical.total_invoices }}</dd>
                                    </div>
                                    <div class="rounded-xl bg-white p-3">
                                        <dt class="text-slate-500">Duración estimada</dt>
                                        <dd class="mt-1 text-lg font-semibold text-slate-900">{{ formatMinutes(comparison.summary.historical.duration_seconds) }}</dd>
                                    </div>
                                </dl>
                            </article>

                            <article class="rounded-[1.5rem] border border-sky-200 bg-sky-50 p-5">
                                <p class="text-xs font-semibold uppercase tracking-[0.22em] text-sky-700">Cómo lo hubiéramos planillado</p>
                                <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
                                    <div class="rounded-xl bg-white p-3">
                                        <dt class="text-slate-500">Conductores</dt>
                                        <dd class="mt-1 text-lg font-semibold text-slate-900">{{ comparison.summary.proposed.driver_count }}</dd>
                                    </div>
                                    <div class="rounded-xl bg-white p-3">
                                        <dt class="text-slate-500">Paradas</dt>
                                        <dd class="mt-1 text-lg font-semibold text-slate-900">{{ comparison.summary.proposed.total_stops }}</dd>
                                    </div>
                                    <div class="rounded-xl bg-white p-3">
                                        <dt class="text-slate-500">Facturas</dt>
                                        <dd class="mt-1 text-lg font-semibold text-slate-900">{{ comparison.summary.proposed.total_invoices }}</dd>
                                    </div>
                                    <div class="rounded-xl bg-white p-3">
                                        <dt class="text-slate-500">Duración estimada</dt>
                                        <dd class="mt-1 text-lg font-semibold text-slate-900">{{ formatMinutes(comparison.summary.proposed.duration_seconds) }}</dd>
                                    </div>
                                </dl>
                            </article>
                        </div>
                    </div>

                    <div class="rounded-[2rem] border border-slate-200 bg-white p-6 shadow-sm">
                        <h3 class="text-lg font-semibold text-slate-900">Ruido excluido del demo</h3>
                        <div class="mt-5 grid gap-3">
                            <div class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950">
                                <p class="font-semibold">Fuera de Bogotá</p>
                                <p class="mt-1">{{ comparison.summary.outside_bogota_stops }} paradas · {{ comparison.summary.outside_bogota_invoices }} facturas</p>
                            </div>
                            <div class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-950">
                                <p class="font-semibold">Datos excluidos por calidad</p>
                                <p class="mt-1">{{ comparison.summary.data_quality_excluded_stops }} paradas · {{ comparison.summary.data_quality_excluded_invoices }} facturas</p>
                            </div>
                            <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700">
                                <p class="font-semibold text-slate-900">Sin asignar en propuesta</p>
                                <p class="mt-1">{{ comparison.summary.unassigned_stops }} paradas · {{ comparison.summary.unassigned_invoices }} facturas</p>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="rounded-[2rem] border border-slate-200 bg-gradient-to-b from-white to-slate-50 p-5 shadow-sm sm:p-6">
                    <div class="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.24em] text-sky-600">Mapa principal</p>
                            <h3 class="mt-2 text-xl font-semibold text-slate-900">{{ activeWorldMeta.label }}</h3>
                            <p class="mt-1 text-sm text-slate-600">{{ activeWorldMeta.description }}</p>
                        </div>

                        <div class="flex flex-wrap items-center gap-3">
                            <div class="inline-flex rounded-2xl border border-slate-200 bg-white p-1 shadow-sm">
                                <button
                                    v-for="tab in worldTabs"
                                    :key="tab.key"
                                    type="button"
                                    class="rounded-xl px-4 py-2 text-sm font-medium transition"
                                    :class="activeTabClass(tab.key)"
                                    @click="activeWorldTab = tab.key"
                                >
                                    {{ tab.label }}
                                </button>
                            </div>

                            <button
                                v-if="selectedDriverKey !== 'all'"
                                type="button"
                                class="inline-flex items-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
                                @click="selectedDriverKey = 'all'"
                            >
                                Ver todas las rutas
                            </button>
                        </div>
                    </div>

                    <div v-if="activeWorldTab === 'overlay'" class="mt-4 flex flex-wrap gap-3 text-xs font-medium text-slate-600">
                        <span class="inline-flex items-center gap-2 rounded-full bg-white px-3 py-1.5 shadow-sm">
                            <span class="h-[2px] w-8" :style="{ backgroundColor: overlayWorldColor(overlayDriverRows[0]?.driver_key, 'historical') }" />
                            Real · tono profundo
                        </span>
                        <span class="inline-flex items-center gap-2 rounded-full bg-white px-3 py-1.5 shadow-sm">
                            <span class="h-[2px] w-8 border-t-2 border-dashed" :style="{ borderColor: overlayWorldColor(overlayDriverRows[0]?.driver_key, 'proposed') }" />
                            Propuesta · color opuesto
                        </span>
                    </div>

                    <div class="mt-6">
                        <OperationalComparisonMap
                            :journeys="activeMapJourneys"
                            :world="activeWorldTab"
                            :driver-colors="driverColors"
                            :selected-driver-key="selectedDriverKey"
                        />
                    </div>

                    <div class="mt-6">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                            <div>
                                <h4 class="text-lg font-semibold text-slate-900">
                                    {{ activeWorldTab === 'overlay' ? 'Conductores en modo superpuesto' : 'Conductores visibles' }}
                                </h4>
                                <p class="mt-1 text-sm text-slate-600">
                                    {{ activeWorldTab === 'overlay' ? 'Cada conductor usa colores claramente opuestos entre real y propuesta. Puedes resaltar ambos mundos del mismo conductor o abrir el detalle específico.' : 'Cada tarjeta hereda el color de su ruta. Puedes resaltar la ruta en el mapa o abrir el detalle en popup.' }}
                                </p>
                            </div>
                            <div class="rounded-full bg-white px-3 py-1 text-xs font-semibold text-slate-600 shadow-sm">
                                {{ activeWorldTab === 'overlay' ? overlayDriverRows.length : activeMapJourneys.length }} elementos visibles
                            </div>
                        </div>

                        <div v-if="activeWorldTab !== 'overlay' && activeMapJourneys.length === 0" class="mt-5 rounded-2xl border border-dashed border-slate-300 px-6 py-10 text-center text-sm text-slate-500">
                            No hay rutas disponibles para este mundo del corte.
                        </div>

                        <div v-else-if="activeWorldTab !== 'overlay'" class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                            <article
                                v-for="journey in activeMapJourneys"
                                :key="`${activeWorldTab}-${journey.driver_key}`"
                                class="rounded-[1.5rem] border bg-white p-5 shadow-sm transition"
                                :class="selectedDriverKey === journey.driver_key ? 'border-slate-900 shadow-md' : 'border-slate-200 hover:border-slate-300'"
                            >
                                <div class="h-2 rounded-full" :style="{ backgroundColor: journeyColor(journey.driver_key) }" />

                                <div class="mt-4 flex items-start justify-between gap-3">
                                    <div>
                                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">{{ journey.driver.external_id ?? 'Sin external_id' }}</p>
                                        <h5 class="mt-2 text-base font-semibold text-slate-900">{{ journey.driver.name }}</h5>
                                        <p class="mt-1 text-sm text-slate-600">{{ comparisonHint(journey) }}</p>
                                    </div>

                                    <span class="rounded-full bg-slate-100 px-2.5 py-1 text-[11px] font-semibold text-slate-700">
                                        {{ journey.total_invoices }} facturas
                                    </span>
                                </div>

                                <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
                                    <div class="rounded-xl bg-slate-50 p-3">
                                        <dt class="text-slate-500">Paradas</dt>
                                        <dd class="mt-1 text-lg font-semibold text-slate-900">{{ journey.total_stops }}</dd>
                                    </div>
                                    <div class="rounded-xl bg-slate-50 p-3">
                                        <dt class="text-slate-500">Duración</dt>
                                        <dd class="mt-1 text-lg font-semibold text-slate-900">{{ formatMinutes(journey.route_preview.metrics.duration_seconds) }}</dd>
                                    </div>
                                    <div class="rounded-xl bg-slate-50 p-3">
                                        <dt class="text-slate-500">Distancia</dt>
                                        <dd class="mt-1 text-lg font-semibold text-slate-900">{{ formatKilometers(journey.route_preview.metrics.distance_meters) }}</dd>
                                    </div>
                                    <div class="rounded-xl bg-slate-50 p-3">
                                        <dt class="text-slate-500">Proveedor</dt>
                                        <dd class="mt-1 text-lg font-semibold text-slate-900">{{ journey.route_preview.provider }}</dd>
                                    </div>
                                </dl>

                                <div class="mt-5 flex flex-wrap gap-3">
                                    <button
                                        type="button"
                                        class="inline-flex items-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
                                        @click="toggleDriverFocus(journey.driver_key)"
                                    >
                                        {{ selectedDriverKey === journey.driver_key ? 'Quitar foco' : 'Resaltar ruta' }}
                                    </button>
                                    <button
                                        type="button"
                                        class="inline-flex items-center rounded-xl bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800"
                                        @click="openJourneyDetail(journey, activeWorldTab)"
                                    >
                                        Ver detalle
                                    </button>
                                </div>
                            </article>
                        </div>

                        <div v-else class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                            <article
                                v-for="row in overlayDriverRows"
                                :key="`overlay-${row.driver_key}`"
                                class="rounded-[1.5rem] border bg-white p-5 shadow-sm transition"
                                :class="selectedDriverKey === row.driver_key ? 'border-slate-900 shadow-md' : 'border-slate-200 hover:border-slate-300'"
                            >
                                <div class="h-2 rounded-full" :style="{ backgroundColor: journeyColor(row.driver_key) }" />

                                <div class="mt-4 flex items-start justify-between gap-3">
                                    <div>
                                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">{{ row.driver.external_id ?? 'Sin external_id' }}</p>
                                        <h5 class="mt-2 text-base font-semibold text-slate-900">{{ row.driver.name }}</h5>
                                        <p class="mt-1 text-sm text-slate-600">Contraste rápido entre operación real y propuesta.</p>
                                    </div>

                                    <span class="rounded-full bg-slate-100 px-2.5 py-1 text-[11px] font-semibold text-slate-700">
                                        {{ row.historical.total_invoices }} / {{ row.proposed.total_invoices }} facturas
                                    </span>
                                </div>

                                <dl class="mt-4 grid gap-3 text-sm">
                                    <div class="rounded-xl bg-slate-50 p-3">
                                        <div class="flex items-center justify-between gap-3">
                                            <dt class="flex items-center gap-2 text-slate-500">
                                                <span class="h-2.5 w-2.5 rounded-full" :style="{ backgroundColor: overlayWorldColor(row.driver_key, 'historical') }" />
                                                Real
                                            </dt>
                                            <dd class="font-semibold text-slate-900">{{ row.historical.total_invoices }} facturas · {{ row.historical.total_stops }} paradas</dd>
                                        </div>
                                    </div>
                                    <div class="rounded-xl bg-slate-50 p-3">
                                        <div class="flex items-center justify-between gap-3">
                                            <dt class="flex items-center gap-2 text-slate-500">
                                                <span class="h-2.5 w-2.5 rounded-full" :style="{ backgroundColor: overlayWorldColor(row.driver_key, 'proposed') }" />
                                                Propuesta
                                            </dt>
                                            <dd class="font-semibold text-slate-900">{{ row.proposed.total_invoices }} facturas · {{ row.proposed.total_stops }} paradas</dd>
                                        </div>
                                    </div>
                                    <div class="rounded-xl bg-slate-50 p-3">
                                        <div class="flex items-center justify-between gap-3">
                                            <dt class="text-slate-500">Delta duración</dt>
                                            <dd class="font-semibold" :class="row.delta.duration_seconds <= 0 ? 'text-emerald-700' : 'text-amber-700'">{{ signedMinutes(row.delta.duration_seconds) }}</dd>
                                        </div>
                                    </div>
                                </dl>

                                <div class="mt-5 flex flex-wrap gap-3">
                                    <button
                                        type="button"
                                        class="inline-flex items-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
                                        @click="toggleDriverFocus(row.driver_key)"
                                    >
                                        {{ selectedDriverKey === row.driver_key ? 'Quitar foco' : 'Resaltar conductor' }}
                                    </button>
                                    <button
                                        v-if="row.historical.present"
                                        type="button"
                                        class="inline-flex items-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
                                        @click="openJourneyDetailFor(row.driver_key, 'historical')"
                                    >
                                        Detalle real
                                    </button>
                                    <button
                                        v-if="row.proposed.present"
                                        type="button"
                                        class="inline-flex items-center rounded-xl bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800"
                                        @click="openJourneyDetailFor(row.driver_key, 'proposed')"
                                    >
                                        Detalle propuesta
                                    </button>
                                </div>
                            </article>
                        </div>
                    </div>
                </section>

                <section class="grid gap-6 xl:grid-cols-3">
                    <article class="rounded-[2rem] border border-amber-200 bg-white p-6 shadow-sm">
                        <h3 class="text-lg font-semibold text-slate-900">Paradas fuera de Bogotá</h3>
                        <p class="mt-1 text-sm text-slate-600">Se excluyen del demo para no deformar la lectura urbana del corte.</p>

                        <ul class="mt-5 space-y-3 text-sm">
                            <li
                                v-for="stop in comparison.excluded_stops.outside_bogota.slice(0, 8)"
                                :key="stop.stop_key"
                                class="rounded-2xl border border-amber-100 bg-amber-50 px-4 py-3 text-amber-950"
                            >
                                <p class="font-semibold">{{ stop.branch_code }} · {{ stop.branch_name }}</p>
                                <p class="mt-1 text-xs">{{ stop.invoice_count }} facturas</p>
                            </li>
                        </ul>
                    </article>

                    <article class="rounded-[2rem] border border-rose-200 bg-white p-6 shadow-sm">
                        <h3 class="text-lg font-semibold text-slate-900">Excluidas por calidad de dato</h3>
                        <p class="mt-1 text-sm text-slate-600">Mantiene visible qué no entró al comparativo por falta de geocódigo o consolidación.</p>

                        <ul class="mt-5 space-y-3 text-sm">
                            <li
                                v-for="stop in comparison.excluded_stops.data_quality.slice(0, 8)"
                                :key="stop.stop_key"
                                class="rounded-2xl border border-rose-100 bg-rose-50 px-4 py-3 text-rose-950"
                            >
                                <p class="font-semibold">{{ stop.branch_code ?? stop.stop_key }} · {{ stop.branch_name }}</p>
                                <p class="mt-1 text-xs">{{ stop.reason }} · {{ stop.invoice_count }} facturas</p>
                            </li>
                        </ul>
                    </article>

                    <article class="rounded-[2rem] border border-slate-200 bg-white p-6 shadow-sm">
                        <h3 class="text-lg font-semibold text-slate-900">No asignadas por la propuesta</h3>
                        <p class="mt-1 text-sm text-slate-600">Si aparecen, indican bloqueo operativo o falta de capacidad en la heurística actual.</p>

                        <ul class="mt-5 space-y-3 text-sm">
                            <li
                                v-for="stop in comparison.unassigned_stops.slice(0, 8)"
                                :key="stop.stop_key"
                                class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-slate-800"
                            >
                                <p class="font-semibold">{{ stop.branch_code }} · {{ stop.branch_name }}</p>
                                <p class="mt-1 text-xs">{{ stop.reason }} · {{ stop.invoice_count }} facturas</p>
                            </li>
                        </ul>
                    </article>
                </section>
            </div>
        </div>

        <Modal :show="detailModalOpen" max-width="2xl" @close="closeJourneyDetail">
            <div v-if="detailJourney" class="p-6 sm:p-7">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.24em] text-sky-600">{{ detailJourney.world === 'historical' ? 'Cómo fue' : 'Cómo lo hubiéramos planillado' }}</p>
                        <h3 class="mt-2 text-xl font-semibold text-slate-900">{{ detailJourney.driver.name }}</h3>
                        <p class="mt-1 text-sm text-slate-600">{{ detailJourney.driver.external_id ?? 'Sin external_id' }} · {{ detailJourney.total_invoices }} facturas · {{ detailJourney.total_stops }} paradas</p>
                    </div>

                    <button
                        type="button"
                        class="inline-flex h-10 w-10 items-center justify-center rounded-full border border-slate-200 text-slate-500 hover:bg-slate-50"
                        @click="closeJourneyDetail"
                    >
                        ×
                    </button>
                </div>

                <div class="mt-6 grid gap-3 text-sm md:grid-cols-4">
                    <div class="rounded-2xl bg-slate-50 p-4">
                        <p class="text-slate-500">Paradas</p>
                        <p class="mt-2 text-2xl font-semibold text-slate-900">{{ detailJourney.total_stops }}</p>
                    </div>
                    <div class="rounded-2xl bg-slate-50 p-4">
                        <p class="text-slate-500">Facturas</p>
                        <p class="mt-2 text-2xl font-semibold text-slate-900">{{ detailJourney.total_invoices }}</p>
                    </div>
                    <div class="rounded-2xl bg-slate-50 p-4">
                        <p class="text-slate-500">Distancia</p>
                        <p class="mt-2 text-2xl font-semibold text-slate-900">{{ formatKilometers(detailJourney.route_preview.metrics.distance_meters) }}</p>
                    </div>
                    <div class="rounded-2xl bg-slate-50 p-4">
                        <p class="text-slate-500">Duración</p>
                        <p class="mt-2 text-2xl font-semibold text-slate-900">{{ formatMinutes(detailJourney.route_preview.metrics.duration_seconds) }}</p>
                    </div>
                </div>

                <div class="mt-6 grid gap-6 lg:grid-cols-[0.9fr_1.1fr]">
                    <div class="rounded-[1.5rem] border border-slate-200 bg-slate-50 p-5">
                        <h4 class="text-base font-semibold text-slate-900">Contraste con el otro mundo</h4>
                        <p class="mt-1 text-sm text-slate-600">{{ counterpartWorldLabel() }} para este mismo conductor.</p>

                        <div v-if="detailCounterpartJourney" class="mt-5 grid gap-3 text-sm">
                            <div class="rounded-xl bg-white p-4">
                                <p class="text-slate-500">Facturas en el otro mundo</p>
                                <p class="mt-2 text-lg font-semibold text-slate-900">{{ detailCounterpartJourney.total_invoices }}</p>
                            </div>
                            <div class="rounded-xl bg-white p-4">
                                <p class="text-slate-500">Paradas en el otro mundo</p>
                                <p class="mt-2 text-lg font-semibold text-slate-900">{{ detailCounterpartJourney.total_stops }}</p>
                            </div>
                            <div class="rounded-xl bg-white p-4">
                                <p class="text-slate-500">Delta duración</p>
                                <p class="mt-2 text-lg font-semibold" :class="detailComparisonRow?.delta.duration_seconds <= 0 ? 'text-emerald-700' : 'text-amber-700'">{{ signedMinutes(detailComparisonRow?.delta.duration_seconds ?? 0) }}</p>
                            </div>
                            <div class="rounded-xl bg-white p-4">
                                <p class="text-slate-500">Delta distancia</p>
                                <p class="mt-2 text-lg font-semibold" :class="detailComparisonRow?.delta.distance_meters <= 0 ? 'text-emerald-700' : 'text-amber-700'">{{ signedKilometers(detailComparisonRow?.delta.distance_meters ?? 0) }}</p>
                            </div>
                        </div>

                        <div v-else class="mt-5 rounded-xl border border-dashed border-slate-300 px-4 py-6 text-sm text-slate-500">
                            Este conductor no tiene jornada visible en el otro mundo del comparativo.
                        </div>
                    </div>

                    <div class="rounded-[1.5rem] border border-slate-200 bg-white p-5">
                        <div class="flex items-center justify-between gap-3">
                            <h4 class="text-base font-semibold text-slate-900">Secuencia de paradas</h4>
                            <span class="inline-flex items-center gap-2 rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700">
                                <span class="h-2.5 w-2.5 rounded-full" :style="{ backgroundColor: journeyColor(detailJourney.driver_key) }" />
                                Color de la ruta
                            </span>
                        </div>

                        <ol class="mt-5 space-y-3">
                            <li
                                v-for="stop in detailJourney.stops"
                                :key="`${detailJourney.driver_key}-${detailJourney.world}-${stop.stop_key}`"
                                class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm"
                            >
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <p class="font-semibold text-slate-900">{{ stop.sequence }}. {{ stop.branch_code }} · {{ stop.branch_name }}</p>
                                        <p class="mt-1 text-xs text-slate-500">{{ stop.branch_address }}</p>
                                        <p class="mt-2 text-xs text-slate-600">{{ detailStopContext(stop) }}</p>
                                    </div>
                                    <div class="text-right text-xs text-slate-600">
                                        <p class="font-semibold text-slate-800">{{ stop.invoice_count }} facturas</p>
                                        <p v-if="stop.historical_sequence">Secuencia hist. {{ stop.historical_sequence }}</p>
                                        <p v-else-if="stop.historical_sequence_min">Hist. min {{ stop.historical_sequence_min }}</p>
                                    </div>
                                </div>
                            </li>
                        </ol>
                    </div>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>
