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
     * بدون parent: يرجع أبناء "All Item Groups" — جذر شجرة Item Group الثابت في Frappe
     * (مش قيمة ديناميكية بنكتشفها، اسم معروف وثابت).
     */
    public function index(Request $request): JsonResponse
    {
        $parent = $request->query('parent') ?: 'All Item Groups';

        $children = ItemGroup::where('parent_erp_name', $parent)
            ->where('is_active', true)
            ->get(['erp_name', 'is_group', 'image_path']);

        return response()->json(['data' => $children]);
    }
}
