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
        Schema::create('planning_scenario_journeys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('planning_scenario_id')->constrained('planning_scenarios')->cascadeOnDelete();
            $table->foreignId('driver_id')->constrained('drivers')->cascadeOnDelete();
            $table->string('name');
            $table->string('status')->default('draft');
            $table->unsignedInteger('total_stops')->default(0);
            $table->unsignedInteger('total_invoices')->default(0);
            $table->jsonb('summary')->nullable();
            $table->timestamps();

            $table->unique(['planning_scenario_id', 'driver_id'], 'planning_scenario_journeys_scenario_driver_unique');
            $table->index(['planning_scenario_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('planning_scenario_journeys');
    }
};
