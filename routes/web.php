<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\WhatsappController;
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

Route::group(['namespace' => 'App\Http\Controllers'], function()
{   
    /**
     * Home Routes
     */
    Route::get('/', 'HomeController@index')->name('home.index');

    Route::group(['middleware' => ['guest']], function() {
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

    Route::group(['middleware' => ['auth']], function() {
        /**
         * Logout Routes
         */
      
    });


    Route::prefix('whatsapp')->middleware(['auth'])->name('whatsapp.')->group(function () {
        Route::get('/', 'App\Http\Controllers\WhatsappController@index')->name('index');
        Route::get('/new', 'WhatsappController@new')->name('new');
        Route::post('/store', 'WhatsappController@store')->name('store');
        Route::post('/update', 'WhatsappController@update')->name('update');
        Route::get('/{id}/edit', 'WhatsappController@edit')->name('edit');
        Route::get('/{id}/send', 'WhatsappController@send')->name('send');
        Route::get('/delete', 'WhatsappController@delete')->name('delete');
    });

    Route::resource('/campaigns', 'App\Http\Controllers\CampaignController')->middleware(['auth']);
    Route::resource('/contacts', 'App\Http\Controllers\ContactController')->middleware(['auth']);
    Route::resource('/campaign-items', 'App\Http\Controllers\CampaignItemController')->middleware(['auth']);;
    Route::post('/contact/import', 'App\Http\Controllers\ContactController@import')->name('contacts.import')->middleware(['auth']);;
    Route::get('/contact/clean', 'App\Http\Controllers\ContactController@clean')->name('contacts.clear')->middleware(['auth']);;
    Route::get('/campaign-items/{id}/sned', 'CampaignItemController@send')->name('campaign-items.send');

    Route::get('/logout', 'LogoutController@perform')->name('logout.perform');

});