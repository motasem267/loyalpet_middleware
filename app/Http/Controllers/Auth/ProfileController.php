<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Jobs\SyncToERPNext;
use App\Models\PendingSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class ProfileController extends Controller
{
    /**
     * تعديل الاسم والعنوان بس — رقم الهاتف مش موجود في قواعد التحقق أصلًا،
     * فمستحيل يتغيّر من هنا حتى لو المستخدم بعته في الطلب.
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'address' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $user = $request->user();
        $nameChanged = array_key_exists('name', $validated) && $validated['name'] !== $user->name;

        $user->fill($validated);
        $user->save();

        if ($nameChanged && $user->erp_customer_id) {
            $sync = PendingSync::create([
                'user_id' => $user->id,
                'action' => 'update',
                'doctype' => 'Customer',
                'erp_name' => $user->erp_customer_id,
                'payload' => ['customer_name' => $user->name],
                'status' => 'pending',
                'priority' => 3,
            ]);

            SyncToERPNext::dispatch($sync);
        }

        return response()->json(['data' => $user]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $user = $request->user();

        if (! Hash::check($validated['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['كلمة المرور الحالية غير صحيحة'],
            ]);
        }

        $user->password = $validated['password'];
        $user->save();

        return response()->json(['message' => 'تم تغيير كلمة المرور بنجاح']);
    }
}
