<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plan_items', function (Blueprint $table) {
            $table->json('quarterly_quantities')->nullable();
            $table->string('object_of_expenditure')->nullable();
            $table->json('monthly_quantities')->nullable()->change();
        });

        DB::table('plan_items')->orderBy('id')->chunkById(200, function ($items) {
            foreach ($items as $item) {
                $months = json_decode($item->monthly_quantities, true, 512, JSON_THROW_ON_ERROR);
                $quarters = array_map('array_sum', array_chunk($months, 3));
                DB::table('plan_items')->where('id', $item->id)->update([
                    'quarterly_quantities' => json_encode($quarters, JSON_THROW_ON_ERROR),
                ]);
            }
        });
        // Older records have no category snapshot. Keep it unknown rather than
        // assigning today's catalogue classification to an approved document.
    }

    public function down(): void
    {
        if (DB::table('plan_items')->whereNull('monthly_quantities')->exists()) {
            throw new RuntimeException('Quarterly-only records exist. Restore a pre-update backup instead of inventing monthly quantities.');
        }
        Schema::table('plan_items', function (Blueprint $table) {
            $table->dropColumn(['quarterly_quantities', 'object_of_expenditure']);
            $table->json('monthly_quantities')->nullable(false)->change();
        });
    }
};
