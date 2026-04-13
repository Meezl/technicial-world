<template>
    <div class="job-status-chart">
        <canvas ref="chartCanvas"></canvas>
    </div>
</template>

<script setup>
import { ref, onMounted, watch } from 'vue';
import { Chart, ArcElement, Tooltip, Legend } from 'chart.js';

Chart.register(ArcElement, Tooltip, Legend);

const props = defineProps({
    jobMetrics: Object
});

const chartCanvas = ref(null);
let chartInstance = null;

const createChart = () => {
    if (!chartCanvas.value) return;

    const ctx = chartCanvas.value.getContext('2d');

    if (chartInstance) {
        chartInstance.destroy();
    }

    chartInstance = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: ['Completed', 'Ongoing', 'Pending'],
            datasets: [{
                data: [
                    props.jobMetrics.completed,
                    props.jobMetrics.ongoing,
                    props.jobMetrics.pending
                ],
                backgroundColor: [
                    '#10b981', // green
                    '#3b82f6', // blue
                    '#f59e0b'  // yellow
                ],
                borderWidth: 2,
                borderColor: '#ffffff'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const label = context.label || '';
                            const value = context.parsed || 0;
                            const total = props.jobMetrics.total_jobs;
                            const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                            return `${label}: ${value} (${percentage}%)`;
                        }
                    }
                }
            },
            cutout: '70%'
        }
    });
};

onMounted(() => {
    createChart();
});

watch(() => props.jobMetrics, () => {
    createChart();
}, { deep: true });
</script>

<style scoped>
.job-status-chart {
    max-width: 300px;
    margin: 0 auto;
}
</style>
