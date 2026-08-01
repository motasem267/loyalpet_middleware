<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Jobs\SyncCustomerToERPNext;
use App\Models\User;
use App\Services\SmsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * الحساب بينخلق فورًا لكن غير مفعّل (is_active=false) لحد ما يتحقق من رمز الـ OTP
     * عبر verifyRegistration() — مزامنة ERPNext وإصدار التوكن بتتأجل لحد التحقق.
     */
    public function register(Request $request, SmsService $sms): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:20', 'unique:users,phone'],
            'password' => ['required', 'confirmed', Password::min(8)],
            'address' => ['nullable', 'string', 'max:255'],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'phone' => $validated['phone'],
            'password' => $validated['password'],
            'address' => $validated['address'],
            'is_active' => false,
        ]);

        $code = (string) random_int(100000, 999999);

        DB::table('registration_otps')->updateOrInsert(
            ['phone' => $validated['phone']],
            ['token' => Hash::make($code), 'created_at' => now()]
        );

        $sms->sendOtp($validated['phone'], $code);

        return response()->json([
            'message' => 'تم إنشاء الحساب، تحقق من الرمز المرسل إلى هاتفك',
        ], 201);
    }

    public function verifyRegistration(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'string'],
            'code' => ['required', 'string'],
        ]);

        $record = DB::table('registration_otps')->where('phone', $validated['phone'])->first();

        if (! $record || ! Hash::check($validated['code'], $record->token) || now()->diffInMinutes($record->created_at) > 10) {
            throw ValidationException::withMessages([
                'code' => ['رمز التحقق غير صحيح أو منتهي الصلاحية'],
            ]);
        }

        $user = User::where('phone', $validated['phone'])->firstOrFail();
        $user->update(['is_active' => true]);

        DB::table('registration_otps')->where('phone', $validated['phone'])->delete();

        SyncCustomerToERPNext::dispatch($user);

        // مفيش توكن هنا قصدًا — تسجيل الدخول (login) هو المصدر الوحيد لإصدار التوكن،
        // وهو أصلًا اللي بيتأكد إن المزامنة مع ERPNext خلصت قبل ما يسمح بالدخول.
        return response()->json([
            'message' => 'تم التحقق من رقم هاتفك بنجاح، يمكنك تسجيل الدخول الآن',
        ]);
    }

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('phone', $validated['phone'])->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'phone' => ['بيانات الدخول غير صحيحة'],
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'phone' => ['الحساب غير مفعّل'],
            ]);
        }

        if ($user->erp_sync_status !== 'synced' || ! $user->erp_customer_id) {
            throw ValidationException::withMessages([
                'phone' => ['حسابك لسه في طور المزامنة، حاول مرة ثانية بعد شوي'],
            ]);
        }

        $user->tokens()->delete();

        $token = $user->createToken('mobile')->plainTextToken;

        return response()->json([
            'user' => $user,
            'token' => $token,
        ]);
    }

    public function forgotPassword(Request $request, SmsService $sms): JsonResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'string'],
        ]);

        $user = User::where('phone', $validated['phone'])->first();

        if ($user) {
            $existing = DB::table('password_reset_tokens')->where('phone', $validated['phone'])->first();

            if ($existing && now()->diffInSeconds($existing->created_at) < 60) {
                throw ValidationException::withMessages([
                    'phone' => ['يرجى الانتظار قليلًا قبل طلب رمز جديد'],
                ]);
            }

            $code = (string) random_int(100000, 999999);

            DB::table('password_reset_tokens')->updateOrInsert(
                ['phone' => $validated['phone']],
                ['token' => Hash::make($code), 'created_at' => now()]
            );

            $sms->sendOtp($validated['phone'], $code);
        }

        // ما نكشفش هل الرقم مسجل عندنا أصلًا ولا لأ (حماية من enumeration)
        return response()->json([
            'message' => 'لو رقم الهاتف مسجل لدينا، ستصلك رسالة تحتوي على رمز التحقق',
        ]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'string'],
            'code' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $record = DB::table('password_reset_tokens')->where('phone', $validated['phone'])->first();

        if (! $record || ! Hash::check($validated['code'], $record->token) || now()->diffInMinutes($record->created_at) > 10) {
            throw ValidationException::withMessages([
                'code' => ['رمز التحقق غير صحيح أو منتهي الصلاحية'],
            ]);
        }

        $user = User::where('phone', $validated['phone'])->firstOrFail();
        $user->password = $validated['password'];
        $user->save();

        DB::table('password_reset_tokens')->where('phone', $validated['phone'])->delete();

        return response()->json(['message' => 'تم تغيير كلمة المرور بنجاح']);
    }
}
