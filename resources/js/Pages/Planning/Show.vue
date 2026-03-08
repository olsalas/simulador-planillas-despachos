<script setup>
import PlanningJourneyMap from '@/Components/PlanningJourneyMap.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    scenario: {
        type: Object,
        required: true,
    },
    candidateStops: {
        type: Array,
        required: true,
    },
    excludedStops: {
        type: Array,
        required: true,
    },
    unassignedStops: {
        type: Array,
        required: true,
    },
    drivers: {
        type: Array,
        required: true,
    },
    proposedJourneys: {
        type: Array,
        required: true,
    },
});

const allocationForm = useForm({});

function generateAllocation() {
    allocationForm.post(route('planning.scenarios.allocate', props.scenario.id));
}

function summaryValue(summary, key) {
    return summary?.[key] ?? 0;
}

function configLabel(key, value) {
    const labels = {
        return_to_depot: 'Retorno al depot',
        prioritize_proximity: 'Priorizar cercania',
        respect_zones: 'Respetar zonas',
        allow_cross_zone_assignment: 'Permitir mezcla de zonas',
        max_stops_per_driver: 'Max paradas por conductor',
        max_invoices_per_journey: 'Max facturas por jornada',
    };

    if (value === null) {
        return `${labels[key] ?? key}: sin limite`;
    }

    if (typeof value === 'boolean') {
        return `${labels[key] ?? key}: ${value ? 'si' : 'no'}`;
    }

    return `${labels[key] ?? key}: ${value}`;
}
</script>

<template>
    <Head :title="scenario.name" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.24em] text-sky-600">
                        Escenario persistido
                    </p>
                    <h2 class="mt-2 text-xl font-semibold leading-tight text-gray-800">
                        {{ scenario.name }}
                    </h2>
                    <p class="mt-1 text-sm text-gray-600">
                        Snapshot base del dia {{ scenario.service_date }} para {{ scenario.depot.name }}.
                    </p>
                </div>

                <div class="flex flex-wrap gap-3">
                    <button
                        type="button"
                        class="inline-flex items-center rounded-lg bg-sky-600 px-4 py-2 text-sm font-medium text-white hover:bg-sky-500 disabled:cursor-not-allowed disabled:bg-sky-300"
                        :disabled="allocationForm.processing || candidateStops.length === 0"
                        @click="generateAllocation"
                    >
                        {{ allocationForm.processing ? 'Generando propuesta...' : 'Generar propuesta base' }}
                    </button>
                    <Link
                        :href="route('planning.scenarios.index')"
                        class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                    >
                        Volver a escenarios
                    </Link>
                    <Link
                        :href="route('simulation.run')"
                        class="inline-flex items-center rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800"
                    >
                        Ir al comparador historico
                    </Link>
                </div>
            </div>
        </template>

        <div class="py-10">
            <div class="mx-auto flex max-w-7xl flex-col gap-6 px-4 sm:px-6 lg:px-8">
                <section class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                        <p class="text-sm text-gray-500">Facturas candidatas</p>
                        <p class="mt-2 text-3xl font-semibold text-gray-900">
                            {{ summaryValue(scenario.summary, 'total_invoices') }}
                        </p>
                    </div>
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                        <p class="text-sm text-gray-500">Paradas elegibles</p>
                        <p class="mt-2 text-3xl font-semibold text-gray-900">
                            {{ summaryValue(scenario.summary, 'eligible_stops') }}
                        </p>
                    </div>
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                        <p class="text-sm text-gray-500">Paradas excluidas</p>
                        <p class="mt-2 text-3xl font-semibold text-gray-900">
                            {{ summaryValue(scenario.summary, 'excluded_stops') }}
                        </p>
                    </div>
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                        <p class="text-sm text-gray-500">Conductores activos del depot</p>
                        <p class="mt-2 text-3xl font-semibold text-gray-900">
                            {{ summaryValue(scenario.summary, 'active_drivers_in_depot') }}
                        </p>
                    </div>
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                        <p class="text-sm text-gray-500">Jornadas propuestas</p>
                        <p class="mt-2 text-3xl font-semibold text-gray-900">
                            {{ summaryValue(scenario.summary, 'proposed_journeys') }}
                        </p>
                    </div>
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                        <p class="text-sm text-gray-500">No asignadas</p>
                        <p class="mt-2 text-3xl font-semibold text-gray-900">
                            {{ summaryValue(scenario.summary, 'unassigned_stops') }}
                        </p>
                    </div>
                </section>

                <section class="grid gap-6 xl:grid-cols-[1.2fr_0.8fr]">
                    <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                        <h3 class="text-lg font-semibold text-gray-900">Contexto del escenario</h3>
                        <dl class="mt-5 grid gap-4 md:grid-cols-2 text-sm">
                            <div class="rounded-xl bg-gray-50 p-4">
                                <dt class="text-gray-500">Depot</dt>
                                <dd class="mt-1 font-semibold text-gray-900">
                                    {{ scenario.depot.code }} · {{ scenario.depot.name }}
                                </dd>
                                <p class="mt-1 text-gray-600">{{ scenario.depot.address }}</p>
                            </div>
                            <div class="rounded-xl bg-gray-50 p-4">
                                <dt class="text-gray-500">Estado</dt>
                                <dd class="mt-1 font-semibold text-gray-900">{{ scenario.status }}</dd>
                                <p class="mt-1 text-gray-600">Generado: {{ scenario.last_generated_at }}</p>
                            </div>
                            <div class="rounded-xl bg-gray-50 p-4">
                                <dt class="text-gray-500">Facturas excluidas</dt>
                                <dd class="mt-1 font-semibold text-gray-900">
                                    {{ summaryValue(scenario.summary, 'excluded_invoices') }}
                                </dd>
                                <p class="mt-1 text-gray-600">
                                    Se reportan aparte para no contaminar el motor de asignacion.
                                </p>
                            </div>
                            <div class="rounded-xl bg-gray-50 p-4">
                                <dt class="text-gray-500">Lotes historicos detectados</dt>
                                <dd class="mt-1 font-semibold text-gray-900">
                                    {{ summaryValue(scenario.summary, 'historical_route_batches') }}
                                </dd>
                                <p class="mt-1 text-gray-600">
                                    Referencia util para contrastar contra planillado historico.
                                </p>
                            </div>
                        </dl>

                        <div class="mt-6 rounded-xl bg-sky-50 p-4 text-sm text-sky-900">
                            El escenario queda listo para generar una propuesta base usando un barrido angular desde el depot y secuenciacion
                            vial por vecino mas cercano. Es una primera heuristica explicable, no el optimizador final.
                        </div>
                    </div>

                    <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                        <h3 class="text-lg font-semibold text-gray-900">Configuracion base guardada</h3>
                        <div class="mt-4 flex flex-wrap gap-2">
                            <span
                                v-for="(value, key) in scenario.configuration"
                                :key="key"
                                class="rounded-full bg-gray-100 px-3 py-1 text-xs font-medium text-gray-700"
                            >
                                {{ configLabel(key, value) }}
                            </span>
                        </div>

                        <h4 class="mt-6 text-sm font-semibold uppercase tracking-[0.2em] text-gray-500">
                            Conductores del depot
                        </h4>
                        <div v-if="drivers.length === 0" class="mt-4 rounded-xl border border-dashed border-gray-300 p-4 text-sm text-gray-500">
                            No hay conductores asociados a este depot.
                        </div>
                        <ul v-else class="mt-4 space-y-2">
                            <li
                                v-for="driver in drivers"
                                :key="driver.id"
                                class="flex items-center justify-between rounded-xl bg-gray-50 px-4 py-3 text-sm"
                            >
                                <div>
                                    <p class="font-medium text-gray-900">{{ driver.name }}</p>
                                    <p class="text-gray-500">{{ driver.external_id ?? 'Sin external_id' }}</p>
                                </div>
                                <span
                                    class="rounded-full px-2.5 py-1 text-[11px] font-semibold"
                                    :class="driver.is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-200 text-gray-700'"
                                >
                                    {{ driver.is_active ? 'Activo' : 'Inactivo' }}
                                </span>
                            </li>
                        </ul>
                    </div>
                </section>

                <PlanningJourneyMap :journeys="proposedJourneys" />

                <section class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-900">Jornadas propuestas</h3>
                            <p class="mt-1 text-sm text-gray-600">
                                Resultado base de asignacion por depot. Cada jornada queda asociada a un conductor activo y ya trae su secuencia sugerida.
                            </p>
                        </div>
                        <span class="rounded-full bg-sky-100 px-3 py-1 text-xs font-semibold text-sky-700">
                            {{ proposedJourneys.length }} jornadas
                        </span>
                    </div>

                    <div v-if="proposedJourneys.length === 0" class="mt-6 rounded-xl border border-dashed border-gray-300 p-5 text-sm text-gray-500">
                        Aun no hay propuesta generada. Usa el boton superior para distribuir las paradas candidatas entre los conductores activos del depot.
                    </div>

                    <div v-else class="mt-6 grid gap-4 xl:grid-cols-2">
                        <article
                            v-for="journey in proposedJourneys"
                            :key="journey.id"
                            class="rounded-2xl border border-gray-200 bg-gray-50 p-5"
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
                                    {{ journey.status }}
                                </span>
                            </div>

                            <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
                                <div class="rounded-xl bg-white p-3">
                                    <dt class="text-gray-500">Paradas</dt>
                                    <dd class="mt-1 text-lg font-semibold text-gray-900">{{ journey.total_stops }}</dd>
                                </div>
                                <div class="rounded-xl bg-white p-3">
                                    <dt class="text-gray-500">Facturas</dt>
                                    <dd class="mt-1 text-lg font-semibold text-gray-900">{{ journey.total_invoices }}</dd>
                                </div>
                                <div class="rounded-xl bg-white p-3">
                                    <dt class="text-gray-500">Distancia</dt>
                                    <dd class="mt-1 text-lg font-semibold text-gray-900">
                                        {{ (summaryValue(journey.summary, 'distance_meters') / 1000).toFixed(2) }} km
                                    </dd>
                                </div>
                                <div class="rounded-xl bg-white p-3">
                                    <dt class="text-gray-500">Duracion</dt>
                                    <dd class="mt-1 text-lg font-semibold text-gray-900">
                                        {{ (summaryValue(journey.summary, 'duration_seconds') / 60).toFixed(1) }} min
                                    </dd>
                                </div>
                            </dl>

                            <ol class="mt-4 space-y-2">
                                <li
                                    v-for="stop in journey.stops"
                                    :key="stop.id"
                                    class="rounded-xl bg-white px-4 py-3 text-sm"
                                >
                                    <div class="flex items-start justify-between gap-3">
                                        <div>
                                            <p class="font-medium text-gray-900">
                                                {{ stop.suggested_sequence }}. {{ stop.branch_code }} · {{ stop.branch_name }}
                                            </p>
                                            <p class="text-xs text-gray-500">{{ stop.branch_address }}</p>
                                        </div>
                                        <div class="text-right">
                                            <p class="font-semibold text-gray-800">{{ stop.invoice_count }} facturas</p>
                                            <p class="text-xs text-gray-500">
                                                Hist. min {{ stop.historical_sequence_min ?? 'n/a' }}
                                            </p>
                                        </div>
                                    </div>
                                </li>
                            </ol>
                        </article>
                    </div>
                </section>

                <section class="grid gap-6 xl:grid-cols-2">
                    <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <h3 class="text-lg font-semibold text-gray-900">Paradas candidatas</h3>
                                <p class="mt-1 text-sm text-gray-600">
                                    Vista base del snapshot persistido. Aqui se ven todas las paradas operables, asignadas o aun pendientes.
                                </p>
                            </div>
                            <span class="rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-700">
                                {{ candidateStops.length }} paradas
                            </span>
                        </div>

                        <div v-if="candidateStops.length === 0" class="mt-6 rounded-xl border border-dashed border-gray-300 p-5 text-sm text-gray-500">
                            No hubo paradas elegibles con geocodigo suficiente.
                        </div>

                        <div v-else class="mt-6 overflow-hidden rounded-xl border border-gray-200">
                            <table class="min-w-full divide-y divide-gray-200 text-sm">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-4 py-3 text-left font-semibold text-gray-600">Sucursal</th>
                                        <th class="px-4 py-3 text-left font-semibold text-gray-600">Facturas</th>
                                        <th class="px-4 py-3 text-left font-semibold text-gray-600">Hist. min</th>
                                        <th class="px-4 py-3 text-left font-semibold text-gray-600">Estado</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 bg-white">
                                    <tr v-for="stop in candidateStops" :key="stop.id">
                                        <td class="px-4 py-3">
                                            <p class="font-medium text-gray-900">
                                                {{ stop.branch_code }} · {{ stop.branch_name }}
                                            </p>
                                            <p class="text-xs text-gray-500">{{ stop.branch_address }}</p>
                                        </td>
                                        <td class="px-4 py-3 text-gray-700">{{ stop.invoice_count }}</td>
                                        <td class="px-4 py-3 text-gray-700">
                                            {{ stop.historical_sequence_min ?? 'n/a' }}
                                        </td>
                                        <td class="px-4 py-3">
                                            <span
                                                class="rounded-full px-2.5 py-1 text-[11px] font-semibold"
                                                :class="stop.status === 'assigned'
                                                    ? 'bg-sky-100 text-sky-700'
                                                    : stop.status === 'unassigned'
                                                        ? 'bg-amber-100 text-amber-700'
                                                        : 'bg-emerald-100 text-emerald-700'"
                                            >
                                                {{ stop.status }}
                                            </span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <h3 class="text-lg font-semibold text-gray-900">No asignadas</h3>
                                <p class="mt-1 text-sm text-gray-600">
                                    Paradas operables que la heuristica actual no logro ubicar por limite o falta de capacidad disponible.
                                </p>
                            </div>
                            <span class="rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-700">
                                {{ unassignedStops.length }} no asignadas
                            </span>
                        </div>

                        <div v-if="unassignedStops.length === 0" class="mt-6 rounded-xl border border-dashed border-gray-300 p-5 text-sm text-gray-500">
                            No hay paradas operables sin asignar en la propuesta actual.
                        </div>

                        <div v-else class="mt-6 overflow-hidden rounded-xl border border-gray-200">
                            <table class="min-w-full divide-y divide-gray-200 text-sm">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-4 py-3 text-left font-semibold text-gray-600">Punto</th>
                                        <th class="px-4 py-3 text-left font-semibold text-gray-600">Facturas</th>
                                        <th class="px-4 py-3 text-left font-semibold text-gray-600">Motivo</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 bg-white">
                                    <tr v-for="stop in unassignedStops" :key="stop.id">
                                        <td class="px-4 py-3">
                                            <p class="font-medium text-gray-900">
                                                {{ stop.branch_code ? `${stop.branch_code} · ${stop.branch_name}` : stop.branch_name }}
                                            </p>
                                            <p v-if="stop.branch_address" class="text-xs text-gray-500">{{ stop.branch_address }}</p>
                                        </td>
                                        <td class="px-4 py-3 text-gray-700">{{ stop.invoice_count }}</td>
                                        <td class="px-4 py-3 text-gray-700">{{ stop.reason }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>

                <section class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-900">Excluidas por calidad de datos</h3>
                            <p class="mt-1 text-sm text-gray-600">
                                Estas no entran al algoritmo porque faltan datos base para planillarlas con confianza.
                            </p>
                        </div>
                        <span class="rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-700">
                            {{ excludedStops.length }} excluidas
                        </span>
                    </div>

                    <div v-if="excludedStops.length === 0" class="mt-6 rounded-xl border border-dashed border-gray-300 p-5 text-sm text-gray-500">
                        No hay exclusiones por calidad de datos en este escenario.
                    </div>

                    <div v-else class="mt-6 overflow-hidden rounded-xl border border-gray-200">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left font-semibold text-gray-600">Punto</th>
                                    <th class="px-4 py-3 text-left font-semibold text-gray-600">Facturas</th>
                                    <th class="px-4 py-3 text-left font-semibold text-gray-600">Motivo</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 bg-white">
                                <tr v-for="stop in excludedStops" :key="stop.id">
                                    <td class="px-4 py-3">
                                        <p class="font-medium text-gray-900">
                                            {{ stop.branch_code ? `${stop.branch_code} · ${stop.branch_name}` : stop.branch_name }}
                                        </p>
                                        <p v-if="stop.branch_address" class="text-xs text-gray-500">{{ stop.branch_address }}</p>
                                    </td>
                                    <td class="px-4 py-3 text-gray-700">{{ stop.invoice_count }}</td>
                                    <td class="px-4 py-3 text-gray-700">{{ stop.reason }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
