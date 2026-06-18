<?php

use App\Http\Controllers\Api\Admin\PanicController as AdminPanicController;
use App\Http\Controllers\Api\Admin\UserController as AdminUserController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CampaignController;
use App\Http\Controllers\Api\CampaignItemController;
use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\InstanceController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\ToolsController;
use App\Http\Controllers\Api\WhatsappJobController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| REST API + Tool Call Chain for AI agents.
| Authenticate with: POST /api/v1/auth/login → Bearer token
|
*/

Route::post('/webhook/whatsapp', [WebhookController::class, 'receive']);

Route::prefix('v1')->group(function () {
    Route::post('/auth/login', [AuthController::class, 'login']);

    Route::middleware(['auth:sanctum', 'api.active'])->group(function () {
        // Auth
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/tokens', [AuthController::class, 'tokens']);
        Route::post('/auth/tokens', [AuthController::class, 'createToken']);
        Route::delete('/auth/tokens/{tokenId}', [AuthController::class, 'revokeToken']);

        // Dashboard & Profile
        Route::get('/dashboard', [DashboardController::class, 'index']);
        Route::get('/profile', [ProfileController::class, 'show']);
        Route::put('/profile', [ProfileController::class, 'update']);

        // Contacts
        Route::get('/contacts', [ContactController::class, 'index']);
        Route::post('/contacts', [ContactController::class, 'store']);
        Route::post('/contacts/import', [ContactController::class, 'import']);
        Route::delete('/contacts', [ContactController::class, 'clean']);
        Route::post('/contacts/bulk-delete', [ContactController::class, 'bulkDelete']);
        Route::post('/contacts/bulk-status', [ContactController::class, 'bulkStatus']);
        Route::get('/contacts/{contact}', [ContactController::class, 'show']);
        Route::put('/contacts/{contact}', [ContactController::class, 'update']);
        Route::delete('/contacts/{contact}', [ContactController::class, 'destroy']);
        Route::post('/contacts/{contact}/sync-photo', [ContactController::class, 'syncPhoto']);

        // Campaigns
        Route::get('/campaigns', [CampaignController::class, 'index']);
        Route::post('/campaigns', [CampaignController::class, 'store']);
        Route::get('/campaigns/{campaign}', [CampaignController::class, 'show']);
        Route::put('/campaigns/{campaign}', [CampaignController::class, 'update']);
        Route::delete('/campaigns/{campaign}', [CampaignController::class, 'destroy']);
        Route::get('/campaigns/{campaign}/report', [CampaignController::class, 'report']);

        // Campaign Items
        Route::get('/campaign-items', [CampaignItemController::class, 'index']);
        Route::post('/campaign-items', [CampaignItemController::class, 'store']);
        Route::get('/campaign-items/{campaignItem}', [CampaignItemController::class, 'show']);
        Route::put('/campaign-items/{campaignItem}', [CampaignItemController::class, 'update']);
        Route::delete('/campaign-items/{campaignItem}', [CampaignItemController::class, 'destroy']);
        Route::post('/campaign-items/{campaignItem}/generate-test', [CampaignItemController::class, 'generateTest']);
        Route::post('/campaign-items/{campaignItem}/generate-all', [CampaignItemController::class, 'generateAll']);
        Route::get('/campaign-items/{campaignItemId}/jobs', [WhatsappJobController::class, 'index']);

        // WhatsApp Jobs
        Route::post('/whatsapp-jobs/{id}/retry', [WhatsappJobController::class, 'retry']);
        Route::post('/whatsapp-jobs/bulk-retry', [WhatsappJobController::class, 'bulkRetry']);
        Route::post('/whatsapp-jobs/bulk-delete', [WhatsappJobController::class, 'bulkDelete']);

        // Instances
        Route::get('/instances', [InstanceController::class, 'index']);
        Route::post('/instances', [InstanceController::class, 'store']);
        Route::get('/instances/{instance}', [InstanceController::class, 'show']);
        Route::delete('/instances/{instance}', [InstanceController::class, 'destroy']);
        Route::get('/instances/{instance}/qr', [InstanceController::class, 'qr']);
        Route::get('/instances/{instance}/connection', [InstanceController::class, 'connection']);
        Route::post('/instances/{instance}/warmup', [InstanceController::class, 'toggleWarmup']);
        Route::post('/instances/{instance}/sync', [InstanceController::class, 'sync']);

        // Notifications
        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::post('/notifications/read', [NotificationController::class, 'markRead']);

        // Tool Call Chain (AI agents)
        Route::get('/tools', [ToolsController::class, 'index']);
        Route::post('/tools/call', [ToolsController::class, 'call']);
        Route::post('/tools/chain', [ToolsController::class, 'chain']);

        // Admin
        Route::middleware('admin.api')->prefix('admin')->group(function () {
            Route::get('/users', [AdminUserController::class, 'index']);
            Route::get('/users/{user}', [AdminUserController::class, 'show']);
            Route::put('/users/{user}', [AdminUserController::class, 'update']);
            Route::delete('/users/{user}', [AdminUserController::class, 'destroy']);
            Route::patch('/users/{user}/toggle-active', [AdminUserController::class, 'toggleActive']);
            Route::patch('/users/{user}/toggle-admin', [AdminUserController::class, 'toggleAdmin']);

            Route::get('/panic', [AdminPanicController::class, 'status']);
            Route::post('/panic/toggle', [AdminPanicController::class, 'toggle']);
            Route::post('/panic/clear-queue', [AdminPanicController::class, 'clearQueue']);
            Route::post('/panic/clear-warmup-queue', [AdminPanicController::class, 'clearWarmupQueue']);
        });
    });
});

// Legacy Sanctum route
Route::middleware('auth:sanctum')->get('/user', function (\Illuminate\Http\Request $request) {
    return $request->user();
});
