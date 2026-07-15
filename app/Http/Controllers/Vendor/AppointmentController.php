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

        $upcomingAppointment = Appointment::query()
            // ->where('client_id', auth('client')->user()->id)
            // ->where('starts_at', '>=', now())
            // ->where('status', '!=', 'cancelled')
            // ->orderBy('start_time')
            ->first();

        return view('vendor.appointments.index', compact('upcomingAppointment'));
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
