<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Models\ServiceRequest;

class DashboardController extends Controller
{
    public function index()
    {
        // Redirect based on user role
        $user = auth()->user();

        switch ($user->role) {
            case 'admin':
                return redirect()->route('admin.dashboard');
            case 'client':
                return redirect()->route('client.dashboard');
            case 'technician':
                return redirect()->route('technician.dashboard');
            default:
                return redirect()->route('client.dashboard');
        }
    }

    public function client()
    {
        $user = auth()->user();
        $serviceRequests = ServiceRequest::with(['serviceCategory', 'technician.user'])
            ->where('user_id', $user->id)
            ->latest()
            ->get();

        return Inertia::render('Client/Dashboard', [
            'serviceRequests' => $serviceRequests
        ]);
    }
}
