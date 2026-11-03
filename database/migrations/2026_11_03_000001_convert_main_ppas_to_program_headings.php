<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            // Do not turn an existing procurement parent into a heading.
            $hasLinkedProcurement = DB::table('plans as procurement')
                ->join('plans as parent', 'parent.id', '=', 'procurement.parent_id')
                ->where('procurement.type', 'ppmp')
                ->where('parent.type', 'aip')
                ->whereNull('parent.parent_ppa_id')
                ->exists();

            if ($hasLinkedProcurement) {
                throw new RuntimeException(
                    'An existing PPMP is linked to a main PPA. '
                    .'Its activity relationship must be reviewed before converting headings.'
                );
            }

            // Existing top-level entries become headings.
            // Their original fields and amounts remain stored for reference.
            // Existing child entries remain actual PPAs.
            DB::table('plans')
                ->where('type', 'aip')
                ->whereNull('parent_ppa_id')
                ->update(['type' => 'program']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException(
            'This data conversion cannot be reversed automatically. '
            .'Use the pre-migration database backup if you need to undo it.'
        );
    }
};
