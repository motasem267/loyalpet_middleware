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

        foreach ($items as $item) {
            // attributes (جدول فرعي) ما بيرجعش عبر getAll زي أي child table تاني —
            // بنجيبه بنداء إضافي للـ variants (variant_of موجود) وكمان للـ templates
            // اللي عندها has_variants مفعّل حتى لو مالهاش variants فعلية لسه (بيُستخدم
            // أحيانًا كـ "خصائص" على المنتج نفسه من غير ما يتعمل له variants حقيقية).
            $attributes = [];

            if (! empty($item['variant_of']) || ! empty($item['has_variants'])) {
                $fullDoc = $erp->get('Item', $item['name']);
                $attributes = collect($fullDoc['attributes'] ?? [])
                    ->map(fn (array $row) => [
                        'attribute' => $row['attribute'] ?? null,
                        'attribute_value' => $row['attribute_value'] ?? null,
                    ])
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
