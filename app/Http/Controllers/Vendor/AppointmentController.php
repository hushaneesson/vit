<?php

namespace App\Http\Controllers\Vendor;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\User;
use App\Notifications\AppointmentCancelledWithYouNotification;
use App\Notifications\ClientAppointmentCancelledNotification;
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

    public function cancel(Appointment $appointment)
    {
        abort_unless(
            (int) data_get($appointment->metadata, 'client_id') === (int) auth('client')->id(),
            403
        );

        if (! $appointment->is_active) {
            return back()->with('status', 'This appointment is already cancelled.');
        }

        $appointment->update([
            'is_active' => 0,
        ]);

        $client = auth('client')->user();
        $bookedWith = $appointment->schedulable;
        $firstPeriod = $appointment->periods->sortBy('start_time')->first();

        if ($client instanceof Client) {
            $client->notifyNow(new ClientAppointmentCancelledNotification(
                bookedWithName: $bookedWith instanceof User ? $bookedWith->name : 'your appointment provider',
                date: $appointment->start_date->format('Y-m-d'),
                startsAt: (string) data_get($firstPeriod, 'start_time', ''),
                endsAt: (string) data_get($firstPeriod, 'end_time', ''),
                cancelledByName: $client->name,
            ));
        }

        if ($bookedWith instanceof User) {
            $bookedWith->notifyNow(new AppointmentCancelledWithYouNotification(
                clientName: $client instanceof Client ? $client->name : 'A client',
                date: $appointment->start_date->format('Y-m-d'),
                startsAt: (string) data_get($firstPeriod, 'start_time', ''),
                endsAt: (string) data_get($firstPeriod, 'end_time', ''),
                cancelledByName: $client instanceof Client ? $client->name : 'Client',
            ));
        }

        return back()->with('status', 'Appointment cancelled successfully.');
    }


    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }
}
