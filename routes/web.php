<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ServiceRequestController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\ProjectManagementController;
use App\Http\Controllers\PM\PMDashboardController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\TechnicianLeadController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// ==================== PUBLIC ROUTES ====================

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

// Temporary: run database seeders via URL (REMOVE AFTER USE)
Route::get('/run-seed-9x7k2m', function () {
    Artisan::call('db:seed', ['--force' => true]);
    return response()->json([
        'status' => 'success',
        'output' => Artisan::output(),
    ]);
});

Route::get('/about', fn() => Inertia::render('About'));
Route::get('/services', fn() => Inertia::render('Services'));
Route::get('/contact', fn() => Inertia::render('Contact'));
Route::get('/ecommerce', fn() => Inertia::render('Ecommerce'));

// Public technician interest form
Route::get('/join-as-technician', [TechnicianLeadController::class, 'create'])->name('technician.interest');
Route::post('/join-as-technician', [TechnicianLeadController::class, 'store'])->name('technician.interest.store');

// M-Pesa callback route (no auth required)
Route::post('/api/mpesa/callback', [\App\Http\Controllers\PaymentController::class, 'mpesaCallback'])->name('mpesa.callback');

// M-Pesa C2B (paybill direct payments) — Safaricom posts every paybill payment here.
// URL path avoids the "mpesa" keyword because Safaricom rejects it on registration.
Route::post('/api/transactions/c2b/validation', [\App\Http\Controllers\PaymentController::class, 'c2bValidation'])->name('mpesa.c2b.validation');
Route::post('/api/transactions/c2b/confirmation', [\App\Http\Controllers\PaymentController::class, 'c2bConfirmation'])->name('mpesa.c2b.confirmation');

// ==================== AUTH DASHBOARD (Role Router) ====================

Route::get('/dashboard', [DashboardController::class, 'index'])->middleware(['auth', 'verified'])->name('dashboard');

// ==================== PROFILE ====================

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

// ==================== CLIENT ROUTES ====================

Route::middleware(['auth'])->group(function () {
    Route::get('/client/dashboard', [DashboardController::class, 'client'])->name('client.dashboard');
    Route::get('/client/new-request', [ServiceRequestController::class, 'create'])->name('client.new-request');
    Route::post('/client/service-requests', [ServiceRequestController::class, 'store'])->name('service-requests.store');
    Route::get('/client/request-status/{serviceRequest}', [ServiceRequestController::class, 'show'])->name('client.request-status');
    Route::get('/client/payments', [\App\Http\Controllers\ClientController::class, 'payments'])->name('client.payments');
    Route::get('/client/support', function () {
        return Inertia::render('Client/Support');
    })->name('client.support');
    Route::get('/client/profile', [\App\Http\Controllers\ClientController::class, 'profile'])->name('client.profile');

    // Quotation approval/decline
    Route::post('/client/quotations/{quotation}/approve', [\App\Http\Controllers\ClientController::class, 'approveQuotation'])->name('client.quotation.approve');
    Route::post('/client/quotations/{quotation}/decline', [\App\Http\Controllers\ClientController::class, 'declineQuotation'])->name('client.quotation.decline');

    // Legacy RFQ approval/decline routes
    Route::post('/client/rfq/{serviceRequest}/approve', [\App\Http\Controllers\ClientController::class, 'approveRFQ'])->name('client.rfq.approve');
    Route::post('/client/rfq/{serviceRequest}/decline', [\App\Http\Controllers\ClientController::class, 'declineRFQ'])->name('client.rfq.decline');

    // Service request progress routes
    Route::post('/client/service-request/{serviceRequest}/confirm-arrival', [\App\Http\Controllers\ClientController::class, 'confirmArrival'])->name('client.confirm-arrival');
    Route::post('/client/service-request/{serviceRequest}/confirm-completion', [\App\Http\Controllers\ClientController::class, 'confirmCompletion'])->name('client.confirm-completion');
    Route::post('/client/service-request/{serviceRequest}/rate', [\App\Http\Controllers\ClientController::class, 'rateJob'])->name('client.rate-job');

    // Client payment routes
    Route::post('/client/payments/{paymentRequest}/mpesa', [\App\Http\Controllers\PaymentController::class, 'initiateMpesa'])->name('client.payments.mpesa');
    Route::get('/client/payments/{paymentRequest}/status', [\App\Http\Controllers\PaymentController::class, 'checkMpesaStatus'])->name('client.payments.status');
    Route::post('/client/payments/{paymentRequest}/offline', [\App\Http\Controllers\PaymentController::class, 'recordOfflinePayment'])->name('client.payments.offline');

    // Client statements (redirects to payments)
    Route::get('/client/statements', function () {
        return redirect()->route('client.payments');
    })->name('client.statements');

    // Client messages
    Route::get('/client/messages', [\App\Http\Controllers\ClientController::class, 'messages'])->name('client.messages');
    Route::post('/client/conversations/{conversation}/messages', [\App\Http\Controllers\ClientController::class, 'sendMessage'])->name('client.messages.send');

    // Client notifications
    Route::get('/client/notifications', [\App\Http\Controllers\ClientController::class, 'notifications'])->name('client.notifications');
    Route::post('/notifications/{notification}/read', function ($notificationId) {
        auth()->user()->notifications()->where('id', $notificationId)->update(['read_at' => now()]);
        return back();
    })->name('notifications.read');
});

// ==================== PM ROUTES ====================

Route::middleware(['auth', 'role:project_manager'])->prefix('pm')->group(function () {
    Route::get('/dashboard', [PMDashboardController::class, 'index'])->name('pm.dashboard');

    // RFQ Management
    Route::get('/rfqs', [PMDashboardController::class, 'rfqs'])->name('pm.rfqs');
    Route::post('/rfqs/{serviceRequest}/quotation', [PMDashboardController::class, 'createQuotation'])->name('pm.quotation.create');
    Route::post('/quotations/{quotation}/revise', [PMDashboardController::class, 'reviseQuotation'])->name('pm.quotation.revise');

    // Job Management
    Route::get('/jobs', [PMDashboardController::class, 'jobs'])->name('pm.jobs');
    Route::post('/jobs/{serviceRequest}/assign', [PMDashboardController::class, 'assignTechnician'])->name('pm.jobs.assign');
    Route::post('/jobs/{serviceRequest}/suspend', [PMDashboardController::class, 'suspendJob'])->name('pm.jobs.suspend');
    Route::post('/jobs/{serviceRequest}/resume', [PMDashboardController::class, 'resumeJob'])->name('pm.jobs.resume');
    Route::post('/jobs/{serviceRequest}/reassign', [PMDashboardController::class, 'reassignJob'])->name('pm.jobs.reassign');
    Route::post('/jobs/{serviceRequest}/payment-request', [AdminDashboardController::class, 'requestPayment'])->name('pm.rfq.request-payment');

    // Progress Validation
    Route::get('/progress-reports', [PMDashboardController::class, 'progressReports'])->name('pm.progress-reports');
    Route::post('/progress-reports/{progressReport}/validate', [PMDashboardController::class, 'validateProgress'])->name('pm.progress.validate');
    Route::post('/jobs/{serviceRequest}/progress-on-behalf', [PMDashboardController::class, 'createProgressOnBehalf'])->name('pm.progress.on-behalf');

    // Technician Payment Sheets
    Route::get('/payment-sheets', [PMDashboardController::class, 'paymentSheets'])->name('pm.payment-sheets');
    Route::post('/payment-sheets', [PMDashboardController::class, 'createPaymentSheet'])->name('pm.payment-sheets.create');
    Route::post('/payment-sheets/{sheet}/finalize', [PMDashboardController::class, 'finalizePaymentSheet'])->name('pm.payment-sheets.finalize');
    Route::get('/payment-sheets/{sheet}/download', [PMDashboardController::class, 'downloadPaymentSheet'])->name('pm.payment-sheets.download');

    // Compensation Amendments
    Route::post('/jobs/{serviceRequest}/compensation-amendment', [PMDashboardController::class, 'requestCompensationAmendment'])->name('pm.compensation.request');

    // Technician Directory
    Route::get('/technicians', [PMDashboardController::class, 'technicians'])->name('pm.technicians');
    Route::post('/technicians/{technician}/documents', [AdminDashboardController::class, 'uploadTechnicianDocument'])->name('pm.technicians.documents.upload');
    Route::post('/technician-documents/{document}/verify', [AdminDashboardController::class, 'verifyTechnicianDocument'])->name('pm.technicians.documents.verify');
    Route::get('/technician-documents/{document}/download', [AdminDashboardController::class, 'showTechnicianDocument'])->name('pm.technicians.documents.show');

    // Messaging
    Route::get('/messages', [PMDashboardController::class, 'messages'])->name('pm.messages');

    // Reports
    Route::get('/reports', [PMDashboardController::class, 'reports'])->name('pm.reports');
    Route::get('/reports/rfq-revenue', [PMDashboardController::class, 'rfqRevenueReport'])->name('pm.reports.rfq');
    Route::get('/reports/client-revenue', [PMDashboardController::class, 'clientRevenueReport'])->name('pm.reports.client');
    Route::get('/reports/rfq-revenue/export/{format}', [PMDashboardController::class, 'exportRfqRevenueReport'])->name('pm.reports.rfq.export');
    Route::get('/reports/client-revenue/export/{format}', [PMDashboardController::class, 'exportClientRevenueReport'])->name('pm.reports.client.export');
});

// ==================== ADMIN ROUTES ====================

Route::middleware(['auth', 'role:admin'])->prefix('admin')->group(function () {
    Route::get('/dashboard', [AdminDashboardController::class, 'index'])->name('admin.dashboard');

    // Technician management
    Route::get('/technicians', [AdminDashboardController::class, 'technicians'])->name('admin.technicians');
    Route::post('/technicians', [AdminDashboardController::class, 'storeTechnician'])->name('admin.technicians.store');
    Route::put('/technicians/{technician}', [AdminDashboardController::class, 'updateTechnician'])->name('admin.technicians.update');
    Route::delete('/technicians/{technician}', [AdminDashboardController::class, 'destroyTechnician'])->name('admin.technicians.destroy');
    Route::post('/technicians/{technician}/approve', [AdminDashboardController::class, 'approveTechnician'])->name('admin.technicians.approve');
    Route::post('/technicians/{technician}/reject', [AdminDashboardController::class, 'rejectTechnician'])->name('admin.technicians.reject');
    Route::get('/technicians/{technician}/report', [AdminDashboardController::class, 'technicianReport'])->name('admin.technicians.report');
    Route::post('/technicians/{technician}/documents', [AdminDashboardController::class, 'uploadTechnicianDocument'])->name('admin.technicians.documents.upload');
    Route::post('/technician-documents/{document}/verify', [AdminDashboardController::class, 'verifyTechnicianDocument'])->name('admin.technicians.documents.verify');
    Route::get('/technician-documents/{document}/download', [AdminDashboardController::class, 'showTechnicianDocument'])->name('admin.technicians.documents.show');

    // Job management
    Route::get('/jobs', [AdminDashboardController::class, 'jobs'])->name('admin.jobs');
    Route::get('/jobs/{serviceRequest}', [AdminDashboardController::class, 'showJob'])->name('admin.jobs.show');
    Route::post('/jobs/{serviceRequest}/assign', [AdminDashboardController::class, 'assignTechnician'])->name('admin.jobs.assign');
    Route::post('/jobs/{serviceRequest}/assign-lead', [AdminDashboardController::class, 'assignLeadTechnician'])->name('admin.jobs.assign-lead');
    Route::post('/jobs/{serviceRequest}/assignment-fee', [AdminDashboardController::class, 'updateAssignmentCompensation'])->name('admin.jobs.assignment.fee');
    Route::post('/progress-reports/{progressReport}/validate', [AdminDashboardController::class, 'validateProgress'])->name('admin.progress.validate');

    // Payment Milestones
    Route::post('/jobs/{serviceRequest}/milestones', [AdminDashboardController::class, 'storeMilestone'])->name('admin.milestones.store');
    Route::put('/milestones/{milestone}', [AdminDashboardController::class, 'updateMilestone'])->name('admin.milestones.update');
    Route::delete('/milestones/{milestone}', [AdminDashboardController::class, 'destroyMilestone'])->name('admin.milestones.destroy');

    Route::get('/payments', [AdminDashboardController::class, 'payments'])->name('admin.payments');

    // Payment Approvals (offline payments)
    Route::post('/payments/{payment}/approve', [\App\Http\Controllers\PaymentController::class, 'approveOfflinePayment'])->name('admin.payments.approve');
    Route::post('/payments/{payment}/reject', [\App\Http\Controllers\PaymentController::class, 'rejectOfflinePayment'])->name('admin.payments.reject');
    Route::post('/payments/{paymentRequest}/confirm', [\App\Http\Controllers\PaymentController::class, 'confirmOfflinePayment'])->name('admin.payments.confirm');

    // Compensation Amendment Approval
    Route::post('/compensation-amendments/{amendment}/approve', [AdminDashboardController::class, 'approveCompensationAmendment'])->name('admin.compensation.approve');
    Route::post('/compensation-amendments/{amendment}/reject', [AdminDashboardController::class, 'rejectCompensationAmendment'])->name('admin.compensation.reject');

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

    // Service Categories
    Route::get('/categories', [AdminDashboardController::class, 'categories'])->name('admin.categories');
    Route::post('/categories', [AdminDashboardController::class, 'storeCategory'])->name('admin.categories.store');
    Route::put('/categories/{serviceCategory}', [AdminDashboardController::class, 'updateCategory'])->name('admin.categories.update');
    Route::delete('/categories/{serviceCategory}', [AdminDashboardController::class, 'destroyCategory'])->name('admin.categories.destroy');
});

// Tools management (accessible by Admin and Storeman)
Route::middleware(['auth', 'role:admin,storeman'])->prefix('admin')->group(function () {
    Route::get('/tools', [AdminDashboardController::class, 'tools'])->name('admin.tools');
    Route::post('/tools', [AdminDashboardController::class, 'storeTool'])->name('admin.tools.store');
    Route::put('/tools/{tool}', [AdminDashboardController::class, 'updateTool'])->name('admin.tools.update');
    Route::delete('/tools/{tool}', [AdminDashboardController::class, 'destroyTool'])->name('admin.tools.destroy');
    Route::post('/tools/{tool}/assign', [AdminDashboardController::class, 'assignTool'])->name('admin.tools.assign');
    Route::post('/tools/{tool}/return', [AdminDashboardController::class, 'returnTool'])->name('admin.tools.return');
    Route::post('/tool-requests/{toolRequestItem}/approve', [AdminDashboardController::class, 'approveToolRequestItem'])->name('admin.tool-requests.approve');
    Route::post('/tool-requests/{toolRequestItem}/reject', [AdminDashboardController::class, 'rejectToolRequestItem'])->name('admin.tool-requests.reject');
});

Route::middleware(['auth', 'role:admin'])->prefix('admin')->group(function () {
    // User Management
    Route::get('/users', [AdminDashboardController::class, 'users'])->name('admin.users');
    Route::post('/users', [AdminDashboardController::class, 'storeUser'])->name('admin.users.store');
    Route::put('/users/{user}', [AdminDashboardController::class, 'updateUser'])->name('admin.users.update');
    Route::delete('/users/{user}', [AdminDashboardController::class, 'destroyUser'])->name('admin.users.destroy');

    // RFQ Management
    Route::redirect('/rfqs', '/admin/rfq');
    Route::get('/rfq/create', [AdminDashboardController::class, 'createAdminAssistedRfq'])->name('admin.rfq.create');
    Route::post('/rfq/create', [AdminDashboardController::class, 'storeAdminAssistedRfq'])->name('admin.rfq.store-assisted');
    Route::get('/rfq', [AdminDashboardController::class, 'rfq'])->name('admin.rfq');
    Route::post('/rfq/quote', [AdminDashboardController::class, 'submitQuote'])->name('admin.rfq.quote');
    Route::post('/rfq/{serviceRequest}/approve-on-behalf', [AdminDashboardController::class, 'approveRfqOnBehalf'])->name('admin.rfq.approve-on-behalf');
    Route::post('/rfq/{serviceRequest}/reject', [AdminDashboardController::class, 'rejectRFQ'])->name('admin.rfq.reject');
    Route::post('/rfq/{serviceRequest}/assign-pm', [AdminDashboardController::class, 'assignPm'])->name('admin.rfq.assign-pm');
    Route::post('/rfq/{serviceRequest}/request-payment', [AdminDashboardController::class, 'requestPayment'])->name('admin.rfq.request-payment');
    Route::post('/rfq/{serviceRequest}/confirm-payment-on-behalf', [AdminDashboardController::class, 'confirmPaymentOnBehalf'])->name('admin.rfq.confirm-payment-on-behalf');

    // Reports
    Route::get('/reports', [AdminDashboardController::class, 'reports'])->name('admin.reports');
    Route::get('/reports/rfq-revenue', [AdminDashboardController::class, 'rfqRevenueReport'])->name('admin.reports.rfq');
    Route::get('/reports/client-revenue', [AdminDashboardController::class, 'clientRevenueReport'])->name('admin.reports.client');
    Route::get('/reports/rfq-revenue/export/{format}', [AdminDashboardController::class, 'exportRfqRevenueReport'])->name('admin.reports.rfq.export');
    Route::get('/reports/client-revenue/export/{format}', [AdminDashboardController::class, 'exportClientRevenueReport'])->name('admin.reports.client.export');

    // Audit Logs
    Route::get('/audit-logs', [AdminDashboardController::class, 'auditLogs'])->name('admin.audit-logs');

    // M-Pesa Transactions
    Route::resource('mpesa-transactions', \App\Http\Controllers\Admin\MpesaTransactionController::class)->except(['create', 'store']);
    Route::post('mpesa-transactions/{mpesaTransaction}/reconcile', [\App\Http\Controllers\Admin\MpesaTransactionController::class, 'reconcile'])->name('admin.mpesa-transactions.reconcile');
    Route::post('mpesa/register-c2b-urls', [\App\Http\Controllers\Admin\MpesaTransactionController::class, 'registerC2BUrls'])->name('admin.mpesa.register-c2b');
    Route::get('mpesa/diagnose', [\App\Http\Controllers\Admin\MpesaTransactionController::class, 'diagnoseMpesa'])->name('admin.mpesa.diagnose');

    // Technician Leads
    Route::get('/technician-leads', [AdminDashboardController::class, 'technicianLeads'])->name('admin.technician-leads');

    // ==================== PROJECT MANAGEMENT ROUTES ====================
    Route::get('/projects/dashboard', [ProjectManagementController::class, 'dashboard'])->name('admin.projects.dashboard');
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
    Route::post('/tasks/{task}/comments', [ProjectManagementController::class, 'storeComment'])->name('admin.tasks.comments.store');
    Route::delete('/comments/{comment}', [ProjectManagementController::class, 'destroyComment'])->name('admin.comments.destroy');
    Route::post('/comments/{comment}/reaction', [ProjectManagementController::class, 'toggleCommentReaction'])->name('admin.comments.reaction');

    // File Upload
    Route::post('/projects/upload-file', [ProjectManagementController::class, 'storeFile'])->name('admin.files.store');
    Route::delete('/projects/files/{file}', [ProjectManagementController::class, 'destroyFile'])->name('admin.files.destroy');
    Route::get('/projects/files/{file}/download', [ProjectManagementController::class, 'downloadFile'])->name('admin.projects.files.download');

    // Dependencies
    Route::post('/tasks/{task}/dependencies', [ProjectManagementController::class, 'storeDependency'])->name('admin.tasks.dependencies.store');
    Route::delete('/tasks/dependencies/{dependency}', [ProjectManagementController::class, 'destroyDependency'])->name('admin.tasks.dependencies.destroy');
    Route::get('/tasks/search', [ProjectManagementController::class, 'searchTasks'])->name('admin.tasks.search');

    // Service Request Integration
    Route::post('/service-requests/{serviceRequest}/convert', [ProjectManagementController::class, 'convertServiceRequest'])->name('admin.service-requests.convert');

    // Sub-Task Management
    Route::post('/jobs/{serviceRequest}/sub-tasks', [AdminDashboardController::class, 'addSubTask'])->name('admin.sub-tasks.store');
    Route::put('/sub-tasks/{serviceSubTask}', [AdminDashboardController::class, 'updateSubTask'])->name('admin.sub-tasks.update');
    Route::delete('/sub-tasks/{serviceSubTask}', [AdminDashboardController::class, 'deleteSubTask'])->name('admin.sub-tasks.destroy');
    Route::post('/sub-tasks/{serviceSubTask}/assign', [AdminDashboardController::class, 'assignSubTaskTechnician'])->name('admin.sub-tasks.assign');

    // Payment Processing (Technician Payment Sheets)
    Route::get('/payment-processing', [\App\Http\Controllers\Admin\PaymentProcessingController::class, 'index'])->name('admin.payment-processing.index');
    Route::post('/payment-processing', [\App\Http\Controllers\Admin\PaymentProcessingController::class, 'store'])->name('admin.payment-processing.store');
    // API helpers (must be before the {sheet} wildcard)
    Route::get('/payment-processing/api/technicians-for-job', [\App\Http\Controllers\Admin\PaymentProcessingController::class, 'techniciansForJob'])->name('admin.payment-processing.technicians-for-job');
    Route::get('/payment-processing/api/compute-amounts', [\App\Http\Controllers\Admin\PaymentProcessingController::class, 'computeAmounts'])->name('admin.payment-processing.compute-amounts');
    Route::get('/payment-processing/{sheet}', [\App\Http\Controllers\Admin\PaymentProcessingController::class, 'show'])->name('admin.payment-processing.show');
    Route::get('/payment-processing/{sheet}/download', [\App\Http\Controllers\Admin\PaymentProcessingController::class, 'download'])->name('admin.payment-processing.download');

    // Budget & Payment Management
    Route::post('/jobs/{serviceRequest}/budget', [\App\Http\Controllers\Admin\AdminPaymentController::class, 'storeBudget'])->name('admin.budget.store');
    Route::put('/budgets/{budget}', [\App\Http\Controllers\Admin\AdminPaymentController::class, 'updateBudget'])->name('admin.budget.update');
    Route::post('/technician-payments', [\App\Http\Controllers\Admin\AdminPaymentController::class, 'storeTechnicianPayment'])->name('admin.technician-payments.store');
    Route::put('/technician-payments/{technicianPayment}', [\App\Http\Controllers\Admin\AdminPaymentController::class, 'updateTechnicianPayment'])->name('admin.technician-payments.update');
    Route::post('/progress-reports/{progressReport}/pay-technician', [\App\Http\Controllers\Admin\AdminPaymentController::class, 'payApprovedProgressReport'])->name('admin.progress.pay-technician');
    Route::post('/expenditures', [\App\Http\Controllers\Admin\AdminPaymentController::class, 'storeExpenditure'])->name('admin.expenditures.store');
    Route::put('/expenditures/{expenditure}', [\App\Http\Controllers\Admin\AdminPaymentController::class, 'updateExpenditure'])->name('admin.expenditures.update');
    Route::delete('/expenditures/{expenditure}', [\App\Http\Controllers\Admin\AdminPaymentController::class, 'destroyExpenditure'])->name('admin.expenditures.destroy');
});

// ==================== TECHNICIAN ROUTES ====================

Route::middleware(['auth', 'role:technician'])->group(function () {
    Route::get('/technician/dashboard', [\App\Http\Controllers\TechnicianController::class, 'dashboard'])->name('technician.dashboard');
    Route::get('/technician/jobs', [\App\Http\Controllers\TechnicianController::class, 'jobs'])->name('technician.jobs');
    Route::get('/technician/jobs/{serviceRequest}', [\App\Http\Controllers\TechnicianController::class, 'show'])->name('technician.jobs.show');
    Route::get('/technician/tools', [\App\Http\Controllers\TechnicianController::class, 'tools'])->name('technician.tools');
    Route::get('/technician/profile', [\App\Http\Controllers\TechnicianController::class, 'profile'])->name('technician.profile');
    Route::post('/technician/profile/update', [\App\Http\Controllers\TechnicianController::class, 'updateProfile'])->name('technician.profile.update');
    Route::post('/technician/profile/document', [\App\Http\Controllers\TechnicianController::class, 'uploadDocument'])->name('technician.profile.document');
    Route::post('/technician/availability', [\App\Http\Controllers\TechnicianController::class, 'updateAvailability'])->name('technician.availability');
    Route::post('/technician/jobs/{serviceRequest}/status', [\App\Http\Controllers\TechnicianController::class, 'updateJobStatus'])->name('technician.jobs.status');
    Route::post('/technician/tools/{tool}/return', [\App\Http\Controllers\TechnicianController::class, 'returnTool'])->name('technician.tools.return');
    Route::post('/technician/tool-requests', [\App\Http\Controllers\TechnicianController::class, 'storeToolRequest'])->name('technician.tool-requests.store');
    Route::post('/technician/tool-requests/{toolRequest}/cancel', [\App\Http\Controllers\TechnicianController::class, 'cancelToolRequest'])->name('technician.tool-requests.cancel');
    Route::post('/technician/sub-tasks/{serviceSubTask}/progress', [\App\Http\Controllers\TechnicianController::class, 'updateSubTaskProgress'])->name('technician.sub-tasks.progress');

    // Progress reports
    Route::post('/technician/jobs/{serviceRequest}/progress-report', [\App\Http\Controllers\TechnicianController::class, 'submitProgressReport'])->name('technician.progress-report');

    // Earnings
    Route::get('/technician/earnings', [\App\Http\Controllers\TechnicianController::class, 'earnings'])->name('technician.earnings');
});

// ==================== REQUISITION MANAGEMENT (Multi-Role) ====================
Route::middleware(['auth'])->group(function () {
    Route::get('/admin/requisitions', [\App\Http\Controllers\Admin\RequisitionController::class, 'index'])->name('admin.requisitions.index');
    Route::post('/admin/requisitions', [\App\Http\Controllers\Admin\RequisitionController::class, 'store'])->name('admin.requisitions.store');
    Route::post('/admin/requisitions/items/{item}', [\App\Http\Controllers\Admin\RequisitionController::class, 'updateItem'])->name('admin.requisitions.items.update');
    Route::post('/admin/requisitions/items/{item}/acknowledge', [\App\Http\Controllers\Admin\RequisitionController::class, 'acknowledgeItem'])->name('admin.requisitions.items.acknowledge');
});

require __DIR__ . '/auth.php';
