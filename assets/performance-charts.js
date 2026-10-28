/* YSEO 5.83.0: dependency-free chart enhancement. Server SVG works without this file.
 * Only enqueued on Search Performance; no timers, canvas dependency or network I/O. */
(function () {
    'use strict';
    var svgNS = 'http://www.w3.org/2000/svg';
    function finiteNumber(n) { return typeof n === 'number' && Number.isFinite(n); }
    function enhance(svg) {
        var series;
        try { series = JSON.parse(svg.getAttribute('data-yseo-series') || '[]'); } catch (error) { return; }
        if (!Array.isArray(series) || !series.length) { return; }
        var card = svg.closest('.yseo-performance-chart');
        if (!card) { return; }
        var available = [];
        series.forEach(function (row, i) {
            if (row && finiteNumber(row.value)) { available.push({ index: i, value: row.value, date: String(row.date || ''), label: String(row.label || '') }); }
        });
        if (!available.length) { return; }
        var invert = svg.getAttribute('data-yseo-invert') === '1';
        var min = Math.min.apply(null, available.map(function (row) { return row.value; }));
        var max = Math.max.apply(null, available.map(function (row) { return row.value; }));
        if (Math.abs(max - min) < 0.000001) { max = min + 1; }
        var grids = Array.prototype.slice.call(svg.querySelectorAll('.yseo-chart-gridline'));
        var axes = Array.prototype.slice.call(svg.querySelectorAll('.yseo-chart-axis'));
        var paths = Array.prototype.slice.call(svg.querySelectorAll('.yseo-chart-line'));
        var dots = Array.prototype.slice.call(svg.querySelectorAll('.yseo-chart-point'));
        var crosshair = document.createElementNS(svgNS, 'line');
        crosshair.setAttribute('class', 'yseo-chart-crosshair');
        svg.insertBefore(crosshair, dots[0] || null);
        var tooltip = document.createElement('div');
        tooltip.className = 'yseo-chart-tooltip';
        tooltip.hidden = true;
        tooltip.setAttribute('aria-hidden', 'true');
        var dateEl = document.createElement('strong');
        var valueEl = document.createElement('span');
        tooltip.appendChild(dateEl);
        tooltip.appendChild(valueEl);
        card.appendChild(tooltip);
        var current = -1;
        var w = 520, h = 180;
        var pad = { l: 38, r: 28, t: 18, b: 32 };
        var coords = [];
        var n = Math.max(1, series.length - 1);
        var dotMap = new Map();
        dots.forEach(function (dot) {
            var index = Number(dot.getAttribute('data-index'));
            dotMap.set(index, dot);
            dot.setAttribute('tabindex', '-1');
            var title = dot.querySelector('title');
            if (title) { title.remove(); }
        });
        if (dots.length) { dots[0].setAttribute('tabindex', '0'); }
        function render() {
            var rect = svg.getBoundingClientRect();
            w = Math.max(220, Math.round(rect.width));
            h = Math.max(175, Math.round(rect.height));
            svg.setAttribute('viewBox', '0 0 ' + w + ' ' + h);
            svg.setAttribute('preserveAspectRatio', 'none');
            var plotW = Math.max(1, w - pad.l - pad.r);
            var plotH = Math.max(1, h - pad.t - pad.b);
            coords = series.map(function (row, i) {
                if (!row || !finiteNumber(row.value)) { return null; }
                var ratio = (row.value - min) / (max - min);
                if (invert) { ratio = 1 - ratio; }
                return { x: pad.l + plotW * (i / n), y: pad.t + plotH * (1 - ratio) };
            });
            grids.forEach(function (grid, i) {
                var y = pad.t + plotH * i / Math.max(1, grids.length - 1);
                grid.setAttribute('x1', pad.l); grid.setAttribute('x2', w - pad.r);
                grid.setAttribute('y1', y); grid.setAttribute('y2', y);
            });
            if (axes.length > 1) {
                axes[0].setAttribute('x', 2); axes[0].setAttribute('y', pad.t + 4);
                axes[1].setAttribute('x', 2); axes[1].setAttribute('y', h - pad.b + 4);
            }
            var segments = []; var segment = [];
            coords.forEach(function (point) {
                if (!point) { if (segment.length > 1) { segments.push(segment); } segment = []; return; }
                segment.push(point.x.toFixed(2) + ',' + point.y.toFixed(2));
            });
            if (segment.length > 1) { segments.push(segment); }
            paths.forEach(function (path, i) { path.setAttribute('points', segments[i] ? segments[i].join(' ') : ''); });
            coords.forEach(function (point, i) {
                var dot = dotMap.get(i);
                if (point && dot) { dot.setAttribute('cx', point.x.toFixed(2)); dot.setAttribute('cy', point.y.toFixed(2)); dot.setAttribute('r', 8); }
            });
            if (current >= 0) { show(current); }
        }
        function show(index) {
            var point = coords[index]; var row = series[index];
            if (!point || !row) { return; }
            if (current !== index) {
                var previous = dotMap.get(current);
                if (previous) { previous.classList.remove('is-active'); previous.setAttribute('tabindex', '-1'); }
                current = index;
                var selected = dotMap.get(index);
                if (selected) { selected.classList.add('is-active'); selected.setAttribute('tabindex', '0'); }
            }
            var svgBounds = svg.getBoundingClientRect(); var cardBounds = card.getBoundingClientRect();
            dateEl.textContent = String(row.date || '');
            valueEl.textContent = String(row.label || '');
            var x = svgBounds.left - cardBounds.left + point.x;
            var y = svgBounds.top - cardBounds.top + point.y;
            // Account for SVG/card relative coordinates and keep tooltip within the card.
            tooltip.style.left = Math.min(cardBounds.width - 75, Math.max(75, x)) + 'px';
            tooltip.style.top = Math.max(66, y) + 'px';
            tooltip.hidden = false;
            crosshair.setAttribute('x1', point.x); crosshair.setAttribute('x2', point.x);
            crosshair.setAttribute('y1', pad.t); crosshair.setAttribute('y2', h - pad.b);
            crosshair.style.display = 'block';
        }
        function hide() {
            var selected = dotMap.get(current);
            if (selected) { selected.classList.remove('is-active'); }
            tooltip.hidden = true; crosshair.style.display = 'none';
            current = -1;
        }
        svg.addEventListener('pointermove', function (event) {
            var rect = svg.getBoundingClientRect();
            if (!rect.width) { return; }
            var x = (event.clientX - rect.left) * w / rect.width;
            var idx = available.reduce(function (best, row) {
                var dist = Math.abs(coords[row.index].x - x);
                return dist < best.distance ? { index: row.index, distance: dist } : best;
            }, { index: available[0].index, distance: Infinity }).index;
            if (idx !== current) { show(idx); }
        }, { passive: true });
        svg.addEventListener('pointerleave', hide);
        svg.addEventListener('focusin', function (event) {
            var dot = event.target.closest('.yseo-chart-point');
            if (dot) { show(Number(dot.getAttribute('data-index'))); }
        });
        svg.addEventListener('focusout', function (event) {
            if (!svg.contains(event.relatedTarget)) { hide(); }
        });
        svg.addEventListener('keydown', function (event) {
            if (!['ArrowRight', 'ArrowLeft', 'Home', 'End', 'Escape'].includes(event.key)) { return; }
            if (event.key === 'Escape') { hide(); return; }
            event.preventDefault();
            var activePos = available.findIndex(function (row) { return row.index === current; });
            var pos = event.key === 'Home' ? 0 : event.key === 'End' ? available.length - 1 : Math.max(0, Math.min(available.length - 1, activePos + (event.key === 'ArrowRight' ? 1 : -1)));
            var target = dotMap.get(available[pos].index);
            if (target) { target.focus(); }
        });
        render();
        if ('ResizeObserver' in window) {
            var ro = new ResizeObserver(function () { render(); });
            ro.observe(svg);
        } else { window.addEventListener('resize', render, { passive: true }); }
    }
    function init() {
        var charts = document.querySelectorAll('.yseo-search-performance .yseo-performance-svg[data-yseo-series]');
        if (!charts.length) { return; }
        document.documentElement.classList.add('yseo-charts-enhanced');
        charts.forEach(enhance);
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); }
    else { init(); }
})();
