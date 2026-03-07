<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    scenario: {
        type: Object,
        required: true,
    },
    eligibleStops: {
        type: Array,
        required: true,
    },
    excludedStops: {
        type: Array,
        required: true,
    },
    drivers: {
        type: Array,
        required: true,
    },
});

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
                            Este escenario todavia no distribuye las paradas entre conductores. La siguiente fase consumira este snapshot
                            para asignar, secuenciar y comparar escenarios de planillado real.
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

                <section class="grid gap-6 xl:grid-cols-2">
                    <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <h3 class="text-lg font-semibold text-gray-900">Paradas elegibles</h3>
                                <p class="mt-1 text-sm text-gray-600">
                                    Snapshot listo para entrar al motor de asignacion.
                                </p>
                            </div>
                            <span class="rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-700">
                                {{ eligibleStops.length }} paradas
                            </span>
                        </div>

                        <div v-if="eligibleStops.length === 0" class="mt-6 rounded-xl border border-dashed border-gray-300 p-5 text-sm text-gray-500">
                            No hubo paradas elegibles con geocodigo suficiente.
                        </div>

                        <div v-else class="mt-6 overflow-hidden rounded-xl border border-gray-200">
                            <table class="min-w-full divide-y divide-gray-200 text-sm">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-4 py-3 text-left font-semibold text-gray-600">Sucursal</th>
                                        <th class="px-4 py-3 text-left font-semibold text-gray-600">Facturas</th>
                                        <th class="px-4 py-3 text-left font-semibold text-gray-600">Hist. min</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 bg-white">
                                    <tr v-for="stop in eligibleStops" :key="stop.id">
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
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <h3 class="text-lg font-semibold text-gray-900">Excluidas</h3>
                                <p class="mt-1 text-sm text-gray-600">
                                    Casos que requieren mejor calidad de datos antes de entrar al planillado.
                                </p>
                            </div>
                            <span class="rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-700">
                                {{ excludedStops.length }} excluidas
                            </span>
                        </div>

                        <div v-if="excludedStops.length === 0" class="mt-6 rounded-xl border border-dashed border-gray-300 p-5 text-sm text-gray-500">
                            No hay exclusiones para este escenario.
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
                    </div>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
