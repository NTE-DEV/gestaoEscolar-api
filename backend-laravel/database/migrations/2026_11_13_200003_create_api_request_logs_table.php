<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_request_logs', function (Blueprint $table) {
            $table->id();
            $table->string('service_name', 60)->default('gestao-escolar-api')->index();
            $table->string('method', 10);
            $table->string('route', 255)->index();
            $table->unsignedSmallInteger('status_code')->index();
            $table->unsignedInteger('response_time_ms')->default(0);
            $table->string('ip_address', 45)->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_request_logs');
    }
};
