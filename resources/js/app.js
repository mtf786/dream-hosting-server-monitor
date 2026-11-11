import 'bootstrap';
import Chart from 'chart.js/auto';

window.Chart = Chart;

const canvas = document.querySelector('[data-metrics-url]');

if (canvas) {
    const chart = new Chart(canvas, {
        type: 'line',
        data: {
            labels: [],
            datasets: [
                { label: 'CPU %', data: [], borderColor: '#2368e8', backgroundColor: '#2368e822', tension: 0.32 },
                { label: 'Memory %', data: [], borderColor: '#16a780', backgroundColor: '#16a78022', tension: 0.32 },
                { label: 'Disk %', data: [], borderColor: '#e89a24', backgroundColor: '#e89a2422', tension: 0.32 },
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { intersect: false, mode: 'index' },
            scales: { y: { min: 0, max: 100, ticks: { callback: (value) => `${value}%` } } },
        },
    });

    const refreshMetrics = async () => {
        try {
            const response = await fetch(canvas.dataset.metricsUrl, { headers: { Accept: 'application/json' } });
            if (!response.ok) return;
            const data = await response.json();
            chart.data.labels = data.labels;
            chart.data.datasets[0].data = data.cpu;
            chart.data.datasets[1].data = data.memory;
            chart.data.datasets[2].data = data.disk;
            chart.update();
        } catch {
            // Keep the last successful chart while the server is temporarily unreachable.
        }
    };

    refreshMetrics();
    window.setInterval(refreshMetrics, 60_000);
}

document.querySelectorAll('[data-copy]').forEach((button) => {
    button.addEventListener('click', async () => {
        const target = document.querySelector(button.dataset.copy);
        if (!target) return;
        await navigator.clipboard.writeText(target.textContent.trim());
        button.textContent = 'Copied';
        window.setTimeout(() => { button.textContent = 'Copy'; }, 1600);
    });
});
