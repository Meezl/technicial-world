<template>
    <AdminLayout>
        <template #header>
            <div class="flex justify-between items-center">
                <div>
                    <h1 class="text-3xl font-bold text-gray-900">Technician Performance</h1>
                    <p class="mt-1 text-sm text-gray-500">Comprehensive analytics and performance tracking</p>
                </div>
                <Link href="/admin/technician-performance" class="btn btn-secondary">
                    <i class="fas fa-arrow-left mr-2"></i>Back to List
                </Link>
            </div>
        </template>

        <div class="p-6" v-if="performance && performance.technician">
            <!-- Technician Header -->
            <div class="bg-white rounded-lg shadow-md p-6 mb-6">
                <div class="flex items-start justify-between">
                    <div class="flex items-start space-x-4">
                        <div class="w-20 h-20 bg-gradient-to-br from-blue-500 to-purple-600 rounded-full flex items-center justify-center text-white text-2xl font-bold">
                            {{ getInitials(performance.technician.name) }}
                        </div>
                        <div>
                            <div class="flex items-center gap-3">
                                <h2 class="text-2xl font-bold text-gray-900">{{ performance.technician.name }}</h2>
                                <span v-if="performance.technician.is_top_rated" class="px-3 py-1 bg-yellow-100 text-yellow-800 text-sm font-semibold rounded-full flex items-center gap-1">
                                    <i class="fas fa-star"></i> Top Rated
                                </span>
                                <span v-if="performance.technician.is_recommended" class="px-3 py-1 bg-green-100 text-green-800 text-sm font-semibold rounded-full flex items-center gap-1">
                                    <i class="fas fa-thumbs-up"></i> Recommended
                                </span>
                            </div>
                            <p class="text-gray-600 mt-1">{{ performance.technician.email }}</p>
                            <p class="text-gray-600">ID: {{ performance.technician.technician_id }}</p>
                            <div class="flex gap-4 mt-2">
                                <span class="text-sm text-gray-500">
                                    <i class="fas fa-wrench mr-1"></i>{{ performance.technician.specialization }}
                                </span>
                                <span class="text-sm text-gray-500">
                                    <i class="fas fa-map-marker-alt mr-1"></i>{{ performance.technician.location }}
                                </span>
                                <span class="text-sm" :class="performance.technician.availability === 'available' ? 'text-green-600' : 'text-red-600'">
                                    <i class="fas fa-circle mr-1"></i>{{ performance.technician.availability }}
                                </span>
                            </div>
                            <p v-if="performance.technician.bio" class="text-gray-600 mt-2 text-sm">{{ performance.technician.bio }}</p>
                        </div>
                    </div>
                    <div class="text-right">
                        <div class="text-4xl font-bold text-blue-600">{{ performance.match_score }}</div>
                        <div class="text-sm text-gray-500">Match Score</div>
                    </div>
                </div>
            </div>

            <!-- Stats Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">
                <StatsCard
                    title="Total Jobs"
                    :value="performance.job_metrics.total_jobs"
                    icon="fas fa-briefcase"
                    color="blue"
                />
                <StatsCard
                    title="Total Revenue"
                    :value="formatCurrency(performance.financial_tracking.total_revenue)"
                    icon="fas fa-dollar-sign"
                    color="green"
                />
                <StatsCard
                    title="Outstanding Balance"
                    :value="formatCurrency(performance.financial_tracking.outstanding_balance)"
                    :subtitle="`Earned: ${formatCurrency(performance.financial_tracking.total_earned_payouts)}`"
                    icon="fas fa-wallet"
                    color="orange"
                />
                <StatsCard
                    title="Average Rating"
                    :value="performance.performance_engine.average_rating.toFixed(1)"
                    :subtitle="`${performance.performance_engine.total_reviews} reviews`"
                    icon="fas fa-star"
                    color="yellow"
                />
            </div>

            <!-- Job Status Visualization & Financial Summary -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                <!-- Job Status Breakdown -->
                <div class="bg-white rounded-lg shadow-md p-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Job Status Breakdown</h3>
                    <JobStatusChart :jobMetrics="performance.job_metrics" />
                    <div class="mt-4 space-y-2">
                        <div class="flex justify-between items-center">
                            <span class="flex items-center gap-2">
                                <span class="w-3 h-3 bg-green-500 rounded-full"></span>
                                <span class="text-sm text-gray-600">Completed</span>
                            </span>
                            <span class="text-sm font-semibold text-gray-900">{{ performance.job_metrics.completed }} ({{ performance.job_metrics.breakdown_percentages.completed }}%)</span>
                        </div>
                        <div class="flex justify-between items-center">
                            <span class="flex items-center gap-2">
                                <span class="w-3 h-3 bg-blue-500 rounded-full"></span>
                                <span class="text-sm text-gray-600">Ongoing</span>
                            </span>
                            <span class="text-sm font-semibold text-gray-900">{{ performance.job_metrics.ongoing }} ({{ performance.job_metrics.breakdown_percentages.ongoing }}%)</span>
                        </div>
                        <div class="flex justify-between items-center">
                            <span class="flex items-center gap-2">
                                <span class="w-3 h-3 bg-yellow-500 rounded-full"></span>
                                <span class="text-sm text-gray-600">Pending</span>
                            </span>
                            <span class="text-sm font-semibold text-gray-900">{{ performance.job_metrics.pending }} ({{ performance.job_metrics.breakdown_percentages.pending }}%)</span>
                        </div>
                    </div>
                </div>

                <!-- Financial Summary -->
                <div class="bg-white rounded-lg shadow-md p-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Financial Summary</h3>
                    <div class="space-y-4">
                        <div class="flex justify-between items-center py-3 border-b">
                            <span class="text-gray-600">Total Revenue Generated</span>
                            <span class="text-lg font-bold text-gray-900">{{ formatCurrency(performance.financial_tracking.total_revenue) }}</span>
                        </div>
                        <div class="flex justify-between items-center py-3 border-b">
                            <span class="text-gray-600">Total Earned Payouts</span>
                            <span class="text-lg font-bold text-gray-900">{{ formatCurrency(performance.financial_tracking.total_earned_payouts) }}</span>
                        </div>
                        <div class="flex justify-between items-center py-3 border-b">
                            <span class="text-gray-600">Payments Made</span>
                            <span class="text-lg font-bold text-green-600">{{ formatCurrency(performance.financial_tracking.payments_made) }}</span>
                        </div>
                        <div class="flex justify-between items-center py-3 bg-orange-50 rounded-lg px-4">
                            <span class="text-gray-900 font-semibold">Outstanding Balance</span>
                            <span class="text-xl font-bold text-orange-600">{{ formatCurrency(performance.financial_tracking.outstanding_balance) }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent Payments Table -->
            <div class="bg-white rounded-lg shadow-md p-6 mb-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Recent Payments</h3>
                <PaymentsTable :payments="performance.financial_tracking.recent_payments" />
            </div>

            <!-- Client Feedback -->
            <div class="bg-white rounded-lg shadow-md p-6">
                <h3 class="text-lg font-semibold text-gray-900 mb-4">Client Feedback</h3>
                <FeedbackList :feedback="performance.performance_engine.feedback" />
            </div>
        </div>

        <!-- Loading/Error State -->
        <div v-else class="p-6">
            <div class="bg-white rounded-lg shadow-md p-12 text-center">
                <div class="text-gray-400 mb-4">
                    <i class="fas fa-spinner fa-spin text-4xl"></i>
                </div>
                <p class="text-gray-600">Loading technician performance data...</p>
            </div>
        </div>
    </AdminLayout>
</template>

<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Link } from '@inertiajs/vue3';
import StatsCard from '@/Components/TechnicianPerformance/StatsCard.vue';
import JobStatusChart from '@/Components/TechnicianPerformance/JobStatusChart.vue';
import PaymentsTable from '@/Components/TechnicianPerformance/PaymentsTable.vue';
import FeedbackList from '@/Components/TechnicianPerformance/FeedbackList.vue';

const props = defineProps({
    performance: Object
});

const getInitials = (name) => {
    return name
        .split(' ')
        .map(n => n[0])
        .join('')
        .toUpperCase()
        .slice(0, 2);
};

const formatCurrency = (amount) => {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'KES',
    }).format(amount || 0);
};
</script>

<style scoped>
.btn {
    @apply px-4 py-2 rounded-lg font-semibold transition-colors;
}

.btn-secondary {
    @apply bg-gray-200 text-gray-700 hover:bg-gray-300;
}
</style>
