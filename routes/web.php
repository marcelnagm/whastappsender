<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UserController;
use App\Http\Controllers\InstanceController;
use Illuminate\Support\Facades\Mail;
use App\Http\Controllers\WhatsappController;
use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\WhatsappJobController;

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


Route::group(['namespace' => 'App\Http\Controllers', 'middleware' => ['auth']], function () {


    Route::prefix('whatsapp')->middleware(['auth'])->name('whatsapp.')->group(function () {
        Route::get('/{id}', 'App\Http\Controllers\WhatsappController@index')->name('index');
        Route::get('/delete', 'WhatsappController@delete')->name('delete');
    });

    Route::resource('/campaigns', 'App\Http\Controllers\CampaignController')->middleware(['auth']);
    Route::resource('/contacts', 'App\Http\Controllers\ContactController')->middleware(['auth']);
    Route::resource('/campaign-items', 'App\Http\Controllers\CampaignItemController')->middleware(['auth']);;
    Route::post('/contact/import', 'App\Http\Controllers\ContactController@import')->name('contacts.import')->middleware(['auth']);;
    Route::get('/contact/clean', 'App\Http\Controllers\ContactController@clean')->name('contacts.clear')->middleware(['auth']);;
    Route::get('/contact/photo/{id}', 'App\Http\Controllers\ContactController@photo')->name('contacts.photo')->middleware(['auth']);
    Route::post('/contact/bulk-delete', 'App\Http\Controllers\ContactController@bulkDelete')
        ->name('contacts.bulk-delete')
        ->middleware(['auth']); // Garanta que apenas usuários logados acessem
    Route::post('/contact/bulk-status', 'App\Http\Controllers\ContactController@bulkStatus')
        ->name('contacts.bulk-status')
        ->middleware(['auth']); // Garanta que apenas usuários logados acessem

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

    Route::group(['middleware' => ['auth', 'admin'], 'prefix' => 'admin'], function () {

        // Listagem e CRUD básico
        Route::get('/users', 'App\Http\Controllers\UserController@index')->name('users.index');
        Route::get('/users/{user}/edit', 'App\Http\Controllers\UserController@edit')->name('users.edit');
        Route::post('/users/{user}/update', 'App\Http\Controllers\UserController@update')->name('users.update');
        Route::delete('/users/{user}/delete', 'App\Http\Controllers\UserController@destroy')->name('users.destroy');

        // Ações de Controle de Status (As convenientes para sua VPS)
        Route::patch('/users/{user}/toggle-active', 'UserController@toggleActive')->name('users.toggleActive');
        Route::patch('/users/{user}/toggle-admin', 'UserController@toggleAdmin')->name('users.toggleAdmin');
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
