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
        Schema::create('planning_scenario_stops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('planning_scenario_id')->constrained('planning_scenarios')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('stop_key');
            $table->string('status')->default('pending_assignment');
            $table->string('exclusion_reason')->nullable();
            $table->string('branch_code')->nullable();
            $table->string('branch_name')->nullable();
            $table->string('branch_address')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedInteger('invoice_count')->default(0);
            $table->unsignedInteger('historical_sequence_min')->nullable();
            $table->jsonb('invoice_ids')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->unique(['planning_scenario_id', 'stop_key'], 'planning_scenario_stops_scenario_stop_key_unique');
            $table->index(['planning_scenario_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('planning_scenario_stops');
    }
};
