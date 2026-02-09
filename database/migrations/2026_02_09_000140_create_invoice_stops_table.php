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
        Schema::create('invoice_stops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained('drivers')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->date('service_date');
            $table->unsignedInteger('invoice_count')->default(0);
            $table->unsignedInteger('planned_sequence')->nullable();
            $table->string('status')->default('pending');
            $table->unsignedInteger('distance_from_previous_meters')->nullable();
            $table->unsignedInteger('travel_time_from_previous_seconds')->nullable();
            $table->timestamps();

            $table->index(['driver_id', 'service_date']);
            $table->unique(['driver_id', 'branch_id', 'service_date'], 'invoice_stops_driver_branch_date_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_stops');
    }
};
