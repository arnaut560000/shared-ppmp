<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offices', function (Blueprint $t) {
            $t->id();
            $t->string('code')->unique();
            $t->string('name');
            $t->timestamps();
        });
        Schema::table('users', function (Blueprint $t) {
            $t->foreignId('office_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('role')->default('office');
        });
        Schema::create('budgets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('office_id')->constrained()->restrictOnDelete();
            $t->unsignedSmallInteger('fiscal_year');
            $t->unsignedBigInteger('ceiling_cents');
            $t->timestamps();
            $t->unique(['office_id', 'fiscal_year']);
        });
        Schema::create('catalogue_items', function (Blueprint $t) {
            $t->id();
            $t->string('code')->unique();
            $t->string('name');
            $t->text('specification')->nullable();
            $t->string('unit', 40);
            $t->string('category');
            $t->string('expense_class', 10);
            $t->unsignedBigInteger('price_cents');
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('annual_plans', function (Blueprint $t) {
            $t->id();
            $t->unsignedSmallInteger('fiscal_year');
            $t->string('title');
            $t->string('status')->default('submitted');
            $t->foreignId('created_by')->constrained('users');
            $t->timestamps();
        });
        Schema::create('plans', function (Blueprint $t) {
            $t->id();
            $t->string('type', 10);
            $t->foreignId('office_id')->constrained()->restrictOnDelete();
            $t->unsignedSmallInteger('fiscal_year');
            $t->string('reference');
            $t->string('title');
            $t->text('description')->nullable();
            $t->string('fund_source');
            $t->text('expected_output')->nullable();
            $t->date('start_date');
            $t->date('end_date');
            $t->string('status')->default('draft');
            $t->unsignedInteger('version')->default(1);
            $t->foreignId('parent_id')->nullable()->constrained('plans')->restrictOnDelete();
            $t->foreignId('annual_plan_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users');
            foreach (['ps', 'mooe', 'fe', 'co', 'total'] as $key) {
                $t->unsignedBigInteger($key.'_cents')->default(0);
            }
            $t->timestamp('submitted_at')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->timestamps();
            $t->unique(['office_id', 'fiscal_year', 'type', 'reference']);
            $t->index(['fiscal_year', 'type', 'status']);
        });
        Schema::create('plan_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $t->foreignId('catalogue_item_id')->constrained()->restrictOnDelete();
            $t->string('code');
            $t->string('name');
            $t->text('specification')->nullable();
            $t->string('unit');
            $t->string('expense_class');
            $t->unsignedBigInteger('price_cents');
            $t->json('monthly_quantities');
            $t->unsignedInteger('quantity');
            $t->unsignedBigInteger('total_cents');
            $t->timestamps();
            $t->unique(['plan_id', 'catalogue_item_id']);
        });
        Schema::create('audit_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('office_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('subject_type');
            $t->unsignedBigInteger('subject_id');
            $t->string('action');
            $t->text('comment')->nullable();
            $t->json('metadata')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        foreach (['audit_events', 'plan_items', 'plans', 'annual_plans', 'catalogue_items', 'budgets'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', function (Blueprint $t) {
            $t->dropConstrainedForeignId('office_id');
            $t->dropColumn('role');
        });
        Schema::dropIfExists('offices');
    }
};
