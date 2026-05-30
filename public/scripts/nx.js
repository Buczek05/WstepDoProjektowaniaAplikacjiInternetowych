// NexusOS charts — turns every <canvas.nx-canvas data-chart="..."> into a
// Chart.js chart with hover tooltips, a date X-axis and a value Y-axis.
document.addEventListener('DOMContentLoaded', function () {
  if (typeof Chart === 'undefined') return;

  var fmt = {
    currency: function (v) { return '$' + Number(v).toLocaleString('en-US', { maximumFractionDigits: 0 }); },
    ratio:    function (v) { return Number(v).toFixed(2) + 'x'; },
    count:    function (v) { return Number(v).toLocaleString('en-US'); }
  };

  var accent = '#6d8bff', grid = 'rgba(255,255,255,0.06)', muted = '#8a93a8';

  document.querySelectorAll('canvas.nx-canvas').forEach(function (cv) {
    var cfg;
    try { cfg = JSON.parse(cv.dataset.chart); } catch (e) { return; }
    var f = fmt[cfg.unit] || fmt.count;
    var isBar = cfg.type === 'bar';

    new Chart(cv, {
      type: cfg.type || 'line',
      data: {
        labels: cfg.labels,
        datasets: [{
          label: cfg.label,
          data: cfg.values,
          borderColor: accent,
          backgroundColor: isBar ? accent : 'rgba(109,139,255,0.16)',
          borderWidth: 2,
          fill: !isBar,
          tension: 0.35,
          pointRadius: 0,
          pointHoverRadius: 5,
          pointHoverBackgroundColor: accent,
          borderRadius: isBar ? 4 : 0,
          maxBarThickness: 18
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: { display: false },
          tooltip: {
            backgroundColor: '#161d31',
            borderColor: '#232c44',
            borderWidth: 1,
            titleColor: '#e8ecf5',
            bodyColor: '#e8ecf5',
            padding: 10,
            displayColors: false,
            callbacks: {
              label: function (ctx) { return cfg.label + ': ' + f(ctx.parsed.y); }
            }
          }
        },
        scales: {
          x: {
            grid: { display: false },
            ticks: { color: muted, maxTicksLimit: 8, autoSkip: true, maxRotation: 0 }
          },
          y: {
            beginAtZero: true,
            grid: { color: grid },
            ticks: { color: muted, maxTicksLimit: 6, callback: function (v) { return f(v); } }
          }
        }
      }
    });
  });
});
