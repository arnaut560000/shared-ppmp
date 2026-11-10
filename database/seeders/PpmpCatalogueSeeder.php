<?php

namespace Database\Seeders;

use App\Models\CatalogueItem;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PpmpCatalogueSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('data/ppmp-catalogue.json');
        if (! is_file($path)) {
            throw new RuntimeException("Cannot read the PPMP catalogue at {$path}");
        }

        $items = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $added = DB::transaction(function () use ($items) {
            $count = 0;
            foreach ($items as $item) {
                $record = CatalogueItem::firstOrCreate(
                    ['code' => $item['code']],
                    $item + ['active' => true]
                );
                if ($record->wasRecentlyCreated) {
                    $count++;
                }
            }

            return $count;
        });

        $this->command?->info(sprintf(
            '%d added, %d codes already present.',
            $added, count($items) - $added
        ));
    }
}
