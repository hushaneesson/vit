<?php

namespace App\Http\Controllers\Vendor;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Vendor-portal home. Shows this client's (and therefore vendor's) recent
 * catalog items and submissions. Every query here is scoped by vendor_id,
 * never client_id, so colleagues under the same vendor see the same data.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request)
    {
        $client = Auth::guard('client')->user();
        $vendor = $client->vendor;

        $submissions = $vendor->submissions()->latest('submission_date')->limit(10)->get();
        $catalogItemCount = $vendor->catalogItems()->count();

        return view('vendor.dashboard', [
            'client' => $client,
            'vendor' => $vendor,
            'submissions' => $submissions,
            'catalogItemCount' => $catalogItemCount,
        ]);
    }
}
