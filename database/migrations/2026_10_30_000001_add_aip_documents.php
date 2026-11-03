<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aip_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('fiscal_year');
            $table->string('title');
            $table->timestamps();
            $table->unique(['office_id', 'fiscal_year']);
        });

        if (DB::getDriverName() === 'sqlite') {
            // Native ADD COLUMN preserves existing self-references and PPMP
            // children without rebuilding (and dropping) the plans table.
            DB::statement('ALTER TABLE plans ADD COLUMN aip_document_id INTEGER REFERENCES aip_documents(id) ON DELETE RESTRICT');
        } else {
            Schema::table('plans', function (Blueprint $table) {
                $table->foreignId('aip_document_id')->nullable()->constrained()->restrictOnDelete();
            });
        }

        Schema::table('plans', function (Blueprint $table) {
            $table->index('aip_document_id');
            $table->string('program_name')->nullable();
            $table->string('sector')->nullable();
            $table->string('sub_sector')->nullable();
            $table->string('division')->nullable();
        });

        // Existing AIP rows are individual activities. Preserve them and their
        // PPMP links; only attach an office/year document above each group.
        $groups = DB::table('plans')->where('type', 'aip')
            ->select('office_id', 'fiscal_year')->distinct()->get();

        foreach ($groups as $group) {
            $id = DB::table('aip_documents')->insertGetId([
                'office_id' => $group->office_id,
                'fiscal_year' => $group->fiscal_year,
                'title' => 'Annual Investment Plan '.$group->fiscal_year,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('plans')->where('type', 'aip')
                ->where('office_id', $group->office_id)
                ->where('fiscal_year', $group->fiscal_year)
                ->update(['aip_document_id' => $id]);
        }
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropIndex(['aip_document_id']);
        });
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('ALTER TABLE plans DROP COLUMN aip_document_id');
        } else {
            Schema::table('plans', function (Blueprint $table) {
                $table->dropConstrainedForeignId('aip_document_id');
            });
        }
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['program_name', 'sector', 'sub_sector', 'division']);
        });
        Schema::dropIfExists('aip_documents');
    }
};
