<?php

namespace App\Console\Commands;

use App\Models\ItemGroup;
use App\Services\ERPNextService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('sync:item-groups')]
#[Description('سحب شجرة Item Group كاملة من ERPNext وتحديث الكاش المحلي')]
class SyncItemGroups extends Command
{
    public function handle(ERPNextService $erp): int
    {
        $groups = $erp->getAll('Item Group', fields: ['name', 'parent_item_group', 'is_group', 'image']);

        foreach ($groups as $group) {
            ItemGroup::updateOrCreate(
                ['erp_name' => $group['name']],
                [
                    'parent_erp_name' => $group['parent_item_group'] ?: null,
                    'is_group' => (bool) $group['is_group'],
                    'image_path' => $group['image'] ?: null,
                    'is_active' => true,
                    'updated_at' => now(),
                ]
            );
        }

        $this->info('تمت مزامنة '.count($groups).' تصنيف.');

        return self::SUCCESS;
    }
}
