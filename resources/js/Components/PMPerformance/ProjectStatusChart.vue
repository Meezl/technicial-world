<template>
    <div class="project-status-chart">
        <canvas ref="chartCanvas"></canvas>
    </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { Chart, ArcElement, Tooltip, Legend } from 'chart.js';

Chart.register(ArcElement, Tooltip, Legend);

const props = defineProps({
    projectMetrics: Object
});

const chartCanvas = ref(null);

onMounted(() => {
    const ctx = chartCanvas.value.getContext('2d');

    new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: ['Active', 'Completed', 'Pending', 'On Hold'],
            datasets: [{
                data: [
                    props.projectMetrics.status_distribution.active,
                    props.projectMetrics.status_distribution.completed,
                    props.projectMetrics.status_distribution.pending,
                    props.projectMetrics.status_distribution.on_hold
                ],
                backgroundColor: ['#3b82f6', '#10b981', '#f59e0b', '#ef4444'],
                borderWidth: 2,
                borderColor: '#ffffff'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: {
                    position: 'bottom'
                }
            },
            cutout: '60%'
        }
    });
});
</script>

<style scoped>
.project-status-chart {
    max-width: 300px;
    margin: 0 auto;
}
</style>
