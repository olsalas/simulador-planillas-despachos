<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();
const flash = computed(() => page.props.flash ?? {});

const form = useForm({
    type: 'invoices',
    file: null,
});

function submit() {
    form.post(route('ingestion.upload.store'), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => form.reset('file'),
    });
}
</script>

<template>
    <Head title="Cargar CSV" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Cargar CSV
            </h2>
        </template>

        <div class="py-10">
            <div class="mx-auto max-w-4xl px-4 sm:px-6 lg:px-8">
                <div
                    v-if="flash.success"
                    class="mb-4 rounded-md border border-green-300 bg-green-50 px-4 py-3 text-sm text-green-700"
                >
                    {{ flash.success }}
                </div>

                <div
                    v-if="flash.error"
                    class="mb-4 rounded-md border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-700"
                >
                    {{ flash.error }}
                </div>

                <form
                    class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm"
                    @submit.prevent="submit"
                >
                    <h3 class="text-base font-semibold text-gray-900">
                        Nueva carga CSV
                    </h3>

                    <div class="mt-4 grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">
                                Tipo de carga
                            </label>
                            <select
                                v-model="form.type"
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-gray-900 focus:ring-gray-900"
                            >
                                <option value="invoices">Facturas</option>
                                <option value="drivers">Conductores</option>
                            </select>
                            <p v-if="form.errors.type" class="mt-1 text-xs text-red-600">
                                {{ form.errors.type }}
                            </p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700">
                                Archivo CSV
                            </label>
                            <input
                                type="file"
                                accept=".csv,text/csv"
                                class="mt-1 block w-full text-sm text-gray-700 file:mr-4 file:rounded-md file:border-0 file:bg-gray-900 file:px-4 file:py-2 file:text-sm file:font-medium file:text-white hover:file:bg-gray-800"
                                @change="form.file = $event.target.files[0]"
                            >
                            <p v-if="form.errors.file" class="mt-1 text-xs text-red-600">
                                {{ form.errors.file }}
                            </p>
                        </div>
                    </div>

                    <div class="mt-5 flex items-center gap-3">
                        <button
                            type="submit"
                            :disabled="form.processing"
                            class="inline-flex rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            {{ form.processing ? 'Procesando...' : 'Procesar CSV' }}
                        </button>

                        <Link
                            :href="route('ingestion.batches')"
                            class="inline-flex rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                        >
                            Ver Batches
                        </Link>
                    </div>
                </form>

                <div
                    v-if="flash.importReport"
                    class="mt-4 rounded-lg border border-gray-200 bg-white p-6 shadow-sm"
                >
                    <h3 class="text-base font-semibold text-gray-900">Resultado de importación</h3>
                    <div class="mt-3 grid gap-3 text-sm text-gray-700 md:grid-cols-4">
                        <p><span class="font-semibold">Tipo:</span> {{ flash.importReport.type }}</p>
                        <p><span class="font-semibold">Filas:</span> {{ flash.importReport.total_rows }}</p>
                        <p><span class="font-semibold">Válidas:</span> {{ flash.importReport.valid_rows }}</p>
                        <p><span class="font-semibold">Inválidas:</span> {{ flash.importReport.invalid_rows }}</p>
                    </div>

                    <div
                        v-if="flash.importReport.invalid_samples?.length"
                        class="mt-4 overflow-hidden rounded-md border border-red-200"
                    >
                        <div class="border-b border-red-200 bg-red-50 px-4 py-2 text-sm font-medium text-red-800">
                            Errores por fila (muestra)
                        </div>
                        <ul class="divide-y divide-red-100">
                            <li
                                v-for="sample in flash.importReport.invalid_samples"
                                :key="sample.row_number"
                                class="px-4 py-3 text-sm text-red-700"
                            >
                                Fila {{ sample.row_number }}:
                                {{ Object.values(sample.errors).flat().join(', ') }}
                            </li>
                        </ul>
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
