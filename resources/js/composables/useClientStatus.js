/**
 * What a job's status means to the client, in their words.
 *
 * The raw status names are the office's vocabulary. Shown to a client
 * title-cased they misled: "Completed Pending Confirmation" on a job the
 * client had already rated read as though they still owed a confirmation,
 * when it is the office's final sign-off it waits on.
 *
 * The quotation's own status is read alongside the job's. Declining a quote
 * records the decline on rfq_status and leaves the job at
 * awaiting_quote_approval — so the job status alone told a client who had
 * said no that we were still waiting for their approval.
 */

// Before the client has agreed to a quote, a decline outranks the job status.
const PRE_APPROVAL = ['pending', 'awaiting_pm_assignment', 'awaiting_quote_generation', 'awaiting_quote_approval']

const LABELS = {
    draft_rfq: 'Draft',
    pending: 'Under Review',
    awaiting_pm_assignment: 'Under Review',
    awaiting_quote_generation: 'Preparing Your Quote',
    awaiting_quote_approval: 'Quote Ready for Your Approval',
    awaiting_client_date_response: 'Please Confirm a Date',
    awaiting_tech_availability: 'Scheduling a Technician',
    payment_pending_approval: 'Payment Being Confirmed',
    ready_for_assignment: 'Assigning a Technician',
    assigned: 'Technician Assigned',
    queued: 'Scheduled',
    reassigned: 'Technician Assigned',
    in_progress: 'Work in Progress',
    delayed: 'Work Delayed',
    suspended: 'On Hold',
    completed_pending_confirmation: 'Work Done · Final Office Check',
    awaiting_client_verification: 'Please Confirm the Work',
    client_query_raised: 'Your Concern Is With Our Office',
    completed: 'Completed',
    closed: 'Completed',
    archived: 'Completed',
    cancelled: 'Cancelled',
}

export function isQuoteDeclined(request) {
    return request?.rfq_status === 'rejected' && PRE_APPROVAL.includes(request?.status)
}

/**
 * @param {object} request  needs status and rfq_status; for awaiting_payment
 *                          either pending_payment_requests_count or payment_requests
 */
export function clientStatusLabel(request) {
    const status = request?.status || 'pending'

    if (isQuoteDeclined(request)) return 'Quote Declined'

    if (status === 'awaiting_payment') {
        const hasPending = request.pending_payment_requests_count !== undefined
            ? Number(request.pending_payment_requests_count) > 0
            : (request.payment_requests || []).some(pr => pr.status === 'pending')
        return hasPending ? 'Awaiting Payment' : 'Awaiting Payment Request from TW'
    }

    return LABELS[status] || status.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase())
}

/** One line on what happens next, for the same states. */
export function clientNextStep(request) {
    if (isQuoteDeclined(request)) {
        return 'You declined this quotation. Our office will be in touch if a revised one is prepared.'
    }

    switch (request?.status) {
        case 'completed_pending_confirmation':
            return request?.client_confirmed_completion || Number(request?.rating) >= 3
                ? 'The work is done on site and you have confirmed it. Our office is giving it a final check before closing it.'
                : 'The work is done on site. Our office is giving it a final check, then we will ask you to confirm.'
        case 'awaiting_client_verification':
            return 'The work is complete. Please confirm it, or tell us if anything is not right.'
        case 'client_query_raised':
            return 'Thank you for raising this. Our office is looking into it and will come back to you.'
        default:
            return null
    }
}
