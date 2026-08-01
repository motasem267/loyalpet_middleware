<?php

namespace App\Http\Controllers\Catalog;

use App\Http\Controllers\Controller;
use App\Models\ItemGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    /**
     * يرجع مستوى واحد فقط من الشجرة (أبناء التصنيف المطلوب) — تصفح النزول درجة درجة.
     * بدون parent: يرجع المستوى الأول تحت جذر الشجرة تلقائيًا (بدون افتراض اسم الجذر).
     */
    public function index(Request $request): JsonResponse
    {
        $parent = $request->query('parent');

        if (! $parent) {
            $root = ItemGroup::whereNull('parent_erp_name')->first();
            $parent = $root?->erp_name;
        }

        $children = $parent
            ? ItemGroup::where('parent_erp_name', $parent)
                ->where('is_active', true)
                ->get(['erp_name', 'is_group', 'image_path'])
            : collect();

        return response()->json(['data' => $children]);
    }
}
