<?php

use App\Http\Controllers\Vendor\CatalogItemController;
use App\Http\Controllers\Vendor\CatalogItemImageController;
use App\Http\Controllers\Vendor\ClientAuthController;
use App\Http\Controllers\Vendor\DashboardController;
use App\Http\Controllers\Vendor\SubmissionDownloadController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

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
    Route::get('activate/{token}', [ClientAuthController::class, 'activate'])->name('activate');

    Route::get('login', [ClientAuthController::class, 'showLoginForm'])->name('login');
    Route::post('login', [ClientAuthController::class, 'sendOtp'])->name('login.send');
    Route::get('login/otp', [ClientAuthController::class, 'showOtpForm'])->name('login.otp');
    Route::post('login/otp', [ClientAuthController::class, 'verifyOtp'])->name('login.otp.verify');
    Route::post('logout', [ClientAuthController::class, 'logout'])->name('logout');

    Route::middleware('client.active')->group(function () {
        Route::get('dashboard', DashboardController::class)->name('dashboard');

        Route::get('submissions/{submission}/download', SubmissionDownloadController::class)
            ->name('submissions.download');

        // Phase 4/5/6/12 — catalog data entry (standard + complex fields,
        // hierarchy/classification, edit & add-items workflows).
        Route::get('catalog', [CatalogItemController::class, 'index'])->name('catalog.index');
        Route::get('catalog/create', [CatalogItemController::class, 'create'])->name('catalog.create');
        Route::get('catalog/{catalogItem}/edit', [CatalogItemController::class, 'edit'])->name('catalog.edit');
        Route::delete('catalog/{catalogItem}', [CatalogItemController::class, 'destroy'])->name('catalog.destroy');
        Route::post('catalog/submit', [CatalogItemController::class, 'submit'])->name('catalog.submit');

        // Authenticated, vendor-scoped preview of a catalog item's uploaded
        // image (used by the entry form and catalog list — images live on
        // the private disk, never a public URL).
        Route::get('catalog-images/{catalogItemImage}', CatalogItemImageController::class)
            ->name('catalog-images.show');
    });
});
