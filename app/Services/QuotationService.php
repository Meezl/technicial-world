<?php

namespace App\Services;

use App\Models\Quotation;
use App\Models\QuotationLineItem;
use App\Models\ServiceRequest;
use App\Models\AuditLog;
use Illuminate\Support\Facades\DB;

class QuotationService
{
    /**
     * Create a new quotation for a service request.
     */
    public function create(ServiceRequest $serviceRequest, array $data, int $pmId): Quotation
    {
        return DB::transaction(function () use ($serviceRequest, $data, $pmId) {
            // Determine version
            $latestVersion = $serviceRequest->quotations()->max('version') ?? 0;

            // Create quotation
            $quotation = Quotation::create([
                'service_request_id' => $serviceRequest->id,
                'created_by' => $pmId,
                'version' => $latestVersion + 1,
                'status' => Quotation::STATUS_DRAFT,
                'payment_terms' => $data['payment_terms'] ?? null,
                'delivery_timeline' => $data['delivery_timeline'] ?? null,
                'valid_until' => $data['valid_until'] ?? null,
                'notes' => $data['notes'] ?? null,
                'materials_file_path' => $data['materials_file_path'] ?? null,
            ]);

            // Create line items
            if (!empty($data['line_items'])) {
                foreach ($data['line_items'] as $index => $item) {
                    QuotationLineItem::create([
                        'quotation_id' => $quotation->id,
                        'category' => $item['category'] ?? 'material',
                        'description' => $item['description'],
                        'quantity' => $item['quantity'] ?? 1,
                        'unit' => $item['unit'] ?? 'pcs',
                        'unit_price' => $item['unit_price'],
                        'total_price' => ($item['quantity'] ?? 1) * $item['unit_price'],
                        'sort_order' => $index,
                    ]);
                }
            }

            // Recalculate totals
            $quotation->recalculateTotals();

            AuditLog::log(AuditLog::ACTION_CREATED, $quotation);

            return $quotation->fresh(['lineItems']);
        });
    }

    /**
     * Send quotation to client.
     */
    public function send(Quotation $quotation): Quotation
    {
        $quotation->update(['status' => Quotation::STATUS_SENT]);

        // Update service request status
        $updateData = [
            'rfq_status' => ServiceRequest::RFQ_STATUS_QUOTED,
            'quote_amount' => $quotation->grand_total,
        ];

        if (in_array($quotation->serviceRequest->status, [
            ServiceRequest::STATUS_AWAITING_QUOTE_GENERATION,
            ServiceRequest::STATUS_PENDING,
            'pending',
        ])) {
            $updateData['status'] = ServiceRequest::STATUS_AWAITING_QUOTE_APPROVAL;
        }

        $quotation->serviceRequest->update($updateData);

        AuditLog::log(AuditLog::ACTION_UPDATED, $quotation, null, ['status' => 'sent']);

        return $quotation;
    }

    /**
     * Client approves quotation.
     */
    public function approve(Quotation $quotation): Quotation
    {
        return DB::transaction(function () use ($quotation) {
            $quotation->update([
                'status' => Quotation::STATUS_APPROVED,
                'approved_at' => now(),
            ]);

            $quotation->serviceRequest->update([
                'rfq_status' => ServiceRequest::RFQ_STATUS_APPROVED,
                'quote_amount' => $quotation->grand_total,
                'status' => ServiceRequest::STATUS_AWAITING_PAYMENT,
            ]);

            AuditLog::log(AuditLog::ACTION_APPROVAL, $quotation);

            return $quotation;
        });
    }

    /**
     * Client declines quotation.
     */
    public function decline(Quotation $quotation, string $reason): Quotation
    {
        $quotation->update([
            'status' => Quotation::STATUS_DECLINED,
            'declined_at' => now(),
            'decline_reason' => $reason,
        ]);

        AuditLog::log(AuditLog::ACTION_UPDATED, $quotation, null, ['status' => 'declined']);

        return $quotation;
    }

    /**
     * Create a revision of an existing quotation.
     */
    public function revise(Quotation $quotation): Quotation
    {
        $newQuotation = $quotation->createRevision();

        AuditLog::log(AuditLog::ACTION_CREATED, $newQuotation, null, [
            'revised_from' => $quotation->id,
            'version' => $newQuotation->version,
        ]);

        return $newQuotation;
    }
}
