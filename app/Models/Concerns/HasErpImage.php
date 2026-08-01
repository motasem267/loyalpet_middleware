<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Casts\Attribute;

/**
 * أي موديل عنده عمود image_path (مسار نسبي راجع من ERPNext) يستخدم التريت ده
 * عشان يبني رابط صورة كامل جاهز للموبايل، من غير ما يعرف حاجة عن رابط ERPNext.
 */
trait HasErpImage
{
    protected function imageUrl(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->image_path
                ? rtrim((string) config('erpnext.url'), '/').$this->image_path
                : null,
        );
    }
}
