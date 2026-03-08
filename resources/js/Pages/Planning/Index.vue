<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    depots: {
        type: Array,
        required: true,
    },
    scenarios: {
        type: Array,
        required: true,
    },
    defaultServiceDate: {
        type: String,
        required: true,
    },
});

const form = useForm({
    service_date: props.defaultServiceDate,
    depot_id: props.depots[0]?.id ?? '',
});

const hasDepots = computed(() => props.depots.length > 0);

function submit() {
    form.post(route('planning.scenarios.store'));
}

function summaryValue(summary, key) {
    return summary?.[key] ?? 0;
}

function statusLabel(status) {
    if (status === 'snapshot_ready') {
        return 'Snapshot listo';
    }

    if (status === 'allocation_ready') {
        return 'Propuesta lista';
    }

    if (status === 'allocation_partial') {
        return 'Propuesta parcial';
    }

    if (status === 'allocation_blocked') {
        return 'Propuesta bloqueada';
    }

    if (status === 'empty') {
        return 'Sin demanda';
    }

    return status;
}
</script>

<template>
    <Head title="Planificar" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col gap-1">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">
                    Propuesta diaria de planillado
                </h2>
                <p class="text-sm text-gray-600">
                    Genera un snapshot operativo por fecha y CEDIS usando la demanda histórica disponible del depot.
                </p>
            </div>
        </template>

        <div class="py-10">
            <div class="mx-auto flex max-w-7xl flex-col gap-6 px-4 sm:px-6 lg:px-8">
                <section class="grid gap-6 lg:grid-cols-[1.1fr_0.9fr]">
                    <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                        <div class="flex flex-col gap-2">
                            <p class="text-xs font-semibold uppercase tracking-[0.24em] text-sky-600">
                                Fundacion V1
                            </p>
                            <h3 class="text-lg font-semibold text-gray-900">
                                Crear o refrescar escenario base
                            </h3>
                            <p class="text-sm text-gray-600">
                                El escenario deja persistido el corte operativo del dia para un depot: demanda candidata,
                                paradas elegibles, excluidas y conductores activos que luego alimentan la propuesta base.
                            </p>
                        </div>

                        <div
                            v-if="!$page.props.auth.abilities.manage_planning"
                            class="mt-6 rounded-xl border border-dashed border-amber-300 bg-amber-50 p-4 text-sm text-amber-900"
                        >
                            Tu rol actual es de consulta. Puedes revisar escenarios existentes, pero no crear ni refrescar snapshots.
                        </div>

                        <form
                            v-else
                            class="mt-6 grid gap-4 md:grid-cols-2"
                            @submit.prevent="submit"
                        >
                            <label class="flex flex-col gap-2 text-sm font-medium text-gray-700">
                                Fecha de servicio
                                <input
                                    v-model="form.service_date"
                                    type="date"
                                    class="rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-sky-500 focus:outline-none focus:ring-2 focus:ring-sky-200"
                                >
                                <span v-if="form.errors.service_date" class="text-xs text-red-600">
                                    {{ form.errors.service_date }}
                                </span>
                            </label>

                            <label class="flex flex-col gap-2 text-sm font-medium text-gray-700">
                                Depot / CEDIS
                                <select
                                    v-model="form.depot_id"
                                    class="rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-sky-500 focus:outline-none focus:ring-2 focus:ring-sky-200"
                                    :disabled="!hasDepots"
                                >
                                    <option value="" disabled>Selecciona un depot</option>
                                    <option
                                        v-for="depot in depots"
                                        :key="depot.id"
                                        :value="depot.id"
                                    >
                                        {{ depot.code }} · {{ depot.name }} · {{ depot.active_drivers_count }} activos
                                    </option>
                                </select>
                                <span v-if="form.errors.depot_id" class="text-xs text-red-600">
                                    {{ form.errors.depot_id }}
                                </span>
                            </label>

                            <div class="md:col-span-2 flex flex-wrap items-center gap-3">
                                <button
                                    type="submit"
                                    class="inline-flex items-center rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-gray-800 disabled:cursor-not-allowed disabled:bg-gray-400"
                                    :disabled="form.processing || !hasDepots"
                                >
                                    {{ form.processing ? 'Generando...' : 'Crear o actualizar escenario' }}
                                </button>
                                <p class="text-xs text-gray-500">
                                    Si ya existe un escenario para esa fecha y depot, se refresca el snapshot en vez de duplicarlo.
                                </p>
                            </div>
                        </form>
                    </div>

                    <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                        <h3 class="text-lg font-semibold text-gray-900">Que queda listo en esta rama</h3>
                        <ul class="mt-4 space-y-3 text-sm text-gray-600">
                            <li>Persistencia de escenarios por fecha + depot.</li>
                            <li>Snapshot de demanda agrupada por paradas usando branches geocodificados.</li>
                            <li>Identificacion explicita de paradas excluidas por calidad de datos.</li>
                            <li>Propuesta base de asignacion a conductores activos del depot.</li>
                        </ul>

                        <div class="mt-5 rounded-xl bg-sky-50 p-4 text-sm text-sky-900">
                            La fuente de demanda de esta version usa las facturas historicas del dia asociadas a conductores del depot.
                            El siguiente salto es desacoplarla hacia una propuesta operativa independiente del historico.
                        </div>
                    </div>
                </section>

                <section class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                    <div class="flex items-center justify-between gap-4">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-900">Escenarios recientes</h3>
                            <p class="mt-1 text-sm text-gray-600">
                                Revisa el corte operativo y entra al detalle para ver paradas elegibles, excluidas y base de conductores.
                            </p>
                        </div>
                        <div class="rounded-full bg-gray-100 px-3 py-1 text-xs font-semibold text-gray-600">
                            {{ scenarios.length }} escenarios
                        </div>
                    </div>

                    <div v-if="scenarios.length === 0" class="mt-6 rounded-xl border border-dashed border-gray-300 px-6 py-12 text-center text-sm text-gray-500">
                        Aun no hay escenarios persistidos. Crea el primero desde el formulario superior.
                    </div>

                    <div v-else class="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        <article
                            v-for="scenario in scenarios"
                            :key="scenario.id"
                            class="rounded-2xl border border-gray-200 bg-gray-50 p-5"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <p class="text-xs font-semibold uppercase tracking-[0.22em] text-sky-600">
                                        {{ scenario.service_date }}
                                    </p>
                                    <h4 class="mt-2 text-base font-semibold text-gray-900">
                                        {{ scenario.depot.name }}
                                    </h4>
                                    <p class="mt-1 text-sm text-gray-600">
                                        {{ scenario.depot.code }}
                                    </p>
                                </div>
                                <span class="rounded-full bg-white px-2.5 py-1 text-[11px] font-semibold text-gray-700 shadow-sm">
                                    {{ statusLabel(scenario.status) }}
                                </span>
                            </div>

                            <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
                                <div class="rounded-xl bg-white p-3">
                                    <dt class="text-gray-500">Facturas</dt>
                                    <dd class="mt-1 text-lg font-semibold text-gray-900">
                                        {{ summaryValue(scenario.summary, 'total_invoices') }}
                                    </dd>
                                </div>
                                <div class="rounded-xl bg-white p-3">
                                    <dt class="text-gray-500">Paradas elegibles</dt>
                                    <dd class="mt-1 text-lg font-semibold text-gray-900">
                                        {{ summaryValue(scenario.summary, 'eligible_stops') }}
                                    </dd>
                                </div>
                                <div class="rounded-xl bg-white p-3">
                                    <dt class="text-gray-500">Excluidas</dt>
                                    <dd class="mt-1 text-lg font-semibold text-gray-900">
                                        {{ summaryValue(scenario.summary, 'excluded_stops') }}
                                    </dd>
                                </div>
                                <div class="rounded-xl bg-white p-3">
                                    <dt class="text-gray-500">Conductores activos</dt>
                                    <dd class="mt-1 text-lg font-semibold text-gray-900">
                                        {{ summaryValue(scenario.summary, 'active_drivers_in_depot') }}
                                    </dd>
                                </div>
                            </dl>

                            <Link
                                :href="route('planning.scenarios.show', scenario.id)"
                                class="mt-5 inline-flex items-center rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800"
                            >
                                Ver detalle del escenario
                            </Link>
                        </article>
                    </div>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
