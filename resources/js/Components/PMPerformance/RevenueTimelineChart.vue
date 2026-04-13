<template>
    <div class="revenue-timeline-chart">
        <canvas ref="chartCanvas"></canvas>
    </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { Chart, LineElement, PointElement, LinearScale, CategoryScale, Title, Tooltip, Legend } from 'chart.js';

Chart.register(LineElement, PointElement, LinearScale, CategoryScale, Title, Tooltip, Legend);

const props = defineProps({
    timeSeries: Object
});

const chartCanvas = ref(null);

onMounted(() => {
    const ctx = chartCanvas.value.getContext('2d');

    const months = props.timeSeries.revenue_by_month.map(item => item.month);
    const revenues = props.timeSeries.revenue_by_month.map(item => item.revenue);

    new Chart(ctx, {
        type: 'line',
        data: {
            labels: months,
            datasets: [{
                label: 'Revenue',
                data: revenues,
                borderColor: '#10b981',
                backgroundColor: 'rgba(16, 185, 129, 0.1)',
                borderWidth: 2,
                fill: true,
                tension: 0.4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: {
                    display: false
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return 'KES ' + value.toLocaleString();
                        }
                    }
                }
            }
        }
    });
});
</script>
