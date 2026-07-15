<?php

namespace App\Http\Controllers\Vendor;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use Illuminate\Http\Request;

class AppointmentController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $upcomingAppointments = Appointment::whereJsonContains(
            'metadata->client_id',
            auth('client')->id()
        )
            ->whereDate('start_date', '>=', now())
            ->where('is_active', 1)
            ->orderBy('start_date')
            ->get();

        return view('vendor.appointments.index', compact('upcomingAppointments'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return view('vendor.appointments.create');
    }


    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }
}
