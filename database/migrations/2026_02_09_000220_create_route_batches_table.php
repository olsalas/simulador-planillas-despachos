<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('route_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained('drivers')->cascadeOnDelete();
            $table->date('service_date');
            $table->foreignId('source_ingestion_batch_id')->nullable()->constrained('ingestion_batches')->nullOnDelete();
            $table->unsignedInteger('total_invoices')->default(0);
            $table->unsignedInteger('total_stops')->default(0);
            $table->unsignedInteger('pending_invoices')->default(0);
            $table->string('status')->default('ready');
            $table->timestamps();

            $table->unique(['driver_id', 'service_date']);
            $table->index(['service_date', 'driver_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('route_batches');
    }
};
