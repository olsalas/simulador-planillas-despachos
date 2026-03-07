<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    batch: {
        type: Object,
        required: true,
    },
    stops: {
        type: Array,
        required: true,
    },
    pendingInvoices: {
        type: Array,
        required: true,
    },
});
</script>

<template>
    <Head :title="`Batch ${batch.id}`" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Batch #{{ batch.id }}
            </h2>
        </template>

        <div class="py-10">
            <div class="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">
                <div class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                    <div class="grid gap-3 text-sm text-gray-700 md:grid-cols-5">
                        <p><span class="font-semibold">Fecha:</span> {{ batch.service_date }}</p>
                        <p><span class="font-semibold">Conductor:</span> {{ batch.driver?.name || 'Sin conductor' }}</p>
                        <p><span class="font-semibold">Facturas:</span> {{ batch.total_invoices }}</p>
                        <p><span class="font-semibold">Paradas:</span> {{ batch.total_stops }}</p>
                        <p><span class="font-semibold">Pendientes:</span> {{ batch.pending_invoices }}</p>
                    </div>
                </div>

                <div class="mt-4 overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
                    <div class="border-b border-gray-200 bg-gray-50 px-4 py-3 text-sm font-semibold text-gray-800">
                        Paradas consolidadas
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left font-semibold text-gray-700">Sucursal</th>
                                    <th class="px-4 py-3 text-left font-semibold text-gray-700">Código</th>
                                    <th class="px-4 py-3 text-left font-semibold text-gray-700">Facturas</th>
                                    <th class="px-4 py-3 text-left font-semibold text-gray-700">Estado</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 bg-white">
                                <tr v-for="stop in stops" :key="stop.id">
                                    <td class="px-4 py-3 text-gray-700">{{ stop.branch?.name || 'Sin sucursal' }}</td>
                                    <td class="px-4 py-3 text-gray-700">{{ stop.branch?.code || '-' }}</td>
                                    <td class="px-4 py-3 text-gray-700">{{ stop.invoice_count }}</td>
                                    <td class="px-4 py-3 text-gray-700">{{ stop.status }}</td>
                                </tr>
                                <tr v-if="stops.length === 0">
                                    <td colspan="4" class="px-4 py-6 text-center text-sm text-gray-500">
                                        Este batch no tiene paradas consolidadas.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="mt-4 overflow-hidden rounded-lg border border-yellow-200 bg-white shadow-sm">
                    <div class="border-b border-yellow-200 bg-yellow-50 px-4 py-3 text-sm font-semibold text-yellow-800">
                        Pendientes / Outliers
                    </div>
                    <ul class="divide-y divide-yellow-100">
                        <li
                            v-for="invoice in pendingInvoices"
                            :key="invoice.id"
                            class="px-4 py-3 text-sm text-yellow-800"
                        >
                            Factura {{ invoice.external_invoice_id }}
                            <span v-if="invoice.invoice_number">({{ invoice.invoice_number }})</span>:
                            {{ invoice.outlier_reason || 'sin razón' }}
                        </li>
                        <li
                            v-if="pendingInvoices.length === 0"
                            class="px-4 py-3 text-sm text-gray-500"
                        >
                            No hay pendientes para este batch.
                        </li>
                    </ul>
                </div>

                <div class="mt-4 flex gap-2">
                    <Link
                        :href="route('ingestion.batches')"
                        class="inline-flex rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                    >
                        Volver a Batches
                    </Link>
                    <Link
                        :href="route('dashboard')"
                        class="inline-flex rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                    >
                        Dashboard
                    </Link>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
