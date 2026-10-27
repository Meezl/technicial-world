<template>
    <div class="pwa-app">
        <header class="pwa-header">
            <div class="header-main">
                <Link href="/technician/jobs" class="btn btn-outline btn-sm header-back">
                    <i class="fas fa-arrow-left"></i>
                </Link>
                <div>
                    <h1>{{ job.job_reference || job.request_id || `Job #${job.id}` }}</h1>
                    <p class="header-subtitle">{{ job.service_category?.name || 'Service Job' }}</p>
                </div>
            </div>
            <span class="status-badge" :class="getStatusClass(job.status)">
                {{ formatStatus(job.status) }}
            </span>
        </header>

        <main class="pwa-content job-page">
            <!-- The office is waiting to hear whether this technician will
                 take the job. Declining sends it straight back for
                 reassignment, so it goes first on the page. -->
            <section v-for="assignment in pendingAssignments" :key="assignment.id" class="assignment-response">
                <div class="assignment-response-copy">
                    <strong>You have been assigned to this job</strong>
                    <p>
                        <template v-if="assignment.sub_task_title">Your task: {{ assignment.sub_task_title }}. </template>
                        <template v-else-if="assignment.role_on_job">Your role: {{ assignment.role_on_job }}. </template>
                        Please accept it, or decline so the office can reassign it.
                    </p>
                </div>

                <div v-if="decliningId === assignment.id" class="assignment-decline-form">
                    <textarea
                        v-model="declineReason"
                        rows="3"
                        class="input"
                        placeholder="Why can't you take this job?"
                    ></textarea>
                    <p v-if="page.props.errors?.reason" class="assignment-error">{{ page.props.errors.reason }}</p>
                    <div class="assignment-response-actions">
                        <button class="btn btn-outline" :disabled="responding" @click="decliningId = null">Cancel</button>
                        <button class="btn btn-danger" :disabled="responding || declineReason.trim().length < 5" @click="declineAssignment(assignment)">
                            Confirm decline
                        </button>
                    </div>
                </div>
                <!-- Sized like the decision it is. These were two ordinary
                     buttons at the top of a long job page, read on a phone on
                     site — the same mistake the client portal made, where the
                     thing the page exists to ask was the quietest element on
                     it. Accept leads; declining stays available and calmer,
                     because it is the harder one to undo. -->
                <div v-else class="assignment-decision">
                    <button
                        class="btn-decision btn-decision-accept"
                        :disabled="responding"
                        @click="acceptAssignment(assignment)"
                    >
                        <i class="fas fa-check-circle"></i>
                        <span>{{ responding ? 'Accepting…' : 'Accept this job' }}</span>
                        <span v-if="!responding && assignment.agreed_compensation" class="btn-decision-amount">
                            KSH {{ Number(assignment.agreed_compensation).toLocaleString() }}
                        </span>
                    </button>
                    <button
                        class="btn-decision btn-decision-refuse"
                        :disabled="responding"
                        @click="startDecline(assignment)"
                    >
                        <i class="fas fa-comment-dots"></i>
                        Decline — I can't take this
                    </button>
                </div>
            </section>

            <section class="hero-card">
                <div class="hero-copy">
                    <span class="hero-kicker">Field Job</span>
                    <h2>{{ job.service_category?.name || 'Service Request' }}</h2>
                    <p>{{ job.description || 'No job description was provided for this request.' }}</p>
                </div>

                <div class="hero-meta-grid">
                    <div class="meta-tile">
                        <span>Location</span>
                        <strong>{{ job.location || 'On site' }}</strong>
                    </div>
                    <div class="meta-tile">
                        <span>Progress</span>
                        <strong>{{ Number(job.progress_percentage || 0) }}%</strong>
                    </div>
                    <div class="meta-tile">
                        <span>Reports</span>
                        <strong>{{ progressReports.length }}</strong>
                    </div>
                </div>
            </section>

            <section class="panel-card">
                <div class="section-heading">
                    <div>
                        <span class="section-kicker">Client</span>
                        <h3>Client details</h3>
                    </div>
                </div>

                <div class="info-list">
                    <div class="info-row">
                        <span>Name</span>
                        <strong>{{ job.user?.name || 'Guest client' }}</strong>
                    </div>
                    <div class="info-row">
                        <span>Email</span>
                        <strong>{{ job.user?.email || 'Not provided' }}</strong>
                    </div>
                    <div class="info-row">
                        <span>Phone</span>
                        <strong>{{ job.user?.phone || 'Not provided' }}</strong>
                    </div>
                </div>

                <a v-if="job.user?.phone" :href="'tel:' + job.user.phone" class="btn btn-outline full-width">
                    <i class="fas fa-phone"></i>
                    Call Client
                </a>
            </section>

            <!-- What the client sent in — seen before going back to site,
                 rather than found in a WhatsApp thread afterwards. -->
            <section class="panel-card" v-if="jobPhotos.length">
                <div class="section-heading">
                    <div>
                        <span class="section-kicker">Evidence</span>
                        <h3>Photos on this job</h3>
                    </div>
                    <span class="section-pill">{{ jobPhotos.length }}</span>
                </div>

                <JobPhotoGallery :photos="jobPhotos" />
            </section>

            <section class="panel-card" v-if="compensationSummary">
                <div class="section-heading">
                    <div>
                        <span class="section-kicker">Compensation</span>
                        <h3>Paid and owed on this job</h3>
                    </div>
                </div>

                <div class="hero-meta-grid">
                    <div class="meta-tile">
                        <span>Agreed</span>
                        <strong>{{ formatCurrency(compensationSummary.agreed_compensation) }}</strong>
                    </div>
                    <div class="meta-tile">
                        <span>Paid so far</span>
                        <strong style="color: var(--success-color);">{{ formatCurrency(compensationSummary.paid_to_date) }}</strong>
                    </div>
                    <div class="meta-tile">
                        <span>Still owed</span>
                        <strong style="color: #c2410c;">{{ formatCurrency(compensationSummary.outstanding_balance) }}</strong>
                    </div>
                </div>

                <div v-if="compensationSummary.latest_progress_pct" class="info-list" style="margin-top: 1rem;">
                    <div class="info-row">
                        <span>Latest approved progress</span>
                        <strong>{{ compensationSummary.latest_progress_pct }}%</strong>
                    </div>
                    <div class="info-row">
                        <span>Latest approved amount</span>
                        <strong>{{ formatCurrency(compensationSummary.latest_period_payable) }}</strong>
                    </div>
                </div>

                <div v-if="recentPayouts.length" style="margin-top: 1rem;">
                    <span class="section-kicker">Recent payouts</span>
                    <div class="info-list" style="margin-top: 0.5rem;">
                        <div v-for="entry in recentPayouts" :key="`${entry.type}-${entry.reference}-${entry.date}`" class="info-row">
                            <span>{{ entry.label }}{{ entry.date ? ` • ${formatShortDate(entry.date)}` : '' }}</span>
                            <strong>{{ formatCurrency(entry.amount) }}</strong>
                        </div>
                    </div>
                </div>
            </section>

            <!-- What was quoted and the dates being held to. The technician
                 was working off a description and a location alone; the scope,
                 the material list and the programme all sat on the REQ where
                 only the office could see them. Client pricing stays out —
                 the fee that matters to them is in Compensation above. -->
            <section class="panel-card" v-if="hasScopeDetail">
                <div class="section-heading">
                    <div>
                        <!-- Not "RFQ" any more: what a technician gets is their
                             own brief, not the quotation the client was sent. -->
                        <span class="section-kicker">Your work</span>
                        <h3>{{ scope.is_lead_view ? 'Scope &amp; programme' : 'Your task &amp; programme' }}</h3>
                    </div>
                </div>

                <!-- What this person is on the job to do. For a crew member
                     with no task of their own, this is the whole of it. -->
                <p v-if="scope.role_on_job" class="scope-role">
                    <i class="fas fa-user-hard-hat"></i> {{ scope.role_on_job }}
                </p>

                <div v-if="scopeTasks.length" class="scope-tasks">
                    <h4>{{ scope.is_lead_view ? 'Tasks on this job' : 'Your task' }}</h4>
                    <div v-for="(task, index) in scopeTasks" :key="`${task.title}-${index}`" class="scope-task">
                        <strong>{{ task.title }}</strong>
                        <p v-if="task.description">{{ task.description }}</p>
                    </div>
                </div>

                <div
                    v-if="scope.commencement_at || scope.target_completion_at || scope.expected_duration_days"
                    class="info-list"
                    style="margin-top: 1rem;"
                >
                    <div v-if="scope.commencement_at" class="info-row">
                        <span>Start on site</span>
                        <strong>{{ formatShortDate(scope.commencement_at) }}</strong>
                    </div>
                    <div v-if="scope.target_completion_at" class="info-row">
                        <span>Target completion</span>
                        <strong>{{ formatShortDate(scope.target_completion_at) }}</strong>
                    </div>
                    <div v-if="scope.expected_duration_days" class="info-row">
                        <span>Expected duration</span>
                        <strong>{{ scope.expected_duration_days }} day{{ scope.expected_duration_days == 1 ? '' : 's' }}</strong>
                    </div>
                </div>

            </section>

            <!-- Drawings and briefs: the files attached to this technician's
                 own assignment, the client's own uploads, and the specs ops
                 draw for the job. The office's commercial paperwork for the
                 client — quotations, approvals, case analyses — never appears
                 here. -->
            <section class="panel-card" v-if="hasJobFiles">
                <div class="section-heading">
                    <div>
                        <span class="section-kicker">Documents</span>
                        <h3>Drawings &amp; briefs</h3>
                    </div>
                </div>

                <div class="info-list">
                    <a
                        v-for="(file, index) in assignmentFiles"
                        :key="`assignment-${index}`"
                        class="info-row file-row"
                        :href="`/storage/${file.path}`"
                        target="_blank"
                        rel="noopener"
                    >
                        <span><i class="fas fa-paperclip"></i> {{ file.name }}</span>
                        <strong>Open</strong>
                    </a>
                    <a
                        v-for="doc in sharedDocuments"
                        :key="`doc-${doc.id}`"
                        class="info-row file-row"
                        :href="`/storage/${doc.path}`"
                        target="_blank"
                        rel="noopener"
                    >
                        <span><i class="fas fa-file-alt"></i> {{ doc.title || doc.original_name }}</span>
                        <strong>Open</strong>
                    </a>
                </div>
            </section>

            <section class="panel-card" v-if="job.sub_tasks?.length">
                <div class="section-heading">
                    <div>
                        <span class="section-kicker">Sub-Tasks</span>
                        <h3>Assigned work items</h3>
                    </div>
                    <!-- The lead pushes the reviewed reports up to the office as
                         one batch. Until this, the office sees nothing — which
                         is what spares the client a separate update per crew
                         member. -->
                    <button
                        v-if="isLeadTechnician && postableReportCount"
                        type="button"
                        class="btn btn-primary"
                        :disabled="postingReports"
                        @click="postReportsToOffice()"
                        title="Send the reviewed reports to the office in one batch"
                    >
                        <i class="fas fa-paper-plane"></i>
                        {{ postingReports ? 'Posting…' : `Post ${postableReportCount} to office` }}
                    </button>
                </div>

                <!-- Reminder so reviewed reports don't sit unposted: the office
                     sees nothing on this job until the lead sends them up. -->
                <div v-if="isLeadTechnician && postableReportCount" class="post-reports-nudge">
                    <i class="fas fa-circle-info"></i>
                    <span>
                        You have {{ postableReportCount }} reviewed
                        {{ postableReportCount === 1 ? 'report' : 'reports' }}
                        ready to send. The office won't see this job's progress until you post them.
                    </span>
                </div>

                <div class="subtask-list">
                    <article
                        v-for="task in job.sub_tasks"
                        :key="task.id"
                        class="subtask-card"
                        :class="{ 'subtask-mine': isMyTask(task) }"
                    >
                        <div class="subtask-top">
                            <div>
                                <strong>{{ task.title || task.name }}</strong>
                                <!-- On a job with several trades, which item is
                                     actually yours was left to be inferred from
                                     the name underneath. -->
                                <span v-if="isMyTask(task)" class="mine-pill">Your task</span>
                                <p>{{ task.description || 'No sub-task description.' }}</p>
                            </div>
                            <span class="status-badge" :class="getStatusClass(task.status)">
                                {{ formatStatus(task.status) }}
                            </span>
                        </div>

                        <div class="subtask-meta">
                            <span>Assigned to {{ task.technician?.user?.name || 'Unassigned' }}</span>
                            <strong>{{ task.progress_percentage || 0 }}%</strong>
                        </div>

                        <!-- The amount agreed for this item. Only ever present
                             on the viewer's own sub-tasks — a colleague's fee
                             is not serialised to them at all. -->
                        <div v-if="task.agreed_compensation" class="subtask-fee">
                            <span>Agreed for this task</span>
                            <strong>{{ formatCurrency(task.agreed_compensation) }}</strong>
                        </div>

                        <input
                            type="range"
                            min="0"
                            max="100"
                            step="10"
                            :value="claimInFlightFor(task)?.percent_complete ?? task.progress_percentage ?? 0"
                            :disabled="!canUpdateTask(task) || !!claimInFlightFor(task)"
                            @change="(event) => updateTaskProgress(task, event.target.value)"
                        >

                        <!-- A bar that cannot be dragged reads as broken
                             unless it says why it is not yours to drag. -->
                        <p v-if="!isMyTask(task)" class="subtask-supervising">
                            <i class="fas fa-eye"></i>
                            {{ task.technician?.user?.name || 'The assigned technician' }} moves this one.
                            Yours is to approve what they claim, or send it back.
                        </p>

                        <!-- A claim counts only once someone signs it off, so
                             say plainly that it is waiting rather than showing
                             a bar that looks banked. -->
                        <p v-if="pendingClaimFor(task)" class="subtask-pending">
                            <i class="fas fa-hourglass-half"></i>
                            {{ claimAuthorLine(pendingClaimFor(task)) }} —
                            <template v-if="canSignOffClaim(pendingClaimFor(task))">awaiting your approval.</template>
                            <template v-else-if="pendingClaimFor(task).revised_by_lead_at && isLeadTechnician">
                                you answered the project team's query; it is back with them.
                            </template>
                            <!-- Whose desk it is actually on. The lead cannot
                                 ratify his own work, so his goes straight to
                                 the office; a crew member's waits for the lead
                                 first, and telling him the office had it sent
                                 him chasing the wrong people. -->
                            <template v-else-if="pendingClaimFor(task).submitted_by === technician.user_id && isLeadTechnician">
                                you wrote this, so the project team ratifies it.
                            </template>
                            <template v-else-if="pendingClaimFor(task).submitted_by === technician.user_id && job.lead_technician_id">
                                your lead signs this off before the office sees it.
                            </template>
                            <template v-else-if="pendingClaimFor(task).submitted_by === technician.user_id">
                                awaiting the project team.
                            </template>
                            <template v-else-if="isLeadTechnician">awaiting the project team.</template>
                            <template v-else>awaiting approval.</template>
                        </p>

                        <!-- Returned by the office. It is the lead's to answer
                             — correct the figure, edit the notes, or say why
                             it stands — and it goes back up, not straight
                             into the banked total. -->
                        <div v-else-if="returnedToLeadFor(task)" class="subtask-returned">
                            <p>
                                <i class="fas fa-reply"></i>
                                {{ returnedToLeadFor(task).percent_complete }}% sent back by
                                {{ returnedToLeadFor(task).rejector?.name || 'the project team' }}:
                                <strong>{{ returnedToLeadFor(task).rejection_reason }}</strong>
                            </p>
                            <p v-if="!isLeadTechnician" class="returned-owner">
                                Your lead is answering this.
                            </p>

                            <div v-else-if="revisingClaim?.id !== returnedToLeadFor(task).id">
                                <button class="btn btn-primary btn-full" @click="openRevise(returnedToLeadFor(task))">
                                    <i class="fas fa-pen"></i> Edit or comment
                                </button>
                            </div>

                            <div v-else class="revise-box">
                                <label class="form-field">
                                    <span>Progress %</span>
                                    <input v-model.number="reviseForm.percent_complete" type="number" min="0" max="100" class="input">
                                </label>
                                <label class="form-field">
                                    <span>Notes</span>
                                    <textarea v-model="reviseForm.notes" rows="3" class="input textarea"></textarea>
                                </label>
                                <label class="form-field">
                                    <span>Add a comment</span>
                                    <textarea
                                        v-model="reviseForm.comment"
                                        rows="2"
                                        class="input textarea"
                                        placeholder="Answer the query — e.g. the figure is right, the extra panels went in after the photos."
                                    ></textarea>
                                </label>
                                <!-- A returned report is most often asking for better
                                     evidence. Adding it here keeps the photographs with
                                     the claim they answer, instead of in a second report. -->
                                <div class="form-field">
                                    <span>Add photos</span>
                                    <PhotoUploader
                                        v-model="revisePhotos"
                                        :max="6"
                                        :disabled="busyReportId === revisingClaim.id"
                                        hint="Optional. Up to 6 photos backing up what you are saying here."
                                        @busy="preparingRevisePhotos = $event"
                                    />
                                </div>
                                <p v-if="reviseError" class="reject-error">{{ reviseError }}</p>
                                <div class="signoff-actions">
                                    <button
                                        class="btn btn-primary"
                                        :disabled="busyReportId === revisingClaim.id || preparingRevisePhotos"
                                        @click="submitRevision()"
                                    >
                                        {{ busyReportId === revisingClaim.id ? 'Sending…' : 'Send back up' }}
                                    </button>
                                    <button class="btn btn-outline" @click="cancelRevise()">Cancel</button>
                                </div>
                            </div>
                        </div>

                        <!-- Sent back to the crew member by the lead: theirs to
                             correct and resubmit, back to the lead — the same
                             report, not a fresh one. -->
                        <div v-else-if="rejectedClaimFor(task)" class="subtask-returned">
                            <p>
                                <i class="fas fa-rotate-left"></i>
                                {{ rejectedClaimFor(task).percent_complete }}% was sent back<span
                                    v-if="rejectedClaimFor(task).rejector?.name"
                                > by {{ rejectedClaimFor(task).rejector.name }}</span>:
                                <strong>{{ rejectedClaimFor(task).rejection_reason }}</strong>
                            </p>

                            <template v-if="isMyTask(task)">
                                <div v-if="revisingClaim?.id !== rejectedClaimFor(task).id">
                                    <button class="btn btn-primary btn-full" @click="openRevise(rejectedClaimFor(task))">
                                        <i class="fas fa-pen"></i> Edit &amp; resubmit
                                    </button>
                                </div>

                                <div v-else class="revise-box">
                                    <label class="form-field">
                                        <span>Progress %</span>
                                        <input v-model.number="reviseForm.percent_complete" type="number" min="0" max="100" class="input">
                                    </label>
                                    <label class="form-field">
                                        <span>Notes</span>
                                        <textarea v-model="reviseForm.notes" rows="3" class="input textarea"></textarea>
                                    </label>
                                    <label class="form-field">
                                        <span>Add a comment</span>
                                        <textarea
                                            v-model="reviseForm.comment"
                                            rows="2"
                                            class="input textarea"
                                            placeholder="What you put right — e.g. corrected to 60%, the last two panels went in after the photos."
                                        ></textarea>
                                    </label>
                                    <!-- A returned report is most often asking for better
                                         evidence. Adding it here keeps the photographs with
                                         the claim they answer, instead of in a second report. -->
                                    <div class="form-field">
                                        <span>Add photos</span>
                                        <PhotoUploader
                                                v-model="revisePhotos"
                                            :max="6"
                                            :disabled="busyReportId === revisingClaim.id"
                                            hint="Optional. Up to 6 photos backing up what you are saying here."
                                            @busy="preparingRevisePhotos = $event"
                                        />
                                    </div>
                                    <p v-if="reviseError" class="reject-error">{{ reviseError }}</p>
                                    <div class="signoff-actions">
                                        <button
                                            class="btn btn-primary"
                                            :disabled="busyReportId === revisingClaim.id || preparingRevisePhotos"
                                            @click="submitRevision()"
                                        >
                                            {{ busyReportId === revisingClaim.id ? 'Sending…' : 'Resubmit to lead' }}
                                        </button>
                                        <button class="btn btn-outline" @click="cancelRevise()">Cancel</button>
                                    </div>
                                </div>
                            </template>
                        </div>

                        <!-- The lead's sign-off on a crew member's claim. It
                             moves the work and the job percentage and is what
                             the office settles payment against, so it gets the
                             same weight as accepting the job — read on a phone,
                             on site, between other things. -->
                        <div v-if="canSignOffClaim(pendingClaimFor(task))" class="assignment-decision">
                            <button
                                class="btn-decision btn-decision-accept"
                                :disabled="busyReportId === pendingClaimFor(task).id"
                                @click="approveClaim(pendingClaimFor(task))"
                            >
                                <i class="fas fa-check-circle"></i>
                                <span>
                                    {{ busyReportId === pendingClaimFor(task).id ? 'Working…' : 'Approve this claim' }}
                                </span>
                                <span
                                    v-if="busyReportId !== pendingClaimFor(task).id"
                                    class="btn-decision-amount"
                                >
                                    {{ pendingClaimFor(task).percent_complete }}%
                                </span>
                            </button>
                            <button
                                class="btn-decision btn-decision-refuse"
                                :disabled="busyReportId === pendingClaimFor(task).id"
                                @click="openReject(pendingClaimFor(task))"
                            >
                                <i class="fas fa-rotate-left"></i>
                                Send back with a reason
                            </button>
                        </div>

                        <!-- Three answers to a claim, not two: take it as
                             filed, settle it lower, or send it back. The
                             middle one is the common case — the work is real
                             but not as far along as the man says — and without
                             it a lead either passed on a figure he did not
                             believe or bounced work that had genuinely been
                             done. Upwards is the office's, not his. -->
                        <div v-if="approvingClaim && approvingClaim.id === pendingClaimFor(task)?.id" class="reject-box">
                            <label class="form-field">
                                <span>Settle at</span>
                                <input
                                    v-model.number="approvalPercent"
                                    type="number"
                                    min="0"
                                    :max="approvingClaim.percent_complete"
                                    class="input"
                                >
                                <small class="field-note">
                                    {{ approvingClaim.percent_complete }}% claimed. Confirm that, or less if
                                    less has been done — to agree to more, the office has to do it.
                                </small>
                            </label>
                            <label class="form-field">
                                <span>
                                    {{ approvalLowered ? 'What did you see on site?' : 'Anything to note?' }}
                                    <em v-if="!approvalLowered">(optional)</em>
                                </span>
                                <textarea
                                    v-model="approvalNote"
                                    rows="2"
                                    class="input textarea"
                                    :placeholder="approvalLowered
                                        ? 'e.g. Only four of the eight diffusers are actually fitted.'
                                        : 'e.g. Walked the roof with them; capping is sound.'"
                                ></textarea>
                                <small v-if="approvalLowered" class="field-note">
                                    Lowering someone's figure is an argument about their work, so it carries the
                                    same obligation sending it back does. The technician and the office read this.
                                </small>
                            </label>
                            <p v-if="approveError" class="reject-error">{{ approveError }}</p>
                            <div class="signoff-actions">
                                <button
                                    class="btn btn-primary"
                                    :disabled="busyReportId === approvingClaim.id"
                                    @click="confirmApprove()"
                                >
                                    {{ busyReportId === approvingClaim.id
                                        ? 'Approving…'
                                        : (approvalLowered ? `Settle at ${approvalPercent}%` : 'Approve') }}
                                </button>
                                <button class="btn btn-outline" @click="cancelApprove()">Cancel</button>
                            </div>
                        </div>

                        <!-- Reason is required: sending work back without one
                             leaves the technician nothing to act on. -->
                        <div v-if="rejectingClaim && rejectingClaim.id === pendingClaimFor(task)?.id" class="reject-box">
                            <label class="form-field">
                                <span>Why is this being sent back?</span>
                                <textarea
                                    v-model="rejectReason"
                                    rows="3"
                                    class="input textarea"
                                    placeholder="e.g. Only four of the eight diffusers are actually fitted."
                                ></textarea>
                            </label>
                            <p v-if="rejectError" class="reject-error">{{ rejectError }}</p>
                            <div class="signoff-actions">
                                <button
                                    class="btn btn-primary"
                                    :disabled="busyReportId === rejectingClaim.id"
                                    @click="confirmReject()"
                                >
                                    {{ busyReportId === rejectingClaim.id ? 'Sending…' : 'Send back' }}
                                </button>
                                <button class="btn btn-outline" @click="cancelReject()">Cancel</button>
                            </div>
                        </div>
                    </article>
                </div>
            </section>

            <section class="panel-card">
                <div class="section-heading">
                    <div>
                        <span class="section-kicker">Progress Update</span>
                        <h3>Submit field progress</h3>
                    </div>
                </div>

                <div v-if="page.props.flash?.success" class="flash-success" style="background:var(--success-color,#22c55e);color:#fff;padding:.75rem 1rem;border-radius:.5rem;margin-bottom:1rem;font-weight:500;">
                    {{ page.props.flash.success }}
                </div>

                <div v-if="job.status === 'assigned'" class="info-banner" style="background:var(--warning-bg,#fef9c3);border:1px solid var(--warning-border,#fde047);color:var(--warning-text,#713f12);padding:.75rem 1rem;border-radius:.5rem;margin-bottom:1rem;">
                    <i class="fas fa-info-circle"></i>
                    You must <strong>Start the Job</strong> before submitting progress reports.
                </div>

                <form v-else @submit.prevent="submitProgressReport" class="progress-form">
                    <div class="form-grid">
                        <label class="form-field">
                            <span>Progress %</span>
                            <input v-model.number="progressForm.percent_complete" type="number" min="0" max="100" class="input">
                            <!-- The job's headline is the average across its
                                 tasks, so a whole-job figure typed over the top
                                 of it changes nothing. Said here rather than
                                 discovered later, when a lead who entered 100%
                                 wonders why the job still reads 0%. -->
                            <small v-if="reportingWholeSplitJob" class="field-note">
                                The job reads {{ subTaskAverage }}% — the average across its
                                {{ job.sub_tasks.length }} tasks. Move it by signing off your crew's
                                task reports and filing your own; what you enter here is your account
                                of the job, not the figure the client sees.
                            </small>
                        </label>

                        <label class="form-field">
                            <span>Report date</span>
                            <input v-model="progressForm.report_date" type="date" class="input">
                        </label>
                    </div>

                    <label class="form-field" v-if="job.sub_tasks?.length">
                        <span>Sub-task</span>
                        <select v-model="progressForm.service_sub_task_id" class="input">
                            <!-- The job as a whole is the lead's to report on.
                                 Everyone else reports their own sub-task, and
                                 those roll up into the job's percentage. -->
                            <option v-if="isLeadTechnician" value="">Whole job update</option>
                            <option
                                v-for="task in reportableSubTasks"
                                :key="task.id"
                                :value="task.id"
                            >
                                {{ task.title || task.name }}
                            </option>
                        </select>
                        <small v-if="!isLeadTechnician" class="field-hint">
                            Your sub-task progress counts towards the job's overall figure.
                        </small>
                        <!-- A lead covering for someone who never got their
                             report in. Say whose name it lands under before
                             they submit, not after. -->
                        <small v-else-if="onBehalfOf" class="field-hint field-hint-strong">
                            This will be filed for {{ onBehalfOf }} with you named as the author,
                            and goes to the project team to ratify.
                        </small>
                    </label>

                    <label class="form-field">
                        <span>Notes</span>
                        <textarea v-model="progressForm.notes" rows="4" class="input textarea" placeholder="Describe what was completed, what remains, and anything the PM/admin should know."></textarea>
                    </label>

                    <!-- A div, not a label: the uploader renders its own buttons
                         and thumbnails, and a wrapping label would hijack taps
                         on them. -->
                    <div class="form-field">
                        <span>Progress photos</span>
                        <PhotoUploader
                            ref="photoUploader"
                            v-model="photos"
                            :max="6"
                            :disabled="submittingReport"
                            hint="Up to 6 photos showing actual site progress. Take them one at a time or pick several from the gallery — they add up rather than replace each other."
                            @busy="preparingPhotos = $event"
                        />
                    </div>

                    <!-- Blocked while photos are still being resized, so a fast
                         tap can't submit a half-prepared queue. -->
                    <button type="submit" class="btn btn-primary" :disabled="submittingReport || preparingPhotos">
                        <span v-if="preparingPhotos">
                            <i class="fas fa-spinner fa-spin"></i> Preparing photos…
                        </span>
                        <span v-else-if="!submittingReport">
                            <i class="fas fa-camera"></i> Submit Progress Report
                        </span>
                        <span v-else>
                            <i class="fas fa-spinner fa-spin"></i>
                            {{ uploadStage || 'Submitting…' }}
                        </span>
                    </button>

                    <!-- Inline progress bar while uploading. Visible to technician
                         on the field so they know to keep the page open. -->
                    <div v-if="submittingReport" class="upload-progress" style="margin-top:.75rem;">
                        <div class="upload-progress-bar" style="background:#e5e7eb;height:6px;border-radius:999px;overflow:hidden;">
                            <div :style="{
                                width: (uploadPercent || 25) + '%',
                                height: '100%',
                                background: 'linear-gradient(90deg,#10b981,#059669)',
                                transition: 'width .4s ease',
                            }"></div>
                        </div>
                        <p style="font-size:.78rem;color:var(--text-muted);margin:.4rem 0 0;text-align:center;">
                            Please keep this screen open until you see the confirmation.
                        </p>
                    </div>
                </form>
            </section>

            <section class="panel-card">
                <div class="section-heading">
                    <div>
                        <span class="section-kicker">History</span>
                        <h3>Recent progress reports</h3>
                    </div>
                    <span class="section-pill">{{ progressReports.length }} total</span>
                </div>

                <div v-if="progressReports.length" class="report-list">
                    <article v-for="report in progressReports" :key="report.id" class="report-card">
                        <div class="report-top">
                            <div>
                                <strong>{{ formatShortDate(report.report_date) }}</strong>
                                <!-- Who wrote it, and whose work it is about.
                                     Those differ whenever someone files on
                                     another's behalf, and a report about your
                                     work that you did not write should never
                                     read as your own account of it. -->
                                <p>
                                    {{ authorLine(report) }}
                                    <span v-if="report.sub_task"> • {{ report.sub_task.title || report.sub_task.name }}</span>
                                </p>
                            </div>
                            <span :class="['status-badge', reportStateClass(report)]">
                                {{ reportStateLabel(report) }}
                            </span>
                        </div>

                        <p v-if="report.rejected_at" class="report-rejected-note">
                            Sent back<span v-if="report.rejector?.name"> by {{ report.rejector.name }}</span>:
                            {{ report.rejection_reason }}
                        </p>

                        <!-- Answering a report sent back from here, rather than
                             filing a second one. A whole-job report has no
                             sub-task card to answer from, so without this the
                             only way out of a query was a duplicate — which the
                             office then had to reconcile against the original. -->
                        <div v-if="canReviseFromHistory(report)" class="report-revise">
                            <div v-if="revisingClaim?.id !== report.id">
                                <button class="btn btn-primary btn-full" @click="openRevise(report)">
                                    <i class="fas fa-pen"></i> Edit &amp; resubmit
                                </button>
                            </div>

                            <div v-else class="revise-box">
                                <label class="form-field">
                                    <span>Progress %</span>
                                    <input v-model.number="reviseForm.percent_complete" type="number" min="0" max="100" class="input">
                                </label>
                                <label class="form-field">
                                    <span>Notes</span>
                                    <textarea v-model="reviseForm.notes" rows="3" class="input textarea"></textarea>
                                </label>
                                <label class="form-field">
                                    <span>Add a comment</span>
                                    <textarea
                                        v-model="reviseForm.comment"
                                        rows="2"
                                        class="input textarea"
                                        placeholder="Answer what was asked — e.g. corrected to 60%, the last two panels went in after the photos."
                                    ></textarea>
                                </label>
                                <div class="form-field">
                                    <span>Add photos</span>
                                    <PhotoUploader
                                        v-model="revisePhotos"
                                        :max="6"
                                        :disabled="busyReportId === report.id"
                                        hint="Optional. Up to 6 photos backing up what you are saying here."
                                        @busy="preparingRevisePhotos = $event"
                                    />
                                </div>
                                <p v-if="reviseError" class="reject-error">{{ reviseError }}</p>
                                <div class="signoff-actions">
                                    <button
                                        class="btn btn-primary"
                                        :disabled="busyReportId === report.id || preparingRevisePhotos"
                                        @click="submitRevision()"
                                    >
                                        {{ busyReportId === report.id ? 'Sending…' : reviseSubmitLabel(report) }}
                                    </button>
                                    <button class="btn btn-outline" @click="cancelRevise()">Cancel</button>
                                </div>
                            </div>
                        </div>

                        <p v-if="report.notes" class="report-notes">{{ report.notes }}</p>
                        <p v-if="report.validation_notes" class="report-validation-note">
                            PM/Admin note: {{ report.validation_notes }}
                        </p>

                        <!-- Tapping a photo used to dump the raw file into a
                             new browser tab, which on a phone leaves the PWA
                             entirely. Now it opens the swipeable carousel. -->
                        <JobPhotoGallery :photos="report.photos" show-removed-badge />
                    </article>
                </div>

                <p v-else class="empty-text">No progress reports submitted yet.</p>
            </section>

            <!-- The office has not cleared this job to start. Said here rather
                 than left to a failed tap on site, where the technician has
                 already travelled. -->
            <div v-if="commencementBlocker" class="commencement-hold">
                <i class="fas fa-pause-circle"></i>
                <div>
                    <strong>Do not start this job yet</strong>
                    <p>{{ commencementBlocker }}</p>
                </div>
            </div>

            <section class="floating-actions">
                <button
                    v-if="job.status === 'assigned'"
                    class="btn btn-primary"
                    :disabled="!!commencementBlocker"
                    :title="commencementBlocker || ''"
                    @click="updateStatus('en_route')"
                >
                    Start Job
                </button>
                <button
                    v-else-if="job.status === 'in_progress' && !job.technician_arrived"
                    class="btn btn-primary"
                    :disabled="!!commencementBlocker"
                    :title="commencementBlocker || ''"
                    @click="updateStatus('on_site')"
                >
                    Arrived On Site
                </button>
                <div v-else-if="job.status === 'in_progress'" class="floating-action-grid">
                    <Link href="/technician/tools" class="btn btn-outline">
                        <i class="fas fa-tools"></i>
                        Tools
                    </Link>
                    <!-- Closing the whole job is the lead's call. A sub-task
                         holder finishes their own item instead. -->
                    <button
                        v-if="isLeadTechnician"
                        class="btn btn-primary complete-btn"
                        @click="updateStatus('completed')"
                    >
                        Mark Complete
                    </button>
                    <span v-else-if="mySubTasks.length" class="lead-closes-note">
                        Set your sub-task to 100% — the lead closes the job.
                    </span>
                </div>
            </section>
        </main>

        <TechnicianBottomNav current-page="jobs" />
    </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import TechnicianBottomNav from '@/Components/TechnicianBottomNav.vue'
import PhotoUploader from '@/Components/PhotoUploader.vue'
import JobPhotoGallery from '@/Components/JobPhotoGallery.vue'

const page = usePage()

const props = defineProps({
    technician: { type: Object, required: true },
    job: { type: Object, required: true },
    compensationSummary: { type: Object, default: null },
    isLeadTechnician: { type: Boolean, default: false },
    scope: { type: Object, default: () => ({}) },
    assignmentFiles: { type: Array, default: () => [] },
    commencementBlocker: { type: String, default: null },
    pendingAssignments: { type: Array, default: () => [] },
})

// Answering the assignment.
const responding = ref(false)
const decliningId = ref(null)
const declineReason = ref('')

const acceptAssignment = (assignment) => {
    responding.value = true
    router.post(`/technician/assignments/${assignment.id}/accept`, {}, {
        preserveScroll: true,
        onFinish: () => { responding.value = false },
    })
}

const startDecline = (assignment) => {
    decliningId.value = assignment.id
    declineReason.value = ''
}

const declineAssignment = (assignment) => {
    responding.value = true
    router.post(`/technician/assignments/${assignment.id}/decline`, { reason: declineReason.value }, {
        preserveScroll: true,
        onFinish: () => { responding.value = false },
    })
}

// What this technician is owed: their own task, or — for the lead, who signs
// off the whole assignment — the job-wide list. Never the quotation's notes or
// any pricing. See TechnicianController::jobScopeForTechnician.
const scopeTasks = computed(() => props.scope?.sub_tasks || [])
const sharedDocuments = computed(() => props.job.documents || [])
const hasScopeDetail = computed(() =>
    Boolean(
        props.scope?.role_on_job ||
        scopeTasks.value.length ||
        props.scope?.expected_duration_days ||
        props.scope?.commencement_at ||
        props.scope?.target_completion_at,
    ),
)
const hasJobFiles = computed(() =>
    props.assignmentFiles.length > 0 || sharedDocuments.value.length > 0,
)

// Camera and gallery picks both land here, already compressed by the
// uploader (#27).
const photoUploader = ref(null)
const photos = ref([])
const preparingPhotos = ref(false)

const submittingReport = ref(false)
const uploadStage = ref('')
const uploadPercent = ref(0)
// A technician who does not lead the job has no "whole job" option, so the
// form opens on their own sub-task rather than on a choice the server will
// reject — and on that sub-task's progress, not the job's blended figure.
const ownSubTasks = (props.job.sub_tasks || []).filter(
    (task) => task.technician_id === props.technician.id,
)
const defaultSubTaskId =
    !props.isLeadTechnician && ownSubTasks.length ? ownSubTasks[0].id : ''

const progressForm = ref({
    percent_complete: Number(
        (defaultSubTaskId ? ownSubTasks[0].progress_percentage : props.job.progress_percentage) || 0,
    ),
    report_date: new Date().toISOString().slice(0, 10),
    notes: '',
    service_sub_task_id: defaultSubTaskId,
})

// What the job actually reads: the average across its tasks. The lead's
// whole-job report used to override this wherever it was higher, which is how
// a job came to say "work is fully complete" above two tasks at nothing.
const subTaskAverage = computed(() => {
    const tasks = props.job.sub_tasks || []
    if (!tasks.length) return Number(props.job.progress_percentage || 0)

    const total = tasks.reduce((sum, task) => sum + Number(task.progress_percentage || 0), 0)
    return Math.round(total / tasks.length)
})

const reportingWholeSplitJob = computed(() =>
    Boolean(props.job.sub_tasks?.length) && !progressForm.value.service_sub_task_id,
)

// Switching between the whole job and one task changes what the figure is
// about, so it is re-read rather than left on the last one — a lead who moved
// off a task at 40% was being offered 40% as the job's.
watch(
    () => progressForm.value.service_sub_task_id,
    (subTaskId) => {
        if (!subTaskId) {
            progressForm.value.percent_complete = subTaskAverage.value
            return
        }

        const task = (props.job.sub_tasks || []).find((t) => t.id === Number(subTaskId))
        progressForm.value.percent_complete = Number(task?.progress_percentage || 0)
    },
)

// Set when a lead has picked a sub-task that is not their own — the report
// will be about that technician's work, authored by the lead.
const onBehalfOf = computed(() => {
    if (!props.isLeadTechnician || !progressForm.value.service_sub_task_id) return null

    const task = (props.job.sub_tasks || [])
        .find((t) => t.id === Number(progressForm.value.service_sub_task_id))

    if (!task || task.technician_id === props.technician.id) return null

    return task.technician?.user?.name || 'the assigned technician'
})

const progressReports = computed(() => props.job.progress_reports || [])
const jobPhotos = computed(() => props.job.photos || [])
const recentPayouts = computed(() => (props.compensationSummary?.history || []).slice(0, 3))

// Reports ready for the lead to push up: the crew work they have ratified,
// plus their own, that has not already gone to the office. Un-reviewed crew
// claims are excluded — the lead approves them first, then posts the batch.
const postableReportCount = computed(() =>
    progressReports.value.filter((r) =>
        !r.submitted_to_office_at &&
        !r.rejected_at &&
        (r.approved_by_lead_at ||
            r.submitted_by === props.technician.user_id ||
            r.technician_id === props.technician.id)
    ).length
)
const postingReports = ref(false)
const postReportsToOffice = () => {
    const n = postableReportCount.value
    if (!confirm(`Post ${n} reviewed ${n === 1 ? 'report' : 'reports'} to the office? The client hears once, when the office releases the batch.`)) return
    postingReports.value = true
    router.post(`/technician/jobs/${props.job.id}/post-reports`, {}, {
        preserveScroll: true,
        onFinish: () => { postingReports.value = false },
    })
}

// Mirrors the server rule: your own sub-task, or all of them when you
// carry the job (lead / sole assignee).
const reportableSubTasks = computed(() => {
    return (props.job.sub_tasks || []).filter(
        (task) => props.isLeadTechnician || props.technician.id === task.technician_id,
    )
})

// The sub-tasks this technician is personally on — what they update from
// the job page when someone else leads the project.
const mySubTasks = computed(() =>
    (props.job.sub_tasks || []).filter((task) => task.technician_id === props.technician.id),
)

function getStatusClass(status) {
    const map = {
        assigned: 'status-assigned',
        in_progress: 'status-in_progress',
        completed: 'status-completed',
        completed_pending_confirmation: 'status-completed',
        pending: 'status-pending',
        approved: 'status-approved',
    }

    return map[status] || 'status-pending'
}

function formatStatus(status) {
    return status ? status.replace(/_/g, ' ').replace(/\b\w/g, (letter) => letter.toUpperCase()) : 'Unknown'
}

function formatShortDate(dateString) {
    if (!dateString) return 'No date'

    return new Intl.DateTimeFormat('en-KE', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    }).format(new Date(dateString))
}

function formatCurrency(amount) {
    return 'KES ' + Number(amount || 0).toLocaleString('en-KE', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    })
}

function isMyTask(task) {
    return props.technician.id === task.technician_id
}

// The bar belongs to whoever is doing the work. A lead supervising a task
// reads it and rules on what is claimed against it; he does not set it. His
// own task he moves like anybody else.
function canUpdateTask(task) {
    return isMyTask(task)
}

const CAPACITY_LABELS = {
    technician: 'technician',
    lead: 'lead technician',
    project_manager: 'project manager',
    admin: 'admin',
}

// "Filed by X" when it is their own work; "Filed by X for Y (lead)" when
// somebody wrote it on another's behalf. The distinction is the point: a
// ratified crew report and a report the lead wrote for them are different
// pieces of evidence about the same sub-task.
function authorLine(report) {
    const author = report.submitter?.name || 'Unknown'
    const subject = report.technician?.user?.name

    if (!report.authored_as || report.authored_as === 'technician') {
        return `Filed by ${subject || author}`
    }

    const capacity = CAPACITY_LABELS[report.authored_as] || report.authored_as
    return subject && subject !== author
        ? `Filed by ${author} (${capacity}) for ${subject}`
        : `Filed by ${author} (${capacity})`
}

function reportStateLabel(report) {
    if (isReturnedToLead(report)) return `With the lead · ${report.percent_complete}% claimed`
    if (report.rejected_at) return `Sent back · ${report.percent_complete}% claimed`

    if (!report.is_validated) return `Awaiting ratification · ${report.percent_complete}% claimed`

    const pct = report.validated_percent ?? report.percent_complete
    const by = CAPACITY_LABELS[report.validated_as] || 'project manager'

    // A lead's ratification counts for progress but not for money, so say so
    // rather than letting it read as fully settled.
    return report.approved_by_lead_at
        ? `Ratified on site · ${pct}%`
        : `Ratified by ${by} · ${pct}%`
}

function reportStateClass(report) {
    if (report.rejected_at) return 'status-rejected'
    if (!report.is_validated) return 'status-pending'
    return report.approved_by_lead_at ? 'status-onsite' : 'status-approved'
}

function claimsFor(task) {
    return progressReports.value
        .filter((report) => report.service_sub_task_id === task.id)
        .sort((a, b) => new Date(b.created_at) - new Date(a.created_at))
}

const OFFICE_CAPACITIES = ['project_manager', 'admin']

const isReturnedToLead = (report) =>
    Boolean(report?.rejected_at && OFFICE_CAPACITIES.includes(report.rejected_as))

// The newest claim still waiting on somebody. Until it is signed off the
// sub-task's own percentage is still the banked figure. A claim the lead sent
// back to the crew is not waiting — it is theirs to redo.
function pendingClaimFor(task) {
    return claimsFor(task).find((report) => !report.is_validated && !report.rejected_at)
}

// Sent back by the office and sitting with the lead. Still live work on this
// sub-task, so the slider stays shut while it is being answered.
function returnedToLeadFor(task) {
    const latest = claimsFor(task)[0]
    return isReturnedToLead(latest) ? latest : undefined
}

// Shown when the lead sent the crew member's claim back to them.
function rejectedClaimFor(task) {
    const latest = claimsFor(task)[0]
    return latest?.rejected_at && !isReturnedToLead(latest) ? latest : undefined
}

function claimInFlightFor(task) {
    return pendingClaimFor(task) || returnedToLeadFor(task)
}

// The lead settles the crew's claims. Nobody rules on their own account —
// which means both their own sub-task and anything they wrote themselves on
// somebody else's behalf. Mirrors authorizeLeadSignOff on the server.
function canSignOffClaim(claim) {
    return Boolean(
        claim &&
        props.isLeadTechnician &&
        claim.technician_id !== props.technician.id &&
        claim.submitted_by !== props.technician.user_id &&
        // Answered a query from the office — theirs to settle now, not the
        // lead's to re-sign on their own corrected figure.
        !claim.revised_by_lead_at,
    )
}

// Who is waiting on whom, in the claim's own terms.
function claimAuthorLine(claim) {
    const subject = claim.technician?.user?.name || 'the technician'

    if (!claim.authored_as || claim.authored_as === 'technician') {
        return `${claim.percent_complete}% submitted by ${subject}`
    }

    const author = claim.submitter?.name || 'someone else'
    const capacity = CAPACITY_LABELS[claim.authored_as] || claim.authored_as
    return `${claim.percent_complete}% filed for ${subject} by ${author} (${capacity})`
}

const busyReportId = ref(null)
const rejectingClaim = ref(null)
const rejectReason = ref('')
const rejectError = ref('')

const approvingClaim = ref(null)
const approvalNote = ref('')
const approvalPercent = ref(0)
const approveError = ref('')

// Settling below what was claimed is a different act from confirming it, and
// the form says so as soon as the figure moves.
const approvalLowered = computed(() =>
    Boolean(approvingClaim.value)
    && Number(approvalPercent.value) < Number(approvingClaim.value.percent_complete),
)

// Opens the box rather than approving outright — one extra tap, in exchange
// for the lead being able to settle the figure and say what he saw.
function approveClaim(claim) {
    if (!claim) return
    approvingClaim.value = claim
    approvalNote.value = ''
    approvalPercent.value = Number(claim.percent_complete)
    approveError.value = ''
    rejectingClaim.value = null
}

function cancelApprove() {
    approvingClaim.value = null
    approvalNote.value = ''
    approveError.value = ''
}

function confirmApprove() {
    const claim = approvingClaim.value
    if (!claim) return

    const claimed = Number(claim.percent_complete)
    const settled = Number(approvalPercent.value)

    // Checked here as well as on the server so the lead is told before the
    // round trip, not after it.
    if (!Number.isFinite(settled) || settled < 0 || settled > claimed) {
        approveError.value = `Confirm this at ${claimed}% or less. Agreeing to more than was claimed is the office's to do.`
        return
    }

    if (settled < claimed && !approvalNote.value.trim()) {
        approveError.value = 'Say what you saw on site before settling this below what was claimed.'
        return
    }

    approveError.value = ''
    busyReportId.value = claim.id

    router.post(`/technician/progress-reports/${claim.id}/approve`, {
        approval_note: approvalNote.value || null,
        validated_percent: settled,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            approvingClaim.value = null
            approvalNote.value = ''
            approveError.value = ''
        },
        onFinish: () => { busyReportId.value = null },
    })
}

const revisingClaim = ref(null)
const reviseForm = ref({ percent_complete: 0, notes: '', comment: '' })
const reviseError = ref('')
// Photographs added while answering. Kept out of reviseForm because the
// uploader owns the queue and is reset through its own ref.
// No uploader ref to reset: the box is removed from the page when the answer
// is sent or cancelled, so the queue goes with it. A ref would also be an
// array here — one of these boxes renders inside the history's v-for.
const revisePhotos = ref([])
const preparingRevisePhotos = ref(false)

// May this technician answer a report that was sent back, rather than file a
// fresh one? Mirrors reviseReturnedReport on the server: the office returns to
// the lead, the lead returns to whoever's work it is.
function canReviseReport(report) {
    if (!report?.rejected_at) return false

    if (isReturnedToLead(report)) {
        return props.isLeadTechnician
    }

    return report.technician_id === props.technician.id
        || report.submitted_by === props.technician.user_id
}

// The sub-task cards already offer the latest claim on each task. Anything
// else that was sent back — a whole-job report above all, which has no card of
// its own — is answered from the history list, so no returned report is left
// without a way back other than filing a duplicate.
function offeredOnSubTaskCard(report) {
    if (!report.service_sub_task_id) return false

    const task = (props.job.sub_tasks || []).find((t) => t.id === report.service_sub_task_id)

    return Boolean(task && claimsFor(task)[0]?.id === report.id)
}

function canReviseFromHistory(report) {
    return canReviseReport(report) && !offeredOnSubTaskCard(report)
}

// An answer to the office goes back to the office; an answer to the lead goes
// back to the lead. The button says which, so nobody expects the client to
// hear about it next.
function reviseSubmitLabel(report) {
    return isReturnedToLead(report) ? 'Send back to the office' : 'Resubmit to lead'
}

function openRevise(claim) {
    revisingClaim.value = claim
    reviseError.value = ''
    revisePhotos.value = []
    reviseForm.value = {
        percent_complete: claim.percent_complete,
        notes: claim.notes || '',
        comment: '',
    }
}

function cancelRevise() {
    revisingClaim.value = null
    reviseError.value = ''
    revisePhotos.value = []
    preparingRevisePhotos.value = false
}

function submitRevision() {
    const claim = revisingClaim.value
    if (!claim || preparingRevisePhotos.value) return

    const unchanged =
        Number(reviseForm.value.percent_complete) === Number(claim.percent_complete) &&
        (reviseForm.value.notes || '') === (claim.notes || '') &&
        !reviseForm.value.comment.trim() &&
        revisePhotos.value.length === 0

    if (unchanged) {
        reviseError.value = 'Change the figure, edit the notes, add a photo, or add a comment before sending it back.'
        return
    }

    // FormData rather than a plain object: the answer can carry photographs,
    // which is the commonest thing a returned report is asking for.
    const formData = new FormData()
    formData.append('percent_complete', reviseForm.value.percent_complete)
    formData.append('notes', reviseForm.value.notes || '')
    formData.append('comment', reviseForm.value.comment || '')
    revisePhotos.value.forEach((file, index) => {
        formData.append(`photos[${index}]`, file)
    })

    busyReportId.value = claim.id
    router.post(`/technician/progress-reports/${claim.id}/revise`, formData, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => cancelRevise(),
        onError: (errors) => {
            reviseError.value = errors.comment || errors.percent_complete
                || errors['photos.0'] || 'Could not send that back.'
        },
        onFinish: () => { busyReportId.value = null },
    })
}

function openReject(claim) {
    rejectingClaim.value = claim
    rejectReason.value = ''
    rejectError.value = ''
}

function cancelReject() {
    rejectingClaim.value = null
    rejectReason.value = ''
    rejectError.value = ''
}

function confirmReject() {
    const claim = rejectingClaim.value
    if (!claim) return

    if (rejectReason.value.trim().length < 5) {
        rejectError.value = 'Give the technician something to act on — a few words at least.'
        return
    }

    busyReportId.value = claim.id
    router.post(`/technician/progress-reports/${claim.id}/reject`, {
        rejection_reason: rejectReason.value.trim(),
    }, {
        preserveScroll: true,
        onSuccess: () => cancelReject(),
        onError: (errors) => { rejectError.value = errors.rejection_reason || 'Could not send that back.' },
        onFinish: () => { busyReportId.value = null },
    })
}

function updateTaskProgress(task, value) {
    router.post(`/technician/sub-tasks/${task.id}/progress`, {
        progress_percentage: parseInt(value, 10),
    }, {
        preserveScroll: true,
    })
}

function updateStatus(action) {
    // Completing is the lead's sign-off and goes to the office, so it asks
    // for a note rather than a bare confirmation. The other transitions are
    // simple facts about where somebody is.
    if (action === 'completed') {
        const note = window.prompt(
            'Signing this job off as complete. Anything the office should know? (optional)',
            ''
        )
        if (note === null) return

        router.post(`/technician/jobs/${props.job.id}/status`, {
            action,
            completion_note: note || null,
        }, { preserveScroll: true })
        return
    }

    if (!confirm(`Update job status to ${action.replace('_', ' ')}?`)) return

    router.post(`/technician/jobs/${props.job.id}/status`, { action }, {
        preserveScroll: true,
    })
}

async function submitProgressReport() {
    if (preparingPhotos.value) return

    submittingReport.value = true
    uploadStage.value = 'Uploading…'

    // Photos arrive already compressed from PhotoUploader — without that a
    // 5-photo submission from an iPhone over 4G easily tops 30 MB and times
    // out.
    const allFiles = photos.value

    const formData = new FormData()
    formData.append('percent_complete', progressForm.value.percent_complete)
    formData.append('report_date', progressForm.value.report_date)
    formData.append('notes', progressForm.value.notes || '')

    if (progressForm.value.service_sub_task_id) {
        formData.append('service_sub_task_id', progressForm.value.service_sub_task_id)
    }

    allFiles.forEach((file, index) => {
        formData.append(`photos[${index}]`, file)
    })

    // STAGE 2 — upload with real-time progress feedback
    uploadPercent.value = 0
    router.post(`/technician/jobs/${props.job.id}/progress-report`, formData, {
        forceFormData: true,
        preserveScroll: true,
        onProgress: (event) => {
            if (event?.percentage != null) {
                uploadPercent.value = event.percentage
                if (event.percentage < 100) {
                    uploadStage.value = `Uploading… ${event.percentage}%`
                } else {
                    uploadStage.value = 'Saving your report…'
                }
            }
        },
        onSuccess: () => {
            submittingReport.value = false
            uploadStage.value = ''
            uploadPercent.value = 0
            progressForm.value.notes = ''
            // Back to their own sub-task, not to a "whole job" they may not
            // be allowed to report on.
            progressForm.value.service_sub_task_id = defaultSubTaskId
            // Only clear the queue on success — on failure the technician
            // keeps their photos and can retry without re-shooting.
            photoUploader.value?.reset()
        },
        onError: () => {
            submittingReport.value = false
            uploadStage.value = ''
            uploadPercent.value = 0
        },
    })
}

defineOptions({ layout: null })
</script>

<style scoped>
/*
 * Decisions, sized like decisions.
 *
 * Accepting a job and signing off a crew member's claim are the two things a
 * technician is actually asked to decide, and both were ordinary buttons on a
 * long page read one-handed on site. Same treatment as the client's quotation
 * decision, for the same reason: the thing the screen exists to ask should be
 * the thing you cannot miss.
 */
.assignment-decision {
    display: flex;
    flex-direction: column;
    gap: 0.6rem;
    margin-top: 0.9rem;
}

.btn-decision {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.55rem;
    width: 100%;
    padding: 0.95rem 1.25rem;
    border: 2px solid transparent;
    border-radius: 12px;
    font-size: 1.02rem;
    font-weight: 700;
    cursor: pointer;
    transition: transform 0.12s ease, background 0.2s ease, border-color 0.2s ease;
}

.btn-decision:disabled {
    opacity: 0.65;
    cursor: not-allowed;
}

.btn-decision-accept {
    background: #16a34a;
    color: #ffffff;
    box-shadow: 0 6px 16px rgba(22, 163, 74, 0.28);
}

.btn-decision-accept:hover:not(:disabled) {
    background: #15803d;
    transform: translateY(-1px);
}

.btn-decision-amount {
    padding: 0.12rem 0.55rem;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.22);
    font-size: 0.92rem;
}

/* Quieter than Accept, and never the easier of the two to press. */
.btn-decision-refuse {
    background: #ffffff;
    border-color: #e2e8f0;
    color: #b91c1c;
    font-size: 0.95rem;
    font-weight: 600;
}

.btn-decision-refuse:hover:not(:disabled) {
    border-color: #fca5a5;
    background: #fef2f2;
}


.job-page {
    display: grid;
    gap: 1rem;
    padding-bottom: calc(var(--nav-height) + 5rem);
}

.header-main {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.header-back {
    width: auto;
    padding: 0.5rem;
    border: none;
    font-size: 1rem;
}

.header-subtitle {
    margin: 0.15rem 0 0;
    font-size: 0.8rem;
    color: var(--light-text);
}

.hero-card,
.panel-card,
.meta-tile,
.subtask-card,
.report-card,
.photo-card {
    border-radius: 16px;
    border: 1px solid var(--border-color);
    box-shadow: 0 12px 30px rgba(15, 23, 42, 0.06);
}

.hero-card,
.panel-card {
    background: var(--white);
    padding: 1rem;
}

.hero-card {
    background:
        radial-gradient(circle at top right, rgba(56, 189, 248, 0.18), transparent 30%),
        linear-gradient(180deg, #ffffff, #f8fbff);
}

.hero-kicker,
.section-kicker {
    display: inline-flex;
    font-size: 0.72rem;
    font-weight: 800;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: #0f6c8f;
}

.hero-copy h2,
.section-heading h3 {
    margin: 0.35rem 0 0;
    color: var(--text-color);
}

.hero-copy p,
.subtask-top p,
.report-top p,
.report-notes,
.report-validation-note,
.form-hint,
.empty-text {
    color: var(--light-text);
}

.hero-meta-grid,
.form-grid {
    display: grid;
    gap: 0.75rem;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    margin-top: 1rem;
}

.meta-tile {
    background: rgba(255, 255, 255, 0.9);
    padding: 0.85rem;
}

.meta-tile span,
.info-row span,
.subtask-meta span,
.section-pill {
    color: var(--light-text);
    font-size: 0.78rem;
}

.meta-tile strong,
.info-row strong,
.subtask-meta strong,
.subtask-top strong,
.report-top strong {
    display: block;
    margin-top: 0.25rem;
    color: var(--text-color);
}

.section-heading,
.subtask-top,
.subtask-meta,
.report-top,
.floating-action-grid {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 0.75rem;
}

.section-pill {
    display: inline-flex;
    padding: 0.42rem 0.72rem;
    border-radius: 999px;
    background: #f1f5f9;
    font-weight: 700;
}

.info-list,
.progress-form,
.subtask-list,
.report-list {
    display: grid;
    gap: 0.75rem;
}

.info-row {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 0.75rem;
    padding: 0.8rem 0;
    border-bottom: 1px solid var(--border-color);
}

.info-row:last-child {
    border-bottom: none;
    padding-bottom: 0;
}

.full-width {
    width: 100%;
    margin-top: 0.75rem;
}

.subtask-card,
.report-card {
    background: #f8fafc;
    padding: 0.9rem;
}

.subtask-meta {
    margin: 0.75rem 0 0.5rem;
    align-items: center;
}

.form-field {
    display: grid;
    gap: 0.35rem;
}

.form-field span {
    font-size: 0.78rem;
    font-weight: 700;
    color: #475569;
}

.field-note {
    font-size: 0.74rem;
    line-height: 1.45;
    color: #64748b;
}

.input {
    width: 100%;
    border: 1px solid #d7dee7;
    border-radius: 12px;
    padding: 0.8rem 0.9rem;
    background: #ffffff;
    color: var(--text-color);
    font: inherit;
}

.textarea {
    min-height: 110px;
    resize: vertical;
}

.form-hint {
    font-size: 0.75rem;
}

.report-notes,
.report-validation-note {
    margin: 0.65rem 0 0;
    line-height: 1.55;
}

.report-validation-note {
    color: #0f6c8f;
    font-weight: 600;
}

.photo-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.75rem;
    margin-top: 0.9rem;
}

.photo-card {
    position: relative;
    overflow: hidden;
    display: block;
    min-height: 110px;
    background: #e2e8f0;
}

.photo-card img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}

.photo-flag {
    position: absolute;
    left: 0.5rem;
    bottom: 0.5rem;
    padding: 0.3rem 0.55rem;
    border-radius: 999px;
    background: rgba(15, 23, 42, 0.78);
    color: #ffffff;
    font-size: 0.68rem;
    font-weight: 700;
}

.floating-actions {
    position: fixed;
    left: 1rem;
    right: 1rem;
    bottom: calc(var(--nav-height) + 0.9rem);
    z-index: 20;
}

.floating-action-grid {
    gap: 0.5rem;
}

.floating-action-grid .btn {
    flex: 1;
}

.complete-btn {
    background: var(--success-color);
}

.subtask-supervising {
    margin: 0.4rem 0 0;
    font-size: 0.76rem;
    color: #64748b;
    display: flex;
    gap: 0.4rem;
    align-items: baseline;
}

.subtask-mine {
    border-left: 3px solid var(--primary-color);
}

.mine-pill {
    display: inline-block;
    margin-left: .45rem;
    padding: .1rem .45rem;
    border-radius: 999px;
    background: #eff6ff;
    color: #1d4ed8;
    font-size: .68rem;
    font-weight: 700;
    vertical-align: middle;
}

.subtask-pending {
    margin: .5rem 0 0;
    font-size: .8rem;
    line-height: 1.4;
    color: #92400e;
    background: #fffbeb;
    border: 1px solid #fde68a;
    border-radius: .45rem;
    padding: .5rem .6rem;
}

.status-onsite {
    background: #ecfeff;
    color: #0e7490;
}

.status-rejected {
    background: #fff1f2;
    color: #9f1239;
}

.report-rejected-note {
    margin: .4rem 0 .5rem;
    font-size: .82rem;
    line-height: 1.45;
    color: #9f1239;
}

/* The way out of a returned report, sitting directly under the reason it was
   returned for. */
.report-revise {
    margin: .25rem 0 .6rem;
}

.subtask-returned {
    margin-top: .5rem;
    font-size: .8rem;
    line-height: 1.45;
    color: #075985;
    background: #f0f9ff;
    border: 1px solid #bae6fd;
    border-radius: .45rem;
    padding: .6rem;
}

.subtask-returned p { margin: 0 0 .5rem; }
.subtask-returned p:last-child { margin-bottom: 0; }
.returned-owner { font-style: italic; opacity: .85; }
.btn-full { width: 100%; }

.revise-box {
    margin-top: .5rem;
    padding-top: .5rem;
    border-top: 1px dashed #bae6fd;
}

.subtask-rejected {
    margin: .5rem 0 0;
    font-size: .8rem;
    line-height: 1.45;
    color: #9f1239;
    background: #fff1f2;
    border: 1px solid #fecdd3;
    border-radius: .45rem;
    padding: .5rem .6rem;
}

.signoff-actions {
    display: flex;
    gap: .5rem;
    margin-top: .6rem;
}

.signoff-actions .btn {
    flex: 1;
}

.btn-reject {
    border-color: #fecdd3;
    color: #9f1239;
}

.reject-box {
    margin-top: .6rem;
    padding: .7rem;
    border: 1px dashed #cbd5e1;
    border-radius: .5rem;
    background: #f8fafc;
}

.reject-error {
    margin: .4rem 0 0;
    font-size: .78rem;
    color: #b91c1c;
}

.field-hint {
    display: block;
    margin-top: .35rem;
    font-size: .78rem;
    color: var(--text-muted, #64748b);
}

.field-hint-strong {
    color: #0e7490;
    font-weight: 600;
}


.subtask-fee {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: .75rem;
    margin-top: .6rem;
    padding-top: .6rem;
    border-top: 1px dashed #e2e8f0;
    font-size: .85rem;
}

.subtask-fee span { color: var(--text-muted, #64748b); }

.file-row {
    text-decoration: none;
    color: inherit;
}

.file-row strong { color: var(--primary-color); }

.lead-closes-note {
    flex: 1;
    align-self: center;
    font-size: .8rem;
    line-height: 1.35;
    color: var(--text-muted, #64748b);
}

.post-reports-nudge {
    display: flex;
    align-items: flex-start;
    gap: .55rem;
    margin: 0 0 1rem;
    padding: .7rem .9rem;
    border-radius: .6rem;
    background: #fef3c7;
    border: 1px solid #fcd34d;
    color: #92400e;
    font-size: .82rem;
    line-height: 1.4;
}
.post-reports-nudge i {
    margin-top: .12rem;
}

@media (max-width: 640px) {
    .hero-meta-grid,
    .form-grid,
    .photo-grid {
        grid-template-columns: 1fr;
    }

    .section-heading,
    .report-top,
    .subtask-top,
    .subtask-meta {
        flex-direction: column;
    }
}

/* Office hold on starting work. Muted rather than alarming — this is a
   normal state on a job whose paperwork is still moving, not a fault. */
.assignment-response {
    margin: 0 0 1rem;
    padding: 1rem;
    background: #EFF6FF;
    border: 1px solid #BFDBFE;
    border-radius: 12px;
    color: #1E3A8A;
    font-size: 0.9rem;
}
.assignment-response-copy strong { display: block; margin-bottom: 4px; }
.assignment-response-copy p { margin: 0 0 0.75rem; line-height: 1.45; }
.assignment-response-actions {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.5rem;
}
.assignment-decline-form textarea { width: 100%; margin-bottom: 0.5rem; }
.assignment-error { color: #B91C1C; margin: 0 0 0.5rem; font-size: 0.85rem; }
.assignment-response .btn-danger { background: #DC2626; color: #fff; border: none; }
.assignment-response .btn:disabled { opacity: 0.5; cursor: not-allowed; }

.commencement-hold {
    display: flex;
    gap: 0.7rem;
    align-items: flex-start;
    margin: 0 1rem 0.75rem;
    padding: 0.85rem 1rem;
    background: #FFFBEB;
    border: 1px solid #FDE68A;
    border-radius: 12px;
    color: #92400E;
    font-size: 0.88rem;
    line-height: 1.45;
}
.commencement-hold i {
    margin-top: 2px;
    color: #D97706;
    font-size: 1.05rem;
    flex-shrink: 0;
}
.commencement-hold strong { display: block; margin-bottom: 2px; }
.commencement-hold p { margin: 0; }

.floating-actions .btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.scope-role {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin: 0 0 0.85rem;
    padding: 0.6rem 0.8rem;
    background: #EFF6FF;
    border: 1px solid #BFDBFE;
    border-radius: 10px;
    color: #1E40AF;
    font-size: 0.9rem;
    font-weight: 600;
}
.scope-tasks { margin-bottom: 0.5rem; }
.scope-tasks h4 {
    margin: 0 0 0.5rem;
    font-size: 0.72rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: #64748B;
}
.scope-task {
    padding: 0.6rem 0.75rem;
    border: 1px solid #E2E8F0;
    border-radius: 10px;
    background: #fff;
}
.scope-task + .scope-task { margin-top: 0.5rem; }
.scope-task strong { display: block; color: #0F172A; font-size: 0.9rem; }
.scope-task p { margin: 0.25rem 0 0; color: #475569; font-size: 0.85rem; line-height: 1.45; }
</style>
