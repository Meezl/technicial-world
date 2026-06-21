<template>
    <div class="dashboard-container">
        <AdminSidebar current-page="email-logs" />

        <main class="main-content">
            <header class="main-header">
                <Link href="/admin/email-logs" class="btn btn-secondary btn-sm">
                    <i class="fas fa-arrow-left"></i> Back to list
                </Link>
                <h1>{{ email.subject }}</h1>
            </header>

            <section class="main-panel">
                <div class="panel-card" style="padding:1.25rem;">
                    <table class="data-table" style="margin-bottom:1.25rem;">
                        <tbody>
                            <tr>
                                <th style="width:130px;">Sent</th>
                                <td>{{ formatFullDate(email.sent_at) }}</td>
                            </tr>
                            <tr>
                                <th>From</th>
                                <td>{{ email.from_name ? `${email.from_name} <${email.from_address}>` : email.from_address }}</td>
                            </tr>
                            <tr>
                                <th>To</th>
                                <td>
                                    <span v-for="(addr, i) in email.to" :key="i" style="display:inline-block;margin-right:.5rem;">
                                        {{ addr.name ? `${addr.name} <${addr.address}>` : addr.address }}
                                    </span>
                                </td>
                            </tr>
                            <tr v-if="email.cc?.length">
                                <th>CC</th>
                                <td>
                                    <span v-for="(addr, i) in email.cc" :key="i">{{ addr.address }};</span>
                                </td>
                            </tr>
                            <tr>
                                <th>Mailable</th>
                                <td><code>{{ email.mailable_class || '—' }}</code></td>
                            </tr>
                            <tr v-if="email.attachments?.length">
                                <th>Attachments</th>
                                <td>
                                    <ul style="margin:0;padding-left:1.2rem;">
                                        <li v-for="(a, i) in email.attachments" :key="i">
                                            {{ a.name }} <small style="color:#6b7280;">({{ formatSize(a.size_bytes) }})</small>
                                        </li>
                                    </ul>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <h3 style="margin-top:1.5rem;">Rendered Body</h3>
                    <div style="border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;">
                        <iframe
                            :srcdoc="email.body_html"
                            sandbox=""
                            style="width:100%;min-height:600px;border:0;background:white;"
                        ></iframe>
                    </div>

                    <details v-if="email.body_text" style="margin-top:1rem;">
                        <summary style="cursor:pointer;font-weight:600;">Plain-text version</summary>
                        <pre style="background:#f3f4f6;padding:1rem;border-radius:6px;white-space:pre-wrap;font-family:monospace;font-size:.85rem;">{{ email.body_text }}</pre>
                    </details>
                </div>
            </section>
        </main>
    </div>
</template>

<script setup>
import { Link } from '@inertiajs/vue3'
import AdminSidebar from '../../../Components/AdminSidebar.vue'

defineProps({
    email: { type: Object, required: true },
})

const formatFullDate = (s) => s ? new Date(s).toLocaleString('en-KE') : '—'
const formatSize = (b) => {
    if (!b) return '0 B'
    if (b < 1024) return `${b} B`
    if (b < 1024 * 1024) return `${Math.round(b / 1024)} KB`
    return `${(b / 1024 / 1024).toFixed(2)} MB`
}
</script>
