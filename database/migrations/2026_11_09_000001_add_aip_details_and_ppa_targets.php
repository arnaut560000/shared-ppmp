<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aip_documents', function (Blueprint $table) {
            $table->text('description')->nullable();
            $table->unsignedInteger('revision')->default(1);
        });
        Schema::table('plans', function (Blueprint $table) {
            $table->text('performance_indicator')->nullable();
            $table->text('physical_target')->nullable();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Restore the pre-update backup to remove saved AIP details and PPA targets.');
    }
};
