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
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('ingestion_batch')->nullable()->index();
            $table->string('external_invoice_id')->index();
            $table->string('invoice_number')->nullable();
            $table->foreignId('driver_id')->nullable()->constrained('drivers')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->date('service_date')->index();
            $table->unsignedInteger('historical_sequence')->nullable();
            $table->decimal('historical_latitude', 10, 7)->nullable();
            $table->decimal('historical_longitude', 10, 7)->nullable();
            $table->string('status')->default('pending');
            $table->string('outlier_reason')->nullable();
            $table->jsonb('raw_payload')->nullable();
            $table->timestamps();

            $table->index(['driver_id', 'service_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
