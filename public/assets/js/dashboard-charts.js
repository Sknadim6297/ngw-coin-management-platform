(() => {
  const palette = {
    gold: '#D4AF37',
    blue: '#3B82F6',
    green: '#22C55E',
    orange: '#F97316',
    purple: '#A855F7',
    pink: '#EC4899',
    cyan: '#06B6D4',
    amber: '#F59E0B',
    indigo: '#6366F1',
    teal: '#14B8A6',
    red: '#EF4444',
    muted: '#A1A1AA',
  };

  const textColor = '#A1A1AA';
  const gridColor = 'rgba(48, 42, 28, 0.9)';

  const colorForIndex = (index) => {
    const keys = ['blue', 'green', 'orange', 'purple', 'teal', 'amber', 'pink', 'cyan', 'indigo', 'gold'];
    return palette[keys[index % keys.length]];
  };

  const parsePayload = (el) => {
    try {
      return JSON.parse(el.getAttribute('data-chart') || '{}');
    } catch (_err) {
      return null;
    }
  };

  const buildConfig = (payload) => {
    const type = payload.type || 'line';
    const labels = payload.labels || [];
    const values = (payload.values || []).map((v) => Number(v) || 0);
    const isCircular = type === 'doughnut' || type === 'pie';

    return {
      type,
      data: {
        labels,
        datasets: [
          {
            label: 'Value',
            data: values,
            borderColor: isCircular ? labels.map((_, i) => colorForIndex(i)) : palette.gold,
            backgroundColor: isCircular
              ? labels.map((_, i) => colorForIndex(i))
              : type === 'bar'
                ? 'rgba(212, 175, 55, 0.55)'
                : 'rgba(212, 175, 55, 0.18)',
            borderWidth: isCircular ? 0 : 2,
            tension: 0.35,
            fill: type === 'line',
            pointRadius: type === 'line' ? 3 : 0,
            pointHoverRadius: 5,
          },
        ],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: {
            display: isCircular,
            labels: { color: textColor, boxWidth: 12 },
          },
          tooltip: {
            backgroundColor: '#18181D',
            titleColor: '#F8F7F4',
            bodyColor: '#F8F7F4',
            borderColor: '#302A1C',
            borderWidth: 1,
          },
        },
        scales: isCircular
          ? {}
          : {
              x: {
                ticks: { color: textColor, maxRotation: 0, autoSkip: true, maxTicksLimit: 7 },
                grid: { color: gridColor },
              },
              y: {
                beginAtZero: true,
                ticks: { color: textColor },
                grid: { color: gridColor },
              },
            },
      },
    };
  };

  const boot = () => {
    if (typeof Chart === 'undefined') {
      return;
    }

    document.querySelectorAll('[data-dashboard-chart]').forEach((canvas) => {
      const payload = parsePayload(canvas);
      if (!payload) {
        return;
      }
      // eslint-disable-next-line no-new
      new Chart(canvas, buildConfig(payload));
    });
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
