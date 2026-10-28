(function () {
    'use strict';
    if (typeof YSEO_INSPECTOR === 'undefined') { return; }
    const panel = document.getElementById('yseo-inspector');
    const body = panel ? panel.querySelector('[data-yseo-inspector-body]') : null;
    const adminLink = document.querySelector('#wp-admin-bar-yseo-inspector > a');
    if (!panel || !body || !adminLink) { return; }

    function esc(value) {
        const div = document.createElement('div');
        div.textContent = value == null ? '' : String(value);
        return div.innerHTML;
    }
    function row(label, value, evidence) {
        return '<div class="yseo-inspector-row"><span>' + esc(label) + '</span><div><code>' + esc(value || '—') + '</code>' + (evidence ? '<small>' + esc(evidence) + '</small>' : '') + '</div></div>';
    }
    function render(data) {
        const p = data.projection || {};
        const e = data.evidence || {};
        const og = p.open_graph || {};
        const tw = p.twitter || {};
        const robots = p.robots || {};
        const schemaTypes = Array.isArray(data.schema_types) ? data.schema_types.join(', ') : '';
        body.innerHTML = '<div class="yseo-inspector-badges"><span>Authority: ' + esc(data.authority || 'unknown') + '</span><span>' + esc((data.resource || {}).type || '') + '</span><span>' + esc((data.resource || {}).language || 'auto') + '</span></div>' +
            row('Title', p.title, e.title) + row('Description', p.description, e.description) + row('Canonical', p.canonical, e.canonical) +
            row('Robots', (robots.index ? 'index' : 'noindex') + ', ' + (robots.follow ? 'follow' : 'nofollow'), e.robots) +
            row('OG title', og.title, e.open_graph) + row('OG image', og.image, '') + row('X title', tw.title, e.twitter) + row('X image', tw.image, '') +
            row('Schema types', schemaTypes, e.schema);
    }
    async function load() {
        body.textContent = (YSEO_INSPECTOR.labels && YSEO_INSPECTOR.labels.loading) || 'Loading…';
        const params = new URLSearchParams();
        params.set('action', 'yseo_frontend_inspect');
        params.set('nonce', YSEO_INSPECTOR.nonce || '');
        const resource = YSEO_INSPECTOR.resource || {};
        Object.keys(resource).forEach(function (key) { params.set('resource[' + key + ']', resource[key] == null ? '' : String(resource[key])); });
        const response = await fetch(YSEO_INSPECTOR.ajaxUrl, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'}, body: params.toString()});
        const json = await response.json();
        if (!json || !json.success || !json.data) { throw new Error((json && json.data && json.data.message) || ((YSEO_INSPECTOR.labels || {}).failed) || 'Error'); }
        render(json.data);
    }
    adminLink.addEventListener('click', function (event) {
        event.preventDefault();
        panel.hidden = false;
        document.documentElement.classList.add('yseo-inspector-open');
        load().catch(function (error) { body.textContent = error && error.message ? error.message : ((YSEO_INSPECTOR.labels || {}).failed || 'Error'); });
    });
    const close = panel.querySelector('[data-yseo-inspector-close]');
    if (close) { close.addEventListener('click', function () { panel.hidden = true; document.documentElement.classList.remove('yseo-inspector-open'); }); }
}());
