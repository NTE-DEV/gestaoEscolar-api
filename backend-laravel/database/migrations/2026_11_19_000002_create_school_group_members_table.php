<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_group_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_group_id')
                ->constrained('school_groups')
                ->cascadeOnDelete();
            $table->uuid('school_uid')->index();
            $table->unsignedBigInteger('portal_user_id')->nullable()->index();
            $table->uuid('portal_user_uid')->nullable()->index();
            $table->foreignId('local_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('name', 180)->nullable();
            $table->string('email', 180)->nullable();
            $table->string('cpf', 11)->nullable();
            $table->string('role', 80)->default('Membro Escolar');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['school_uid', 'email', 'active'], 'idx_sgm_school_email_active');
            $table->index(['school_uid', 'cpf', 'active'], 'idx_sgm_school_cpf_active');
            $table->unique(['school_group_id', 'email'], 'uq_sgm_group_email');
            $table->unique(['school_group_id', 'cpf'], 'uq_sgm_group_cpf');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_group_members');
    }
};
