<?php

namespace App\Services;

use App\Models\InventoryAsset;
use App\Models\User;

class InventoryRegistration
{
    /**
     * Call inside the transaction that records the asset or accepts its delivery.
     */
    public static function register(array $data, User $user): InventoryAsset
    {
        $asset = InventoryAsset::create([...$data, 'received_for_office_id' => $data['office_id']]);
        $asset->movements()->create([
            'action' => 'registered',
            'to_office_id' => $asset->office_id,
            'actor_id' => $user->id,
            'actor_name' => $user->name,
        ]);

        return $asset;
    }
}
