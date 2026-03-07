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
        Schema::create('planning_scenarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('depot_id')->constrained('depots')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('service_date');
            $table->string('name');
            $table->string('status')->default('draft');
            $table->jsonb('configuration')->nullable();
            $table->jsonb('summary')->nullable();
            $table->timestamp('last_generated_at')->nullable();
            $table->timestamps();

            $table->unique(['depot_id', 'service_date']);
            $table->index(['service_date', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('planning_scenarios');
    }
};
