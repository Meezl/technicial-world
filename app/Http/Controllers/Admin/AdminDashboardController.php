<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use App\Models\ServiceRequest;
use App\Models\Technician;
use App\Models\User;
use App\Models\Tool;
use App\Mail\QuotationSent;
use App\Mail\QuotationRejected;
use App\Models\PaymentRequest;
use App\Notifications\PaymentRequestNotification;
use Illuminate\Support\Facades\Mail;
use App\Mail\JobAssigned;
use App\Mail\TechnicianAssigned;
use App\Models\ServiceSubTask;
use App\Models\Payment;
use App\Models\TechnicianPayment;
use App\Models\Expenditure;

class AdminDashboardController extends Controller
{
    public function index()
    {
        // Calculate stats
        $totalJobs = ServiceRequest::count();
        $completedJobs = ServiceRequest::where('status', 'completed')->count();
        $completionRate = $totalJobs > 0 ? round(($completedJobs / $totalJobs) * 100, 1) : 0;
        $pendingRfqs = ServiceRequest::where('status', 'pending')->count();

        $stats = [
            'totalJobs' => $totalJobs,
            'completionRate' => $completionRate . '%',
            'pendingRfqs' => $pendingRfqs,
            'averageRating' => '4.8' // TODO: Calculate from actual ratings
        ];

        return Inertia::render('Admin/Dashboard', [
            'stats' => $stats
        ]);
    }

    public function technicians()
    {
        $technicians = Technician::with('user')->orderBy('created_at', 'desc')->get();

        return Inertia::render('Admin/Technicians', [
            'technicians' => $technicians
        ]);
    }

    public function jobs(Request $request)
    {
        $query = ServiceRequest::with(['user', 'serviceCategory', 'technician.user', 'leadTechnician.user', 'subTasks.technician.user'])
            ->orderBy('created_at', 'desc');

        // Search Filter
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('request_id', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($q) use ($search) {
                        $q->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        // Status Filter
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $jobs = $query->paginate(10)->withQueryString();

        $technicians = Technician::with('user')
            ->orderBy('rating', 'desc')
            ->get();

        return Inertia::render('Admin/Jobs', [
            'jobs' => $jobs,
            'technicians' => $technicians,
            'filters' => $request->only(['search', 'status'])
        ]);
    }

    public function showJob(ServiceRequest $serviceRequest)
    {
        $job = $serviceRequest->load([
            'user',
            'serviceCategory',
            'technician.user',
            'leadTechnician.user',
            'subTasks.technician.user',
            'budget',
            'technicianPayments.technician.user',
            'expenditures',
            'payments',
            'paymentRequests',
        ]);

        $technicians = Technician::with('user')
            ->orderBy('rating', 'desc')
            ->get();

        // Compute budget vs actual for this SR
        $budgetSummary = null;
        if ($job->budget) {
            $laborSpent = $job->technicianPayments
                ->where('category', 'labor')->where('status', 'completed')->sum('amount');
            $materialsSpentPayments = $job->technicianPayments
                ->where('category', 'materials')->where('status', 'completed')->sum('amount');
            $materialsSpentExpenditures = $job->expenditures
                ->where('category', 'materials')->sum('amount');
            $otherSpentPayments = $job->technicianPayments
                ->where('category', 'other')->where('status', 'completed')->sum('amount');
            $otherSpentExpenditures = $job->expenditures
                ->where('category', 'other')->sum('amount');

            $budgetSummary = [
                'labor' => [
                    'budgeted' => (float) $job->budget->labor_budget,
                    'actual' => (float) $laborSpent,
                    'remaining' => (float) $job->budget->labor_budget - (float) $laborSpent,
                ],
                'materials' => [
                    'budgeted' => (float) $job->budget->materials_budget,
                    'actual' => (float) ($materialsSpentPayments + $materialsSpentExpenditures),
                    'remaining' => (float) $job->budget->materials_budget - (float) ($materialsSpentPayments + $materialsSpentExpenditures),
                ],
                'other' => [
                    'budgeted' => (float) $job->budget->other_budget,
                    'actual' => (float) ($otherSpentPayments + $otherSpentExpenditures),
                    'remaining' => (float) $job->budget->other_budget - (float) ($otherSpentPayments + $otherSpentExpenditures),
                ],
                'total' => [
                    'budgeted' => (float) $job->budget->total_budget,
                    'actual' => (float) ($laborSpent + $materialsSpentPayments + $materialsSpentExpenditures + $otherSpentPayments + $otherSpentExpenditures),
                    'remaining' => (float) $job->budget->total_budget - (float) ($laborSpent + $materialsSpentPayments + $materialsSpentExpenditures + $otherSpentPayments + $otherSpentExpenditures),
                ],
            ];
        }

        return Inertia::render('Admin/JobDetails', [
            'job' => $job,
            'technicians' => $technicians,
            'budgetSummary' => $budgetSummary,
        ]);
    }

    public function storeTechnician(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
            'phone' => 'nullable|string|max:20',
            'specialization' => 'required|string|max:255',
            'location' => 'required|string|max:255',
            'availability' => 'required|in:available,busy,on_leave',
            'bio' => 'nullable|string',
            'skills' => 'nullable|array',
        ]);

        // Create user first
        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'phone' => $request->phone,
            'role' => 'technician',
        ]);

        // Generate technician ID if not provided
        $technicianId = $request->technician_id ?: 'TECH-' . str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT);

        // Create technician
        Technician::create([
            'user_id' => $user->id,
            'technician_id' => $technicianId,
            'specialization' => $request->specialization,
            'location' => $request->location,
            'availability' => $request->availability,
            'bio' => $request->bio,
            'skills' => $request->skills,
            'rating' => 0,
            'total_jobs' => 0,
        ]);

        return redirect()->route('admin.technicians')->with('success', 'Technician created successfully!');
    }

    public function updateTechnician(Request $request, Technician $technician)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,' . $technician->user_id,
            'phone' => 'nullable|string|max:20',
            'specialization' => 'required|string|max:255',
            'location' => 'required|string|max:255',
            'availability' => 'required|in:available,busy,on_leave',
            'bio' => 'nullable|string',
            'skills' => 'nullable|array',
        ]);

        // Update user
        $technician->user->update([
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
        ]);

        // Update technician
        $technician->update([
            'specialization' => $request->specialization,
            'location' => $request->location,
            'availability' => $request->availability,
            'bio' => $request->bio,
            'skills' => $request->skills,
        ]);

        return redirect()->route('admin.technicians')->with('success', 'Technician updated successfully!');
    }

    public function destroyTechnician(Technician $technician)
    {
        // Check if technician has active jobs
        $activeJobs = $technician->serviceRequests()->whereIn('status', ['assigned', 'in_progress'])->count();

        if ($activeJobs > 0) {
            return redirect()->route('admin.technicians')->with('error', 'Cannot delete technician with active jobs.');
        }

        // Delete technician and associated user
        $user = $technician->user;
        $technician->delete();
        $user->delete();

        return redirect()->route('admin.technicians')->with('success', 'Technician deleted successfully!');
    }

    public function assignTechnician(Request $request, ServiceRequest $serviceRequest)
    {
        $request->validate([
            'technician_id' => 'required|exists:technicians,id'
        ]);

        // Block direct assignment when sub-tasks exist
        if ($serviceRequest->has_sub_tasks) {
            return redirect()->route('admin.jobs.show', $serviceRequest)->with('error', 'This service request has sub-tasks. Please assign technicians to individual sub-tasks.');
        }

        // Check if RFQ has been approved (if RFQ workflow is enabled)
        if ($serviceRequest->rfq_status && $serviceRequest->rfq_status !== ServiceRequest::RFQ_STATUS_APPROVED) {
            return redirect()->route('admin.jobs')->with('error', 'Cannot assign technician until RFQ is approved by client.');
        }

        $technician = Technician::findOrFail($request->technician_id);

        // Update service request
        $serviceRequest->update([
            'technician_id' => $technician->id,
            'status' => 'assigned',
            'assigned_at' => now()
        ]);

        // Update technician availability if they become busy
        if ($technician->availability === 'available') {
            $technician->update(['availability' => 'busy']);
        }

        // Send email notification to technician
        if ($technician->user && $technician->user->email) {
            Mail::to($technician->user->email)->send(new JobAssigned($serviceRequest));
        }

        // Send email notification to client
        if ($serviceRequest->user && $serviceRequest->user->email) {
            Mail::to($serviceRequest->user->email)->send(new TechnicianAssigned($serviceRequest, $technician));
        }

        return redirect()->route('admin.jobs')->with('success', 'Technician assigned successfully!');
    }

    public function tools()
    {
        $tools = Tool::with(['technician.user', 'serviceRequest'])
            ->orderBy('created_at', 'desc')
            ->get();

        $technicians = Technician::with('user')
            ->where('availability', 'available')
            ->orderBy('user_id', 'asc')
            ->get();

        $activeJobs = ServiceRequest::with('user')
            ->whereIn('status', ['assigned', 'in_progress'])
            ->orderBy('created_at', 'desc')
            ->get();

        return Inertia::render('Admin/Tools', [
            'tools' => $tools,
            'technicians' => $technicians,
            'activeJobs' => $activeJobs
        ]);
    }

    public function storeTool(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'serial_number' => 'nullable|string|max:255|unique:tools,serial_number',
            'category' => 'required|string|max:255',
            'condition' => 'required|in:new,good,fair,needs_repair,damaged',
            'description' => 'nullable|string',
            'notes' => 'nullable|string'
        ]);

        Tool::create([
            'name' => $request->name,
            'serial_number' => $request->serial_number,
            'category' => $request->category,
            'condition' => $request->condition,
            'description' => $request->description,
            'notes' => $request->notes,
            'status' => Tool::STATUS_AVAILABLE
        ]);

        return redirect()->route('admin.tools')->with('success', 'Tool added to inventory successfully!');
    }

    public function updateTool(Request $request, Tool $tool)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'serial_number' => 'nullable|string|max:255|unique:tools,serial_number,' . $tool->id,
            'category' => 'required|string|max:255',
            'condition' => 'required|in:new,good,fair,needs_repair,damaged',
            'description' => 'nullable|string',
            'notes' => 'nullable|string'
        ]);

        $tool->update([
            'name' => $request->name,
            'serial_number' => $request->serial_number,
            'category' => $request->category,
            'condition' => $request->condition,
            'description' => $request->description,
            'notes' => $request->notes
        ]);

        return redirect()->route('admin.tools')->with('success', 'Tool updated successfully!');
    }

    public function destroyTool(Tool $tool)
    {
        if ($tool->status === Tool::STATUS_ISSUED) {
            return redirect()->route('admin.tools')->with('error', 'Cannot delete a tool that is currently issued.');
        }

        $tool->delete();

        return redirect()->route('admin.tools')->with('success', 'Tool deleted successfully!');
    }

    public function assignTool(Request $request, Tool $tool)
    {
        $request->validate([
            'technician_id' => 'required|exists:technicians,id',
            'service_request_id' => 'nullable|exists:service_requests,id',
            'expected_return_date' => 'nullable|date|after:today',
            'notes' => 'nullable|string'
        ]);

        $technician = Technician::findOrFail($request->technician_id);
        $serviceRequest = $request->service_request_id ? ServiceRequest::findOrFail($request->service_request_id) : null;

        $tool->assignTo(
            $technician,
            $serviceRequest,
            $request->expected_return_date,
            $request->notes
        );

        return redirect()->route('admin.tools')->with('success', 'Tool issued successfully!');
    }

    public function returnTool(Request $request, Tool $tool)
    {
        $request->validate([
            'condition' => 'nullable|in:new,good,fair,needs_repair,damaged',
            'notes' => 'nullable|string'
        ]);

        $tool->returnTool($request->condition, $request->notes);

        return redirect()->route('admin.tools')->with('success', 'Tool returned to inventory!');
    }

    public function payments(Request $request)
    {
        $serviceRequestId = $request->input('service_request_id');
        $technicianId = $request->input('technician_id');

        // Client payments
        $paymentsQuery = Payment::with(['serviceRequest', 'user']);
        if ($serviceRequestId) {
            $paymentsQuery->where('service_request_id', $serviceRequestId);
        }
        $payments = $paymentsQuery->orderBy('created_at', 'desc')->get();

        // Payment requests
        $paymentRequestsQuery = PaymentRequest::with(['serviceRequest', 'user']);
        if ($serviceRequestId) {
            $paymentRequestsQuery->where('service_request_id', $serviceRequestId);
        }
        $paymentRequests = $paymentRequestsQuery->orderBy('created_at', 'desc')->get();

        // Technician payments
        $techPaymentsQuery = TechnicianPayment::with(['technician.user', 'serviceRequest']);
        if ($serviceRequestId) {
            $techPaymentsQuery->where('service_request_id', $serviceRequestId);
        }
        if ($technicianId) {
            $techPaymentsQuery->where('technician_id', $technicianId);
        }
        $technicianPayments = $techPaymentsQuery->orderBy('created_at', 'desc')->get();

        // Expenditures
        $expendituresQuery = Expenditure::with(['serviceRequest', 'recordedBy']);
        if ($serviceRequestId) {
            $expendituresQuery->where('service_request_id', $serviceRequestId);
        }
        $expenditures = $expendituresQuery->orderBy('created_at', 'desc')->get();

        // KPI stats
        $stats = [
            'totalReceived' => (float) Payment::where('status', 'completed')->sum('amount'),
            'pendingPayments' => (float) PaymentRequest::where('status', 'pending')->sum('amount'),
            'paidToTechnicians' => (float) TechnicianPayment::where('status', 'completed')->sum('amount'),
            'totalExpenses' => (float) Expenditure::sum('amount'),
        ];

        // Budget summary for filtered SR
        $budgetSummary = null;
        if ($serviceRequestId) {
            $sr = ServiceRequest::with(['budget', 'technicianPayments', 'expenditures'])->find($serviceRequestId);
            if ($sr && $sr->budget) {
                $laborSpent = $sr->technicianPayments
                    ->where('category', 'labor')->where('status', 'completed')->sum('amount');
                $materialsSpentPayments = $sr->technicianPayments
                    ->where('category', 'materials')->where('status', 'completed')->sum('amount');
                $materialsSpentExpenditures = $sr->expenditures
                    ->where('category', 'materials')->sum('amount');
                $otherSpentPayments = $sr->technicianPayments
                    ->where('category', 'other')->where('status', 'completed')->sum('amount');
                $otherSpentExpenditures = $sr->expenditures
                    ->where('category', 'other')->sum('amount');

                $budgetSummary = [
                    'budget' => $sr->budget,
                    'labor' => [
                        'budgeted' => (float) $sr->budget->labor_budget,
                        'actual' => (float) $laborSpent,
                        'remaining' => (float) $sr->budget->labor_budget - (float) $laborSpent,
                    ],
                    'materials' => [
                        'budgeted' => (float) $sr->budget->materials_budget,
                        'actual' => (float) ($materialsSpentPayments + $materialsSpentExpenditures),
                        'remaining' => (float) $sr->budget->materials_budget - (float) ($materialsSpentPayments + $materialsSpentExpenditures),
                    ],
                    'other' => [
                        'budgeted' => (float) $sr->budget->other_budget,
                        'actual' => (float) ($otherSpentPayments + $otherSpentExpenditures),
                        'remaining' => (float) $sr->budget->other_budget - (float) ($otherSpentPayments + $otherSpentExpenditures),
                    ],
                    'total' => [
                        'budgeted' => (float) $sr->budget->total_budget,
                        'actual' => (float) ($laborSpent + $materialsSpentPayments + $materialsSpentExpenditures + $otherSpentPayments + $otherSpentExpenditures),
                        'remaining' => (float) $sr->budget->total_budget - (float) ($laborSpent + $materialsSpentPayments + $materialsSpentExpenditures + $otherSpentPayments + $otherSpentExpenditures),
                    ],
                ];
            }
        }

        // Service requests list for filter dropdown and payments modal
        $serviceRequests = ServiceRequest::with('subTasks:id,service_request_id,technician_id')
            ->select('id', 'request_id', 'description', 'technician_id', 'lead_technician_id')
            ->orderBy('created_at', 'desc')
            ->get();

        // Technicians list for payment modal
        $technicians = Technician::with('user')->orderBy('created_at', 'desc')->get();

        return Inertia::render('Admin/Payments', [
            'payments' => $payments,
            'paymentRequests' => $paymentRequests,
            'technicianPayments' => $technicianPayments,
            'expenditures' => $expenditures,
            'stats' => $stats,
            'budgetSummary' => $budgetSummary,
            'serviceRequests' => $serviceRequests,
            'technicians' => $technicians,
            'filters' => [
                'service_request_id' => $serviceRequestId,
                'technician_id' => $technicianId,
            ],
        ]);
    }

    public function rfq()
    {
        $rfqs = ServiceRequest::with(['user', 'serviceCategory', 'technician.user'])
            ->orderBy('created_at', 'desc')
            ->get();

        // Calculate RFQ statistics
        $stats = [
            'pending' => ServiceRequest::where('rfq_status', 'pending')->count(),
            'quoted' => ServiceRequest::where('rfq_status', 'quoted')->count(),
            'approved' => ServiceRequest::where('rfq_status', 'approved')->count(),
            'totalValue' => ServiceRequest::where('rfq_status', 'approved')->sum('quote_amount')
        ];

        return Inertia::render('Admin/RFQ', [
            'rfqs' => $rfqs,
            'stats' => $stats
        ]);
    }

    public function submitQuote(Request $request)
    {
        $request->validate([
            'service_request_id' => 'required|exists:service_requests,id',
            'materials' => 'required|array|min:1',
            'materials.*.name' => 'required|string',
            'materials.*.quantity' => 'required|numeric|min:1',
            'materials.*.unit_price' => 'required|numeric|min:0',
            'labor_cost' => 'required|numeric|min:0',
            'total_amount' => 'required|numeric|min:0',
            'notes' => 'nullable|string',
            'materials_file' => 'nullable|file|mimes:pdf,docx,xlsx,xls|max:10240',
        ]);

        $serviceRequest = ServiceRequest::findOrFail($request->service_request_id);

        $filePath = null;
        if ($request->hasFile('materials_file')) {
            $file = $request->file('materials_file');
            $fileName = 'quote_materials_' . $serviceRequest->request_id . '_' . time() . '.' . $file->getClientOriginalExtension();
            $filePath = $file->storeAs('quotes', $fileName, 'public');
        }

        $serviceRequest->update([
            'rfq_status' => ServiceRequest::RFQ_STATUS_QUOTED,
            'quote_amount' => $request->total_amount,
            'quote_materials' => $request->materials,
            'quote_labor_cost' => $request->labor_cost,
            'quote_notes' => $request->notes,
            'quote_materials_file_path' => $filePath,
        ]);

        // Send email notification to client
        Mail::to($serviceRequest->user->email)->send(new QuotationSent($serviceRequest));

        return redirect()->route('admin.rfq')->with('success', 'Quotation sent to client successfully!');
    }

    public function rejectRFQ(Request $request, ServiceRequest $serviceRequest)
    {
        $request->validate([
            'reason' => 'required|string|min:10'
        ]);

        $serviceRequest->update([
            'rfq_status' => ServiceRequest::RFQ_STATUS_REJECTED,
            'rejection_reason' => $request->reason
        ]);

        // Send email notification to client
        Mail::to($serviceRequest->user->email)->send(new QuotationRejected($serviceRequest));

        return redirect()->route('admin.rfq')->with('success', 'Service request rejected successfully.');
    }

    public function users(Request $request)
    {
        $query = User::with('technician')->orderBy('created_at', 'desc');

        // Search Filter
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        // Role Filter
        if ($request->filled('role') && $request->role !== 'all') {
            $query->where('role', $request->role);
        }

        $users = $query->paginate(10)->withQueryString();

        $stats = [
            'total' => User::count(),
            'clients' => User::where('role', 'client')->count(),
            'technicians' => User::where('role', 'technician')->count(),
            'admins' => User::where('role', 'admin')->count()
        ];

        return Inertia::render('Admin/Users', [
            'users' => $users,
            'stats' => $stats,
            'filters' => $request->only(['search', 'role'])
        ]);
    }

    public function storeUser(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
            'phone' => 'nullable|string|max:20',
            'role' => 'required|in:client,technician,admin',
            'specialization' => 'required_if:role,technician|string|max:255',
            'location' => 'required_if:role,technician|string|max:255',
            'availability' => 'nullable|in:available,busy,on_leave',
            'bio' => 'nullable|string',
            'skills' => 'nullable|array'
        ]);

        // Create user
        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'phone' => $request->phone,
            'role' => $request->role,
            'email_verified_at' => now() // Auto-verify admin created users
        ]);

        // Create technician profile if role is technician
        if ($request->role === 'technician') {
            $technicianId = 'TECH-' . str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT);

            Technician::create([
                'user_id' => $user->id,
                'technician_id' => $technicianId,
                'specialization' => $request->specialization,
                'location' => $request->location,
                'availability' => $request->availability ?? 'available',
                'bio' => $request->bio,
                'skills' => $request->skills,
                'rating' => 0,
                'total_jobs' => 0
            ]);
        }

        return redirect()->route('admin.users')->with('success', 'User created successfully!');
    }

    public function updateUser(Request $request, User $user)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,' . $user->id,
            'phone' => 'nullable|string|max:20',
            'role' => 'required|in:client,technician,admin',
            'specialization' => 'required_if:role,technician|string|max:255',
            'location' => 'required_if:role,technician|string|max:255',
            'availability' => 'nullable|in:available,busy,on_leave',
            'bio' => 'nullable|string',
            'skills' => 'nullable|array'
        ]);

        // Update user
        $user->update([
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'role' => $request->role
        ]);

        // Handle technician profile
        if ($request->role === 'technician') {
            if ($user->technician) {
                // Update existing technician
                $user->technician->update([
                    'specialization' => $request->specialization,
                    'location' => $request->location,
                    'availability' => $request->availability ?? 'available',
                    'bio' => $request->bio,
                    'skills' => $request->skills
                ]);
            } else {
                // Create new technician profile
                $technicianId = 'TECH-' . str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT);

                Technician::create([
                    'user_id' => $user->id,
                    'technician_id' => $technicianId,
                    'specialization' => $request->specialization,
                    'location' => $request->location,
                    'availability' => $request->availability ?? 'available',
                    'bio' => $request->bio,
                    'skills' => $request->skills,
                    'rating' => 0,
                    'total_jobs' => 0
                ]);
            }
        } else {
            // Remove technician profile if role changed from technician
            if ($user->technician) {
                $user->technician->delete();
            }
        }

        return redirect()->route('admin.users')->with('success', 'User updated successfully!');
    }

    public function destroyUser(User $user)
    {
        // Prevent deleting admin users
        if ($user->role === 'admin') {
            return redirect()->route('admin.users')->with('error', 'Cannot delete admin users.');
        }

        // Check for active jobs if technician
        if ($user->role === 'technician' && $user->technician) {
            $activeJobs = $user->technician->serviceRequests()->whereIn('status', ['assigned', 'in_progress'])->count();
            if ($activeJobs > 0) {
                return redirect()->route('admin.users')->with('error', 'Cannot delete technician with active jobs.');
            }
        }

        // Check for pending service requests if client
        if ($user->role === 'client') {
            $pendingRequests = $user->serviceRequests()->whereIn('status', ['pending', 'assigned', 'in_progress'])->count();
            if ($pendingRequests > 0) {
                return redirect()->route('admin.users')->with('error', 'Cannot delete client with pending service requests.');
            }
        }

        $user->delete();

        return redirect()->route('admin.users')->with('success', 'User deleted successfully!');
    }

    /**
     * Request payment from client for an approved service request.
     */
    public function requestPayment(Request $request, ServiceRequest $serviceRequest)
    {
        $request->validate([
            'percentage' => 'required|numeric|min:1|max:100',
            'notes' => 'nullable|string|max:500',
        ]);

        // Check if RFQ is approved
        if ($serviceRequest->rfq_status !== ServiceRequest::RFQ_STATUS_APPROVED) {
            return response()->json([
                'error' => 'Payment can only be requested for approved service requests.'
            ], 422);
        }

        // Check if there's already a pending payment request
        $existingPendingRequest = PaymentRequest::where('service_request_id', $serviceRequest->id)
            ->where('status', PaymentRequest::STATUS_PENDING)
            ->first();

        if ($existingPendingRequest) {
            return response()->json([
                'error' => 'There is already a pending payment request for this service.'
            ], 422);
        }

        // Calculate amount based on percentage
        $percentage = $request->percentage;
        $amount = ($percentage / 100) * $serviceRequest->quote_amount;

        // Create payment request
        $paymentRequest = PaymentRequest::create([
            'payment_request_id' => PaymentRequest::generatePaymentRequestId(),
            'service_request_id' => $serviceRequest->id,
            'user_id' => $serviceRequest->user_id,
            'requested_by' => auth()->id(),
            'percentage' => $percentage,
            'amount' => $amount,
            'status' => PaymentRequest::STATUS_PENDING,
            'notes' => $request->notes,
        ]);

        // Send notification to client
        $serviceRequest->user->notify(new PaymentRequestNotification($paymentRequest));

        return response()->json([
            'success' => true,
            'message' => 'Payment request sent successfully!',
            'payment_request' => $paymentRequest,
        ]);
    }

    // ==================== SUB-TASK MANAGEMENT ====================

    public function addSubTask(Request $request, ServiceRequest $serviceRequest)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
        ]);

        $maxOrder = $serviceRequest->subTasks()->max('order') ?? 0;

        ServiceSubTask::create([
            'service_request_id' => $serviceRequest->id,
            'title' => $request->title,
            'description' => $request->description,
            'order' => $maxOrder + 1,
        ]);

        // Mark service request as having sub-tasks
        if (!$serviceRequest->has_sub_tasks) {
            $serviceRequest->update(['has_sub_tasks' => true]);
        }

        return redirect()->route('admin.jobs.show', $serviceRequest)->with('success', 'Sub-task added successfully!');
    }

    public function updateSubTask(Request $request, ServiceSubTask $serviceSubTask)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
        ]);

        $serviceSubTask->update([
            'title' => $request->title,
            'description' => $request->description,
        ]);

        return redirect()->route('admin.jobs.show', $serviceSubTask->service_request_id)->with('success', 'Sub-task updated successfully!');
    }

    public function deleteSubTask(ServiceSubTask $serviceSubTask)
    {
        $serviceRequest = $serviceSubTask->serviceRequest;
        $serviceSubTask->delete();

        // If no sub-tasks remain, reset the flag
        if ($serviceRequest->subTasks()->count() === 0) {
            $serviceRequest->update(['has_sub_tasks' => false]);
        }

        $serviceRequest->recalculateProgress();

        return redirect()->route('admin.jobs.show', $serviceRequest)->with('success', 'Sub-task deleted successfully!');
    }

    public function assignSubTaskTechnician(Request $request, ServiceSubTask $serviceSubTask)
    {
        $request->validate([
            'technician_id' => 'required|exists:technicians,id'
        ]);

        $technician = Technician::findOrFail($request->technician_id);
        $serviceRequest = $serviceSubTask->serviceRequest;

        // Check if RFQ has been approved (if RFQ workflow is enabled)
        if ($serviceRequest->rfq_status && $serviceRequest->rfq_status !== ServiceRequest::RFQ_STATUS_APPROVED) {
            return redirect()->route('admin.jobs.show', $serviceRequest)->with('error', 'Cannot assign technician until RFQ is approved by client.');
        }

        // Assign technician to sub-task
        $serviceSubTask->update([
            'technician_id' => $technician->id,
            'status' => ServiceSubTask::STATUS_ASSIGNED,
            'assigned_at' => now(),
        ]);

        // Determine if this is the first assigned technician (becomes lead)
        $isFirstAssignment = !$serviceRequest->lead_technician_id;

        if ($isFirstAssignment) {
            $serviceRequest->update([
                'lead_technician_id' => $technician->id,
                'technician_id' => $technician->id,
                'status' => 'assigned',
                'assigned_at' => now(),
            ]);

            // Send email notifications
            if ($technician->user && $technician->user->email) {
                Mail::to($technician->user->email)->send(new JobAssigned($serviceRequest));
            }
            if ($serviceRequest->user && $serviceRequest->user->email) {
                Mail::to($serviceRequest->user->email)->send(new TechnicianAssigned($serviceRequest, $technician));
            }
        }

        // Update technician availability
        if ($technician->availability === 'available') {
            $technician->update(['availability' => 'busy']);
        }

        return redirect()->route('admin.jobs.show', $serviceRequest)->with('success', 'Technician assigned to sub-task successfully!');
    }
}
