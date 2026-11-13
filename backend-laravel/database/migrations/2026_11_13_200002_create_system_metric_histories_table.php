<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_metric_histories', function (Blueprint $table) {
            $table->id();
            $table->date('metric_date')->unique();
            $table->decimal('cpu_peak_percent', 5, 2)->default(0);
            $table->decimal('cpu_avg_percent', 5, 2)->default(0);
            $table->string('cpu_peak_time', 8)->nullable();
            $table->decimal('memory_peak_percent', 5, 2)->default(0);
            $table->decimal('memory_avg_percent', 5, 2)->default(0);
            $table->unsignedBigInteger('memory_peak_used_bytes')->default(0);
            $table->unsignedBigInteger('memory_total_bytes')->default(0);
            $table->unsignedInteger('samples_count')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_metric_histories');
    }
};
