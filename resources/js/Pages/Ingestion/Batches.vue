<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    filters: {
        type: Object,
        required: true,
    },
    batches: {
        type: Object,
        required: true,
    },
    drivers: {
        type: Array,
        required: true,
    },
});
</script>

<template>
    <Head title="Batches" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Ver Batches
            </h2>
        </template>

        <div class="py-10">
            <div class="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8">
                <form
                    method="get"
                    :action="route('ingestion.batches')"
                    class="mb-4 rounded-lg border border-gray-200 bg-white p-4 shadow-sm"
                >
                    <div class="grid gap-4 md:grid-cols-3">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Fecha</label>
                            <input
                                type="date"
                                name="service_date"
                                :value="filters.service_date"
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-gray-900 focus:ring-gray-900"
                            >
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Conductor</label>
                            <select
                                name="driver_id"
                                :value="filters.driver_id"
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-gray-900 focus:ring-gray-900"
                            >
                                <option value="">Todos</option>
                                <option
                                    v-for="driver in drivers"
                                    :key="driver.id"
                                    :value="driver.id"
                                >
                                    {{ driver.name }} ({{ driver.external_id }})
                                </option>
                            </select>
                        </div>
                        <div class="flex items-end gap-2">
                            <button
                                type="submit"
                                class="inline-flex rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800"
                            >
                                Filtrar
                            </button>
                            <Link
                                :href="route('ingestion.batches')"
                                class="inline-flex rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                            >
                                Limpiar
                            </Link>
                        </div>
                    </div>
                </form>

                <div class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left font-semibold text-gray-700">Fecha</th>
                                    <th class="px-4 py-3 text-left font-semibold text-gray-700">Conductor</th>
                                    <th class="px-4 py-3 text-left font-semibold text-gray-700">Facturas</th>
                                    <th class="px-4 py-3 text-left font-semibold text-gray-700">Paradas</th>
                                    <th class="px-4 py-3 text-left font-semibold text-gray-700">Pendientes</th>
                                    <th class="px-4 py-3 text-left font-semibold text-gray-700">Estado</th>
                                    <th class="px-4 py-3 text-left font-semibold text-gray-700">Detalle</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 bg-white">
                                <tr v-for="batch in batches.data" :key="batch.id">
                                    <td class="px-4 py-3 text-gray-700">{{ batch.service_date }}</td>
                                    <td class="px-4 py-3 text-gray-700">
                                        {{ batch.driver?.name || 'Sin conductor' }}
                                        <span v-if="batch.driver?.external_id">
                                            ({{ batch.driver.external_id }})
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-gray-700">{{ batch.total_invoices }}</td>
                                    <td class="px-4 py-3 text-gray-700">{{ batch.total_stops }}</td>
                                    <td class="px-4 py-3 text-gray-700">{{ batch.pending_invoices }}</td>
                                    <td class="px-4 py-3">
                                        <span
                                            class="inline-flex rounded-full px-2 py-1 text-xs font-medium"
                                            :class="batch.status === 'ready' ? 'bg-green-100 text-green-700' : 'bg-yellow-100 text-yellow-700'"
                                        >
                                            {{ batch.status }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <Link
                                            :href="route('ingestion.batches.show', batch.id)"
                                            class="text-sm font-medium text-gray-900 underline"
                                        >
                                            Ver
                                        </Link>
                                    </td>
                                </tr>
                                <tr v-if="batches.data.length === 0">
                                    <td colspan="7" class="px-4 py-6 text-center text-sm text-gray-500">
                                        No hay batches para los filtros seleccionados.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div
                    v-if="batches.links?.length > 3"
                    class="mt-4 flex flex-wrap gap-2"
                >
                    <Link
                        v-for="link in batches.links"
                        :key="`${link.label}-${link.url}`"
                        :href="link.url || ''"
                        :class="[
                            'rounded-md border px-3 py-1 text-sm',
                            link.active ? 'border-gray-900 bg-gray-900 text-white' : 'border-gray-300 bg-white text-gray-700',
                            !link.url ? 'pointer-events-none opacity-40' : '',
                        ]"
                        v-html="link.label"
                    />
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
