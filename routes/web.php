<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ServiceRequestController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\ProjectManagementController;
use App\Http\Controllers\ProfileController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

// About, Services, Contact pages
Route::get('/about', function () {
    return Inertia::render('About');
});

Route::get('/services', function () {
    return Inertia::render('Services');
});

Route::get('/contact', function () {
    return Inertia::render('Contact');
});

Route::get('/ecommerce', function () {
    return Inertia::render('Ecommerce');
});

Route::get('/dashboard', [DashboardController::class, 'index'])->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

// Client routes (protected by auth middleware)
Route::middleware(['auth'])->group(function () {
    Route::get('/client/dashboard', [DashboardController::class, 'client'])->name('client.dashboard');
    Route::get('/client/new-request', [ServiceRequestController::class, 'create'])->name('client.new-request');
    Route::post('/client/service-requests', [ServiceRequestController::class, 'store'])->name('service-requests.store');
    Route::get('/client/request-status/{serviceRequest}', [ServiceRequestController::class, 'show'])->name('client.request-status');
    Route::get('/client/payments', function () {
        return Inertia::render('Client/Payments');
    })->name('client.payments');
    Route::get('/client/support', function () {
        return Inertia::render('Client/Support');
    })->name('client.support');
    Route::get('/client/profile', function () {
        return Inertia::render('Client/Profile');
    })->name('client.profile');

    // RFQ approval/decline routes
    Route::post('/client/rfq/{serviceRequest}/approve', [\App\Http\Controllers\ClientController::class, 'approveRFQ'])->name('client.rfq.approve');
    Route::post('/client/rfq/{serviceRequest}/decline', [\App\Http\Controllers\ClientController::class, 'declineRFQ'])->name('client.rfq.decline');

    // Service request progress routes
    Route::post('/client/service-request/{serviceRequest}/confirm-arrival', [\App\Http\Controllers\ClientController::class, 'confirmArrival'])->name('client.confirm-arrival');
    Route::post('/client/service-request/{serviceRequest}/confirm-completion', [\App\Http\Controllers\ClientController::class, 'confirmCompletion'])->name('client.confirm-completion');

    // Client payment routes
    Route::post('/client/payments/{paymentRequest}/mpesa', [\App\Http\Controllers\PaymentController::class, 'initiateMpesa'])->name('client.payments.mpesa');
    Route::get('/client/payments/{paymentRequest}/status', [\App\Http\Controllers\PaymentController::class, 'checkMpesaStatus'])->name('client.payments.status');
    Route::post('/client/payments/{paymentRequest}/offline', [\App\Http\Controllers\PaymentController::class, 'recordOfflinePayment'])->name('client.payments.offline');
});

// M-Pesa callback route (no auth required)
Route::post('/api/mpesa/callback', [\App\Http\Controllers\PaymentController::class, 'mpesaCallback'])->name('mpesa.callback');

// Admin routes (protected by auth and role middleware)
Route::middleware(['auth', 'role:admin'])->prefix('admin')->group(function () {
    Route::get('/dashboard', [AdminDashboardController::class, 'index'])->name('admin.dashboard');

    // Technician management
    Route::get('/technicians', [AdminDashboardController::class, 'technicians'])->name('admin.technicians');
    Route::post('/technicians', [AdminDashboardController::class, 'storeTechnician'])->name('admin.technicians.store');
    Route::put('/technicians/{technician}', [AdminDashboardController::class, 'updateTechnician'])->name('admin.technicians.update');
    Route::delete('/technicians/{technician}', [AdminDashboardController::class, 'destroyTechnician'])->name('admin.technicians.destroy');

    // Job management
    Route::get('/jobs', [AdminDashboardController::class, 'jobs'])->name('admin.jobs');
    Route::get('/jobs/{serviceRequest}', [AdminDashboardController::class, 'showJob'])->name('admin.jobs.show');
    Route::post('/jobs/{serviceRequest}/assign', [AdminDashboardController::class, 'assignTechnician'])->name('admin.jobs.assign');

    // Tools management
    Route::get('/tools', [AdminDashboardController::class, 'tools'])->name('admin.tools');
    Route::post('/tools', [AdminDashboardController::class, 'storeTool'])->name('admin.tools.store');
    Route::put('/tools/{tool}', [AdminDashboardController::class, 'updateTool'])->name('admin.tools.update');
    Route::delete('/tools/{tool}', [AdminDashboardController::class, 'destroyTool'])->name('admin.tools.destroy');
    Route::post('/tools/{tool}/assign', [AdminDashboardController::class, 'assignTool'])->name('admin.tools.assign');
    Route::post('/tools/{tool}/return', [AdminDashboardController::class, 'returnTool'])->name('admin.tools.return');

    Route::get('/payments', [AdminDashboardController::class, 'payments'])->name('admin.payments');

    // Technician Performance & Analytics
    Route::get('/technician-performance', [\App\Http\Controllers\Admin\TechnicianPerformanceController::class, 'index'])->name('admin.technician-performance.index');
    Route::get('/technician-performance/{technician}', [\App\Http\Controllers\Admin\TechnicianPerformanceController::class, 'show'])->name('admin.technician-performance.show');
    Route::get('/api/technician-performance/{technician}', [\App\Http\Controllers\Admin\TechnicianPerformanceController::class, 'getPerformance'])->name('api.technician-performance.show');
    Route::get('/api/technician-performance', [\App\Http\Controllers\Admin\TechnicianPerformanceController::class, 'getAllPerformance'])->name('api.technician-performance.index');
    Route::get('/api/technician-recommendations', [\App\Http\Controllers\Admin\TechnicianPerformanceController::class, 'getRecommendations'])->name('api.technician-recommendations');
    Route::get('/api/technician/{technician}/match-score', [\App\Http\Controllers\Admin\TechnicianPerformanceController::class, 'getMatchScore'])->name('api.technician-match-score');

    // PM Performance & Analytics
    Route::get('/pm-performance', [\App\Http\Controllers\Admin\PMPerformanceController::class, 'index'])->name('admin.pm-performance.index');
    Route::get('/pm-performance/{manager}', [\App\Http\Controllers\Admin\PMPerformanceController::class, 'show'])->name('admin.pm-performance.show');
    Route::get('/api/pm-performance/{manager}', [\App\Http\Controllers\Admin\PMPerformanceController::class, 'getPerformance'])->name('api.pm-performance.show');
    Route::get('/api/pm-performance', [\App\Http\Controllers\Admin\PMPerformanceController::class, 'getAllPerformance'])->name('api.pm-performance.index');
    Route::get('/api/pm-top-performers', [\App\Http\Controllers\Admin\PMPerformanceController::class, 'getTopPerformers'])->name('api.pm-top-performers');

    // User Management
    Route::get('/users', [AdminDashboardController::class, 'users'])->name('admin.users');
    Route::post('/users', [AdminDashboardController::class, 'storeUser'])->name('admin.users.store');
    Route::put('/users/{user}', [AdminDashboardController::class, 'updateUser'])->name('admin.users.update');
    Route::delete('/users/{user}', [AdminDashboardController::class, 'destroyUser'])->name('admin.users.destroy');

    // RFQ Management
    Route::get('/rfq', [AdminDashboardController::class, 'rfq'])->name('admin.rfq');
    Route::post('/rfq/quote', [AdminDashboardController::class, 'submitQuote'])->name('admin.rfq.quote');
    Route::post('/rfq/{serviceRequest}/reject', [AdminDashboardController::class, 'rejectRFQ'])->name('admin.rfq.reject');
    Route::post('/rfq/{serviceRequest}/request-payment', [AdminDashboardController::class, 'requestPayment'])->name('admin.rfq.request-payment');

    // Payment confirmation (admin)
    Route::post('/payments/{paymentRequest}/confirm', [\App\Http\Controllers\PaymentController::class, 'confirmOfflinePayment'])->name('admin.payments.confirm');

    // ==================== PROJECT MANAGEMENT ROUTES ====================
    // Project Dashboard
    Route::get('/projects/dashboard', [ProjectManagementController::class, 'dashboard'])->name('admin.projects.dashboard');

    // Project CRUD
    Route::get('/projects', [ProjectManagementController::class, 'index'])->name('admin.projects');
    Route::post('/projects', [ProjectManagementController::class, 'store'])->name('admin.projects.store');
    Route::get('/projects/{project}', [ProjectManagementController::class, 'show'])->name('admin.projects.show');
    Route::put('/projects/{project}', [ProjectManagementController::class, 'update'])->name('admin.projects.update');
    Route::delete('/projects/{project}', [ProjectManagementController::class, 'destroy'])->name('admin.projects.destroy');

    // Task Management
    Route::post('/projects/{project}/tasks', [ProjectManagementController::class, 'storeTask'])->name('admin.projects.tasks.store');
    Route::put('/tasks/{task}', [ProjectManagementController::class, 'updateTask'])->name('admin.tasks.update');
    Route::delete('/tasks/{task}', [ProjectManagementController::class, 'destroyTask'])->name('admin.tasks.destroy');

    // Kanban & Gantt
    Route::get('/projects/{project}/kanban', [ProjectManagementController::class, 'kanban'])->name('admin.projects.kanban');
    Route::post('/tasks/{task}/move', [ProjectManagementController::class, 'moveTask'])->name('admin.tasks.move');
    Route::get('/projects/{project}/gantt', [ProjectManagementController::class, 'gantt'])->name('admin.projects.gantt');

    // Time Tracking
    Route::post('/tasks/{task}/timer/start', [ProjectManagementController::class, 'startTimer'])->name('admin.tasks.timer.start');
    Route::post('/time-logs/{timeLog}/stop', [ProjectManagementController::class, 'stopTimer'])->name('admin.time-logs.stop');
    Route::get('/projects/timesheets', [ProjectManagementController::class, 'timesheets'])->name('admin.projects.timesheets');

    // Comments & Files
    // Comments & Files
    Route::post('/tasks/{task}/comments', [ProjectManagementController::class, 'storeComment'])->name('admin.tasks.comments.store');
    Route::delete('/comments/{comment}', [ProjectManagementController::class, 'destroyComment'])->name('admin.comments.destroy');
    Route::post('/comments/{comment}/reaction', [ProjectManagementController::class, 'toggleCommentReaction'])->name('admin.comments.reaction');

    // File Upload (Generic)
    Route::post('/projects/upload-file', [ProjectManagementController::class, 'storeFile'])->name('admin.files.store');
    Route::delete('/projects/files/{file}', [ProjectManagementController::class, 'destroyFile'])->name('admin.files.destroy');
    Route::get('/projects/files/{file}/download', [ProjectManagementController::class, 'downloadFile'])->name('admin.projects.files.download');

    // Dependencies
    Route::post('/tasks/{task}/dependencies', [ProjectManagementController::class, 'storeDependency'])->name('admin.tasks.dependencies.store');
    Route::delete('/tasks/dependencies/{dependency}', [ProjectManagementController::class, 'destroyDependency'])->name('admin.tasks.dependencies.destroy');

    // Search for linking
    Route::get('/tasks/search', [ProjectManagementController::class, 'searchTasks'])->name('admin.tasks.search');

    // Service Request Integration
    Route::post('/service-requests/{serviceRequest}/convert', [ProjectManagementController::class, 'convertServiceRequest'])->name('admin.service-requests.convert');

    // Sub-Task Management
    Route::post('/jobs/{serviceRequest}/sub-tasks', [AdminDashboardController::class, 'addSubTask'])->name('admin.sub-tasks.store');
    Route::put('/sub-tasks/{serviceSubTask}', [AdminDashboardController::class, 'updateSubTask'])->name('admin.sub-tasks.update');
    Route::delete('/sub-tasks/{serviceSubTask}', [AdminDashboardController::class, 'deleteSubTask'])->name('admin.sub-tasks.destroy');
    Route::post('/sub-tasks/{serviceSubTask}/assign', [AdminDashboardController::class, 'assignSubTaskTechnician'])->name('admin.sub-tasks.assign');

    // Budget & Payment Management
    Route::post('/jobs/{serviceRequest}/budget', [\App\Http\Controllers\Admin\AdminPaymentController::class, 'storeBudget'])->name('admin.budget.store');
    Route::put('/budgets/{budget}', [\App\Http\Controllers\Admin\AdminPaymentController::class, 'updateBudget'])->name('admin.budget.update');
    Route::post('/technician-payments', [\App\Http\Controllers\Admin\AdminPaymentController::class, 'storeTechnicianPayment'])->name('admin.technician-payments.store');
    Route::put('/technician-payments/{technicianPayment}', [\App\Http\Controllers\Admin\AdminPaymentController::class, 'updateTechnicianPayment'])->name('admin.technician-payments.update');
    Route::post('/expenditures', [\App\Http\Controllers\Admin\AdminPaymentController::class, 'storeExpenditure'])->name('admin.expenditures.store');
    Route::put('/expenditures/{expenditure}', [\App\Http\Controllers\Admin\AdminPaymentController::class, 'updateExpenditure'])->name('admin.expenditures.update');
    Route::delete('/expenditures/{expenditure}', [\App\Http\Controllers\Admin\AdminPaymentController::class, 'destroyExpenditure'])->name('admin.expenditures.destroy');

});

// Technician routes
Route::middleware(['auth', 'role:technician'])->group(function () {
    Route::get('/technician/dashboard', [\App\Http\Controllers\TechnicianController::class, 'dashboard'])->name('technician.dashboard');
    Route::get('/technician/jobs', [\App\Http\Controllers\TechnicianController::class, 'jobs'])->name('technician.jobs');
    Route::get('/technician/jobs/{serviceRequest}', [\App\Http\Controllers\TechnicianController::class, 'show'])->name('technician.jobs.show');
    Route::get('/technician/tools', [\App\Http\Controllers\TechnicianController::class, 'tools'])->name('technician.tools');
    Route::get('/technician/profile', [\App\Http\Controllers\TechnicianController::class, 'profile'])->name('technician.profile');
    Route::post('/technician/availability', [\App\Http\Controllers\TechnicianController::class, 'updateAvailability'])->name('technician.availability');
    Route::post('/technician/jobs/{serviceRequest}/status', [\App\Http\Controllers\TechnicianController::class, 'updateJobStatus'])->name('technician.jobs.status');
    Route::post('/technician/tools/{tool}/return', [\App\Http\Controllers\TechnicianController::class, 'returnTool'])->name('technician.tools.return');
    Route::post('/technician/sub-tasks/{serviceSubTask}/progress', [\App\Http\Controllers\TechnicianController::class, 'updateSubTaskProgress'])->name('technician.sub-tasks.progress');
});

// ==================== REQUISITION MANAGEMENT (Multi-Role) ====================
// Accessible by Admin, Foreman, Office, Procurement, Accounts
Route::middleware(['auth'])->group(function () {
    Route::get('/admin/requisitions', [\App\Http\Controllers\Admin\RequisitionController::class, 'index'])->name('admin.requisitions.index');
    Route::post('/admin/requisitions', [\App\Http\Controllers\Admin\RequisitionController::class, 'store'])->name('admin.requisitions.store');
    Route::post('/admin/requisitions/items/{item}', [\App\Http\Controllers\Admin\RequisitionController::class, 'updateItem'])->name('admin.requisitions.items.update');
    Route::post('/admin/requisitions/items/{item}/acknowledge', [\App\Http\Controllers\Admin\RequisitionController::class, 'acknowledgeItem'])->name('admin.requisitions.items.acknowledge');
});

require __DIR__ . '/auth.php';
