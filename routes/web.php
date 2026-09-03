<?php

use App\Http\Controllers\Vendor\AppointmentController;
use App\Http\Controllers\Vendor\CatalogController;
use App\Http\Controllers\Vendor\CatalogItemController;
use App\Http\Controllers\Vendor\CatalogItemImageController;
use App\Http\Controllers\Vendor\ClientAuthController;
use App\Http\Controllers\Vendor\DashboardController;
use App\Http\Controllers\Vendor\SubmissionDownloadController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
})->name('home');


/*
|--------------------------------------------------------------------------
| Vendor (Client) Portal Routes
|--------------------------------------------------------------------------
|
| Entirely separate from the Filament admin panel and its "web" guard.
| Clients authenticate via email-OTP only (see ClientAuthController) on
| the dedicated "client" guard, and every /vendor/* route below the auth
| group is protected by the client.active middleware.
|
*/
Route::prefix('vendor')->name('vendor.')->group(function () {
    Route::get('login', [ClientAuthController::class, 'showLoginForm'])->name('login');
    Route::post('login', [ClientAuthController::class, 'sendOtp'])->name('login.send');
    Route::get('login/otp', [ClientAuthController::class, 'showOtpForm'])->name('login.otp');
    Route::post('login/otp', [ClientAuthController::class, 'verifyOtp'])->name('login.otp.verify');
    Route::post('logout', [ClientAuthController::class, 'logout'])->name('logout');

    Route::middleware('client.active')->group(function () {
        Route::get('dashboard', DashboardController::class)->name('dashboard');

        Route::get('catalog-submissions/{catalogSubmission}/download', SubmissionDownloadController::class)
            ->name('catalog-submissions.download');

        Route::get('appointments', [AppointmentController::class, 'index'])->name('appointments.index');
        Route::get('appointments/create', [AppointmentController::class, 'create'])->name('appointments.create');
        Route::patch('appointments/{appointment}/cancel', [AppointmentController::class, 'cancel'])
            ->name('appointments.cancel');

        Route::get('catalogs', [CatalogController::class, 'index'])->name('catalog.index');
        Route::get('catalogs/create', [CatalogController::class, 'create'])->name('catalogs.create');
        Route::post('catalogs', [CatalogController::class, 'store'])->name('catalogs.store');

        Route::get('catalog/{catalog}/items', [CatalogItemController::class, 'index'])->name('catalog.items');
        Route::get('catalog/{catalog}/items/create', [CatalogItemController::class, 'create'])->name('catalog.create');
        Route::get('catalog/{catalogItem}/edit', [CatalogItemController::class, 'edit'])->name('catalog.edit');
        Route::delete('catalog/{catalogItem}', [CatalogItemController::class, 'destroy'])->name('catalog.destroy');

        Route::get('catalog-images/{catalogItemImage}', CatalogItemImageController::class)
            ->name('catalog-images.show');

        Route::get('catalog/{catalogId}/upload', [CatalogItemController::class, 'renderUpload'])->name('catalog-upload');
    });
});
