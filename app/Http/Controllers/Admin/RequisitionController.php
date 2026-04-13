<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use App\Models\Project;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use Inertia\Inertia;
use Illuminate\Support\Facades\DB;

class RequisitionController extends Controller
{
    public function index(Request $request)
    {
        $user = auth()->user();

        $query = Requisition::with(['project', 'creator', 'items']);

        // Role-based filtering
        // Foreman: Only their own or project specific? Spec says "inputs for quantity"
        // Let's simpler: everyone sees checks checks role in UI. 
        // But for Accounts/Procurement, maybe filter?

        if ($request->project_id) {
            $query->where('project_id', $request->project_id);
        }

        $requisitions = $query->latest()->get();

        return Inertia::render('Admin/Requisitions/Index', [
            'requisitions' => $requisitions,
            'projects' => Project::select('id', 'name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'project_id' => 'required|exists:projects,id',
            'description' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.name' => 'required|string',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.unit' => 'required|string',
        ]);

        $requisition = null;
        DB::transaction(function () use ($request, &$requisition) {
            $requisition = Requisition::create([
                'project_id' => $request->project_id,
                'created_by' => auth()->id(),
                'status' => 'pending',
                'description' => $request->description,
            ]);

            foreach ($request->items as $item) {
                $requisition->items()->create([
                    'name' => $item['name'],
                    'quantity' => $item['quantity'],
                    'unit' => $item['unit'],
                    'status' => 'requested',
                    'currency' => 'USD', // Default
                ]);
            }
        });

        // Notify Office team about new requisition
        if ($requisition) {
            $requisition->load(['project', 'items']);
            $officeUsers = \App\Models\User::where('role', 'office')->orWhere('role', 'admin')->get();
            foreach ($officeUsers as $officeUser) {
                \Illuminate\Support\Facades\Mail::to($officeUser->email)
                    ->send(new \App\Mail\RequisitionCreatedMail($requisition, auth()->user()->name));
            }
        }

        return redirect()->back()->with('success', 'Requisition created successfully.');
    }

    public function updateItem(Request $request, RequisitionItem $item)
    {
        $action = $request->action;
        $user = auth()->user();

        // RBAC: Role-based action authorization
        $this->authorizeAction($user->role, $action);

        switch ($action) {
            case 'approve':
                $this->validateTransition($item, 'approved');
                $item->update([
                    'status' => 'approved',
                    'approved_by' => $user->id,
                    'approved_at' => now(),
                ]);
                break;

            case 'reject':
                $this->validateTransition($item, 'rejected');
                $request->validate(['notes' => 'required|string']);
                $item->update([
                    'status' => 'rejected',
                    'rejection_reason' => $request->notes,
                    'notes' => $request->notes,
                ]);
                break;

            case 'update_qty':
                $request->validate(['quantity' => 'required|numeric|min:0.01']);
                $item->update(['quantity' => $request->quantity]);
                break;

            case 'procure':
                $this->validateTransition($item, 'procured');
                $request->validate([
                    'supplier_name' => 'required|string',
                    'price' => 'required|numeric|min:0',
                    'quotation_notes' => 'nullable|string',
                ]);

                $quotationPath = null;
                if ($request->hasFile('quotation_file')) {
                    $quotationPath = $request->file('quotation_file')->store('quotations', 'public');
                }

                $item->update([
                    'status' => 'procured',
                    'supplier_name' => $request->supplier_name,
                    'price' => $request->price,
                    'currency' => $request->currency ?? 'USD',
                    'quotation_file_path' => $quotationPath,
                    'quotation_notes' => $request->quotation_notes,
                ]);

                // Auto-transition to awaiting_payment
                $item->update(['status' => 'awaiting_payment']);
                break;

            case 'pay':
                $this->validateTransition($item, 'paid');
                $item->update(['status' => 'paid']);
                break;

            case 'transit':
                $this->validateTransition($item, 'in_transit');
                $request->validate([
                    'tracking_number' => 'nullable|string',
                    'expected_delivery_date' => 'nullable|date',
                ]);
                $item->update([
                    'status' => 'in_transit',
                    'tracking_number' => $request->tracking_number,
                    'expected_delivery_date' => $request->expected_delivery_date,
                ]);
                break;

            case 'deliver':
                $this->validateTransition($item, 'delivered');
                $item->update([
                    'status' => 'delivered',
                    'actual_delivery_date' => now()->toDateString(),
                ]);
                break;
        }

        $this->updateRequisitionStatus($item->requisition);

        // Send email notifications based on action
        $this->sendItemStatusNotification($item, $action, $user->name, $request->notes ?? null);

        return redirect()->back()->with('success', 'Item updated successfully.');
    }

    protected function sendItemStatusNotification(RequisitionItem $item, string $action, string $actorName, ?string $notes = null)
    {
        $item->load('requisition.creator', 'requisition.project');
        $recipients = [];

        switch ($action) {
            case 'approve':
            case 'reject':
                // Notify the foreman (requisition creator)
                if ($item->requisition->creator) {
                    $recipients[] = $item->requisition->creator;
                }
                break;

            case 'procure':
                // Notify accounts team
                $recipients = \App\Models\User::where('role', 'accounts')->get()->all();
                break;

            case 'pay':
                // Notify procurement team
                $recipients = \App\Models\User::where('role', 'procurement')->get()->all();
                break;

            case 'transit':
            case 'deliver':
                // Notify foreman
                if ($item->requisition->creator) {
                    $recipients[] = $item->requisition->creator;
                }
                break;
        }

        foreach ($recipients as $recipient) {
            \Illuminate\Support\Facades\Mail::to($recipient->email)
                ->send(new \App\Mail\RequisitionItemStatusMail($item, $action, $actorName, $notes));
        }
    }

    protected function authorizeAction($role, $action)
    {
        $permissions = [
            'office' => ['approve', 'reject', 'update_qty'],
            'procurement' => ['procure', 'transit', 'deliver'],
            'accounts' => ['pay'],
            'admin' => ['approve', 'reject', 'update_qty', 'procure', 'transit', 'deliver', 'pay'],
        ];

        if (!isset($permissions[$role]) || !in_array($action, $permissions[$role])) {
            abort(403, 'Unauthorized action for your role.');
        }
    }

    protected function validateTransition(RequisitionItem $item, $newStatus)
    {
        if (!$item->canTransitionTo($newStatus)) {
            abort(400, "Cannot transition from '{$item->status}' to '{$newStatus}'.");
        }
    }

    public function acknowledgeItem(Request $request, RequisitionItem $item)
    {
        $this->validateTransition($item, 'acknowledged');

        $request->validate([
            'delivery_condition_notes' => 'nullable|string',
        ]);

        // Transition to acknowledged
        $item->update([
            'status' => 'acknowledged',
            'acknowledged_at' => now(),
            'acknowledged_by' => auth()->id(),
            'delivery_condition_notes' => $request->delivery_condition_notes,
        ]);

        // Auto-close after acknowledgment
        $item->update(['status' => 'closed']);

        $this->updateRequisitionStatus($item->requisition);

        return redirect()->back()->with('success', 'Item acknowledged and closed successfully.');
    }

    protected function updateRequisitionStatus(Requisition $req)
    {
        $req->load('items');
        // If all items closed/rejected -> closed
        // If any pending/requested -> pending
        // If some approved -> partially_approved

        $total = $req->items->count();
        $closed = $req->items->whereIn('status', ['closed', 'rejected'])->count();

        if ($total > 0 && $total === $closed) {
            $req->update(['status' => 'closed']);
        } elseif ($req->items->where('status', '!=', 'requested')->count() > 0) {
            $req->update(['status' => 'active']); // simple status
        }
    }
}
