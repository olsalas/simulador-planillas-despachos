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
                    Punto de entrada para históricos, comparador de jornadas y propuesta diaria de planillado.
                </p>

                <div class="grid gap-4 md:grid-cols-4">
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

                    <div
                        v-if="$page.props.auth.abilities.view_batches"
                        class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm"
                    >
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

                    <div
                        v-if="$page.props.auth.abilities.view_planning"
                        class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm"
                    >
                        <h3 class="text-base font-semibold text-gray-900">Planificar por depot</h3>
                        <p class="mt-2 text-sm text-gray-600">
                            Crear o revisar escenarios diarios por fecha y CEDIS.
                        </p>
                        <Link
                            :href="route('planning.scenarios.index')"
                            class="mt-4 inline-flex rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800"
                        >
                            Ir a Planificar
                        </Link>
                    </div>

                    <div
                        v-if="$page.props.auth.abilities.view_simulation"
                        class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm"
                    >
                        <h3 class="text-base font-semibold text-gray-900">Simular (conductor+día)</h3>
                        <p class="mt-2 text-sm text-gray-600">
                            Comparar cómo fue una jornada y cómo pudo haberse recorrido mejor.
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
