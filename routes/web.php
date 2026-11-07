<?php

use App\Http\Controllers\CampaignController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UserController;
use App\Http\Controllers\InstanceController;
use App\Http\Controllers\PanicController;
use Illuminate\Support\Facades\Mail;
use App\Http\Controllers\WhatsappController;
use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\WhatsappJobController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\AdminBillingController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::group(['namespace' => 'App\Http\Controllers', 'middleware' => ['guest']], function () {
    /**
     * Register Routes
     */
    Route::get('/register', 'RegisterController@show')->name('register.show');
    Route::post('/register', 'RegisterController@register')->name('register.perform');

    /**
     * Login Routes
     */
    Route::get('/login', 'LoginController@show')->name('login');
    Route::post('/login', 'LoginController@login')->name('login.perform');
});

Route::get('/', 'App\Http\Controllers\HomeController@index')->name('home.index');

// Language selector (public: guests can choose too)
Route::get('/language/{locale}', [\App\Http\Controllers\LanguageController::class, 'switch'])->name('language.switch');

// Public JS import of an embeddable contact form (CORS open; the form itself checks allowed sites)
Route::get('/f/{key}.js', [\App\Http\Controllers\PublicFormController::class, 'script'])->where('key', '[a-z0-9]+')->name('forms.script');

// Public proposal (quote) page: the token is the access; answers are throttled
Route::prefix('p')->name('proposals.public.')->group(function () {
    $pp = \App\Http\Controllers\PublicProposalController::class;
    Route::get('/{token}', [$pp, 'show'])->name('show');
    Route::post('/{token}/accept', [$pp, 'accept'])->middleware('throttle:10,1')->name('accept');
    Route::post('/{token}/decline', [$pp, 'decline'])->middleware('throttle:10,1')->name('decline');
});

// WhatsApp Web-style chat (CRM). The browser only talks to Laravel; Laravel talks to the Evolution API.
Route::middleware(['auth', 'subscription.writable', 'plan.feature:chat'])->prefix('chat')->name('chat.')->group(function () {
    $chat = \App\Http\Controllers\ChatController::class;

    Route::get('/', [$chat, 'index'])->name('index');
    Route::get('/media/{message}', [$chat, 'media'])->name('media');
    Route::get('/avatar/{chat}', [$chat, 'avatar'])->name('avatar');

    Route::prefix('api')->name('api.')->group(function () use ($chat) {
        Route::get('/chats', [$chat, 'chats'])->name('chats');
        Route::post('/chats', [$chat, 'openChat'])->name('open');
        Route::get('/contacts', [$chat, 'searchContacts'])->name('contacts');
        Route::put('/signature', [$chat, 'saveSignature'])->name('signature');
        Route::post('/instances/{instance}/sync', [$chat, 'syncHistory'])->name('sync');
        Route::get('/instances/{instance}/sync', [$chat, 'syncStatus'])->name('sync-status');

        Route::get('/chats/{chat}/messages', [$chat, 'messages'])->name('messages');
        Route::post('/chats/{chat}/send', [$chat, 'send'])->name('send');
        Route::post('/chats/{chat}/scheduled', [\App\Http\Controllers\ScheduledMessagesController::class, 'store'])->name('scheduled.store');
        Route::delete('/chats/{chat}/scheduled/{scheduled}', [\App\Http\Controllers\ScheduledMessagesController::class, 'destroy'])->whereNumber('scheduled')->name('scheduled.destroy');
        Route::post('/chats/{chat}/read', [$chat, 'markRead'])->name('read');
        Route::post('/chats/{chat}/presence', [$chat, 'presence'])->name('presence');
        Route::get('/chats/{chat}/contact', [$chat, 'contactInfo'])->name('contact');
        Route::get('/chats/{chat}/links', [$chat, 'links'])->name('links');
        Route::get('/chats/{chat}/links/search', [$chat, 'linkSearch'])->name('links.search');
        Route::post('/chats/{chat}/links', [$chat, 'linkStore'])->name('links.store');
        Route::delete('/chats/{chat}/links/{contact}', [$chat, 'linkDestroy'])->name('links.destroy');
        Route::post('/chats/{chat}/link-phone', [$chat, 'linkPhone'])->name('link-phone');
        Route::post('/chats/{chat}/contact', [$chat, 'saveContact'])->name('contact.save');
        Route::post('/chats/{chat}/ai-pause', [$chat, 'aiPause'])->name('ai-pause');

        // team side: assignment, labels, internal notes, deal, inbox settings
        $crm = \App\Http\Controllers\ChatCrmController::class;
        Route::get('/chats/{chat}/crm', [$crm, 'show'])->name('crm');
        Route::post('/chats/{chat}/assign', [$crm, 'assign'])->name('assign');
        Route::put('/chats/{chat}/tags', [$crm, 'syncTags'])->name('tags.sync');
        Route::post('/tags', [$crm, 'storeTag'])->name('tags.store');
        Route::delete('/tags/{tag}', [$crm, 'destroyTag'])->name('tags.destroy');
        Route::post('/chats/{chat}/notes', [$crm, 'storeNote'])->name('notes.store');
        Route::delete('/notes/{note}', [$crm, 'destroyNote'])->name('notes.destroy');
        Route::post('/chats/{chat}/deal', [$crm, 'createDeal'])->name('deal');
        Route::put('/settings', [$crm, 'settings'])->name('settings');

        Route::post('/messages/{message}/retry', [$chat, 'retry'])->name('retry');
        Route::post('/messages/{message}/react', [$chat, 'react'])->name('react');
        Route::delete('/messages/{message}', [$chat, 'destroy'])->name('destroy');
    });
});


Route::group(['namespace' => 'App\Http\Controllers', 'middleware' => ['auth', 'subscription.writable']], function () {

    // Plans & billing (kept reachable for blocked accounts so they can pay)
    Route::get('/billing', [BillingController::class, 'index'])->name('billing.index');
    Route::post('/billing/subscribe', [BillingController::class, 'subscribe'])->name('billing.subscribe');
    Route::post('/billing/cancel', [BillingController::class, 'cancel'])->name('billing.cancel');
    Route::post('/billing/resume', [BillingController::class, 'resume'])->name('billing.resume');
    Route::get('/billing/invoices/{invoice}', [BillingController::class, 'invoice'])->name('billing.invoice');
    Route::post('/billing/invoices/{invoice}/pay', [BillingController::class, 'pay'])->name('billing.pay');
    Route::get('/billing/invoices/{invoice}/status', [BillingController::class, 'status'])->name('billing.status');
    Route::post('/billing/auto-renew/stop', [BillingController::class, 'stopAutoRenew'])->name('billing.autorenew.stop');

    // Telegram bots (channel = telegram instances)
    Route::prefix('telegram')->name('telegram.')->group(function () {
        $tg = \App\Http\Controllers\TelegramController::class;
        Route::get('/', [$tg, 'index'])->name('index');
        Route::post('/', [$tg, 'store'])->name('store')->middleware('throttle:10,1');
        Route::post('/{instance}/reconnect', [$tg, 'reconnect'])->name('reconnect');
        Route::delete('/{instance}', [$tg, 'destroy'])->name('destroy');
    });

    Route::get('/stats', [\App\Http\Controllers\ChannelStatsController::class, 'index'])->name('stats.index');

    // TikTok Business accounts (channel = tiktok instances, OAuth)
    Route::prefix('tiktok')->name('tiktok.')->group(function () {
        $tt = \App\Http\Controllers\TikTokController::class;
        Route::get('/', [$tt, 'index'])->name('index');
        Route::get('/connect', [$tt, 'connect'])->name('connect');
        Route::get('/callback', [$tt, 'callback'])->name('callback');
        Route::post('/{instance}/refresh', [$tt, 'refresh'])->name('refresh');
        Route::delete('/{instance}', [$tt, 'destroy'])->name('destroy');
    });

    // E-mail mailboxes (channel = email instances): SMTP credentials to send, IMAP to receive
    Route::prefix('email')->name('email.')->group(function () {
        $em = \App\Http\Controllers\EmailController::class;
        Route::get('/', [$em, 'index'])->name('index');
        Route::post('/', [$em, 'store'])->name('store')->middleware('throttle:10,1');
        Route::post('/{instance}/refresh', [$em, 'refresh'])->name('refresh')->middleware('throttle:10,1');
        Route::delete('/{instance}', [$em, 'destroy'])->name('destroy');
    });

    // Facebook Pages (channel = facebook instances)
    Route::prefix('facebook')->name('facebook.')->group(function () {
        $fb = \App\Http\Controllers\FacebookController::class;
        Route::get('/', [$fb, 'index'])->name('index');
        Route::post('/', [$fb, 'store'])->name('store')->middleware('throttle:10,1');
        Route::post('/{instance}/refresh', [$fb, 'refresh'])->name('refresh');
        Route::delete('/{instance}', [$fb, 'destroy'])->name('destroy');
    });

    // Instagram accounts (channel = instagram instances)
    Route::prefix('instagram')->name('instagram.')->group(function () {
        $ig = \App\Http\Controllers\InstagramController::class;
        Route::get('/', [$ig, 'index'])->name('index');
        Route::post('/', [$ig, 'store'])->name('store')->middleware('throttle:10,1');
        Route::post('/{instance}/refresh', [$ig, 'refresh'])->name('refresh');
        Route::delete('/{instance}', [$ig, 'destroy'])->name('destroy');
    });

    // AI agents: provider/model/prompt and which WhatsApp number each one answers for
    Route::prefix('approvals')->name('approvals.')->group(function () {
        $ap = \App\Http\Controllers\ApprovalController::class;
        Route::get('/', [$ap, 'index'])->name('index');
        Route::get('/{item}', [$ap, 'show'])->whereNumber('item')->name('show');
        Route::post('/{item}/preview', [$ap, 'preview'])->whereNumber('item')->name('preview');
        Route::post('/{item}/approve', [$ap, 'approve'])->whereNumber('item')->name('approve');
        Route::post('/{item}/reject', [$ap, 'reject'])->whereNumber('item')->name('reject');
    });
    Route::post('/campaign-items/{item}/submit', [\App\Http\Controllers\ApprovalController::class, 'submit'])->whereNumber('item')->name('campaign-items.submit');

    // Embeddable contact forms: the owner builds them here, visitors submit through the public script (routes/api.php)
    Route::prefix('forms')->name('forms.')->group(function () {
        $fm = \App\Http\Controllers\ContactFormController::class;
        Route::get('/', [$fm, 'index'])->name('index');
        Route::get('/create', [$fm, 'create'])->name('create');
        Route::post('/', [$fm, 'store'])->name('store');
        Route::get('/{form}/edit', [$fm, 'edit'])->whereNumber('form')->name('edit');
        Route::put('/{form}', [$fm, 'update'])->whereNumber('form')->name('update');
        Route::delete('/{form}', [$fm, 'destroy'])->whereNumber('form')->name('destroy');
        Route::get('/{form}/submissions', [$fm, 'submissions'])->whereNumber('form')->name('submissions');
    });

    // Sales funnel (Kanban of deals)
    Route::prefix('pipeline')->name('pipeline.')->group(function () {
        $pl = \App\Http\Controllers\PipelineController::class;
        Route::get('/', [$pl, 'index'])->name('index');
        Route::get('/priorities', [$pl, 'priorities'])->name('priorities');
        Route::get('/sources', [$pl, 'sources'])->name('sources');
        Route::post('/deals/{deal}/advice', [$pl, 'advice'])->whereNumber('deal')->middleware('throttle:20,1')->name('deals.advice');
        Route::post('/pipelines', [$pl, 'storePipeline'])->name('pipelines.store');
        Route::delete('/pipelines/{pipeline}', [$pl, 'destroyPipeline'])->whereNumber('pipeline')->name('pipelines.destroy');
        Route::post('/pipelines/{pipeline}/stages', [$pl, 'storeStage'])->whereNumber('pipeline')->name('stages.store');
        Route::put('/stages/{stage}', [$pl, 'updateStage'])->whereNumber('stage')->name('stages.update');
        Route::delete('/stages/{stage}', [$pl, 'destroyStage'])->whereNumber('stage')->name('stages.destroy');
        Route::post('/pipelines/{pipeline}/deals', [$pl, 'storeDeal'])->whereNumber('pipeline')->name('deals.store');
        Route::put('/deals/{deal}', [$pl, 'updateDeal'])->whereNumber('deal')->name('deals.update');
        Route::post('/deals/{deal}/move', [$pl, 'moveDeal'])->whereNumber('deal')->name('deals.move');
        Route::delete('/deals/{deal}', [$pl, 'destroyDeal'])->whereNumber('deal')->name('deals.destroy');
        // what a deal sells, and the catalog
        $di = \App\Http\Controllers\DealItemsController::class;
        Route::get('/deals/{deal}/items', [$di, 'index'])->whereNumber('deal')->name('deals.items.index');
        Route::post('/deals/{deal}/items', [$di, 'store'])->whereNumber('deal')->name('deals.items.store');
        Route::put('/deals/{deal}/items/{item}', [$di, 'update'])->whereNumber(['deal', 'item'])->name('deals.items.update');
        Route::delete('/deals/{deal}/items/{item}', [$di, 'destroy'])->whereNumber(['deal', 'item'])->name('deals.items.destroy');
        $pr = \App\Http\Controllers\ProductsController::class;
        Route::get('/products', [$pr, 'index'])->name('products.index');
        Route::post('/products', [$pr, 'store'])->name('products.store');
        Route::put('/products/{product}', [$pr, 'update'])->whereNumber('product')->name('products.update');
        Route::delete('/products/{product}', [$pr, 'destroy'])->whereNumber('product')->name('products.destroy');
    });

    // Proposals (quotes) built from the items of a deal
    Route::prefix('proposals')->name('proposals.')->group(function () {
        $pr = \App\Http\Controllers\ProposalsController::class;
        Route::get('/', [$pr, 'index'])->name('index');
        Route::post('/deal/{deal}', [$pr, 'store'])->whereNumber('deal')->name('store');
        Route::get('/{proposal}', [$pr, 'show'])->whereNumber('proposal')->name('show');
        Route::put('/{proposal}', [$pr, 'update'])->whereNumber('proposal')->name('update');
        Route::post('/{proposal}/refresh', [$pr, 'refresh'])->whereNumber('proposal')->name('refresh');
        Route::post('/{proposal}/sent', [$pr, 'sent'])->whereNumber('proposal')->name('sent');
        Route::delete('/{proposal}', [$pr, 'destroy'])->whereNumber('proposal')->name('destroy');
    });

    // Quick replies (canned answers typed with "/")
    Route::prefix('replies')->name('replies.')->group(function () {
        $qr = \App\Http\Controllers\QuickRepliesController::class;
        Route::get('/', [$qr, 'index'])->name('index');
        Route::get('/search', [$qr, 'search'])->name('search');
        Route::post('/', [$qr, 'store'])->name('store');
        Route::put('/{reply}', [$qr, 'update'])->whereNumber('reply')->name('update');
        Route::post('/{reply}/used', [$qr, 'used'])->whereNumber('reply')->name('used');
        Route::delete('/{reply}', [$qr, 'destroy'])->whereNumber('reply')->name('destroy');
    });

    // Consent register (opt-in / opt-out proof)
    Route::prefix('consent')->name('consent.')->group(function () {
        $cs = \App\Http\Controllers\ConsentController::class;
        Route::get('/', [$cs, 'index'])->name('index');
        Route::post('/settings', [$cs, 'settings'])->name('settings');
        Route::get('/export', [$cs, 'export'])->name('export');
        Route::post('/contacts/{contact}', [$cs, 'contact'])->whereNumber('contact')->name('contact');
    });

    // Custom contact fields + saved segments
    Route::prefix('audience')->name('audience.')->group(function () {
        $au = \App\Http\Controllers\AudienceController::class;
        Route::get('/', [$au, 'index'])->name('index');
        Route::post('/fields', [$au, 'storeField'])->name('fields.store');
        Route::delete('/fields/{field}', [$au, 'destroyField'])->whereNumber('field')->name('fields.destroy');
        Route::post('/preview', [$au, 'preview'])->name('preview');
        Route::post('/segments', [$au, 'storeSegment'])->name('segments.store');
        Route::delete('/segments/{segment}', [$au, 'destroySegment'])->whereNumber('segment')->name('segments.destroy');
        Route::get('/segments/{segment}/export', [$au, 'export'])->whereNumber('segment')->name('segments.export');
    });

    // Automations: when X happens, do Y
    Route::prefix('automations')->name('automations.')->group(function () {
        $am = \App\Http\Controllers\AutomationsController::class;
        Route::get('/', [$am, 'index'])->name('index');
        Route::post('/', [$am, 'store'])->name('store');
        Route::post('/{automation}/toggle', [$am, 'toggle'])->whereNumber('automation')->name('toggle');
        Route::delete('/{automation}', [$am, 'destroy'])->whereNumber('automation')->name('destroy');
    });

    // Team performance: first response, answered conversations, deals, goals
    Route::prefix('team-report')->name('team-report.')->group(function () {
        $tr = \App\Http\Controllers\TeamReportController::class;
        Route::get('/', [$tr, 'index'])->name('index');
        Route::post('/goals', [$tr, 'storeGoal'])->name('goals.store');
        Route::delete('/goals/{goal}', [$tr, 'destroyGoal'])->whereNumber('goal')->name('goals.destroy');
    });

    // Opening hours + automatic greeting / away message
    Route::get('hours', [\App\Http\Controllers\ServiceHoursController::class, 'edit'])->name('hours.edit');
    Route::put('hours', [\App\Http\Controllers\ServiceHoursController::class, 'update'])->name('hours.update');

    // Follow-up tasks
    Route::prefix('tasks')->name('tasks.')->group(function () {
        $tk = \App\Http\Controllers\TasksController::class;
        Route::get('/', [$tk, 'index'])->name('index');
        Route::post('/', [$tk, 'store'])->name('store');
        Route::put('/{task}', [$tk, 'update'])->whereNumber('task')->name('update');
        Route::post('/{task}/done', [$tk, 'done'])->whereNumber('task')->name('done');
        Route::delete('/{task}', [$tk, 'destroy'])->whereNumber('task')->name('destroy');
    });

    Route::prefix('team')->name('team.')->group(function () {
        $team = \App\Http\Controllers\TeamController::class;
        Route::get('/', [$team, 'index'])->name('index');
        Route::post('/', [$team, 'store'])->name('store');
        Route::put('/{member}', [$team, 'update'])->whereNumber('member')->name('update');
        Route::delete('/{member}', [$team, 'destroy'])->whereNumber('member')->name('destroy');
    });

    Route::prefix('ai')->name('ai.')->group(function () {
        $ai = \App\Http\Controllers\AiSettingsController::class;
        Route::get('/', [$ai, 'index'])->name('index');
        Route::post('/toggle', [$ai, 'toggle'])->name('toggle');
        Route::get('/agents/create', [$ai, 'create'])->name('create');
        Route::post('/agents', [$ai, 'store'])->name('store');
        Route::post('/agents/test', [$ai, 'test'])->name('test')->middleware('throttle:15,1');
        Route::get('/agents/{profile}/edit', [$ai, 'edit'])->name('edit');
        Route::put('/agents/{profile}', [$ai, 'update'])->name('update');
        Route::delete('/agents/{profile}', [$ai, 'destroy'])->name('destroy');
    });

    Route::get('/me/profile', [UserController::class, 'editProfile'])->name('profile.edit');
    Route::post('/me/profile', [UserController::class, 'updateProfile'])->name('profile.update');
    Route::post('/me/api-tokens', [UserController::class, 'createApiToken'])->name('profile.api-tokens.create')->middleware('plan.feature:api_access');
    Route::delete('/me/api-tokens/{tokenId}', [UserController::class, 'revokeApiToken'])->name('profile.api-tokens.revoke');

    Route::prefix('whatsapp')->middleware(['auth'])->name('whatsapp.')->group(function () {
        Route::get('/{id}', 'App\Http\Controllers\WhatsappController@index')->name('index');
        Route::get('/{id}/qr', 'App\Http\Controllers\WhatsappController@qr')->name('qr');
        Route::get('/{id}/status', 'App\Http\Controllers\WhatsappController@status')->name('status');
        Route::get('/delete', 'WhatsappController@delete')->name('delete');
        Route::get('/delete', 'WhatsappController@delete')->name('delete');
    });

    Route::resource('/campaigns', 'App\Http\Controllers\CampaignController')->middleware(['auth']);
    Route::resource('/contacts', 'App\Http\Controllers\ContactController')->middleware(['auth']);
    Route::resource('/campaign-items', 'App\Http\Controllers\CampaignItemController')->middleware(['auth']);;
    Route::get('/contact/duplicates', [\App\Http\Controllers\ContactDuplicatesController::class, 'index'])->name('contacts.duplicates')->middleware(['auth']);
    Route::post('/contact/duplicates/merge', [\App\Http\Controllers\ContactDuplicatesController::class, 'merge'])->name('contacts.duplicates.merge')->middleware(['auth']);
    Route::post('/contact/duplicates/ignore', [\App\Http\Controllers\ContactDuplicatesController::class, 'ignore'])->name('contacts.duplicates.ignore')->middleware(['auth']);
    Route::post('/contact/import', 'App\Http\Controllers\ContactController@import')->name('contacts.import')->middleware(['auth']);;
    Route::get('/contact/clean', 'App\Http\Controllers\ContactController@clean')->name('contacts.clear')->middleware(['auth']);;
    Route::get('/contact/photo/{id}', 'App\Http\Controllers\ContactController@photo')->name('contacts.photo')->middleware(['auth']);
    Route::post('/contact/bulk-delete', 'App\Http\Controllers\ContactController@bulkDelete')
        ->name('contacts.bulk-delete')
        ->middleware(['auth']); // Garanta que apenas usuários logados acessem
    Route::post('/contact/bulk-status', 'App\Http\Controllers\ContactController@bulkStatus')
        ->name('contacts.bulk-status')
        ->middleware(['auth']); // Garanta que apenas usuários logados acessem

    Route::get('/campaigns/{campaign}/report', [CampaignController::class, 'report'])->name('campaigns.report');
    Route::get('/campaign-items/{id}/send', 'CampaignItemController@send')->name('campaign-items.send');
    Route::get('/campaign-items/{id}/generate', 'CampaignItemController@generate')->name('campaign-items.generate');
    Route::get('/campaign-items/{id}/generateAll', 'CampaignItemController@generateAll')->name('campaign-items.generateAll');
    Route::get('/campaign-items/{id}/logs', 'WhatsappJobController@index')->name('campaign-items.logs');
    Route::get('/campaign-items/{id}/logs', 'WhatsappJobController@index')->name('whatsapp-jobs.index');
    Route::get('/logout', 'LogoutController@perform')->name('logout.perform');


    Route::post('/whatsapp-jobs/{id}/retry', [WhatsappJobController::class, 'retry'])
        ->name('whatsapp-jobs.retry')
        ->middleware(['auth']); // Garanta que apenas usuários logados acessem

    Route::post('/whatsapp-jobs/bulk-delete', [WhatsappJobController::class, 'bulkDelete'])
        ->name('whatsapp-jobs.bulk-delete')
        ->middleware(['auth']); // Garanta que apenas usuários logados acessem

    Route::post('/whatsapp-jobs/bulk-retry', [WhatsappJobController::class, 'bulkRetry'])
        ->name('whatsapp-jobs.bulk-retry')
        ->middleware(['auth']); // Garanta que apenas usuários logados acessem


        // 
    Route::group(['middleware' => ['auth', 'admin'], 'prefix' => 'admin'], function () {

        // Listagem e CRUD básico
        Route::get('/users', 'App\Http\Controllers\UserController@index')->name('users.index');
        Route::get('/users/{user}/edit', 'App\Http\Controllers\UserController@edit')->name('users.edit');
        Route::post('/users/{user}/update', 'App\Http\Controllers\UserController@update')->name('users.update');
        Route::delete('/users/{user}/delete', 'App\Http\Controllers\UserController@destroy')->name('users.destroy');

        // Ações de Controle de Status (As convenientes para sua VPS)
        Route::patch('/users/{user}/toggle-active', 'UserController@toggleActive')->name('users.toggleActive');
        Route::patch('/users/{user}/toggle-admin', 'UserController@toggleAdmin')->name('users.toggleAdmin');


        Route::get('/billing', [AdminBillingController::class, 'index'])->name('admin.billing.index');
        Route::post('/billing/invoices/{invoice}/paid', [AdminBillingController::class, 'markPaid'])->name('admin.billing.paid');
        Route::post('/billing/grant', [AdminBillingController::class, 'grant'])->name('admin.billing.grant');

        Route::get('/signup-welcome', [\App\Http\Controllers\AdminSignupWelcomeController::class, 'edit'])->name('admin.welcome.edit');
        Route::post('/signup-welcome', [\App\Http\Controllers\AdminSignupWelcomeController::class, 'update'])->name('admin.welcome.update');

        Route::get('/payment-gateway', [\App\Http\Controllers\AdminGatewayController::class, 'edit'])->name('admin.gateway.edit');
        Route::post('/payment-gateway', [\App\Http\Controllers\AdminGatewayController::class, 'update'])->name('admin.gateway.update');
        Route::post('/payment-gateway/webhook', [\App\Http\Controllers\AdminGatewayController::class, 'registerWebhook'])->name('admin.gateway.webhook');
        Route::post('/payment-gateway/test', [\App\Http\Controllers\AdminGatewayController::class, 'test'])->name('admin.gateway.test');

        Route::get('/panic/', [PanicController::class, 'index'])->name('panic.index');
        Route::post('/panic/clear', [PanicController::class, 'clear'])->name('admin.panic.clear');
        Route::post('/panic/toggle', [PanicController::class, 'toggle'])->name('admin.panic.toggle');
        Route::post('/panic/warmup', [PanicController::class, 'warmup'])->name('admin.panic.Warmup');
    });

    Route::post('/notifications/clear', function () {
        auth()->user()->unreadNotifications->markAsRead();
        return back();
    })->name('notifications.clear')->middleware('auth');

    Route::prefix('instances')->group(function () {
        // Listagem de instâncias
        Route::get('/', [InstanceController::class, 'index'])->name('instances.index');

        // Tela de criação
        Route::get('/create', [InstanceController::class, 'create'])->name('instances.create');

        // Processar criação
        Route::post('/store', [InstanceController::class, 'store'])->name('instances.store');

        // Ver detalhes / Escanear QR Code
        Route::get('/{instance}', [InstanceController::class, 'show'])->name('instances.show');
        Route::get('/{instance}/warmup', [InstanceController::class, 'warmup'])->name('instances.warmup');

        // Deletar instância
        Route::delete('/{instance}', [InstanceController::class, 'destroy'])->name('instances.destroy');

        /**
         * Rota Estratégica: Atualizar Status via API
         * (Para quando o usuário clicar em "Atualizar" na tela de listagem)
         */
        Route::get('/{instance}/sync', [InstanceController::class, 'sync'])->name('instances.sync');
    });

    Route::get('/test-email', function () {
        $data = ['name' => 'Teste do Sistema', 'body' => 'O SMTP está funcionando corretamente!'];

        try {

            // Usamos send() aqui (e não queue) para ver o erro na tela imediatamente se falhar
            Mail::raw('Este é um e-mail de teste do seu sistema de WhatsApp.', function ($message) {
                $message->to(Auth::user()->email) // COLOQUE SEU E-MAIL AQUI
                    ->subject('Teste de Conexão SMTP');
            });

            return "E-mail enviado com sucesso! Verifique sua caixa de entrada (e o Spam).";
        } catch (\Exception $e) {
            return "Erro ao enviar e-mail: " . $e->getMessage();
        }
    });
});

Route::get('/instagram-media/{message}', [\App\Http\Controllers\InstagramMediaController::class, 'show'])->middleware('signed')->name('instagram.media');
