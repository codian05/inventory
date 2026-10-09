/**
 * reports.js
 * Generic Chart.js renderer for the Phase 7 report viewer.
 *
 * Every report supplies its chart(s) as plain data (type/title/labels/
 * datasets) via report_view_page.php — this file turns that payload
 * into Chart.js instances. No report-specific code lives here; adding
 * a 9th report to the registry needs zero changes to this file.
 *
 * Reuses window.chartTheme (assets/js/chart-theme.js) for palette and
 * shade generation, exactly like admin/dashboard.php's charts, so
 * report charts stay visually consistent with the rest of the app and
 * automatically follow the light/dark theme toggle.
 */
(function (window) {
    'use strict';

    function seriesColor(pal, index) {
        var seq = [pal.primary, pal.success, pal.danger, pal.secondary, pal.warning, pal.info];
        return seq[index % seq.length];
    }

    function renderOne(canvas, def, pal, gridOpts, tooltipOpts) {
        var type = def.type === 'horizontalBar' ? 'bar' : def.type;
        var isMulti = Array.isArray(def.datasets) && def.datasets.length > 1;
        var isSliceChart = type === 'doughnut' || type === 'pie';

        var datasets = (def.datasets || []).map(function (ds, i) {
            var base = {
                label: ds.label || def.title || '',
                data: ds.data || []
            };

            if (isSliceChart) {
                base.backgroundColor = window.chartTheme.shadeFamily(pal.primary, (ds.data || []).length);
                base.borderWidth = 0;
                base.hoverOffset = 6;
            } else if (type === 'line') {
                base.borderColor = seriesColor(pal, i);
                base.backgroundColor = seriesColor(pal, i) + '26';
                base.fill = true;
                base.tension = 0.4;
                base.pointRadius = 3;
                base.pointBackgroundColor = seriesColor(pal, i);
            } else if (isMulti) {
                base.backgroundColor = seriesColor(pal, i);
                base.borderRadius = 6;
                base.maxBarThickness = 28;
            } else {
                base.backgroundColor = window.chartTheme.shadeFamily(pal.primary, (ds.data || []).length);
                base.borderRadius = 6;
                base.maxBarThickness = 28;
            }

            return base;
        });

        var options = {
            maintainAspectRatio: false,
            plugins: {
                legend: { display: isMulti || isSliceChart, position: 'bottom' },
                tooltip: tooltipOpts
            }
        };

        if (!isSliceChart) {
            options.scales = {
                x: { grid: gridOpts, ticks: { precision: 0 } },
                y: { grid: gridOpts, beginAtZero: true, ticks: { precision: 0 } }
            };
            if (def.type === 'horizontalBar') {
                options.indexAxis = 'y';
            }
        } else {
            options.cutout = type === 'doughnut' ? '68%' : undefined;
        }

        return new Chart(canvas, {
            type: type,
            data: { labels: def.labels || [], datasets: datasets },
            options: options
        });
    }

    /**
     * @param {string} canvasPrefix e.g. "reportChart" -> looks for
     *   #reportChart0, #reportChart1, ...
     * @param {Array} charts array of chart definitions from the server
     */
    function renderAll(canvasPrefix, charts) {
        if (typeof Chart === 'undefined' || typeof window.chartTheme === 'undefined') {
            return;
        }

        var pal = window.chartTheme.palette();
        Chart.defaults.font.family = 'system-ui, -apple-system, Segoe UI, Roboto, sans-serif';
        Chart.defaults.color = pal.label;

        var gridOpts = { color: pal.grid, drawTicks: false };
        var tooltipOpts = {
            backgroundColor: pal.tooltipBg,
            titleColor: pal.label,
            bodyColor: pal.label,
            borderColor: pal.tooltipBorder,
            borderWidth: 1,
            padding: 10,
            boxPadding: 4
        };

        (charts || []).forEach(function (def, i) {
            var canvas = document.getElementById(canvasPrefix + i);
            if (canvas) {
                renderOne(canvas, def, pal, gridOpts, tooltipOpts);
            }
        });
    }

    window.reportsCharts = { renderAll: renderAll };

    /* ---------- Print button ---------- */
    document.addEventListener('DOMContentLoaded', function () {
        var printBtn = document.getElementById('reportPrintBtn');
        if (printBtn) {
            printBtn.addEventListener('click', function () {
                window.print();
            });
        }
    });
})(window);
