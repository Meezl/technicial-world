<template>
    <div class="dashboard-container">
        <AdminSidebar current-page="mpesa-transactions" />

        <main class="main-content">
            <header class="main-header">
                <h1>Edit Transaction</h1>
                <div class="header-actions">
                    <Link href="/admin/mpesa-transactions" class="btn btn-outline">
                        <i class="fas fa-arrow-left"></i> Back
                    </Link>
                </div>
            </header>

            <section class="main-panel">
                <div class="panel-card full-width">
                    <div class="card-header">
                        <h3>Edit M-Pesa Transaction #{{ transaction.id }}</h3>
                    </div>

                    <form @submit.prevent="submit" class="settings-form">
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="receipt_number">Receipt Number</label>
                                <input type="text" id="receipt_number" v-model="form.receipt_number" class="form-control" />
                                <span class="error-message" v-if="form.errors.receipt_number">{{ form.errors.receipt_number }}</span>
                            </div>

                            <div class="form-group">
                                <label for="amount">Amount</label>
                                <input type="number" id="amount" v-model="form.amount" step="0.01" class="form-control" />
                                <span class="error-message" v-if="form.errors.amount">{{ form.errors.amount }}</span>
                            </div>

                            <div class="form-group">
                                <label for="phone_number">Phone Number</label>
                                <input type="text" id="phone_number" v-model="form.phone_number" class="form-control" />
                                <span class="error-message" v-if="form.errors.phone_number">{{ form.errors.phone_number }}</span>
                            </div>

                            <div class="form-group">
                                <label for="result_code">Result Code</label>
                                <input type="number" id="result_code" v-model="form.result_code" class="form-control" />
                                <span class="error-message" v-if="form.errors.result_code">{{ form.errors.result_code }}</span>
                            </div>

                            <div class="form-group">
                                <label for="checkout_request_id">Checkout Request ID</label>
                                <input type="text" id="checkout_request_id" v-model="form.checkout_request_id" class="form-control" />
                                <span class="error-message" v-if="form.errors.checkout_request_id">{{ form.errors.checkout_request_id }}</span>
                            </div>

                            <div class="form-group">
                                <label for="merchant_request_id">Merchant Request ID</label>
                                <input type="text" id="merchant_request_id" v-model="form.merchant_request_id" class="form-control" />
                                <span class="error-message" v-if="form.errors.merchant_request_id">{{ form.errors.merchant_request_id }}</span>
                            </div>

                            <div class="form-group">
                                <label for="transaction_date">Transaction Date</label>
                                <input type="text" id="transaction_date" v-model="form.transaction_date" class="form-control" />
                                <span class="error-message" v-if="form.errors.transaction_date">{{ form.errors.transaction_date }}</span>
                            </div>
                        </div>

                        <div class="form-group full-width" style="grid-column: 1 / -1;">
                            <label for="result_desc">Result Description</label>
                            <textarea id="result_desc" v-model="form.result_desc" class="form-control" rows="3"></textarea>
                            <span class="error-message" v-if="form.errors.result_desc">{{ form.errors.result_desc }}</span>
                        </div>

                        <div class="form-actions mt-4">
                            <button type="submit" class="btn btn-primary" :disabled="form.processing">
                                <span v-if="form.processing"><i class="fas fa-spinner fa-spin"></i> Saving...</span>
                                <span v-else>Save Changes</span>
                            </button>
                        </div>
                    </form>
                </div>
            </section>
        </main>
    </div>
</template>

<script setup>
import { useForm, Link } from '@inertiajs/vue3'
import AdminSidebar from '../../../Components/AdminSidebar.vue'

const props = defineProps({
    transaction: {
        type: Object,
        required: true
    }
})

const form = useForm({
    receipt_number: props.transaction.receipt_number || '',
    amount: props.transaction.amount || '',
    phone_number: props.transaction.phone_number || '',
    result_code: props.transaction.result_code !== null ? props.transaction.result_code : '',
    checkout_request_id: props.transaction.checkout_request_id || '',
    merchant_request_id: props.transaction.merchant_request_id || '',
    transaction_date: props.transaction.transaction_date || '',
    result_desc: props.transaction.result_desc || '',
})

const submit = () => {
    form.put(`/admin/mpesa-transactions/${props.transaction.id}`)
}

defineOptions({
    layout: null
})
</script>

<style scoped>

.form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 1.5rem;
    margin-bottom: 1.5rem;
}

.settings-form {
    padding: 1.5rem;
}

.form-group {
    margin-bottom: 1rem;
}

.form-control {
    width: 100%;
    padding: 0.75rem;
    border: 1px solid var(--border-color);
    border-radius: 4px;
    font-size: 1rem;
}

.error-message {
    color: var(--danger-color);
    font-size: 0.85rem;
    margin-top: 0.25rem;
    display: block;
}

.btn-outline {
    background: transparent;
    border: 1px solid var(--border-color);
    color: var(--text-color);
}
.btn-outline:hover {
    background: #f9fafb;
}

.mt-4 {
    margin-top: 1.5rem;
}
</style>
