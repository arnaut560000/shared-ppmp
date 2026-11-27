<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('annual_procurement_plans', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('fiscal_year')->unique();
            $table->string('status', 24)->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->text('review_note')->nullable();
            $table->string('source_hash', 64);
            $table->timestamps();
        });

        Schema::create('app_source_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_id')->constrained('annual_procurement_plans')->cascadeOnDelete();
            $table->foreignId('office_id')->constrained()->restrictOnDelete();
            $table->foreignId('plan_item_id')->constrained()->restrictOnDelete();
            $table->string('ppmp_reference');
            $table->string('code');
            $table->string('name');
            $table->text('specification')->nullable();
            $table->string('unit');
            $table->string('expense_class', 10);
            $table->unsignedTinyInteger('quarter');
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('price_cents');
            $table->unique(['app_id', 'plan_item_id', 'quarter']);
            $table->index(['app_id', 'office_id']);
        });

        Schema::create('purchase_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_id')->constrained('annual_procurement_plans')->restrictOnDelete();
            $table->foreignId('office_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 24)->default('awaiting_treasurer');
            $table->unsignedInteger('version')->default(1);
            $table->text('purpose');
            $table->text('review_note')->nullable();
            $table->string('pr_number', 80)->nullable();
            $table->timestamp('numbered_at')->nullable();
            $table->timestamps();
            $table->unique(['app_id', 'pr_number']);
            $table->index(['app_id', 'office_id', 'status']);
        });

        Schema::create('purchase_request_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('app_source_line_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->unique(['purchase_request_id', 'app_source_line_id'], 'pr_line_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_request_lines');
        Schema::dropIfExists('purchase_requests');
        Schema::dropIfExists('app_source_lines');
        Schema::dropIfExists('annual_procurement_plans');
    }
};
