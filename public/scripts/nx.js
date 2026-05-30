// NexusOS — mobile side-drawer navigation (off-canvas).
(function () {
  function drawer() { return document.querySelector('[data-drawer]'); }
  function backdrop() { return document.querySelector('[data-nav-backdrop]'); }
  function open() { var d = drawer(), b = backdrop(); if (d) d.classList.add('is-open'); if (b) b.hidden = false; document.body.classList.add('nx-nav-open'); }
  function close() { var d = drawer(), b = backdrop(); if (d) d.classList.remove('is-open'); if (b) b.hidden = true; document.body.classList.remove('nx-nav-open'); }
  document.addEventListener('click', function (e) {
    if (e.target.closest('[data-nav-toggle]')) { e.preventDefault(); drawer() && drawer().classList.contains('is-open') ? close() : open(); }
    else if (e.target.closest('[data-nav-backdrop]')) { close(); }
    else if (e.target.closest('.nx-nav a')) { close(); } // close after navigating
  });
  window.addEventListener('resize', function () { if (window.innerWidth > 860) close(); });
})();

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
  var palette = ['#6d8bff', '#3ad6a8', '#f4c152', '#ff6b6b', '#9b6dff', '#58b4ff', '#ff8f6b', '#5ad1c4', '#c0d16d', '#e06ec0'];

  document.querySelectorAll('canvas.nx-canvas').forEach(function (cv) {
    var cfg;
    try { cfg = JSON.parse(cv.dataset.chart); } catch (e) { return; }
    var f = fmt[cfg.unit] || fmt.count;

    // Doughnut / pie
    if (cfg.type === 'doughnut' || cfg.type === 'pie') {
      new Chart(cv, {
        type: cfg.type,
        data: {
          labels: cfg.labels,
          datasets: [{ data: cfg.values, backgroundColor: palette, borderColor: '#0b0f1a', borderWidth: 2 }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          cutout: cfg.type === 'doughnut' ? '62%' : 0,
          plugins: {
            legend: { position: 'right', labels: { color: '#e8ecf5', boxWidth: 12, padding: 10, font: { size: 12 } } },
            tooltip: {
              backgroundColor: '#161d31', borderColor: '#232c44', borderWidth: 1,
              titleColor: '#e8ecf5', bodyColor: '#e8ecf5', padding: 10,
              callbacks: {
                label: function (ctx) {
                  var total = ctx.dataset.data.reduce(function (a, b) { return a + Number(b); }, 0);
                  var pct = total > 0 ? Math.round(100 * ctx.parsed / total) : 0;
                  return ctx.label + ': ' + f(ctx.parsed) + ' (' + pct + '%)';
                }
              }
            }
          }
        }
      });
      return;
    }

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
