<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('school_groups', function (Blueprint $table) {
            if (! Schema::hasColumn('school_groups', 'unit_type')) {
                $table->string('unit_type', 30)->default('school')->index()->after('school_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('school_groups', function (Blueprint $table) {
            if (Schema::hasColumn('school_groups', 'unit_type')) {
                $table->dropColumn('unit_type');
            }
        });
    }
};
