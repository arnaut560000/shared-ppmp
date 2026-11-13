<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aip_documents', function (Blueprint $table) {
            $table->string('review_status')->default('draft');
            $table->string('planning_review')->default('pending');
            $table->string('budget_review')->default('pending');
            $table->json('submitted_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('aip_documents', function (Blueprint $table) {
            $table->dropColumn(['review_status', 'planning_review', 'budget_review', 'submitted_snapshot']);
        });
    }
};
