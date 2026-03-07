<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
</script>

<template>
    <Head title="Dashboard" />

    <AuthenticatedLayout>
        <template #header>
            <h2
                class="text-xl font-semibold leading-tight text-gray-800"
            >
                Simulador de Planillado y Ruteo
            </h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <p class="mb-6 text-sm text-gray-600">
                    MVP base para carga de históricos y simulación por conductor + día.
                </p>

                <div class="grid gap-4 md:grid-cols-3">
                    <div
                        v-if="$page.props.auth.abilities.upload_csv"
                        class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm"
                    >
                        <h3 class="text-base font-semibold text-gray-900">Cargar CSV</h3>
                        <p class="mt-2 text-sm text-gray-600">
                            Ingresar facturas históricas asignadas a conductor.
                        </p>
                        <Link
                            :href="route('ingestion.upload')"
                            class="mt-4 inline-flex rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800"
                        >
                            Ir a Cargar CSV
                        </Link>
                    </div>

                    <div class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                        <h3 class="text-base font-semibold text-gray-900">Ver Batches</h3>
                        <p class="mt-2 text-sm text-gray-600">
                            Revisar lotes de ingestión y su estado.
                        </p>
                        <Link
                            :href="route('ingestion.batches')"
                            class="mt-4 inline-flex rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800"
                        >
                            Ir a Batches
                        </Link>
                    </div>

                    <div class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
                        <h3 class="text-base font-semibold text-gray-900">Simular (conductor+día)</h3>
                        <p class="mt-2 text-sm text-gray-600">
                            Ejecutar simulación con retorno al CEDIS por defecto.
                        </p>
                        <Link
                            :href="route('simulation.run')"
                            class="mt-4 inline-flex rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800"
                        >
                            Ir a Simular
                        </Link>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
