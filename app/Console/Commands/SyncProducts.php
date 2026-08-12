<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\ERPNextService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('sync:products')]
#[Description('سحب المنتجات من ERPNext وتحديث الكاش المحلي')]
class SyncProducts extends Command
{
    public function handle(ERPNextService $erp): int
    {
        $items = $erp->getAll('Item', fields: [
            'name', 'item_code', 'item_name', 'item_group', 'description',
            'image', 'standard_rate', 'disabled', 'variant_of', 'has_variants',
            'custom_show_in_app', 'custom_is_featured', 'custom_featured_order',
        ]);

        // بنكاش قيم كل خاصية (attribute_name) جوّه تشغيلة sync:products الواحدة —
        // منتجات كتير ممكن تشترك في نفس الخاصية (زي "Wanby Flavors")، فما نعيدش
        // نفس نداء Item Attribute لكل منتج لوحده.
        $attributeValuesCache = [];

        foreach ($items as $item) {
            // attributes (جدول فرعي) ما بيرجعش عبر getAll زي أي child table تاني —
            // بنجيبه بنداء إضافي للـ variants (variant_of موجود) وكمان للـ templates
            // اللي عندها has_variants مفعّل حتى لو مالهاش variants فعلية لسه (بيُستخدم
            // أحيانًا كـ "خصائص" على المنتج نفسه من غير ما يتعمل له variants حقيقية).
            $attributes = [];

            if (! empty($item['variant_of']) || ! empty($item['has_variants'])) {
                $fullDoc = $erp->get('Item', $item['name']);
                $attributes = collect($fullDoc['attributes'] ?? [])
                    ->map(function (array $row) use ($erp, &$attributeValuesCache) {
                        $name = $row['attribute'] ?? null;
                        $value = $row['attribute_value'] ?? null;

                        // مفيش attribute_value على المنتج (Template) نفسه — يعني الخاصية دي
                        // بس بتوصف "الأنواع الممكنة"، مش قيمة محددة، فبنجيب القيم الممكنة
                        // كلها من Item Attribute بدل ما نرجّع null من غير فايدة.
                        if (! $value && $name) {
                            $attributeValuesCache[$name] ??= $erp->getItemAttributeValues($name);
                        }

                        return [
                            'attribute' => $name,
                            'attribute_value' => $value,
                            'values' => $value ? [] : ($attributeValuesCache[$name] ?? []),
                        ];
                    })
                    ->all();
            }

            Product::updateOrCreate(
                ['erp_name' => $item['name']],
                [
                    'item_code' => $item['item_code'],
                    'item_name' => $item['item_name'],
                    'item_group_erp_name' => $item['item_group'] ?: null,
                    'variant_of' => $item['variant_of'] ?: null,
                    'attributes' => $attributes,
                    'description' => $item['description'] ?: null,
                    'image_path' => $item['image'] ?: null,
                    'price' => $item['standard_rate'] ?? 0,
                    'is_active' => ! $item['disabled'],
                    'show_in_app' => (bool) ($item['custom_show_in_app'] ?? true),
                    'is_featured' => (bool) ($item['custom_is_featured'] ?? false),
                    'featured_order' => (int) ($item['custom_featured_order'] ?? 0),
                    'updated_at' => now(),
                ]
            );
        }

        $this->info('تمت مزامنة '.count($items).' منتج.');

        return self::SUCCESS;
    }
}
