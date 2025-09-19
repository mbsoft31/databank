<?php
// routes/api.php
use App\Http\Controllers\Api\{
    AuthController,
    ItemDraftController,
    ItemProdController,
    ConceptController,
    TagController,
    MediaController,
    SearchController,
    ReviewController,
    ExportController,
    AnalyticsController
};
use Illuminate\Support\Facades\Route;

// Authentication routes
Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
    Route::post('register', [AuthController::class, 'register']);
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('user', [AuthController::class, 'user']);
        Route::put('profile', [AuthController::class, 'updateProfile']);
    });
});

// Protected API routes
Route::middleware(['auth:sanctum'])->group(function () {

    // Item Drafts
    Route::apiResource('item-drafts', ItemDraftController::class);
    Route::prefix('item-drafts/{itemDraft}')->group(function () {
        Route::post('submit-review', [ItemDraftController::class, 'submitForReview']);
        Route::post('publish', [ItemDraftController::class, 'publish']);
        Route::get('duplicates', [ItemDraftController::class, 'findDuplicates']);
    });

    // Published Items
    Route::apiResource('items', ItemProdController::class)->only(['index', 'show']);

    // Concepts & Tags
    Route::apiResource('concepts', ConceptController::class);
    Route::apiResource('tags', TagController::class);

    // Media Management
    Route::prefix('media')->group(function () {
        Route::get('/', [MediaController::class, 'index']);
        Route::post('presigned-url', [MediaController::class, 'getPresignedUrl']);
        Route::post('confirm-upload', [MediaController::class, 'confirmUpload']);
        Route::delete('{mediaAsset}', [MediaController::class, 'destroy']);
    });

    // Search
    Route::prefix('search')->group(function () {
        Route::get('drafts', [SearchController::class, 'drafts']);
        Route::get('items', [SearchController::class, 'items']);
        Route::get('concepts', [SearchController::class, 'concepts']);
    });

    // Reviews
    Route::prefix('reviews')->group(function () {
        Route::get('/', [ReviewController::class, 'index']);
        Route::post('/', [ReviewController::class, 'store']);
        Route::get('{review}', [ReviewController::class, 'show']);
        Route::put('{review}', [ReviewController::class, 'update']);
    });

    // Exports
    Route::prefix('exports')->group(function () {
        Route::get('/', [ExportController::class, 'index']);
        Route::post('/', [ExportController::class, 'store']);
        Route::get('{export}', [ExportController::class, 'show']);
        Route::get('{export}/download', [ExportController::class, 'download']);
    });

    // Analytics (Admin/Reviewer only)
    Route::middleware(['can:admin-access'])->prefix('analytics')->group(function () {
        Route::get('overview', [AnalyticsController::class, 'overview']);
        Route::get('authoring-trends', [AnalyticsController::class, 'authoringTrends']);
        Route::get('review-metrics', [AnalyticsController::class, 'reviewMetrics']);
        Route::get('content-stats', [AnalyticsController::class, 'contentStats']);
    });
});

// Rate limiting groups are defined in RouteServiceProvider
