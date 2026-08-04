<?php

namespace Tests\Feature;

use App\Models\Technician;
use App\Models\TechnicianDocument;
use App\Models\JobAssignment;
use App\Models\ProgressReport;
use App\Models\ServiceCategory;
use App\Models\ServiceRequest;
use App\Models\ServiceRequestBudget;
use App\Models\ServiceSubTask;
use App\Models\User;
use App\Models\TechnicianPayment;
use App\Models\TechnicianPaymentEntry;
use App\Models\TechnicianPaymentSheet;
use App\Services\ReportingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TechnicianDocumentsWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_cannot_create_technician_without_required_documents(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
        ]);

        $response = $this->actingAs($admin)->post(route('admin.technicians.store'), [
            'name' => 'Jane Technician',
            'email' => 'jane@example.com',
            'password' => 'password123',
            'specialization' => 'Electrical Installations',
            'location' => 'Nairobi',
            'availability' => 'available',
        ]);

        $response->assertSessionHasErrors([
            'doc_nca_license',
            'doc_tertiary_cert',
            'doc_id_card',
            'doc_passport_photo',
            'doc_kra_pin',
        ]);
    }

    public function test_pm_can_upload_and_verify_technician_documents(): void
    {
        Storage::fake('public');

        $pm = User::factory()->create([
            'role' => User::ROLE_PROJECT_MANAGER,
        ]);

        $technicianUser = User::factory()->create([
            'role' => User::ROLE_TECHNICIAN,
        ]);

        $technician = Technician::create([
            'user_id' => $technicianUser->id,
            'technician_id' => 'TECH-200',
            'specialization' => 'Plumbing',
            'location' => 'Kisumu',
            'availability' => 'available',
        ]);

        $uploadResponse = $this->actingAs($pm)->post(route('pm.technicians.documents.upload', $technician), [
            'document_type' => TechnicianDocument::TYPE_NCA_LICENSE,
            'document' => UploadedFile::fake()->create('nca-license.pdf', 120, 'application/pdf'),
        ]);

        $uploadResponse->assertRedirect();

        $document = TechnicianDocument::first();

        $this->assertNotNull($document);
        $this->assertSame(TechnicianDocument::TYPE_NCA_LICENSE, $document->document_type);
        $this->assertFalse($document->verified);
        Storage::disk('public')->assertExists($document->file_path);

        $verifyResponse = $this->actingAs($pm)->post(route('pm.technicians.documents.verify', $document), [
            'action' => 'approve',
        ]);

        $verifyResponse->assertRedirect();

        $document->refresh();

        $this->assertTrue($document->verified);
        $this->assertSame($pm->id, $document->verified_by);
        $this->assertNotNull($document->verified_at);
    }

    public function test_technician_can_update_profile_and_replace_passport_photo_document(): void
    {
        Storage::fake('public');

        $user = User::factory()->create([
            'role' => User::ROLE_TECHNICIAN,
        ]);

        $technician = Technician::create([
            'user_id' => $user->id,
            'technician_id' => 'TECH-300',
            'specialization' => 'General Repairs',
            'trade' => Technician::TRADE_GENERAL,
            'location' => 'Nairobi',
            'availability' => 'available',
            'bio' => 'Original bio',
        ]);

        $existingPath = UploadedFile::fake()->image('old-passport.jpg')->store("technician-documents/{$technician->id}", 'public');

        $existingDocument = TechnicianDocument::create([
            'technician_id' => $technician->id,
            'document_type' => TechnicianDocument::TYPE_PASSPORT_PHOTO,
            'file_path' => $existingPath,
            'file_name' => 'old-passport.jpg',
            'verified' => true,
            'verified_by' => $user->id,
            'verified_at' => now(),
        ]);

        $profileResponse = $this->actingAs($user)->post(route('technician.profile.update'), [
            'name' => 'Updated Technician',
            'phone' => '0712345678',
            'location' => 'Mombasa',
            'trade' => Technician::TRADE_ELECTRICIAN,
            'specialization' => 'Solar Installations',
            'bio' => 'Updated bio',
            'experience_narrative' => '5 years in field service.',
            'skills' => ['Wiring', 'Solar'],
            'profile_photo' => UploadedFile::fake()->image('profile.jpg'),
        ]);

        $profileResponse->assertRedirect();

        $technician->refresh();
        $user->refresh();

        // Low-risk fields apply immediately.
        $this->assertSame('0712345678', $user->phone);
        $this->assertNotNull($technician->profile_photo_path);
        Storage::disk('public')->assertExists($technician->profile_photo_path);

        // Skills and bio are approval-gated: they are queued for an admin,
        // not written straight onto the live profile a client sees.
        $this->assertSame('Original bio', $technician->bio);
        $this->assertNotSame(['Wiring', 'Solar'], (array) $technician->skills);
        $this->assertSame('Updated bio', $technician->pending_profile_changes['bio'] ?? null);
        $this->assertSame(['Wiring', 'Solar'], $technician->pending_profile_changes['skills'] ?? null);

        // Identity and trade are not technician-editable at all — those move
        // through admin, so posting them must not change anything.
        $this->assertNotSame('Updated Technician', $user->name);
        $this->assertSame('Nairobi', $technician->location);
        $this->assertSame(Technician::TRADE_GENERAL, $technician->trade);
        $this->assertSame('General Repairs', $technician->specialization);

        $replaceResponse = $this->actingAs($user)->post(route('technician.profile.document'), [
            'document_type' => TechnicianDocument::TYPE_PASSPORT_PHOTO,
            'document' => UploadedFile::fake()->image('new-passport.jpg'),
        ]);

        $replaceResponse->assertRedirect();

        $existingDocument->refresh();

        $this->assertSame('new-passport.jpg', $existingDocument->file_name);
        $this->assertFalse($existingDocument->verified);
        $this->assertNull($existingDocument->verified_by);
        $this->assertNull($existingDocument->verified_at);
        Storage::disk('public')->assertExists($existingDocument->file_path);
        Storage::disk('public')->assertMissing($existingPath);
    }

    public function test_technician_can_submit_progress_report_with_photos(): void
    {
        Storage::fake('public');

        $client = User::factory()->create([
            'role' => User::ROLE_CLIENT,
        ]);

        $technicianUser = User::factory()->create([
            'role' => User::ROLE_TECHNICIAN,
        ]);

        $technician = Technician::create([
            'user_id' => $technicianUser->id,
            'technician_id' => 'TECH-400',
            'specialization' => 'Electrical',
            'trade' => Technician::TRADE_ELECTRICIAN,
            'location' => 'Nairobi',
            'availability' => 'busy',
        ]);

        $category = ServiceCategory::create([
            'name' => 'Electrical',
            'is_active' => true,
        ]);

        $serviceRequest = ServiceRequest::create([
            'request_id' => 'REQ-400',
            'user_id' => $client->id,
            'service_category_id' => $category->id,
            'technician_id' => $technician->id,
            'description' => 'Fix damaged distribution board.',
            'location' => 'Westlands',
            // A technician must start the job before reporting progress, so
            // the job has to be under way for this path to be reachable. The
            // guard itself is covered by the test below.
            'status' => 'in_progress',
            'urgency' => 'high',
        ]);

        $response = $this->actingAs($technicianUser)->post(route('technician.progress-report', $serviceRequest), [
            'percent_complete' => 45,
            'report_date' => now()->toDateString(),
            'notes' => 'Initial inspection done and faulty breakers isolated.',
            'photos' => [
                UploadedFile::fake()->image('site-1.jpg'),
                UploadedFile::fake()->image('site-2.jpg'),
            ],
        ]);

        $response->assertRedirect();

        $report = ProgressReport::first();

        $this->assertNotNull($report);
        $this->assertSame($serviceRequest->id, $report->service_request_id);
        $this->assertSame($technician->id, $report->technician_id);
        $this->assertSame(45, $report->percent_complete);
        $this->assertFalse($report->is_validated);
        $this->assertCount(2, $report->photos);

        foreach ($report->photos as $photo) {
            Storage::disk('public')->assertExists($photo->file_path);
        }
    }

    public function test_progress_report_is_rejected_until_the_job_is_started(): void
    {
        Storage::fake('public');

        $client = User::factory()->create([
            'role' => User::ROLE_CLIENT,
        ]);

        $technicianUser = User::factory()->create([
            'role' => User::ROLE_TECHNICIAN,
        ]);

        $technician = Technician::create([
            'user_id' => $technicianUser->id,
            'technician_id' => 'TECH-401',
            'specialization' => 'Electrical',
            'trade' => Technician::TRADE_ELECTRICIAN,
            'location' => 'Nairobi',
            'availability' => 'busy',
        ]);

        $category = ServiceCategory::create([
            'name' => 'Electrical Guard',
            'is_active' => true,
        ]);

        $serviceRequest = ServiceRequest::create([
            'request_id' => 'REQ-401',
            'user_id' => $client->id,
            'service_category_id' => $category->id,
            'technician_id' => $technician->id,
            'description' => 'Fix damaged distribution board.',
            'location' => 'Westlands',
            'status' => 'assigned',
            'urgency' => 'high',
        ]);

        $response = $this->actingAs($technicianUser)->post(route('technician.progress-report', $serviceRequest), [
            'percent_complete' => 45,
            'report_date' => now()->toDateString(),
            'notes' => 'Trying to report before starting.',
            'photos' => [UploadedFile::fake()->image('too-early.jpg')],
        ]);

        $response->assertSessionHas('error');

        // Nothing is written — not the report, and not the photo. A rejected
        // submission must not leave an orphaned file on the disk.
        $this->assertNull(ProgressReport::first());
        $this->assertSame(0, \App\Models\JobPhoto::count());
        $this->assertEmpty(Storage::disk('public')->allFiles());
    }

    public function test_admin_can_validate_progress_and_remove_photo_from_approved_set(): void
    {
        Storage::fake('public');

        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
        ]);

        $client = User::factory()->create([
            'role' => User::ROLE_CLIENT,
        ]);

        $technicianUser = User::factory()->create([
            'role' => User::ROLE_TECHNICIAN,
        ]);

        $technician = Technician::create([
            'user_id' => $technicianUser->id,
            'technician_id' => 'TECH-500',
            'specialization' => 'Plumbing',
            'trade' => Technician::TRADE_PLUMBER,
            'location' => 'Nairobi',
            'availability' => 'busy',
        ]);

        $category = ServiceCategory::create([
            'name' => 'Plumbing',
            'is_active' => true,
        ]);

        $serviceRequest = ServiceRequest::create([
            'request_id' => 'REQ-500',
            'user_id' => $client->id,
            'service_category_id' => $category->id,
            'technician_id' => $technician->id,
            'description' => 'Repair leaking pipe.',
            'location' => 'Upper Hill',
            'status' => 'in_progress',
            'urgency' => 'medium',
            'progress_percentage' => 0,
        ]);

        $report = ProgressReport::create([
            'service_request_id' => $serviceRequest->id,
            'technician_id' => $technician->id,
            'submitted_by' => $technicianUser->id,
            'report_date' => now()->toDateString(),
            'percent_complete' => 60,
            'notes' => 'Pipe rerouting completed.',
        ]);

        $photoOne = $report->photos()->create([
            'service_request_id' => $serviceRequest->id,
            'file_path' => UploadedFile::fake()->image('report-photo-1.jpg')->store("progress-photos/{$serviceRequest->id}", 'public'),
            'added_by' => $technicianUser->id,
        ]);

        $photoTwo = $report->photos()->create([
            'service_request_id' => $serviceRequest->id,
            'file_path' => UploadedFile::fake()->image('report-photo-2.jpg')->store("progress-photos/{$serviceRequest->id}", 'public'),
            'added_by' => $technicianUser->id,
        ]);

        $response = $this->actingAs($admin)->post(route('admin.progress.validate', $report), [
            'validated_percent' => 55,
            'validation_notes' => 'Verified against the submitted photos.',
            'remove_photo_ids' => [$photoTwo->id],
        ]);

        $response->assertRedirect();

        $report->refresh();
        $photoOne->refresh();
        $photoTwo->refresh();
        $serviceRequest->refresh();

        $this->assertTrue($report->is_validated);
        $this->assertSame(55, $report->validated_percent);
        $this->assertSame('Verified against the submitted photos.', $report->validation_notes);
        $this->assertSame($admin->id, $report->validated_by);
        $this->assertFalse($photoOne->removed_by_pm);
        $this->assertTrue($photoTwo->removed_by_pm);
        $this->assertSame(55, $serviceRequest->progress_percentage);
    }

    public function test_admin_can_pay_approved_progress_report_against_agreed_compensation_without_double_paying(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
        ]);

        $client = User::factory()->create([
            'role' => User::ROLE_CLIENT,
        ]);

        $technicianUser = User::factory()->create([
            'role' => User::ROLE_TECHNICIAN,
        ]);

        $technician = Technician::create([
            'user_id' => $technicianUser->id,
            'technician_id' => 'TECH-600',
            'specialization' => 'Fitting',
            'trade' => Technician::TRADE_FITTER,
            'location' => 'Nairobi',
            'availability' => 'busy',
        ]);

        $category = ServiceCategory::create([
            'name' => 'Fitting',
            'is_active' => true,
        ]);

        $serviceRequest = ServiceRequest::create([
            'request_id' => 'REQ-600',
            'user_id' => $client->id,
            'service_category_id' => $category->id,
            'technician_id' => $technician->id,
            'description' => 'Install replacement fittings.',
            'location' => 'Industrial Area',
            'status' => 'in_progress',
            'urgency' => 'medium',
        ]);

        ServiceRequestBudget::create([
            'service_request_id' => $serviceRequest->id,
            'labor_budget' => 100000,
            'materials_budget' => 0,
            'other_budget' => 0,
            'created_by' => $admin->id,
        ]);

        // What this technician is owed comes from their assignment, never
        // from the job's total labor budget — see resolveApprovedAmount.
        // Without this the payout is refused, which the test below covers.
        JobAssignment::create([
            'service_request_id' => $serviceRequest->id,
            'technician_id' => $technician->id,
            'assigned_by' => $admin->id,
            'agreed_compensation' => 100000,
            'status' => JobAssignment::STATUS_ACCEPTED,
        ]);

        TechnicianPayment::create([
            'payment_id' => 'TPY-SEED-1',
            'technician_id' => $technician->id,
            'service_request_id' => $serviceRequest->id,
            'category' => 'labor',
            'amount' => 20000,
            'status' => 'completed',
            'paid_at' => now(),
        ]);

        $report = ProgressReport::create([
            'service_request_id' => $serviceRequest->id,
            'technician_id' => $technician->id,
            'submitted_by' => $technicianUser->id,
            'report_date' => now()->toDateString(),
            'percent_complete' => 50,
            'is_validated' => true,
            'validated_by' => $admin->id,
            'validated_at' => now(),
            'validated_percent' => 50,
        ]);

        $response = $this->actingAs($admin)->post(route('admin.progress.pay-technician', $report));

        $response->assertRedirect();

        $newPayment = TechnicianPayment::where('progress_report_id', $report->id)->first();

        $this->assertNotNull($newPayment);
        $this->assertSame($technician->id, $newPayment->technician_id);
        $this->assertSame($serviceRequest->id, $newPayment->service_request_id);
        $this->assertSame('labor', $newPayment->category);
        $this->assertEquals(30000.0, (float) $newPayment->amount);
        $this->assertSame('completed', $newPayment->status);
        $this->assertSame('progress_report', $newPayment->payment_method);

        $secondResponse = $this->actingAs($admin)->post(route('admin.progress.pay-technician', $report));

        $secondResponse->assertRedirect();

        $this->assertCount(1, TechnicianPayment::where('progress_report_id', $report->id)->get());
    }

    /**
     * A job's labor budget is the pot for every technician on the job, not
     * one technician's fee. Inferring a payout from it paid a single
     * technician the whole budget, so the payout is refused until somebody
     * states what this technician is actually owed.
     */
    public function test_payout_is_refused_when_the_technician_has_no_agreed_compensation(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $client = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $technicianUser = User::factory()->create(['role' => User::ROLE_TECHNICIAN]);

        $technician = Technician::create([
            'user_id' => $technicianUser->id,
            'technician_id' => 'TECH-601',
            'specialization' => 'Fitting',
            'trade' => Technician::TRADE_FITTER,
            'location' => 'Nairobi',
            'availability' => 'busy',
        ]);

        $category = ServiceCategory::create([
            'name' => 'Fitting Unallocated',
            'is_active' => true,
        ]);

        $serviceRequest = ServiceRequest::create([
            'request_id' => 'REQ-601',
            'user_id' => $client->id,
            'service_category_id' => $category->id,
            'technician_id' => $technician->id,
            'description' => 'Install replacement fittings.',
            'location' => 'Industrial Area',
            'status' => 'in_progress',
            'urgency' => 'medium',
        ]);

        // A generous labor budget, but nothing allocated to this technician.
        ServiceRequestBudget::create([
            'service_request_id' => $serviceRequest->id,
            'labor_budget' => 100000,
            'materials_budget' => 0,
            'other_budget' => 0,
            'created_by' => $admin->id,
        ]);

        $report = ProgressReport::create([
            'service_request_id' => $serviceRequest->id,
            'technician_id' => $technician->id,
            'submitted_by' => $technicianUser->id,
            'report_date' => now()->toDateString(),
            'percent_complete' => 50,
            'is_validated' => true,
            'validated_by' => $admin->id,
            'validated_at' => now(),
            'validated_percent' => 50,
        ]);

        $response = $this->actingAs($admin)->post(route('admin.progress.pay-technician', $report));

        $response->assertSessionHas('error');
        $this->assertSame(0, TechnicianPayment::count());
    }

    public function test_admin_can_assign_technician_with_agreed_dues_within_labor_budget(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
        ]);

        $client = User::factory()->create([
            'role' => User::ROLE_CLIENT,
        ]);

        $technicianUser = User::factory()->create([
            'role' => User::ROLE_TECHNICIAN,
        ]);

        $technician = Technician::create([
            'user_id' => $technicianUser->id,
            'technician_id' => 'TECH-700',
            'specialization' => 'Air Conditioning',
            'trade' => Technician::TRADE_HVAC,
            'location' => 'Nairobi',
            'availability' => 'available',
        ]);

        $category = ServiceCategory::create([
            'name' => 'HVAC',
            'is_active' => true,
        ]);

        $serviceRequest = ServiceRequest::create([
            'request_id' => 'REQ-700',
            'user_id' => $client->id,
            'service_category_id' => $category->id,
            'description' => 'Install split-unit AC.',
            'location' => 'Kilimani',
            'status' => 'ready_for_assignment',
            'rfq_status' => ServiceRequest::RFQ_STATUS_APPROVED,
            'urgency' => 'medium',
        ]);

        ServiceRequestBudget::create([
            'service_request_id' => $serviceRequest->id,
            'labor_budget' => 80000,
            'materials_budget' => 0,
            'other_budget' => 0,
            'created_by' => $admin->id,
        ]);

        $response = $this->actingAs($admin)->post(route('admin.jobs.assign', $serviceRequest), [
            'technician_id' => $technician->id,
            'agreed_compensation' => 35000,
            'compensation_notes' => 'Installation labor agreed for this assignment.',
        ]);

        $response->assertRedirect();

        $serviceRequest->refresh();
        $technician->refresh();

        $assignment = JobAssignment::where('service_request_id', $serviceRequest->id)->latest('id')->first();

        $this->assertSame($technician->id, $serviceRequest->technician_id);
        $this->assertSame('assigned', $serviceRequest->status);
        $this->assertSame('busy', $technician->availability);
        $this->assertNotNull($assignment);
        $this->assertSame($technician->id, $assignment->technician_id);
        $this->assertEquals(35000.0, (float) $assignment->agreed_compensation);
        $this->assertSame('Installation labor agreed for this assignment.', $assignment->compensation_notes);
    }

    public function test_admin_cannot_over_allocate_labor_budget_across_sub_task_assignments(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
        ]);

        $client = User::factory()->create([
            'role' => User::ROLE_CLIENT,
        ]);

        $technicianUserOne = User::factory()->create([
            'role' => User::ROLE_TECHNICIAN,
        ]);

        $technicianUserTwo = User::factory()->create([
            'role' => User::ROLE_TECHNICIAN,
        ]);

        $technicianOne = Technician::create([
            'user_id' => $technicianUserOne->id,
            'technician_id' => 'TECH-710',
            'specialization' => 'Electrical',
            'trade' => Technician::TRADE_ELECTRICIAN,
            'location' => 'Nairobi',
            'availability' => 'available',
        ]);

        $technicianTwo = Technician::create([
            'user_id' => $technicianUserTwo->id,
            'technician_id' => 'TECH-711',
            'specialization' => 'Electrical',
            'trade' => Technician::TRADE_ELECTRICIAN,
            'location' => 'Nairobi',
            'availability' => 'available',
        ]);

        $category = ServiceCategory::create([
            'name' => 'Electrical',
            'is_active' => true,
        ]);

        $serviceRequest = ServiceRequest::create([
            'request_id' => 'REQ-710',
            'user_id' => $client->id,
            'service_category_id' => $category->id,
            'description' => 'Rewire two office zones.',
            'location' => 'Westlands',
            'status' => 'ready_for_assignment',
            'rfq_status' => ServiceRequest::RFQ_STATUS_APPROVED,
            'urgency' => 'high',
            'has_sub_tasks' => true,
        ]);

        ServiceRequestBudget::create([
            'service_request_id' => $serviceRequest->id,
            'labor_budget' => 60000,
            'materials_budget' => 0,
            'other_budget' => 0,
            'created_by' => $admin->id,
        ]);

        $subTaskOne = ServiceSubTask::create([
            'service_request_id' => $serviceRequest->id,
            'title' => 'Zone A rewiring',
            'order' => 1,
        ]);

        $subTaskTwo = ServiceSubTask::create([
            'service_request_id' => $serviceRequest->id,
            'title' => 'Zone B rewiring',
            'order' => 2,
        ]);

        $firstAssignment = $this->actingAs($admin)->post(route('admin.sub-tasks.assign', $subTaskOne), [
            'technician_id' => $technicianOne->id,
            'agreed_compensation' => 40000,
            'compensation_notes' => 'First zone allocation.',
        ]);

        $firstAssignment->assertRedirect();

        $secondAssignment = $this->actingAs($admin)->post(route('admin.sub-tasks.assign', $subTaskTwo), [
            'technician_id' => $technicianTwo->id,
            'agreed_compensation' => 25000,
            'compensation_notes' => 'Second zone allocation.',
        ]);

        $secondAssignment->assertSessionHasErrors('agreed_compensation');

        $subTaskOne->refresh();
        $subTaskTwo->refresh();

        $this->assertSame($technicianOne->id, $subTaskOne->technician_id);
        $this->assertEquals(40000.0, (float) $subTaskOne->agreed_compensation);
        $this->assertNull($subTaskTwo->technician_id);
        $this->assertNull($subTaskTwo->agreed_compensation);
    }

    public function test_technician_earnings_statement_shows_paid_and_outstanding_per_job(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
        ]);

        $client = User::factory()->create([
            'role' => User::ROLE_CLIENT,
        ]);

        $technicianUser = User::factory()->create([
            'role' => User::ROLE_TECHNICIAN,
        ]);

        $technician = Technician::create([
            'user_id' => $technicianUser->id,
            'technician_id' => 'TECH-800',
            'specialization' => 'Plumbing',
            'trade' => Technician::TRADE_PLUMBER,
            'location' => 'Nairobi',
            'availability' => 'busy',
        ]);

        $category = ServiceCategory::create([
            'name' => 'Plumbing',
            'is_active' => true,
        ]);

        $serviceRequest = ServiceRequest::create([
            'request_id' => 'REQ-800',
            'user_id' => $client->id,
            'service_category_id' => $category->id,
            'description' => 'Bathroom piping works.',
            'location' => 'Karen',
            'status' => 'in_progress',
            'rfq_status' => ServiceRequest::RFQ_STATUS_APPROVED,
            'urgency' => 'medium',
        ]);

        JobAssignment::create([
            'service_request_id' => $serviceRequest->id,
            'technician_id' => $technician->id,
            'assigned_by' => $admin->id,
            'agreed_compensation' => 50000,
            'status' => JobAssignment::STATUS_PENDING,
        ]);

        TechnicianPayment::create([
            'payment_id' => 'TPY-800-1',
            'technician_id' => $technician->id,
            'service_request_id' => $serviceRequest->id,
            'category' => 'labor',
            'amount' => 10000,
            'status' => 'completed',
            'payment_method' => 'manual',
            'paid_at' => now()->subDays(2),
        ]);

        $sheet = TechnicianPaymentSheet::create([
            'sheet_reference' => 'WPS-TEST-800',
            'period_start' => now()->subWeek()->toDateString(),
            'period_end' => now()->toDateString(),
            'created_by' => $admin->id,
            'status' => TechnicianPaymentSheet::STATUS_FINALIZED,
            'total_amount' => 15000,
        ]);

        TechnicianPaymentEntry::create([
            'payment_sheet_id' => $sheet->id,
            'technician_id' => $technician->id,
            'service_request_id' => $serviceRequest->id,
            'agreed_compensation' => 50000,
            'cumulative_progress_pct' => 50,
            'cumulative_amount_due' => 25000,
            'previous_cumulative_paid' => 10000,
            'current_period_payable' => 15000,
            'status' => TechnicianPaymentEntry::STATUS_APPROVED,
        ]);

        $statement = app(ReportingService::class)->getTechnicianEarnings($technician->id);

        $this->assertEquals(50000.0, (float) $statement['total_agreed']);
        $this->assertEquals(25000.0, (float) $statement['total_paid']);
        $this->assertEquals(25000.0, (float) $statement['total_outstanding']);
        $this->assertCount(1, $statement['by_job']);

        $jobRow = $statement['by_job'][0];

        $this->assertSame($serviceRequest->id, $jobRow['service_request_id']);
        $this->assertEquals(50000.0, (float) $jobRow['agreed_compensation']);
        $this->assertEquals(25000.0, (float) $jobRow['paid_to_date']);
        $this->assertEquals(25000.0, (float) $jobRow['outstanding_balance']);
        $this->assertEquals(25000.0, (float) $jobRow['latest_cumulative_due']);
        $this->assertEquals(50, $jobRow['latest_progress_pct']);
        $this->assertCount(2, $jobRow['history']);
    }
}
