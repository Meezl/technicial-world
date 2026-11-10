<template>
    <div class="dashboard-container">
        <AdminSidebar current-page="mpesa-transactions" />

        <main class="main-content mp">
            <!-- Header -->
            <header class="mp-header">
                <div>
                    <h1>M-Pesa Transactions</h1>
                    <p class="mp-sub">
                        Paybill <strong v-if="paybill">{{ paybill }}</strong> and STK push receipts.
                        Paybill payments are matched to the REQ in the account number automatically.
                    </p>
                </div>
                <div class="mp-tools" ref="toolsRef">
                    <button class="mp-btn mp-btn-ghost" @click="toolsOpen = !toolsOpen" :aria-expanded="toolsOpen">
                        <i class="fas fa-plug"></i> Daraja setup <i class="fas fa-chevron-down mp-caret"></i>
                    </button>
                    <div v-if="toolsOpen" class="mp-menu" role="menu">
                        <button role="menuitem" @click="diagnose" :disabled="diagnosing">
                            <i class="fas fa-stethoscope"></i>
                            <span><strong>{{ diagnosing ? 'Checking…' : 'Test credentials' }}</strong><small>Checks OAuth without exposing keys</small></span>
                        </button>
                        <button role="menuitem" @click="registerC2BUrls" :disabled="registering">
                            <i class="fas fa-link"></i>
                            <span><strong>{{ registering ? 'Registering…' : 'Register paybill URLs' }}</strong><small>Once per shortcode, or after a domain change</small></span>
                        </button>
                    </div>
                </div>
            </header>

            <!-- Daraja diagnostics -->
            <div v-if="diagnoseResult" class="mp-panel mp-diag">
                <div class="mp-diag-head">
                    <h4>Daraja credential check</h4>
                    <button class="mp-x" @click="diagnoseResult = null" aria-label="Close">&times;</button>
                </div>
                <dl class="mp-kv">
                    <dt>Environment</dt>
                    <dd><code>{{ diagnoseResult.environment }}</code>
                        <span v-if="!diagnoseResult.environment_valid" class="mp-bad">must be "production" or "sandbox"</span></dd>
                    <dt>Base URL</dt><dd><code>{{ diagnoseResult.base_url_in_use }}</code></dd>
                    <dt>Shortcode</dt><dd><code>{{ diagnoseResult.shortcode }}</code></dd>
                    <template v-for="(c, name) in diagnoseResult.credentials" :key="name">
                        <dt>{{ name }}</dt>
                        <dd>
                            <span v-if="!c.set" class="mp-bad">Missing</span>
                            <span v-else>length {{ c.length }}, <code>{{ c.preview }}</code>
                                <span v-if="c.trimmed_differs" class="mp-bad">has whitespace</span>
                                <span v-if="c.has_quotes" class="mp-bad">has quotes</span>
                            </span>
                        </dd>
                    </template>
                    <dt>OAuth token</dt>
                    <dd :class="diagnoseResult.oauth_test?.token_obtained ? 'mp-good' : 'mp-bad'">
                        {{ diagnoseResult.oauth_test?.token_obtained ? 'Obtained' : 'Not obtained' }}
                    </dd>
                </dl>
                <p class="mp-next"><strong>Next step:</strong> {{ diagnoseResult.next_step }}</p>
            </div>

            <div v-if="registerResult" :class="['mp-panel', 'mp-diag', registerResult.success ? 'is-ok' : 'is-err']">
                <div class="mp-diag-head">
                    <h4>{{ registerResult.success ? 'Paybill URLs registered' : 'Registration failed' }}</h4>
                    <button class="mp-x" @click="registerResult = null" aria-label="Close">&times;</button>
                </div>
                <p>{{ registerResult.message }}</p>
                <dl v-if="registerResult.confirmation_url" class="mp-kv">
                    <dt>Confirmation</dt><dd><code>{{ registerResult.confirmation_url }}</code></dd>
                    <dt>Validation</dt><dd><code>{{ registerResult.validation_url }}</code></dd>
                    <dt>Shortcode</dt><dd><code>{{ registerResult.shortcode }}</code> ({{ registerResult.environment }})</dd>
                </dl>
                <details v-if="registerResult.raw">
                    <summary>Safaricom response</summary>
                    <pre>{{ JSON.stringify(registerResult.raw, null, 2) }}</pre>
                </details>
            </div>

            <!-- Stats -->
            <section class="mp-stats">
                <div class="mp-stat">
                    <span class="mp-stat-label">Received today</span>
                    <span class="mp-stat-value">{{ kes(stats.today) }}</span>
                    <span class="mp-stat-foot">{{ stats.today_count }} receipt{{ stats.today_count === 1 ? '' : 's' }}</span>
                </div>
                <div class="mp-stat">
                    <span class="mp-stat-label">This month</span>
                    <span class="mp-stat-value">{{ kes(stats.month) }}</span>
                    <span class="mp-stat-foot">{{ stats.month_count }} receipt{{ stats.month_count === 1 ? '' : 's' }}</span>
                </div>
                <button
                    :class="['mp-stat', 'mp-stat-action', { 'is-warn': stats.open_count > 0 }]"
                    @click="setView('queue')"
                >
                    <span class="mp-stat-label">Needs action</span>
                    <span class="mp-stat-value">{{ stats.open_count }}</span>
                    <span class="mp-stat-foot">
                        <template v-if="stats.open_count">{{ kes(stats.open_amount) }} not yet on a bill</template>
                        <template v-else>Every paybill receipt is on a bill</template>
                    </span>
                </button>
                <div class="mp-stat">
                    <span class="mp-stat-label">Matched automatically</span>
                    <span class="mp-stat-value">{{ stats.auto_rate === null ? '—' : stats.auto_rate + '%' }}</span>
                    <span class="mp-stat-foot">of {{ stats.paybill_30 }} paybill receipts, last 30 days</span>
                </div>
            </section>

            <!-- List -->
            <section class="mp-panel mp-list">
                <nav class="mp-tabs" role="tablist">
                    <button
                        v-for="tab in tabs"
                        :key="tab.value"
                        role="tab"
                        :aria-selected="filters.view === tab.value"
                        :class="['mp-tab', { active: filters.view === tab.value, warn: tab.value === 'queue' && counts.queue > 0 }]"
                        @click="setView(tab.value)"
                    >
                        {{ tab.label }}
                        <span v-if="tab.value === 'audit'" :class="['mp-tab-count', { 'is-bad': audit && audit.length }]">{{ audit ? audit.length : '?' }}</span>
                        <span v-else class="mp-tab-count">{{ counts[tab.value] ?? 0 }}</span>
                    </button>
                </nav>

                <div v-if="filters.view === 'audit'" class="mp-audit">
                    <p class="mp-hint">
                        <i class="fas fa-balance-scale"></i>
                        <span>Each M-Pesa receipt is checked against the bills it marked paid: the amount received against the amount
                        marked paid, the bill type (a deposit still owed must be paid first), and the REQ in the account number.
                        <strong>Correct</strong> reopens the bills and re-applies the money to the same REQ: deposit first, part
                        payments split, anything extra held for the next bill.</span>
                    </p>
                    <div v-if="!audit || !audit.length" class="mp-empty">
                        <i class="fas fa-check-circle"></i>
                        <strong>Every receipt matches what it settled</strong>
                        <span>No paid bill is overstated, on the wrong type, or on a REQ the account number disagrees with.</span>
                    </div>
                    <article v-for="f in audit" :key="f.receipt" class="mp-finding">
                        <header>
                            <div>
                                <code class="mp-ref">{{ f.receipt }}</code>
                                <span class="mp-muted mp-small"> · {{ f.source === 'paybill' ? 'Paybill' : f.source === 'stk_push' ? 'STK push' : 'Payment record' }}</span>
                                <span v-if="f.payer" class="mp-muted mp-small"> · {{ f.payer }}</span>
                                <span v-if="f.bill_ref" class="mp-muted mp-small"> · account no. <code>{{ f.bill_ref }}</code></span>
                            </div>
                            <div v-if="f.service_request" class="mp-req">
                                <Link :href="`/admin/jobs/${f.service_request.id}`">{{ f.service_request.request_id }}</Link>
                                <span class="mp-muted"> · {{ f.service_request.client }}</span>
                            </div>
                        </header>
                        <div class="mp-finding-figs">
                            <div><span>Received</span><strong>{{ kes(f.received) }}</strong></div>
                            <div><span>Marked paid</span><strong :class="f.difference >= 1 ? 'mp-bad-text' : ''">{{ kes(f.settled) }}</strong></div>
                            <div class="mp-finding-bills">
                                <span>On bills</span>
                                <div>
                                    <span v-for="b in f.bills" :key="b.id" class="mp-finding-bill">
                                        <span :class="['mp-kind', kindClass(b.label)]">{{ b.label }}</span>
                                        {{ b.code }} · {{ kes(b.amount) }}
                                    </span>
                                </div>
                            </div>
                        </div>
                        <ul class="mp-issues">
                            <li v-for="(i, idx) in f.issues" :key="idx" :class="'issue-' + i.type">
                                <i :class="i.type === 'different_req' ? 'fas fa-question-circle' : 'fas fa-exclamation-triangle'"></i>
                                <span>{{ i.message }}</span>
                            </li>
                        </ul>
                        <p v-if="f.job_warning" class="mp-callout mp-small">{{ f.job_warning }}</p>
                        <footer>
                            <span v-if="f.blocker" class="mp-muted mp-small">{{ f.blocker }}</span>
                            <span class="mp-spacer"></span>
                            <button v-if="f.transaction_id && !f.blocker" class="mp-btn mp-btn-ghost mp-btn-sm" @click="reverseMatch({ id: f.transaction_id, receipt_number: f.receipt })">
                                Reverse and match by hand
                            </button>
                            <button v-if="f.fixable" class="mp-btn mp-btn-primary mp-btn-sm" :disabled="busy === f.receipt" @click="correctFinding(f)">
                                {{ busy === f.receipt ? 'Correcting…' : 'Correct' }}
                            </button>
                        </footer>
                    </article>
                </div>

                <div v-if="filters.view !== 'audit'" class="mp-toolbar">
                    <div class="mp-search">
                        <i class="fas fa-search"></i>
                        <input
                            v-model="searchTerm"
                            @keyup.enter="applyFilters"
                            type="search"
                            placeholder="Receipt, REQ, PAY number, account no., payer or phone"
                            aria-label="Search transactions"
                        />
                    </div>
                    <label class="mp-date">
                        <span>From</span>
                        <input type="date" v-model="dateFrom" @change="applyFilters" />
                    </label>
                    <label class="mp-date">
                        <span>To</span>
                        <input type="date" v-model="dateTo" @change="applyFilters" />
                    </label>
                    <button v-if="searchTerm || dateFrom || dateTo" class="mp-btn mp-btn-ghost" @click="clearFilters">Clear</button>
                </div>

                <p v-if="filters.view === 'queue' && transactions.data.length" class="mp-hint">
                    <i class="fas fa-info-circle"></i>
                    These paybill receipts could not be applied in full on their own — the account number did not match a REQ,
                    or the money is more than what is billed so far. Money waiting on a REQ settles its next bill automatically.
                </p>

                <div v-if="filters.view !== 'audit'" class="mp-table-wrap">
                    <table class="mp-table">
                        <thead>
                            <tr>
                                <th>Received</th>
                                <th>Payer</th>
                                <th>Account no.</th>
                                <th class="num">Amount</th>
                                <th>Applied to</th>
                                <th>Status</th>
                                <th class="act"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="tx in transactions.data" :key="tx.id" :class="{ 'is-open': isOpen(tx) }">
                                <td data-label="Received">
                                    <div class="mp-strong">{{ formatTime(tx.created_at) }}</div>
                                    <div class="mp-muted">
                                        <span :class="['mp-src', tx.source === 'c2b' ? 'src-c2b' : 'src-stk']">{{ tx.source === 'c2b' ? 'Paybill' : 'STK push' }}</span>
                                        <code class="mp-receipt">{{ tx.receipt_number || '—' }}</code>
                                    </div>
                                </td>
                                <td data-label="Payer">
                                    <div class="mp-strong">{{ tx.payer_name || '—' }}</div>
                                    <div class="mp-muted">{{ tx.phone_number || '' }}</div>
                                </td>
                                <td data-label="Account no.">
                                    <code v-if="tx.bill_ref_number" class="mp-ref">{{ tx.bill_ref_number }}</code>
                                    <span v-else class="mp-muted">{{ tx.source === 'c2b' ? 'Blank' : '—' }}</span>
                                </td>
                                <td data-label="Amount" class="num">
                                    <div class="mp-amount">{{ kes(tx.amount) }}</div>
                                    <div v-if="tx.unapplied_amount > 0 && tx.allocated_amount > 0" class="mp-unapplied">
                                        {{ kes(tx.unapplied_amount) }} waiting
                                    </div>
                                </td>
                                <td data-label="Applied to">
                                    <div v-if="tx.service_request" class="mp-req">
                                        <Link :href="`/admin/jobs/${tx.service_request.id}`">{{ tx.service_request.request_id }}</Link>
                                        <span v-if="tx.service_request.client" class="mp-muted">· {{ tx.service_request.client }}</span>
                                    </div>
                                    <ul v-if="tx.allocations.length" class="mp-allocs">
                                        <li v-for="(a, i) in tx.allocations" :key="i">
                                            <span :class="['mp-kind', kindClass(a.label)]">{{ a.label }}</span>
                                            <span>{{ kes(a.amount) }}</span>
                                            <span class="mp-muted">{{ a.code }}</span>
                                            <span v-if="a.part" class="mp-part" :title="`${kes(a.remainder_amount)} still due as ${a.remainder_code}`">part</span>
                                        </li>
                                    </ul>
                                    <div v-else-if="tx.payment_request" class="mp-allocs">
                                        <span :class="['mp-kind', kindClass(tx.payment_request.label)]">{{ tx.payment_request.label }}</span>
                                        <span class="mp-muted">{{ tx.payment_request.code }}</span>
                                    </div>
                                    <p v-if="isOpen(tx) && tx.match_note && !tx.allocations.length" class="mp-note">{{ tx.match_note }}</p>
                                    <span v-if="!tx.service_request && !tx.payment_request && !(isOpen(tx) && tx.match_note)" class="mp-muted">—</span>
                                </td>
                                <td data-label="Status">
                                    <span :class="['mp-pill', statusOf(tx).cls]">{{ statusOf(tx).label }}</span>
                                    <div v-if="statusOf(tx).sub" class="mp-muted mp-small">{{ statusOf(tx).sub }}</div>
                                </td>
                                <td class="act">
                                    <button v-if="isOpen(tx)" class="mp-btn mp-btn-primary mp-btn-sm" @click="openMatch(tx)">
                                        Match
                                    </button>
                                    <div class="mp-row-menu">
                                        <button class="mp-icon" @click.stop="toggleRowMenu(tx.id)" :aria-expanded="rowMenu === tx.id" aria-label="More actions">
                                            <i class="fas fa-ellipsis-h"></i>
                                        </button>
                                        <div v-if="rowMenu === tx.id" class="mp-menu mp-menu-right" role="menu">
                                            <Link role="menuitem" :href="`/admin/mpesa-transactions/${tx.id}`"><i class="fas fa-eye"></i><span>View details</span></Link>
                                            <button v-if="tx.source === 'c2b' && tx.allocated_amount > 0" role="menuitem" @click="reverseMatch(tx)"><i class="fas fa-undo"></i><span>Reverse match</span></button>
                                            <Link role="menuitem" :href="`/admin/mpesa-transactions/${tx.id}/edit`"><i class="fas fa-pen"></i><span>Edit raw record</span></Link>
                                            <button v-if="tx.allocated_amount <= 0" role="menuitem" class="danger" @click="deleteTransaction(tx)"><i class="fas fa-trash"></i><span>Delete</span></button>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="!transactions.data.length" class="mp-empty-row">
                                <td colspan="7">
                                    <div class="mp-empty">
                                        <i :class="filters.view === 'queue' ? 'fas fa-check-circle' : 'fas fa-receipt'"></i>
                                        <strong>{{ filters.view === 'queue' ? 'Nothing waiting' : 'No transactions' }}</strong>
                                        <span>{{ filters.view === 'queue' ? 'Every paybill receipt has been applied to a bill.' : 'Try another tab or clear the search.' }}</span>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="transactions.last_page > 1" class="mp-pager">
                    <span class="mp-muted">{{ transactions.from }}–{{ transactions.to }} of {{ transactions.total }}</span>
                    <div class="mp-pages">
                        <Component
                            :is="link.url ? Link : 'span'"
                            v-for="(link, idx) in transactions.links"
                            :key="idx"
                            :href="link.url"
                            preserve-scroll
                            v-html="link.label"
                            :class="['mp-page', { active: link.active, disabled: !link.url }]"
                        />
                    </div>
                </div>
            </section>
        </main>

        <!-- Match drawer -->
        <div v-if="matchTx" class="mp-drawer-backdrop" @click.self="closeMatch">
            <aside class="mp-drawer" role="dialog" aria-modal="true" aria-labelledby="match-title">
                <header class="mp-drawer-head">
                    <div>
                        <h3 id="match-title">Match paybill payment</h3>
                        <p class="mp-muted">{{ matchTx.receipt_number }} · {{ formatTime(matchTx.created_at) }}</p>
                    </div>
                    <button class="mp-x" @click="closeMatch" aria-label="Close">&times;</button>
                </header>

                <div class="mp-drawer-body">
                    <div class="mp-receipt-card">
                        <div>
                            <span class="mp-stat-label">{{ matchTx.allocated_amount > 0 ? 'Still to apply' : 'Amount' }}</span>
                            <div class="mp-big">{{ kes(matchTx.unapplied_amount) }}</div>
                            <div v-if="matchTx.allocated_amount > 0" class="mp-muted mp-small">
                                of {{ kes(matchTx.amount) }} — {{ kes(matchTx.allocated_amount) }} already applied
                            </div>
                        </div>
                        <dl class="mp-kv mp-kv-tight">
                            <dt>Account no.</dt><dd><code>{{ matchTx.bill_ref_number || 'blank' }}</code></dd>
                            <dt>Payer</dt><dd>{{ matchTx.payer_name || '—' }}</dd>
                            <dt>Phone</dt><dd>{{ matchTx.phone_number || '—' }}</dd>
                        </dl>
                    </div>

                    <!-- Step 1 -->
                    <section class="mp-step">
                        <h4><span class="mp-step-n">1</span> Which REQ is this for?</h4>
                        <div class="mp-search">
                            <i class="fas fa-search"></i>
                            <input
                                v-model="reqQuery"
                                @input="debouncedSearch"
                                type="search"
                                placeholder="Search REQ, PAY number, client name or phone"
                                aria-label="Search REQs"
                            />
                        </div>
                        <div v-if="loadingOptions && !options" class="mp-loading">Finding likely REQs…</div>
                        <div v-else-if="options && !options.candidates.length" class="mp-empty mp-empty-sm">
                            <strong>No likely REQ found</strong>
                            <span>Search by REQ number, client name or phone.</span>
                        </div>
                        <ul v-else-if="options" class="mp-cands" role="radiogroup">
                            <li v-for="c in options.candidates" :key="c.id">
                                <label :class="['mp-cand', { selected: selectedReqId === c.id }]">
                                    <input type="radio" name="req" :value="c.id" :checked="selectedReqId === c.id" @change="selectReq(c.id)" />
                                    <div class="mp-cand-main">
                                        <div class="mp-cand-top">
                                            <strong>{{ c.request_id }}</strong>
                                            <span class="mp-muted">{{ c.client }}</span>
                                        </div>
                                        <div class="mp-muted mp-small">{{ c.description }}</div>
                                        <div class="mp-reasons">
                                            <span v-for="r in c.reasons" :key="r" :class="['mp-reason', reasonClass(r)]">{{ r }}</span>
                                        </div>
                                    </div>
                                    <div class="mp-cand-amt">
                                        <span class="mp-muted mp-small">Outstanding</span>
                                        <strong>{{ kes(c.outstanding) }}</strong>
                                    </div>
                                </label>
                            </li>
                        </ul>
                    </section>

                    <!-- Step 2 -->
                    <section v-if="selected" class="mp-step">
                        <h4><span class="mp-step-n">2</span> Which bill does it pay?</h4>

                        <div class="mp-billing">
                            <div><span>Job value</span><strong>{{ kes(selected.contract_value) }}</strong></div>
                            <div><span>Paid</span><strong>{{ kes(selected.settled) }}</strong></div>
                            <div><span>Outstanding</span><strong>{{ kes(selected.outstanding) }}</strong></div>
                            <div v-if="selected.deposit_required > 0">
                                <span>Deposit</span>
                                <strong :class="selected.deposit_settled ? 'mp-good' : 'mp-warn'">
                                    {{ kes(selected.deposit_required) }} · {{ selected.deposit_settled ? 'paid' : 'not yet paid' }}
                                </strong>
                            </div>
                        </div>

                        <div v-if="!selected.pending_bills.length" class="mp-callout">
                            Nothing on {{ selected.request_id }} is waiting to be paid. Matching holds the money on this REQ,
                            and it settles the next bill raised on it automatically.
                        </div>
                        <div v-else class="mp-bills" role="radiogroup">
                            <label :class="['mp-bill', { selected: preferredBillId === null }]">
                                <input type="radio" name="bill" :checked="preferredBillId === null" @change="selectBill(null)" />
                                <div class="mp-bill-main">
                                    <strong>Let the system choose</strong>
                                    <span class="mp-muted mp-small">A bill for exactly this amount first, otherwise the deposit, then the oldest bill</span>
                                </div>
                                <span class="mp-reco">Recommended</span>
                            </label>
                            <label v-for="b in selected.pending_bills" :key="b.id" :class="['mp-bill', { selected: preferredBillId === b.id }]">
                                <input type="radio" name="bill" :checked="preferredBillId === b.id" @change="selectBill(b.id)" />
                                <div class="mp-bill-main">
                                    <div>
                                        <span :class="['mp-kind', kindClass(b.label)]">{{ b.label }}</span>
                                        <span class="mp-muted mp-small">{{ b.code }} · raised {{ b.raised_at }}</span>
                                    </div>
                                </div>
                                <div class="mp-bill-amt">
                                    <strong>{{ kes(b.amount) }}</strong>
                                    <span v-if="b.exact" class="mp-exact">exact amount</span>
                                </div>
                            </label>
                        </div>

                        <div v-if="pickedSkipsDeposit" class="mp-callout mp-small" style="margin-top:.6rem">
                            <i class="fas fa-exclamation-triangle"></i>
                            The deposit {{ owedDeposit.code }} ({{ kes(owedDeposit.amount) }}) is still owed. Paying another bill first
                            leaves the REQ waiting for its deposit, and the match check will flag it. Choose this only if the client
                            asked for it.
                        </div>

                        <!-- Preview -->
                        <div class="mp-preview" aria-live="polite">
                            <h5>What will happen</h5>
                            <div v-if="loadingOptions" class="mp-loading">Working it out…</div>
                            <template v-else-if="selected.plan">
                                <ul>
                                    <li v-for="line in selected.plan.lines" :key="line.payment_request_id">
                                        <i :class="line.part ? 'fas fa-adjust mp-warn' : 'fas fa-check-circle mp-good'"></i>
                                        <span>
                                            <strong>{{ kes(line.amount) }}</strong> settles the
                                            <strong>{{ line.label.toLowerCase() }}</strong> {{ line.code }}
                                            <template v-if="line.part">
                                                in part — <strong>{{ kes(line.left_on_bill) }}</strong> stays due as a new bill
                                            </template>
                                            <template v-else> in full</template>
                                        </span>
                                    </li>
                                    <li v-if="selected.plan.unapplied > 0">
                                        <i class="fas fa-hourglass-half mp-muted"></i>
                                        <span>
                                            <strong>{{ kes(selected.plan.unapplied) }}</strong> is held on {{ selected.request_id }}
                                            and settles the next bill raised on it
                                        </span>
                                    </li>
                                </ul>
                            </template>
                        </div>
                    </section>

                    <!-- Dismiss -->
                    <section v-if="dismissing" class="mp-step mp-dismiss">
                        <h4>Dismiss this receipt</h4>
                        <p class="mp-muted mp-small">Only for money that is not ours to apply — paid to the wrong paybill, refunded, or a test. The reason is kept on record.</p>
                        <textarea v-model="dismissReason" rows="3" placeholder="Why is this receipt being dismissed?"></textarea>
                    </section>
                </div>

                <footer class="mp-drawer-foot">
                    <template v-if="!dismissing">
                        <button v-if="matchTx.allocated_amount <= 0" class="mp-link-danger" @click="dismissing = true">Not ours? Dismiss</button>
                        <span class="mp-spacer"></span>
                        <button class="mp-btn mp-btn-ghost" @click="closeMatch">Cancel</button>
                        <button class="mp-btn mp-btn-primary" :disabled="!selectedReqId || submitting || loadingOptions" @click="submitMatch">
                            {{ submitting ? 'Matching…' : (selected ? `Match to ${selected.request_id}` : 'Match') }}
                        </button>
                    </template>
                    <template v-else>
                        <span class="mp-spacer"></span>
                        <button class="mp-btn mp-btn-ghost" @click="dismissing = false">Back</button>
                        <button class="mp-btn mp-btn-danger" :disabled="dismissReason.trim().length < 5 || submitting" @click="submitDismiss">
                            {{ submitting ? 'Dismissing…' : 'Dismiss receipt' }}
                        </button>
                    </template>
                </footer>
            </aside>
        </div>
    </div>
</template>

<script setup>
import { Link, router } from '@inertiajs/vue3'
import AdminSidebar from '../../../Components/AdminSidebar.vue'
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import axios from 'axios'

const props = defineProps({
    transactions: { type: Object, required: true },
    filters: { type: Object, default: () => ({ view: 'all', search: null, from: null, to: null }) },
    counts: { type: Object, default: () => ({}) },
    stats: { type: Object, default: () => ({}) },
    paybill: { type: String, default: null },
    audit: { type: Array, default: null },
})


const tabs = [
    { value: 'queue', label: 'Needs action' },
    { value: 'all', label: 'All' },
    { value: 'paybill', label: 'Paybill' },
    { value: 'stk', label: 'STK push' },
    { value: 'matched', label: 'Matched' },
    { value: 'failed', label: 'Failed' },
    { value: 'closed', label: 'Duplicates & dismissed' },
    { value: 'audit', label: 'Match check' },
]

// ---------- Filters ----------
const searchTerm = ref(props.filters?.search || '')
const dateFrom = ref(props.filters?.from || '')
const dateTo = ref(props.filters?.to || '')

const visit = (params) => router.get('/admin/mpesa-transactions', params, { preserveState: true, preserveScroll: true, replace: true })
const query = (view = props.filters.view) => ({
    view,
    search: searchTerm.value || undefined,
    from: dateFrom.value || undefined,
    to: dateTo.value || undefined,
})
const setView = (view) => visit(query(view))
const applyFilters = () => visit(query())
const clearFilters = () => {
    searchTerm.value = ''
    dateFrom.value = ''
    dateTo.value = ''
    visit({ view: props.filters.view })
}

// ---------- Formatting ----------
const money = new Intl.NumberFormat('en-KE', { minimumFractionDigits: 0, maximumFractionDigits: 2 })
const kes = (v) => (v === null || v === undefined ? '—' : 'KES ' + money.format(Number(v)))

const formatTime = (iso) => {
    if (!iso) return '—'
    const d = new Date(iso)
    const today = new Date()
    const sameDay = d.toDateString() === today.toDateString()
    const time = d.toLocaleTimeString('en-KE', { hour: '2-digit', minute: '2-digit' })
    return sameDay ? `Today, ${time}` : `${d.toLocaleDateString('en-KE', { day: 'numeric', month: 'short', year: d.getFullYear() === today.getFullYear() ? undefined : 'numeric' })}, ${time}`
}

const isOpen = (tx) => tx.source === 'c2b' && ['unmatched', 'needs_review'].includes(tx.match_status)

const statusOf = (tx) => {
    if (tx.source === 'stk_push') {
        if (tx.status === 'failed') return { label: 'Failed', cls: 'pill-red', sub: tx.result_desc }
        if (tx.status === 'initiated') return { label: 'Awaiting client', cls: 'pill-grey', sub: 'STK prompt sent' }
        return { label: 'Paid', cls: 'pill-green', sub: 'STK push' }
    }
    switch (tx.match_status) {
        case 'matched':
            return {
                label: 'Matched',
                cls: 'pill-green',
                sub: tx.match_method === 'manual' ? `by ${tx.matched_by || 'ops'}` : 'automatically',
            }
        case 'needs_review':
            return tx.allocated_amount > 0
                ? { label: 'Part applied', cls: 'pill-amber', sub: `${kes(tx.unapplied_amount)} waiting` }
                : { label: 'Waiting for a bill', cls: 'pill-amber', sub: null }
        case 'duplicate':
            return { label: 'Duplicate', cls: 'pill-grey', sub: 'Already paid via STK' }
        case 'dismissed':
            return { label: 'Dismissed', cls: 'pill-grey', sub: tx.matched_by ? `by ${tx.matched_by}` : null }
        default:
            if (tx.match_method === 'reversed') return { label: 'Reversed', cls: 'pill-amber', sub: 'Match by hand' }
            return { label: 'Unmatched', cls: 'pill-red', sub: tx.bill_ref_number ? 'Unknown account no.' : 'No account no.' }
    }
}

const kindClass = (label) => {
    if (label === 'Deposit') return 'kind-deposit'
    if (label === 'Balance') return 'kind-balance'
    if (label === 'Attendance fee') return 'kind-fee'
    if (label === 'Variation') return 'kind-var'
    return 'kind-milestone'
}
const reasonClass = (r) => (r.startsWith('Account number matches') || r.startsWith('Linked') ? 'strong' : r.startsWith('A bill for exactly') ? 'good' : '')

// ---------- Menus ----------
const toolsOpen = ref(false)
const toolsRef = ref(null)
const rowMenu = ref(null)
const toggleRowMenu = (id) => { rowMenu.value = rowMenu.value === id ? null : id }
const onDocClick = (e) => {
    if (toolsRef.value && !toolsRef.value.contains(e.target)) toolsOpen.value = false
    if (!e.target.closest('.mp-row-menu')) rowMenu.value = null
}
const onKey = (e) => {
    if (e.key !== 'Escape') return
    toolsOpen.value = false
    rowMenu.value = null
    if (matchTx.value && !submitting.value) closeMatch()
}
onMounted(() => { document.addEventListener('click', onDocClick); document.addEventListener('keydown', onKey) })
onBeforeUnmount(() => { document.removeEventListener('click', onDocClick); document.removeEventListener('keydown', onKey) })

// ---------- Daraja tools ----------
const registering = ref(false)
const registerResult = ref(null)
const diagnosing = ref(false)
const diagnoseResult = ref(null)

const diagnose = async () => {
    toolsOpen.value = false
    diagnosing.value = true
    diagnoseResult.value = null
    try {
        const { data } = await axios.get('/admin/mpesa/diagnose')
        diagnoseResult.value = data
    } catch (e) {
        diagnoseResult.value = {
            environment: 'unknown', environment_valid: false, credentials: {},
            oauth_test: { token_obtained: false },
            next_step: e.response?.data?.message || e.message || 'Diagnostic request failed.',
        }
    } finally {
        diagnosing.value = false
    }
}

const registerC2BUrls = async () => {
    toolsOpen.value = false
    if (!confirm('Register the paybill confirmation and validation URLs with Safaricom Daraja? Run this once per shortcode, or after the domain changes.')) return
    registering.value = true
    registerResult.value = null
    try {
        const { data } = await axios.post('/admin/mpesa/register-c2b-urls')
        registerResult.value = data
    } catch (e) {
        registerResult.value = e.response?.data || { success: false, message: e.message || 'Registration request failed.' }
    } finally {
        registering.value = false
    }
}

// ---------- Match drawer ----------
const matchTx = ref(null)
const options = ref(null)
const loadingOptions = ref(false)
const reqQuery = ref('')
const selectedReqId = ref(null)
const preferredBillId = ref(null)
const submitting = ref(false)
const dismissing = ref(false)
const dismissReason = ref('')
const selected = computed(() => options.value?.selected || null)
const owedDeposit = computed(() => selected.value?.pending_bills.find(b => b.is_deposit) || null)
const pickedSkipsDeposit = computed(() => !!(owedDeposit.value && preferredBillId.value && preferredBillId.value !== owedDeposit.value.id))

let requestSeq = 0
const loadOptions = async () => {
    if (!matchTx.value) return
    const seq = ++requestSeq
    loadingOptions.value = true
    try {
        const { data } = await axios.get(`/admin/mpesa-transactions/${matchTx.value.id}/match-options`, {
            params: {
                q: reqQuery.value || undefined,
                service_request_id: selectedReqId.value || undefined,
                payment_request_id: preferredBillId.value || undefined,
            },
        })
        if (seq !== requestSeq) return
        options.value = data
        if (!selectedReqId.value && data.selected) selectedReqId.value = data.selected.id
    } catch (e) {
        if (seq === requestSeq) options.value = { candidates: [], selected: null }
    } finally {
        if (seq === requestSeq) loadingOptions.value = false
    }
}

let searchTimer = null
const debouncedSearch = () => {
    clearTimeout(searchTimer)
    searchTimer = setTimeout(loadOptions, 300)
}

const openMatch = (tx) => {
    rowMenu.value = null
    matchTx.value = tx
    options.value = null
    reqQuery.value = ''
    selectedReqId.value = tx.service_request?.id || null
    preferredBillId.value = null
    dismissing.value = false
    dismissReason.value = ''
    loadOptions()
}
const closeMatch = () => { matchTx.value = null; options.value = null }

const selectReq = (id) => {
    selectedReqId.value = id
    preferredBillId.value = null
    loadOptions()
}
const selectBill = (id) => {
    preferredBillId.value = id
    loadOptions()
}

const submitMatch = () => {
    if (!matchTx.value || !selectedReqId.value) return
    submitting.value = true
    router.post(`/admin/mpesa-transactions/${matchTx.value.id}/reconcile`, {
        service_request_id: selectedReqId.value,
        payment_request_id: preferredBillId.value || null,
    }, {
        preserveScroll: true,
        onSuccess: () => closeMatch(),
        onFinish: () => { submitting.value = false },
    })
}

const submitDismiss = () => {
    if (!matchTx.value) return
    submitting.value = true
    router.post(`/admin/mpesa-transactions/${matchTx.value.id}/dismiss`, { reason: dismissReason.value.trim() }, {
        preserveScroll: true,
        onSuccess: () => closeMatch(),
        onFinish: () => { submitting.value = false },
    })
}

const busy = ref(null)

const reverseMatch = (tx) => {
    rowMenu.value = null
    const reason = prompt(
        `Reverse the match for ${tx.receipt_number}?\n\nEvery bill this receipt marked paid goes back to waiting for payment at its original amount, and the receipt returns to Needs action so you can match it again.\n\nReason (kept on record):`
    )
    if (reason === null) return
    if (reason.trim().length < 5) { alert('Please give a reason of at least 5 characters.'); return }
    router.post(`/admin/mpesa-transactions/${tx.id}/reverse`, { reason: reason.trim() }, { preserveScroll: true })
}

const correctFinding = (f) => {
    const msg = `Correct ${f.receipt} on ${f.service_request?.request_id}?\n\n`
        + `Now: ${f.bills.map(b => `${b.label} ${b.code} KES ${money.format(b.amount)}`).join(', ')} marked paid; KES ${money.format(f.received)} received.\n\n`
        + 'These bills will reopen and the money will be re-applied to this REQ: the deposit first, a part payment split so only what came in is marked paid, and anything extra held for the next bill.'
        + (f.job_warning ? `\n\n${f.job_warning}` : '')
    if (!confirm(msg)) return
    busy.value = f.receipt
    router.post(`/admin/mpesa-transactions/${f.transaction_id}/correct`, {}, {
        preserveScroll: true,
        onFinish: () => { busy.value = null },
    })
}

const deleteTransaction = (tx) => {
    rowMenu.value = null
    if (confirm(`Delete the record of ${tx.receipt_number || 'this transaction'}? This cannot be undone.`)) {
        router.delete(`/admin/mpesa-transactions/${tx.id}`, { preserveScroll: true })
    }
}

defineOptions({ layout: null })
</script>

<style scoped>
.mp, .mp-drawer-backdrop {
    --mp-ink: #111827;
    --mp-muted: #6b7280;
    --mp-line: #e5e7eb;
    --mp-soft: #f9fafb;
    --mp-brand: var(--primary-blue, #053272);
    --mp-green: #15803d;
    --mp-green-bg: #ecfdf3;
    --mp-amber: #b45309;
    --mp-amber-bg: #fffbeb;
    --mp-red: #b91c1c;
    --mp-red-bg: #fef2f2;
    color: var(--mp-ink);
}
.sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); }

/* Header */
.mp-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; margin-bottom: 1.25rem; flex-wrap: wrap; }
.mp-header h1 { margin: 0; font-size: 1.6rem; letter-spacing: -0.01em; }
.mp-sub { margin: .3rem 0 0; color: var(--mp-muted); font-size: .9rem; max-width: 60ch; }
.mp-tools { position: relative; }
.mp-caret { font-size: .65rem; margin-left: .2rem; }

/* Buttons */
.mp-btn { display: inline-flex; align-items: center; gap: .45rem; border-radius: 8px; padding: .55rem .95rem; font-size: .875rem; font-weight: 600; border: 1px solid transparent; cursor: pointer; background: none; color: inherit; white-space: nowrap; }
.mp-btn:disabled { opacity: .55; cursor: not-allowed; }
.mp-btn-sm { padding: .35rem .75rem; font-size: .8rem; }
.mp-btn-primary { background: var(--mp-brand); color: #fff; }
.mp-btn-primary:hover:not(:disabled) { filter: brightness(1.15); }
.mp-btn-ghost { border-color: var(--mp-line); background: #fff; }
.mp-btn-ghost:hover { background: var(--mp-soft); }
.mp-btn-danger { background: var(--mp-red); color: #fff; }
.mp-icon { border: 0; background: none; width: 32px; height: 32px; border-radius: 6px; color: var(--mp-muted); cursor: pointer; }
.mp-icon:hover { background: var(--mp-soft); color: var(--mp-ink); }
.mp-x { border: 0; background: none; font-size: 1.5rem; line-height: 1; color: var(--mp-muted); cursor: pointer; padding: 0 .25rem; }
.mp-link-danger { border: 0; background: none; color: var(--mp-red); font-size: .85rem; cursor: pointer; padding: 0; }
.mp-link-danger:hover { text-decoration: underline; }

/* Menus */
.mp-menu { position: absolute; top: calc(100% + 6px); right: 0; z-index: 30; min-width: 260px; background: #fff; border: 1px solid var(--mp-line); border-radius: 10px; box-shadow: 0 10px 30px rgba(17, 24, 39, .12); padding: .35rem; }
.mp-menu > * { display: flex; gap: .7rem; align-items: flex-start; width: 100%; text-align: left; background: none; border: 0; padding: .55rem .65rem; border-radius: 7px; cursor: pointer; color: var(--mp-ink); text-decoration: none; font-size: .875rem; }
.mp-menu > *:hover { background: var(--mp-soft); }
.mp-menu i { width: 16px; margin-top: .15rem; color: var(--mp-muted); }
.mp-menu span { display: flex; flex-direction: column; }
.mp-menu small { color: var(--mp-muted); font-size: .75rem; }
.mp-menu .danger, .mp-menu .danger i { color: var(--mp-red); }
.mp-menu-right { min-width: 180px; }
.mp-row-menu { position: relative; display: inline-block; }

/* Panels */
.mp-panel { background: #fff; border: 1px solid var(--mp-line); border-radius: 12px; }
.mp-diag { padding: 1rem 1.25rem; margin-bottom: 1rem; }
.mp-diag.is-ok { border-color: #bbf7d0; }
.mp-diag.is-err { border-color: #fecaca; }
.mp-diag-head { display: flex; justify-content: space-between; align-items: center; }
.mp-diag h4 { margin: 0; font-size: 1rem; }
.mp-diag pre { background: #0f172a; color: #e2e8f0; padding: .75rem; border-radius: 8px; overflow: auto; font-size: .75rem; }
.mp-kv { display: grid; grid-template-columns: max-content 1fr; gap: .35rem 1rem; margin: .75rem 0; font-size: .85rem; }
.mp-kv dt { color: var(--mp-muted); }
.mp-kv dd { margin: 0; overflow-wrap: anywhere; }
.mp-kv-tight { margin: 0; font-size: .8rem; }
.mp-next { margin: .5rem 0 0; padding: .6rem .8rem; background: var(--mp-soft); border-left: 3px solid var(--mp-brand); border-radius: 4px; font-size: .85rem; }
.mp-good { color: var(--mp-green); }
.mp-warn { color: var(--mp-amber); }
.mp-bad { color: var(--mp-red); font-weight: 600; margin-left: .35rem; }

/* Stats */
.mp-stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: .9rem; margin-bottom: 1.25rem; }
.mp-stat { display: flex; flex-direction: column; gap: .2rem; padding: 1rem 1.1rem; background: #fff; border: 1px solid var(--mp-line); border-radius: 12px; text-align: left; font: inherit; color: inherit; }
.mp-stat-action { cursor: pointer; transition: border-color .15s, box-shadow .15s; }
.mp-stat-action:hover { border-color: var(--mp-brand); box-shadow: 0 2px 8px rgba(5, 50, 114, .08); }
.mp-stat-action.is-warn { background: var(--mp-amber-bg); border-color: #fcd34d; }
.mp-stat-action.is-warn .mp-stat-value { color: var(--mp-amber); }
.mp-stat-label { font-size: .75rem; color: var(--mp-muted); font-weight: 600; text-transform: uppercase; letter-spacing: .04em; }
.mp-stat-value { font-size: 1.45rem; font-weight: 700; font-variant-numeric: tabular-nums; letter-spacing: -0.01em; }
.mp-stat-foot { font-size: .8rem; color: var(--mp-muted); }

/* Tabs + toolbar */
.mp-tabs { display: flex; gap: .25rem; padding: .5rem .75rem 0; border-bottom: 1px solid var(--mp-line); overflow-x: auto; scrollbar-width: none; }
.mp-tab { display: inline-flex; align-items: center; gap: .45rem; padding: .65rem .8rem; border: 0; background: none; font: inherit; font-size: .875rem; font-weight: 600; color: var(--mp-muted); border-bottom: 2px solid transparent; margin-bottom: -1px; cursor: pointer; white-space: nowrap; }
.mp-tab:hover { color: var(--mp-ink); }
.mp-tab.active { color: var(--mp-brand); border-bottom-color: var(--mp-brand); }
.mp-tab-count { font-size: .72rem; background: #f3f4f6; color: var(--mp-muted); padding: .05rem .45rem; border-radius: 999px; font-variant-numeric: tabular-nums; }
.mp-tab.warn .mp-tab-count { background: #fde68a; color: #78350f; }
.mp-tab.active .mp-tab-count { background: #e0e7ff; color: var(--mp-brand); }

.mp-toolbar { display: flex; gap: .6rem; align-items: center; padding: .85rem 1rem; flex-wrap: wrap; }
.mp-search { position: relative; flex: 1 1 280px; }
.mp-search i { position: absolute; left: .75rem; top: 50%; transform: translateY(-50%); color: var(--mp-muted); font-size: .8rem; }
.mp-search input { width: 100%; box-sizing: border-box; padding: .55rem .75rem .55rem 2.1rem; border: 1px solid var(--mp-line); border-radius: 8px; font: inherit; font-size: .875rem; }
.mp-search input:focus, .mp-date input:focus, .mp-dismiss textarea:focus { outline: 2px solid #c7d2fe; border-color: var(--mp-brand); }
.mp-date { display: inline-flex; align-items: center; gap: .4rem; font-size: .8rem; color: var(--mp-muted); }
.mp-date input { padding: .45rem .55rem; border: 1px solid var(--mp-line); border-radius: 8px; font: inherit; font-size: .85rem; }
.mp-hint { display: flex; gap: .5rem; margin: 0 1rem .75rem; padding: .65rem .8rem; background: var(--mp-soft); border-radius: 8px; font-size: .82rem; color: #374151; }
.mp-hint i { color: var(--mp-brand); margin-top: .15rem; }

/* Table */
.mp-table-wrap { overflow-x: auto; }
.mp-table { width: 100%; border-collapse: collapse; font-size: .875rem; }
.mp-table th { text-align: left; font-size: .72rem; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; color: var(--mp-muted); padding: .6rem 1rem; background: var(--mp-soft); border-top: 1px solid var(--mp-line); border-bottom: 1px solid var(--mp-line); white-space: nowrap; }
.mp-table td { padding: .85rem 1rem; border-bottom: 1px solid #f1f5f9; vertical-align: top; }
.mp-table tbody tr:hover td { background: #fcfcfd; }
.mp-table tr.is-open td:first-child { box-shadow: inset 3px 0 0 #f59e0b; }
.mp-table .num { text-align: right; white-space: nowrap; }
.mp-table .act { text-align: right; white-space: nowrap; width: 1%; }
.mp-strong { font-weight: 600; }
.mp-muted { color: var(--mp-muted); }
.mp-small { font-size: .78rem; }
.mp-receipt { font-size: .75rem; color: var(--mp-muted); background: none; padding: 0; margin-left: .35rem; }
.mp-ref { font-size: .8rem; background: #f3f4f6; padding: .15rem .4rem; border-radius: 4px; }
.mp-amount { font-weight: 700; font-variant-numeric: tabular-nums; }
.mp-unapplied { font-size: .75rem; color: var(--mp-amber); font-weight: 600; }
.mp-src { display: inline-block; font-size: .65rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; padding: .1rem .35rem; border-radius: 4px; }
.src-c2b { background: var(--mp-green-bg); color: var(--mp-green); }
.src-stk { background: #eff6ff; color: #1d4ed8; }
.mp-req a { font-weight: 600; color: var(--mp-brand); text-decoration: none; }
.mp-req a:hover { text-decoration: underline; }
.mp-allocs { list-style: none; margin: .3rem 0 0; padding: 0; display: flex; flex-direction: column; gap: .2rem; font-size: .8rem; }
.mp-allocs li, div.mp-allocs { display: flex; gap: .4rem; align-items: center; flex-wrap: wrap; }
.mp-note { margin: .35rem 0 0; font-size: .78rem; color: #4b5563; max-width: 44ch; }
.mp-kind { display: inline-block; font-size: .7rem; font-weight: 700; padding: .1rem .45rem; border-radius: 999px; }
.kind-deposit { background: #e0e7ff; color: #3730a3; }
.kind-balance { background: #f3f4f6; color: #374151; }
.kind-fee { background: #fce7f3; color: #9d174d; }
.kind-var { background: #ede9fe; color: #5b21b6; }
.kind-milestone { background: #e0f2fe; color: #075985; }
.mp-part { font-size: .65rem; font-weight: 700; text-transform: uppercase; color: var(--mp-amber); background: var(--mp-amber-bg); padding: .05rem .35rem; border-radius: 4px; cursor: help; }
.mp-pill { display: inline-block; font-size: .75rem; font-weight: 700; padding: .2rem .6rem; border-radius: 999px; white-space: nowrap; }
.pill-green { background: var(--mp-green-bg); color: var(--mp-green); }
.pill-amber { background: #fef3c7; color: #92400e; }
.pill-red { background: var(--mp-red-bg); color: var(--mp-red); }
.pill-grey { background: #f3f4f6; color: #4b5563; }
.mp-empty-row td { padding: 0; }
.mp-empty { display: flex; flex-direction: column; align-items: center; gap: .3rem; padding: 3rem 1rem; color: var(--mp-muted); text-align: center; }
.mp-empty i { font-size: 1.8rem; color: #d1d5db; margin-bottom: .3rem; }
.mp-empty strong { color: var(--mp-ink); }
.mp-empty-sm { padding: 1.5rem 1rem; }

.mp-pager { display: flex; justify-content: space-between; align-items: center; gap: 1rem; padding: .85rem 1rem; flex-wrap: wrap; font-size: .85rem; }
.mp-pages { display: flex; gap: .25rem; flex-wrap: wrap; }
.mp-page { padding: .35rem .65rem; border: 1px solid var(--mp-line); border-radius: 6px; color: var(--mp-ink); text-decoration: none; font-size: .8rem; background: #fff; }
.mp-page.active { background: var(--mp-brand); color: #fff; border-color: var(--mp-brand); }
.mp-page.disabled { color: #9ca3af; pointer-events: none; background: var(--mp-soft); }

/* Drawer */
.mp-drawer-backdrop { position: fixed; inset: 0; background: rgba(17, 24, 39, .4); z-index: 100; display: flex; justify-content: flex-end; }
.mp-drawer { width: min(560px, 100%); height: 100%; background: #fff; display: flex; flex-direction: column; box-shadow: -10px 0 30px rgba(0, 0, 0, .12); animation: mp-slide .18s ease-out; }
@keyframes mp-slide { from { transform: translateX(24px); opacity: .6; } to { transform: none; opacity: 1; } }
.mp-drawer-head { display: flex; justify-content: space-between; align-items: flex-start; padding: 1.1rem 1.25rem; border-bottom: 1px solid var(--mp-line); }
.mp-drawer-head h3 { margin: 0; font-size: 1.1rem; }
.mp-drawer-head p { margin: .2rem 0 0; font-size: .8rem; }
.mp-drawer-body { flex: 1; overflow-y: auto; padding: 1.1rem 1.25rem; display: flex; flex-direction: column; gap: 1.25rem; }
.mp-drawer-foot { display: flex; align-items: center; gap: .5rem; padding: .9rem 1.25rem; border-top: 1px solid var(--mp-line); background: var(--mp-soft); }
.mp-spacer { flex: 1; }
.mp-receipt-card { display: grid; grid-template-columns: auto 1fr; gap: 1.25rem; align-items: center; padding: 1rem; border-radius: 10px; background: var(--mp-soft); border: 1px solid var(--mp-line); }
.mp-big { font-size: 1.6rem; font-weight: 800; font-variant-numeric: tabular-nums; letter-spacing: -0.01em; }
.mp-step h4 { display: flex; align-items: center; gap: .5rem; margin: 0 0 .6rem; font-size: .95rem; }
.mp-step-n { display: inline-grid; place-items: center; width: 22px; height: 22px; border-radius: 50%; background: var(--mp-brand); color: #fff; font-size: .75rem; }
.mp-loading { padding: .75rem 0; color: var(--mp-muted); font-size: .85rem; }
.mp-cands { list-style: none; padding: 0; margin: .6rem 0 0; display: flex; flex-direction: column; gap: .45rem; }
.mp-cand, .mp-bill { display: flex; gap: .7rem; align-items: flex-start; padding: .7rem .8rem; border: 1px solid var(--mp-line); border-radius: 10px; cursor: pointer; transition: border-color .12s, background .12s; }
.mp-cand:hover, .mp-bill:hover { border-color: #c7d2fe; }
.mp-cand.selected, .mp-bill.selected { border-color: var(--mp-brand); background: #f5f7ff; box-shadow: 0 0 0 1px var(--mp-brand) inset; }
.mp-cand input, .mp-bill input { margin-top: .2rem; accent-color: var(--mp-brand); }
.mp-cand-main, .mp-bill-main { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: .15rem; }
.mp-cand-top { display: flex; gap: .4rem; flex-wrap: wrap; align-items: baseline; }
.mp-cand-amt, .mp-bill-amt { display: flex; flex-direction: column; align-items: flex-end; gap: .1rem; white-space: nowrap; font-variant-numeric: tabular-nums; }
.mp-reasons { display: flex; gap: .3rem; flex-wrap: wrap; margin-top: .25rem; }
.mp-reason { font-size: .68rem; font-weight: 600; padding: .1rem .4rem; border-radius: 4px; background: #f3f4f6; color: #4b5563; }
.mp-reason.strong { background: #e0e7ff; color: #3730a3; }
.mp-reason.good { background: var(--mp-green-bg); color: var(--mp-green); }
.mp-billing { display: grid; grid-template-columns: repeat(auto-fit, minmax(110px, 1fr)); gap: .5rem; margin-bottom: .75rem; }
.mp-billing > div { display: flex; flex-direction: column; gap: .1rem; padding: .55rem .65rem; background: var(--mp-soft); border-radius: 8px; font-size: .78rem; }
.mp-billing span { color: var(--mp-muted); }
.mp-billing strong { font-size: .88rem; font-variant-numeric: tabular-nums; }
.mp-bills { display: flex; flex-direction: column; gap: .45rem; }
.mp-reco { font-size: .68rem; font-weight: 700; color: var(--mp-green); background: var(--mp-green-bg); padding: .1rem .45rem; border-radius: 999px; align-self: center; }
.mp-exact { font-size: .68rem; font-weight: 700; color: var(--mp-green); }
.mp-callout { padding: .75rem .9rem; border-radius: 8px; background: var(--mp-amber-bg); border: 1px solid #fde68a; font-size: .85rem; color: #78350f; }
.mp-preview { margin-top: .9rem; padding: .85rem 1rem; border-radius: 10px; border: 1px dashed #c7d2fe; background: #fafbff; }
.mp-preview h5 { margin: 0 0 .5rem; font-size: .75rem; text-transform: uppercase; letter-spacing: .05em; color: var(--mp-muted); }
.mp-preview ul { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: .45rem; font-size: .87rem; }
.mp-preview li { display: flex; gap: .55rem; align-items: flex-start; }
.mp-preview li i { margin-top: .2rem; }
.mp-dismiss textarea { width: 100%; box-sizing: border-box; padding: .6rem .7rem; border: 1px solid var(--mp-line); border-radius: 8px; font: inherit; font-size: .875rem; resize: vertical; }

/* Match check */
.mp-audit { padding: 0 1rem 1rem; display: flex; flex-direction: column; gap: .75rem; }
.mp-audit .mp-hint { margin: .85rem 0 0; }
.mp-finding { border: 1px solid var(--mp-line); border-left: 3px solid #f59e0b; border-radius: 10px; padding: .9rem 1rem; display: flex; flex-direction: column; gap: .65rem; }
.mp-finding header, .mp-finding footer { display: flex; justify-content: space-between; align-items: center; gap: .75rem; flex-wrap: wrap; }
.mp-finding-figs { display: grid; grid-template-columns: auto auto 1fr; gap: .5rem; }
.mp-finding-figs > div { display: flex; flex-direction: column; gap: .15rem; padding: .5rem .7rem; background: var(--mp-soft); border-radius: 8px; font-size: .78rem; }
.mp-finding-figs span:first-child { color: var(--mp-muted); }
.mp-finding-figs strong { font-size: .95rem; font-variant-numeric: tabular-nums; }
.mp-finding-bills > div { display: flex; flex-direction: column; gap: .2rem; }
.mp-finding-bill { display: flex; gap: .4rem; align-items: center; font-size: .8rem; color: var(--mp-ink) !important; }
.mp-bad-text { color: var(--mp-red); }
.mp-issues { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: .35rem; font-size: .85rem; }
.mp-issues li { display: flex; gap: .5rem; align-items: flex-start; }
.mp-issues li i { margin-top: .2rem; color: var(--mp-amber); }
.mp-issues li.issue-different_req i { color: #2563eb; }
.mp-tab-count.is-bad { background: #fecaca; color: #7f1d1d; }

/* Responsive */
@media (max-width: 1100px) {
    .mp-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
@media (max-width: 760px) {
    .mp-stats { grid-template-columns: 1fr 1fr; gap: .6rem; }
    .mp-stat-value { font-size: 1.15rem; }
    .mp-table thead { display: none; }
    .mp-table, .mp-table tbody, .mp-table tr, .mp-table td { display: block; width: 100%; box-sizing: border-box; }
    .mp-table tr { padding: .75rem 1rem; border-bottom: 1px solid var(--mp-line); }
    .mp-table td { padding: .25rem 0; border: 0; display: flex; justify-content: space-between; gap: 1rem; text-align: right; }
    .mp-table td::before { content: attr(data-label); color: var(--mp-muted); font-size: .75rem; text-align: left; flex-shrink: 0; }
    .mp-table td.act { justify-content: flex-end; padding-top: .5rem; }
    .mp-table td.act::before { content: none; }
    .mp-table tr.is-open td:first-child { box-shadow: none; }
    .mp-table tr.is-open { box-shadow: inset 3px 0 0 #f59e0b; }
    .mp-table .num { text-align: right; }
    .mp-allocs, .mp-allocs li { justify-content: flex-end; }
    .mp-receipt-card { grid-template-columns: 1fr; }
    .mp-finding-figs { grid-template-columns: 1fr 1fr; }
    .mp-finding-bills { grid-column: 1 / -1; }
    .mp-date { flex: 1 1 45%; }
    .mp-date input { flex: 1; }
}
</style>
