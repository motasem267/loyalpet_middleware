<?php

namespace App\Console\Commands;

use App\Models\Bundle;
use App\Models\BundleItem;
use App\Services\ERPNextService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('sync:bundles')]
#[Description('سحب الباقات (Product Bundle) ومحتوياتها من ERPNext وتحديث الكاش المحلي')]
class SyncBundles extends Command
{
    public function handle(ERPNextService $erp): int
    {
        $bundles = $erp->getAll('Product Bundle', fields: [
            'name', 'new_item_code', 'description', 'custom_image',
        ]);

        foreach ($bundles as $bundleData) {
            // اسم الباقة وسعرها بياخدوهم من الصنف (Item) اللي بيمثل الباقة عند البيع
            $item = $erp->get('Item', $bundleData['new_item_code']);

            $bundle = Bundle::updateOrCreate(
                ['erp_name' => $bundleData['name']],
                [
                    'item_code' => $bundleData['new_item_code'],
                    'title' => $item['item_name'] ?? $bundleData['new_item_code'],
                    'description' => $bundleData['description'] ?: null,
                    'price' => $item['standard_rate'] ?? 0,
                    'image_path' => $bundleData['custom_image'] ?: null,
                    'is_active' => ! ($item['disabled'] ?? false),
                    'updated_at' => now(),
                ]
            );

            // محتويات الباقة (جدول "items" الفرعي) مش بيترجع في getAll، لازم نجيب الدوك كامل
            $fullDoc = $erp->get('Product Bundle', $bundleData['name']);

            $bundle->items()->delete();
            $bundle->items()->createMany(
                collect($fullDoc['items'] ?? [])->map(fn (array $row) => [
                    'item_code' => $row['item_code'],
                    'qty' => $row['qty'] ?? 1,
                ])->all()
            );
        }

        $this->info('تمت مزامنة '.count($bundles).' باقة.');

        return self::SUCCESS;
    }
}
