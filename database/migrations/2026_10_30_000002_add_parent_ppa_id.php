<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement(
                'ALTER TABLE plans ADD COLUMN parent_ppa_id INTEGER
                 REFERENCES plans(id) ON DELETE RESTRICT'
            );
        } else {
            Schema::table('plans', function (Blueprint $table) {
                $table->foreignId('parent_ppa_id')
                    ->nullable()
                    ->constrained('plans')
                    ->restrictOnDelete();
            });
        }

        Schema::table('plans', function (Blueprint $table) {
            $table->index('parent_ppa_id');
        });
    }

    public function down(): void
    {
        if (DB::table('plans')->whereNotNull('parent_ppa_id')->exists()) {
            throw new RuntimeException(
                'Sub-PPAs exist. Restore a backup instead of removing their relationships.'
            );
        }

        Schema::table('plans', function (Blueprint $table) {
            $table->dropIndex(['parent_ppa_id']);
        });

        if (DB::getDriverName() === 'sqlite') {
            DB::statement('ALTER TABLE plans DROP COLUMN parent_ppa_id');
        } else {
            Schema::table('plans', function (Blueprint $table) {
                $table->dropConstrainedForeignId('parent_ppa_id');
            });
        }
    }
};