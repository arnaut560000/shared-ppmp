<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ppmp_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_item_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('quarter');
            $table->string('reference', 120);
            $table->date('ordered_on');
            $table->unsignedInteger('quantity');
            $table->boolean('register_equipment');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['plan_item_id', 'quarter', 'reference']);
        });

        Schema::create('ppmp_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ppmp_order_line_id')->constrained()->restrictOnDelete();
            $table->string('reference', 120);
            $table->date('received_on');
            $table->unsignedInteger('quantity');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['ppmp_order_line_id', 'reference']);
        });

        Schema::create('ppmp_receipt_assets', function (Blueprint $table) {
            $table->foreignId('ppmp_receipt_id')->constrained()->restrictOnDelete();
            $table->foreignId('inventory_asset_id')->unique()->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ppmp_receipt_assets');
        Schema::dropIfExists('ppmp_receipts');
        Schema::dropIfExists('ppmp_order_lines');
    }
};
