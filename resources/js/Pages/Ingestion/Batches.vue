<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
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
                <div class="rounded-lg border border-dashed border-gray-300 bg-white p-6 shadow-sm">
                    <p class="text-sm text-gray-700">
                        Pantalla dummy MVP. Aquí aparecerán lotes de carga con totales, estado
                        y errores.
                    </p>
                    <p class="mt-2 text-xs text-gray-500">
                        Próximo paso: tabla de batches + enlace a reporte de outliers.
                    </p>
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
