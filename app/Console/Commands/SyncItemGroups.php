<?php

namespace App\Console\Commands;

use App\Models\ItemGroup;
use App\Services\ERPNextService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('sync:item-groups {--prune : يمسح التصنيفات اللي ما عادش موجودة في ERPNext بعد المزامنة}')]
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

        if ($this->option('prune')) {
            if (! $groups) {
                $this->error('ERPNext رجّع صفر تصنيفات — ما مسحناش حاجة احتياطًا.');

                return self::FAILURE;
            }

            $deleted = ItemGroup::whereNotIn('erp_name', array_column($groups, 'name'))->delete();
            $this->info("تم مسح {$deleted} تصنيف مش موجود في ERPNext.");
        }

        return self::SUCCESS;
    }
}
