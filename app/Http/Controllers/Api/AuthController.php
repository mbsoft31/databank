<?php
// app/Http/Controllers/Api/AuthController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\{LoginRequest, RegisterRequest, UpdateProfileRequest, ChangePasswordRequest};
use App\Models\{User, AuditLog};
use Illuminate\Http\{Request, JsonResponse};
use Illuminate\Support\Facades\{Auth, Hash, RateLimiter};
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum')->except(['login', 'register', 'forgotPassword', 'resetPassword']);
        $this->middleware('throttle:auth')->only(['login', 'register']);
    }

    /**
     * User login
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $key = 'login.' . $request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);
            throw ValidationException::withMessages([
                'email' => "محاولات تسجيل دخول كثيرة. حاول مرة أخرى خلال {$seconds} ثانية."
            ]);
        }

        if (!Auth::attempt($request->only('email', 'password'))) {
            RateLimiter::hit($key, 60); // Lock for 1 minute after failed attempt

            throw ValidationException::withMessages([
                'email' => 'بيانات الاعتماد غير صحيحة.'
            ]);
        }

        RateLimiter::clear($key);

        $user = Auth::user();
        $user->updateLastActiveAt();

        // Create token with abilities based on user role
        $abilities = $this->getTokenAbilities($user);
        $token = $user->createToken('auth-token', $abilities);

        // Log successful login
        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'login',
            'auditable_type' => User::class,
            'auditable_id' => $user->id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'user' => $user->load(['createdItems:id,stem_ar', 'reviews:id,status']),
            'token' => $token->plainTextToken,
            'abilities' => $abilities,
            'expires_at' => now()->addDays(config('sanctum.expiration', 30))->toISOString(),
        ]);
    }

    /**
     * User registration
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => $request->role ?? 'viewer',
            'locale' => $request->get('locale') ?? 'ar',
            'preferences' => [
                'theme' => 'light',
                'language' => $request->get('locale') ?? 'ar',
                'notifications' => ['email' => true, 'browser' => true],
            ],
            'last_active_at' => now(),
        ]);

        $abilities = $this->getTokenAbilities($user);
        $token = $user->createToken('auth-token', $abilities);

        // Log registration
        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'register',
            'auditable_type' => User::class,
            'auditable_id' => $user->id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'user' => $user,
            'token' => $token->plainTextToken,
            'abilities' => $abilities,
            'message' => 'تم إنشاء الحساب بنجاح',
        ], 201);
    }

    /**
     * Get current user profile
     */
    public function me(): JsonResponse
    {
        $user = Auth::user()->load([
            'createdItems:id,stem_ar,status,created_at',
            'reviews:id,status,overall_score,created_at',
            'exports:id,kind,status,created_at',
        ]);

        $user->activity_summary = $user->getActivitySummary();
        $user->productivity_stats = $user->getProductivityStats();

        return response()->json(['user' => $user]);
    }

    /**
     * Update user profile
     */
    public function updateProfile(UpdateProfileRequest $request): JsonResponse
    {
        $user = Auth::user();
        $oldData = $user->only(['name', 'email', 'preferences']);

        $user->update($request->validated());

        // Log profile update
        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'updated',
            'auditable_type' => User::class,
            'auditable_id' => $user->id,
            'old_values' => $oldData,
            'new_values' => $user->only(['name', 'email', 'preferences']),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'user' => $user->fresh(),
            'message' => 'تم تحديث الملف الشخصي بنجاح',
        ]);
    }

    /**
     * Change user password
     */
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $user = Auth::user();

        if (!Hash::check($request->current_password, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'كلمة المرور الحالية غير صحيحة.'
            ]);
        }

        $user->update([
            'password' => Hash::make($request->new_password)
        ]);

        // Revoke all existing tokens for security
        $user->tokens()->delete();

        // Log password change
        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'password_changed',
            'auditable_type' => User::class,
            'auditable_id' => $user->id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'message' => 'تم تغيير كلمة المرور بنجاح. يرجى إعادة تسجيل الدخول.',
        ]);
    }

    /**
     * Logout user
     */
    public function logout(Request $request): JsonResponse
    {
        $user = Auth::user();

        // Log logout
        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'logout',
            'auditable_type' => User::class,
            'auditable_id' => $user->id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        // Delete current token
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'تم تسجيل الخروج بنجاح',
        ]);
    }

    /**
     * Logout from all devices
     */
    public function logoutAll(Request $request): JsonResponse
    {
        $user = Auth::user();

        // Delete all tokens
        $user->tokens()->delete();

        // Log logout from all devices
        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'logout_all',
            'auditable_type' => User::class,
            'auditable_id' => $user->id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'message' => 'تم تسجيل الخروج من جميع الأجهزة',
        ]);
    }

    /**
     * Get user's active sessions
     */
    public function sessions(): JsonResponse
    {
        $user = Auth::user();
        $tokens = $user->tokens()->orderBy('created_at', 'desc')->get();

        $sessions = $tokens->map(function ($token) {
            return [
                'id' => $token->id,
                'name' => $token->name,
                'abilities' => $token->abilities,
                'last_used_at' => $token->last_used_at?->toISOString(),
                'created_at' => $token->created_at->toISOString(),
                'is_current' => $token->id === Auth::user()->currentAccessToken()->id,
            ];
        });

        return response()->json(['sessions' => $sessions]);
    }

    /**
     * Revoke specific session
     */
    public function revokeSession(Request $request): JsonResponse
    {
        $request->validate(['token_id' => 'required|integer']);

        $user = Auth::user();
        $token = $user->tokens()->find($request->token_id);

        if (!$token) {
            return response()->json(['error' => 'الجلسة غير موجودة'], 404);
        }

        $token->delete();

        return response()->json(['message' => 'تم إلغاء الجلسة بنجاح']);
    }

    /**
     * Update user preferences
     */
    public function updatePreferences(Request $request): JsonResponse
    {
        $request->validate([
            'preferences' => 'required|array',
            'preferences.theme' => 'nullable|string|in:light,dark,auto',
            'preferences.language' => 'nullable|string|in:ar,en',
            'preferences.notifications' => 'nullable|array',
            'preferences.ui' => 'nullable|array',
        ]);

        $user = Auth::user();
        $oldPreferences = $user->preferences;

        $user->update([
            'preferences' => array_merge($user->preferences ?? [], $request->preferences)
        ]);

        // Log preference update
        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'preferences_updated',
            'auditable_type' => User::class,
            'auditable_id' => $user->id,
            'old_values' => ['preferences' => $oldPreferences],
            'new_values' => ['preferences' => $user->preferences],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'preferences' => $user->preferences,
            'message' => 'تم حفظ التفضيلات بنجاح',
        ]);
    }

    /**
     * Get user statistics
     */
    public function statistics(): JsonResponse
    {
        $user = Auth::user();

        return response()->json([
            'activity_summary' => $user->getActivitySummary(),
            'productivity_stats' => $user->getProductivityStats(),
        ]);
    }

    private function getTokenAbilities(User $user): array
    {
        return match($user->role) {
            'admin' => ['*'], // All abilities
            'author' => [
                'items:create', 'items:update', 'items:view',
                'media:create', 'media:view',
                'exports:create', 'exports:view',
                'profile:update',
            ],
            'reviewer' => [
                'items:view', 'reviews:create', 'reviews:update',
                'exports:create', 'exports:view',
                'profile:update',
            ],
            'editor' => [
                'items:create', 'items:update', 'items:view', 'items:publish',
                'reviews:create', 'reviews:update', 'reviews:view',
                'media:create', 'media:view',
                'exports:create', 'exports:view',
                'analytics:view',
                'profile:update',
            ],
            default => ['items:view', 'exports:view', 'profile:update'], // viewer
        };
    }
}
