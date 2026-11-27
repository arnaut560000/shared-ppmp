<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_assets', function (Blueprint $table) {
            $table->id();
            $table->string('property_tag', 80)->unique();
            $table->string('name', 150);
            $table->string('serial_number', 120)->nullable();
            $table->date('received_on');
            $table->string('receipt_reference', 120)->nullable();
            $table->foreignId('received_for_office_id')->constrained('offices')->restrictOnDelete();
            $table->foreignId('office_id')->constrained()->restrictOnDelete();
            $table->foreignId('holder_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('holder_name')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['office_id', 'received_on']);
            $table->index(['received_for_office_id', 'received_on']);
        });

        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_asset_id')->constrained()->restrictOnDelete();
            $table->string('action', 20);
            $table->foreignId('from_office_id')->nullable()->constrained('offices')->restrictOnDelete();
            $table->foreignId('to_office_id')->constrained('offices')->restrictOnDelete();
            $table->foreignId('from_holder_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('to_holder_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('from_holder_name')->nullable();
            $table->string('to_holder_name')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name');
            $table->text('remarks')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
        Schema::dropIfExists('inventory_assets');
    }
};
