<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_groups', function (Blueprint $table) {
            $table->id();
            $table->uuid('school_uid')->unique();
            $table->string('school_name', 180)->nullable();
            $table->string('school_inep', 20)->nullable()->index();
            $table->boolean('active')->default(true)->index();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_groups');
    }
};
