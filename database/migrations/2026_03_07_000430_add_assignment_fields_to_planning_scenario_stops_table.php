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
        Schema::table('planning_scenario_stops', function (Blueprint $table) {
            $table->foreignId('planning_scenario_journey_id')
                ->nullable()
                ->after('planning_scenario_id')
                ->constrained('planning_scenario_journeys')
                ->nullOnDelete();
            $table->foreignId('assigned_driver_id')
                ->nullable()
                ->after('planning_scenario_journey_id')
                ->constrained('drivers')
                ->nullOnDelete();
            $table->unsignedInteger('suggested_sequence')->nullable()->after('assigned_driver_id');
            $table->string('assignment_reason')->nullable()->after('suggested_sequence');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('planning_scenario_stops', function (Blueprint $table) {
            $table->dropConstrainedForeignId('planning_scenario_journey_id');
            $table->dropConstrainedForeignId('assigned_driver_id');
            $table->dropColumn(['suggested_sequence', 'assignment_reason']);
        });
    }
};
