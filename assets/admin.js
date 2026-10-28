(function () {
    'use strict';

    function initDashboardTabs() {
        const layerTabs = Array.from(document.querySelectorAll('[data-yps-layer]'));
        const tabs = Array.from(document.querySelectorAll('[data-yps-tab]'));
        const panels = Array.from(document.querySelectorAll('[data-yps-panel]'));
        const contextNav = document.querySelector('.yps-context-tabs');
        if (!panels.length) {
            return;
        }

        const defaults = { overview: 'overview', seo: 'search', aeo: 'answer', geo: 'ai', system: 'migration' };
        function parentFor(name) {
            if (name === 'overview') { return 'overview'; }
            const tab = tabs.find(function (item) { return item.dataset.ypsTab === name; });
            return tab ? (tab.dataset.ypsParent || 'seo') : 'overview';
        }
        function resolve(name) {
            if (defaults[name]) { return defaults[name]; }
            return panels.some(function (panel) { return panel.dataset.ypsPanel === name; }) ? name : 'overview';
        }
        function activate(requested, updateHash) {
            const name = resolve(requested);
            const layer = parentFor(name);
            panels.forEach(function (panel) { panel.hidden = panel.dataset.ypsPanel !== name; });
            layerTabs.forEach(function (item) {
                const active = item.dataset.ypsLayer === layer;
                item.classList.toggle('is-active', active);
                item.setAttribute('aria-selected', active ? 'true' : 'false');
            });
            tabs.forEach(function (tab) {
                const inLayer = tab.dataset.ypsParent === layer;
                tab.hidden = !inLayer || layer === 'overview';
                const active = tab.dataset.ypsTab === name;
                tab.classList.toggle('is-active', active);
                tab.setAttribute('aria-selected', active ? 'true' : 'false');
            });
            if (contextNav) { contextNav.hidden = layer === 'overview'; }
            if (updateHash && window.history && window.history.replaceState) {
                window.history.replaceState(null, '', '#yps-' + name);
            }
        }

        layerTabs.forEach(function (item) {
            item.addEventListener('click', function () { activate(item.dataset.ypsLayer || 'overview', true); });
        });
        tabs.forEach(function (tab) {
            tab.addEventListener('click', function () { activate(tab.dataset.ypsTab, true); });
        });
        document.querySelectorAll('.yps-jump-tab').forEach(function (button) {
            button.addEventListener('click', function () { activate(button.dataset.ypsTarget || 'overview', true); });
        });

        function routeFromHash(hash) {
            const value = String(hash || '');
            if (value.indexOf('#yps-') === 0) { return value.slice(5); }
            const legacy = {
                '#system/migration': 'migration',
                '#seo/sitemaps': 'sitemaps',
                '#aeo/answer': 'answer',
                '#geo/ai': 'ai',
                '#seo/search': 'search',
                '#seo/content': 'content'
            };
            return legacy[value] || 'overview';
        }

        const initial = routeFromHash(window.location.hash);
        activate(initial, false);
        window.addEventListener('hashchange', function () {
            activate(routeFromHash(window.location.hash), false);
        });
    }

    function initEditorTabs() {
        document.querySelectorAll('.yps-editor').forEach(function (editor) {
            const tabs = Array.from(editor.querySelectorAll('[data-yps-editor-tab]'));
            const panels = Array.from(editor.querySelectorAll('[data-yps-editor-panel]'));
            function activate(name) {
                tabs.forEach(function (tab) {
                    const active = tab.dataset.ypsEditorTab === name;
                    tab.classList.toggle('is-active', active);
                    tab.setAttribute('aria-selected', active ? 'true' : 'false');
                });
                panels.forEach(function (panel) {
                    panel.hidden = panel.dataset.ypsEditorPanel !== name;
                });
            }
            tabs.forEach(function (tab) {
                tab.addEventListener('click', function () { activate(tab.dataset.ypsEditorTab); });
            });
            editor.addEventListener('click', function (event) {
                const button = event.target && event.target.closest ? event.target.closest('[data-yps-score-jump]') : null;
                if (!button || !editor.contains(button)) { return; }
                activate(button.dataset.ypsScoreJump || 'search');
            });
            activate('search');
        });
    }

    function initPreview() {
        document.querySelectorAll('.yps-editor').forEach(function (editor) {
            const titleInput = editor.querySelector('[name="yps_seo_title"]');
            const descriptionInput = editor.querySelector('[name="yps_meta_description"]');
            const titleNode = editor.querySelector('[data-yps-preview-title]');
            const descriptionNode = editor.querySelector('[data-yps-preview-description]');
            const fallbackTitle = editor.dataset.ypsFallbackTitle || '';
            const fallbackDescription = editor.dataset.ypsFallbackDescription || '';
            const ogImageInput = editor.querySelector('[name="yps_og_image"]');
            const twitterImageInput = editor.querySelector('[name="yps_twitter_image"]');
            const ogTitleInput = editor.querySelector('[name="yps_og_title"]');
            const ogDescriptionInput = editor.querySelector('[name="yps_og_description"]');
            const twitterTitleInput = editor.querySelector('[name="yps_twitter_title"]');
            const twitterDescriptionInput = editor.querySelector('[name="yps_twitter_description"]');
            const previewImage = editor.querySelector('[data-yps-preview-image]');
            const previewBox = editor.querySelector('[data-yps-search-preview]');
            const focusInput = editor.querySelector('[name="yps_focus_keyphrase"]');
            const templateContext = {
                title: editor.dataset.ypsTemplateTitle || '',
                sitename: editor.dataset.ypsTemplateSitename || '',
                sitedesc: editor.dataset.ypsTemplateSitedesc || '',
                sep: editor.dataset.ypsTemplateSep || '-',
                id: editor.dataset.ypsPostId || '',
                page: '',
                pagetotal: '',
                pagenumber: ''
            };

            function resolveTemplate(value) {
                let resolved = String(value || '');
                const replacements = Object.assign({}, templateContext, {
                    focuskw: focusInput ? focusInput.value.trim() : ''
                });
                Object.keys(replacements).forEach(function (name) {
                    resolved = resolved.split('%%' + name + '%%').join(String(replacements[name] || ''));
                });
                // Unknown variables are intentionally not shown as literal output in the preview.
                resolved = resolved.replace(/%%[A-Za-z0-9_-]+%%/g, '');
                return resolved.replace(/\s+/g, ' ').trim();
            }

            function setSocialPreview(network, title, description, imageUrl) {
                const titleNode = editor.querySelector('[data-yps-social-title="' + network + '"]');
                const descriptionNode = editor.querySelector('[data-yps-social-description="' + network + '"]');
                const imageNode = editor.querySelector('[data-yps-social-image="' + network + '"]');
                if (titleNode) { titleNode.textContent = title; }
                if (descriptionNode) { descriptionNode.textContent = description; }
                if (imageNode) {
                    let image = imageNode.querySelector('img');
                    if (imageUrl) {
                        if (!image) { image = document.createElement('img'); image.alt = ''; imageNode.appendChild(image); }
                        image.src = imageUrl; image.hidden = false;
                    } else if (image) { image.remove(); }
                    imageNode.classList.toggle('is-empty', !imageUrl);
                }
            }

            function update() {
                if (titleNode) {
                    titleNode.textContent = titleInput && titleInput.value.trim() ? resolveTemplate(titleInput.value.trim()) : fallbackTitle;
                }
                if (descriptionNode) {
                    descriptionNode.textContent = descriptionInput && descriptionInput.value.trim() ? resolveTemplate(descriptionInput.value.trim()) : fallbackDescription;
                }
                if (previewImage && ogImageInput) {
                    const imageUrl = ogImageInput.value.trim();
                    previewImage.hidden = !imageUrl;
                    if (imageUrl) { previewImage.src = imageUrl; } else { previewImage.removeAttribute('src'); }
                    if (previewBox) { previewBox.classList.toggle('has-image', !!imageUrl); }
                }
                const searchTitle = titleInput && titleInput.value.trim() ? resolveTemplate(titleInput.value.trim()) : fallbackTitle;
                const searchDescription = descriptionInput && descriptionInput.value.trim() ? resolveTemplate(descriptionInput.value.trim()) : fallbackDescription;
                const ogTitle = ogTitleInput && ogTitleInput.value.trim() ? resolveTemplate(ogTitleInput.value.trim()) : searchTitle;
                const ogDescription = ogDescriptionInput && ogDescriptionInput.value.trim() ? resolveTemplate(ogDescriptionInput.value.trim()) : searchDescription;
                const ogImage = ogImageInput ? ogImageInput.value.trim() : '';
                const xTitle = twitterTitleInput && twitterTitleInput.value.trim() ? resolveTemplate(twitterTitleInput.value.trim()) : searchTitle;
                const xDescription = twitterDescriptionInput && twitterDescriptionInput.value.trim() ? resolveTemplate(twitterDescriptionInput.value.trim()) : searchDescription;
                const xImage = twitterImageInput && twitterImageInput.value.trim() ? twitterImageInput.value.trim() : ogImage;
                setSocialPreview('facebook', ogTitle, ogDescription, ogImage);
                setSocialPreview('x', xTitle, xDescription, xImage);
                editor.querySelectorAll('[data-yps-count-for]').forEach(function (node) {
                    const field = editor.querySelector('[name="' + node.dataset.ypsCountFor + '"]');
                    const count = field ? Array.from(field.value || '').length : 0;
                    if (node.matches('[data-yps-serp-meter]')) {
                        const low = Number(node.dataset.ypsLow || 0);
                        const min = Number(node.dataset.ypsMin || 0);
                        const idealMax = Number(node.dataset.ypsIdealMax || 0);
                        const max = Math.max(1, Number(node.dataset.ypsMax || 1));
                        const labelNode = node.querySelector('[data-yps-serp-label]');
                        const countNode = node.querySelector('[data-yps-serp-count]');
                        const track = node.querySelector('.yps-serp-meter-track');
                        const fill = track ? track.querySelector('i') : null;
                        let state = 'critical';
                        let label = (window.YPS_EDITOR && YPS_EDITOR.labels && YPS_EDITOR.labels.serpEmpty) || 'Empty';
                        if (count > 0 && count < low) {
                            label = (window.YPS_EDITOR && YPS_EDITOR.labels && YPS_EDITOR.labels.serpTooShort) || 'Too short';
                        } else if (count >= low && count < min) {
                            state = 'attention';
                            label = (window.YPS_EDITOR && YPS_EDITOR.labels && YPS_EDITOR.labels.serpShort) || 'Short';
                        } else if (count >= min && count <= idealMax) {
                            state = 'good';
                            label = (window.YPS_EDITOR && YPS_EDITOR.labels && YPS_EDITOR.labels.serpGood) || 'Good fit';
                        } else if (count > idealMax && count <= max) {
                            state = 'attention';
                            label = (window.YPS_EDITOR && YPS_EDITOR.labels && YPS_EDITOR.labels.serpLong) || 'Long';
                        } else if (count > max) {
                            label = (window.YPS_EDITOR && YPS_EDITOR.labels && YPS_EDITOR.labels.serpTooLong) || 'Too long';
                        }
                        node.classList.remove('is-good', 'is-attention', 'is-critical');
                        node.classList.add('is-' + state);
                        if (labelNode) { labelNode.textContent = label; }
                        if (countNode) { countNode.textContent = String(count); }
                        if (fill) { fill.style.width = Math.min(100, (count / max) * 100) + '%'; }
                        if (track) {
                            track.setAttribute('aria-valuenow', String(Math.min(count, max)));
                            track.setAttribute('aria-valuetext', label + ' · ' + count);
                        }
                    } else {
                        node.textContent = String(count);
                    }
                });
            }
            [titleInput, descriptionInput, focusInput, ogImageInput, twitterImageInput, ogTitleInput, ogDescriptionInput, twitterTitleInput, twitterDescriptionInput].forEach(function (field) {
                if (!field) { return; }
                field.addEventListener('input', update);
                field.addEventListener('change', update);
            });
            update();
        });
    }


    function initAnalysisFreshness() {
        document.querySelectorAll('.yps-editor').forEach(function (editor) {
            const notice = editor.querySelector('[data-yps-analysis-stale]');
            if (!notice) { return; }
            let stale = false;
            function markStale() {
                stale = true;
                notice.hidden = false;
            }
            editor.querySelectorAll('input, textarea, select').forEach(function (field) {
                field.addEventListener('input', markStale);
                field.addEventListener('change', markStale);
            });
            if (window.wp && wp.data && typeof wp.data.subscribe === 'function' && wp.data.select && wp.data.select('core/editor')) {
                let wasDirty = !!wp.data.select('core/editor').isEditedPostDirty();
                wp.data.subscribe(function () {
                    const store = wp.data.select('core/editor');
                    if (!store || typeof store.isEditedPostDirty !== 'function') { return; }
                    const isDirty = !!store.isEditedPostDirty();
                    if (isDirty && !wasDirty) { markStale(); }
                    wasDirty = isDirty;
                });
            }
        });
    }


    function initMigration() {
        const status = document.getElementById('yps-migration-status');
        const backupStatus = document.getElementById('yps-backup-status');
        const consoleBox = document.getElementById('yps-migration-console');
        const consoleBadge = document.getElementById('yps-migration-console-badge');
        const headBadge = document.getElementById('yps-migration-head-badge');
        const consoleTitle = document.getElementById('yps-migration-console-title');
        const consoleDetail = document.getElementById('yps-migration-console-detail');
        const consoleMeta = document.getElementById('yps-migration-console-meta');
        const progress = document.getElementById('yps-migration-progress');
        const conflictHost = document.getElementById('yps-conflict-review-host');
        const prepareTakeover = document.getElementById('yps-prepare-takeover');
        const dryRun = document.getElementById('yps-dry-run');
        const backupStart = document.getElementById('yps-backup-start');
        const shadowImport = document.getElementById('yps-shadow-import');
        const takeover = document.getElementById('yps-takeover');
        const inspectConflicts = document.getElementById('yps-inspect-conflicts');
        const resume = document.getElementById('yps-resume');
        const reset = document.getElementById('yps-reset');
        const stages = {
            source: document.querySelector('[data-yps-stage="source"]'),
            backup: document.querySelector('[data-yps-stage="backup"]'),
            shadow: document.querySelector('[data-yps-stage="shadow"]'),
            takeover: document.querySelector('[data-yps-stage="takeover"]')
        };
        let lastMigrationState = null;
        let lastBackupState = null;
        let busyNow = false;
        let dryRunFreshUntil = 0;
        let freshnessTimer = null;

        if (!status || typeof YPS_ADMIN === 'undefined') {
            return;
        }

        function encode(data) {
            const body = new URLSearchParams();
            Object.keys(data).forEach(function (key) { body.append(key, data[key]); });
            return body;
        }
        function sleep(milliseconds) {
            return new Promise(function (resolve) { window.setTimeout(resolve, milliseconds); });
        }
        async function request(action, extra) {
            let lastError = null;
            for (let attempt = 0; attempt < 3; attempt += 1) {
                try {
                    const response = await fetch(YPS_ADMIN.ajaxUrl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
                        body: encode(Object.assign({action: action, nonce: YPS_ADMIN.nonce}, extra || {}))
                    });
                    const json = await response.json();
                    if (response.ok && json.success) { return json.data; }
                    const message = json && json.data && json.data.message ? json.data.message : YPS_ADMIN.labels.failed;
                    if (![429, 502, 503, 504].includes(response.status)) { throw new Error(message); }
                    lastError = new Error(message);
                    lastError.retryAfterMs = json && json.data && Number.isFinite(Number(json.data.retry_after_ms)) ? Number(json.data.retry_after_ms) : 250 * (attempt + 1);
                } catch (error) {
                    lastError = error;
                }
                const retryDelay = lastError && Number.isFinite(Number(lastError.retryAfterMs)) ? Number(lastError.retryAfterMs) : 250 * (attempt + 1);
                await sleep(Math.max(100, Math.min(2000, retryDelay)));
            }
            throw lastError || new Error(YPS_ADMIN.labels.failed);
        }
        function phaseLabel(phase) {
            const map = {
                inventory: YPS_ADMIN.labels.phaseInventory,
                globals: YPS_ADMIN.labels.phaseGlobals,
                posts: YPS_ADMIN.labels.phasePosts,
                users: YPS_ADMIN.labels.phaseUsers,
                taxonomy: YPS_ADMIN.labels.phaseTaxonomy,
                finalize: YPS_ADMIN.labels.phaseFinalize,
                complete: YPS_ADMIN.labels.phaseComplete
            };
            return map[phase] || phase || '';
        }
        function setProgress(tone, percent, indeterminate) {
            if (!consoleBox || !progress) { return; }
            consoleBox.className = 'yps-migration-console is-' + tone;
            progress.classList.toggle('is-indeterminate', !!indeterminate);
            const safePercent = Math.max(0, Math.min(100, Number(percent || 0)));
            progress.setAttribute('aria-valuenow', String(safePercent));
            const bar = progress.querySelector('span');
            if (bar) { bar.style.width = safePercent + '%'; }
        }
        function setConsole(tone, badge, title, detail, meta, percent, indeterminate) {
            if (consoleBadge) { consoleBadge.textContent = badge; }
            if (headBadge) { headBadge.textContent = badge; headBadge.className = 'yps-mini-status ' + tone; }
            if (consoleTitle) { consoleTitle.textContent = title; }
            if (consoleDetail) { consoleDetail.textContent = detail; }
            if (consoleMeta) { consoleMeta.textContent = meta || ''; }
            setProgress(tone, percent, indeterminate);
        }
        function setStage(name, cssClass, text) {
            const el = stages[name];
            if (!el) { return; }
            el.className = cssClass;
            const strong = el.querySelector('strong');
            if (strong) { strong.textContent = text; }
        }
        function showRunning(kind, state) {
            const phase = state ? phaseLabel(state.phase) : '';
            let title = YPS_ADMIN.labels.migrationRunning;
            let detail = YPS_ADMIN.labels.progressSafe;
            let meta = phase;
            if (kind === 'backup') {
                title = YPS_ADMIN.labels.backupRunning;
                meta = phase + ' · ' + (state && state.export_records ? state.export_records : 0) + ' / ' + (state && state.verify_records ? state.verify_records : 0);
            } else if (kind === 'mirror') {
                title = YPS_ADMIN.labels.mirrorRunning;
            } else if (state) {
                meta = phase + ' · ' + YPS_ADMIN.labels.posts + ': ' + (state.processed_posts || 0) + ' · ' + YPS_ADMIN.labels.imported + ': ' + (state.imported_values || 0) + ' · ' + YPS_ADMIN.labels.conflicts + ': ' + (state.conflicts || 0);
            }
            setConsole('running', YPS_ADMIN.labels.badgeRunning || 'RUNNING', title, detail, meta, 46, true);
        }
        function render(state) {
            lastMigrationState = state || null;
            if (!state || state.status === 'reset') {
                status.textContent = YPS_ADMIN.labels.empty;
                updateControls();
                return;
            }
            const items = [
                [YPS_ADMIN.labels.mode, state.mode], [YPS_ADMIN.labels.status, state.status], [YPS_ADMIN.labels.phase, state.phase],
                [YPS_ADMIN.labels.posts, state.processed_posts], [YPS_ADMIN.labels.users, state.processed_user_meta || 0], [YPS_ADMIN.labels.terms, state.processed_terms],
                [YPS_ADMIN.labels.mapped, state.mapped_values], [YPS_ADMIN.labels.global, state.global_mapped_values],
                [YPS_ADMIN.labels.unsupported, state.classification_counts && state.classification_counts.UNSUPPORTED ? state.classification_counts.UNSUPPORTED : 0],
                [YPS_ADMIN.labels.ignored, state.ignored_derived_values], [YPS_ADMIN.labels.imported, state.imported_values], [YPS_ADMIN.labels.verified, state.verified_values],
                [YPS_ADMIN.labels.conflicts, state.conflicts], [YPS_ADMIN.labels.batch, state.adaptive_batch_size || 0], [YPS_ADMIN.labels.stepMs, state.last_step_ms || 0],
                [YPS_ADMIN.labels.queries, state.last_step_queries || 0], [YPS_ADMIN.labels.memory, state.last_step_memory_delta || 0],
                [YPS_ADMIN.labels.pressure, (state.pressure_state || 'normal') + ' · ' + (state.pressure_reason || 'steady')], [YPS_ADMIN.labels.delay, state.recommended_delay_ms || 25]
            ];
            const list = document.createElement('ul');
            items.forEach(function (item) {
                const li = document.createElement('li');
                li.textContent = item[0] + ': ' + item[1];
                list.appendChild(li);
            });
            status.replaceChildren(list);
            if (state.message) {
                const paragraph = document.createElement('p');
                paragraph.textContent = state.message;
                status.appendChild(paragraph);
            }

            if (state.status === 'running') {
                showRunning('migration', state);
                if (state.mode === 'import') { setStage('shadow', 'is-active', YPS_ADMIN.labels.running); }
                else { setStage('source', 'is-active', YPS_ADMIN.labels.running); }
            } else if (state.status === 'blocked') {
                setConsole('stopped', YPS_ADMIN.labels.badgeStopped || 'STOPPED', YPS_ADMIN.labels.migrationStopped, state.message || YPS_ADMIN.labels.progressReview, YPS_ADMIN.labels.conflicts + ': ' + (state.conflicts || 0) + ' · ' + YPS_ADMIN.labels.progressSafe, 100, false);
                if (state.source_coverage_ready) { setStage('source', 'is-complete', YPS_ADMIN.labels.progressVerified); }
                if (state.mode === 'import') { setStage('shadow', 'is-stopped', YPS_ADMIN.labels.progressReview); }
            } else if (state.status === 'complete' && state.mode === 'import' && state.cutover_ready) {
                setConsole('complete', YPS_ADMIN.labels.badgeVerified || 'VERIFIED', YPS_ADMIN.labels.migrationComplete, state.message || YPS_ADMIN.labels.progressVerified, YPS_ADMIN.labels.progressSafe, 100, false);
                setStage('source', 'is-complete', YPS_ADMIN.labels.progressVerified);
                setStage('backup', 'is-complete', YPS_ADMIN.labels.progressVerified);
                setStage('shadow', 'is-complete', YPS_ADMIN.labels.progressVerified);
                setStage('takeover', 'is-active', YPS_ADMIN.labels.available);
            } else if (state.status === 'complete' && state.mode === 'dry-run' && state.source_coverage_ready) {
                dryRunFreshUntil = Date.now() + (15 * 60 * 1000);
                startFreshnessTimer();
                setConsole('complete', YPS_ADMIN.labels.badgeVerified || 'VERIFIED', YPS_ADMIN.labels.migrationComplete, state.message || YPS_ADMIN.labels.progressVerified, YPS_ADMIN.labels.progressSafe, 100, false);
                setStage('source', 'is-complete', YPS_ADMIN.labels.progressVerified);
            }
            updateControls();
        }
        function renderBackup(state) {
            lastBackupState = state || null;
            if (!backupStatus) { return; }
            if (!state || state.status === 'reset') {
                backupStatus.textContent = YPS_ADMIN.labels.backupEmpty;
                updateControls();
                return;
            }
            const list = document.createElement('ul');
            [
                [YPS_ADMIN.labels.backupStatus, state.status],
                [YPS_ADMIN.labels.backupPhase, state.phase],
                [YPS_ADMIN.labels.backupRecords, state.export_records || 0],
                [YPS_ADMIN.labels.backupVerified, state.verify_records || 0]
            ].forEach(function (item) {
                const li = document.createElement('li'); li.textContent = item[0] + ': ' + item[1]; list.appendChild(li);
            });
            backupStatus.replaceChildren(list);
            if (state.message) { const p = document.createElement('p'); p.textContent = state.message; backupStatus.appendChild(p); }
            if (state.status === 'running') {
                showRunning('backup', state);
                setStage('backup', 'is-active', YPS_ADMIN.labels.running);
            } else if (state.status === 'complete' && state.verified) {
                setConsole('complete', YPS_ADMIN.labels.badgeVerified || 'VERIFIED', YPS_ADMIN.labels.backupComplete, state.message || YPS_ADMIN.labels.progressVerified, YPS_ADMIN.labels.progressSafe, 100, false);
                setStage('backup', 'is-complete', YPS_ADMIN.labels.progressVerified);
            } else if (state.status === 'blocked') {
                setConsole('stopped', YPS_ADMIN.labels.badgeStopped || 'STOPPED', YPS_ADMIN.labels.migrationStopped, state.message || YPS_ADMIN.labels.progressReview, YPS_ADMIN.labels.progressSafe, 100, false);
                setStage('backup', 'is-stopped', YPS_ADMIN.labels.progressReview);
            }
            updateControls();
        }
        function startFreshnessTimer() {
            if (freshnessTimer) { window.clearInterval(freshnessTimer); }
            if (!dryRunFreshUntil) { return; }
            const tick = function () {
                if (!consoleMeta || !dryRunFreshUntil) { return; }
                const remaining = Math.max(0, Math.ceil((dryRunFreshUntil - Date.now()) / 1000));
                if (remaining <= 0) {
                    dryRunFreshUntil = 0;
                    consoleMeta.textContent = YPS_ADMIN.labels.dryRunExpired;
                    if (backupStart) { backupStart.disabled = true; }
                    window.clearInterval(freshnessTimer);
                    freshnessTimer = null;
                    return;
                }
                const minutes = Math.floor(remaining / 60);
                const seconds = String(remaining % 60).padStart(2, '0');
                consoleMeta.textContent = YPS_ADMIN.labels.dryRunValid + ' ' + minutes + ':' + seconds + ' · ' + YPS_ADMIN.labels.progressSafe;
            };
            tick();
            freshnessTimer = window.setInterval(tick, 1000);
        }
        async function continueJob(state) {
            while (state && state.status === 'running') {
                state = await request('yps_migration_step');
                render(state);
                await sleep(Math.max(25, Math.min(2000, Number(state.recommended_delay_ms || 25))));
            }
            return state;
        }
        async function continueBackup(state) {
            while (state && state.status === 'running') {
                state = await request('yps_backup_step');
                renderBackup(state);
                await sleep(40);
            }
            return state;
        }
        function updateControls() {
            if (busyNow) { return; }
            const state = lastMigrationState;
            const backup = lastBackupState;
            if (prepareTakeover) { prepareTakeover.disabled = false; }
            if (dryRun) { dryRun.disabled = false; }
            const freshDryRun = !!(state && state.mode === 'dry-run' && state.status === 'complete' && state.source_coverage_ready && (!dryRunFreshUntil || Date.now() < dryRunFreshUntil));
            if (backupStart && state) { backupStart.disabled = !freshDryRun; }
            const backupVerified = !!(backup && backup.status === 'complete' && backup.verified) || !!(state && state.backup_export_verified);
            if (shadowImport && state) { shadowImport.disabled = !(freshDryRun && backupVerified); }
            if (takeover && state) { takeover.disabled = !(state.status === 'complete' && state.mode === 'import' && state.cutover_ready); }
            if (inspectConflicts && state) { inspectConflicts.disabled = !(Number(state.conflicts || 0) > 0); }
            if (resume) { resume.disabled = !(state && state.status === 'running'); }
            if (reset) { reset.disabled = false; }
        }
        function setBusy(busy) {
            busyNow = busy;
            [prepareTakeover, dryRun, backupStart, shadowImport, takeover, inspectConflicts, resume, reset].forEach(function (button) { if (button) { button.disabled = busy; } });
            if (!busy) { updateControls(); }
        }
        function fail(error) {
            const message = error && error.message ? error.message : YPS_ADMIN.labels.failed;
            setConsole('failed', YPS_ADMIN.labels.badgeFailed || 'FAILED', YPS_ADMIN.labels.failed, message, YPS_ADMIN.labels.progressSafe, 100, false);
            const p = document.createElement('p'); p.textContent = message; status.replaceChildren(p);
        }
        async function run(mode) {
            setBusy(true);
            try {
                let state = await request('yps_migration_start', {mode: mode});
                render(state);
                state = await continueJob(state);
                if (state && state.conflicts > 0) { await loadConflictReview(); }
                return state;
            } catch (error) {
                fail(error);
                return null;
            } finally { setBusy(false); }
        }
        function renderConflictReview(inspection) {
            if (!conflictHost) { return; }
            conflictHost.replaceChildren();
            if (!inspection || Number(inspection.state_conflicts || 0) <= 0) { return; }
            const box = document.createElement('div');
            box.className = 'yps-conflict-review ' + (inspection.legacy_global_merge ? 'is-repairable' : 'is-conflict');
            const head = document.createElement('div'); head.className = 'yps-conflict-review-head';
            const title = document.createElement('strong'); title.textContent = inspection.legacy_global_merge ? YPS_ADMIN.labels.legacyMergeTitle : YPS_ADMIN.labels.migrationConflictTitle;
            const badge = document.createElement('span'); badge.className = 'yps-mini-status ' + (inspection.legacy_global_merge ? 'attention' : 'bad'); badge.textContent = inspection.legacy_global_merge ? YPS_ADMIN.labels.repairable : YPS_ADMIN.labels.conflictBadge;
            head.append(title, badge); box.appendChild(head);
            const message = document.createElement('p'); message.textContent = inspection.message || ''; box.appendChild(message);
            if (inspection.legacy_global_merge) {
                const note = document.createElement('p'); note.className = 'yps-muted'; note.textContent = YPS_ADMIN.labels.legacyMergeNote; box.appendChild(note);
            }
            const details = Array.isArray(inspection.details) ? inspection.details : [];
            if (details.length) {
                const wrap = document.createElement('div'); wrap.className = 'yps-conflict-table-wrap';
                const table = document.createElement('table'); table.className = 'widefat striped yps-conflict-table';
                const thead = document.createElement('thead'); const hr = document.createElement('tr');
                [YPS_ADMIN.labels.scope, YPS_ADMIN.labels.object, YPS_ADMIN.labels.sourceField, YPS_ADMIN.labels.yseoField, YPS_ADMIN.labels.reason].forEach(function (text) { const th = document.createElement('th'); th.textContent = text; hr.appendChild(th); });
                thead.appendChild(hr); table.appendChild(thead);
                const tbody = document.createElement('tbody');
                details.slice(0, 20).forEach(function (detail) {
                    const tr = document.createElement('tr');
                    [detail.scope || '', detail.object_id || 0, detail.source_key || '', detail.target_key || '', detail.reason || ''].forEach(function (text) { const td = document.createElement('td'); td.textContent = String(text); tr.appendChild(td); });
                    tbody.appendChild(tr);
                });
                table.appendChild(tbody); wrap.appendChild(table); box.appendChild(wrap);
            }
            conflictHost.appendChild(box);
        }
        async function loadConflictReview() {
            try {
                const inspection = await request('yps_migration_inspect_conflicts');
                renderConflictReview(inspection);
                return inspection;
            } catch (error) {
                if (conflictHost) { const p = document.createElement('p'); p.textContent = error && error.message ? error.message : YPS_ADMIN.labels.failed; conflictHost.replaceChildren(p); }
                return null;
            }
        }

        if (prepareTakeover) {
            prepareTakeover.addEventListener('click', async function () {
                setBusy(true);
                try {
                    setConsole('running', YPS_ADMIN.labels.badgeRunning || 'RUNNING', YPS_ADMIN.labels.safePreparationStarted, YPS_ADMIN.labels.safePreparationDetail, YPS_ADMIN.labels.safeStepDryRun, 12, true);
                    let migration = await request('yps_migration_start', {mode: 'dry-run'});
                    render(migration);
                    migration = await continueJob(migration);
                    if (!migration || migration.status !== 'complete' || !migration.source_coverage_ready) {
                        if (migration && migration.conflicts > 0) { await loadConflictReview(); }
                        throw new Error(migration && migration.message ? migration.message : YPS_ADMIN.labels.failed);
                    }
                    setConsole('running', YPS_ADMIN.labels.badgeRunning || 'RUNNING', YPS_ADMIN.labels.backupRunning, YPS_ADMIN.labels.sourcePassedBackupDetail, YPS_ADMIN.labels.safeStepBackup, 45, true);
                    let backup = await request('yps_backup_start');
                    renderBackup(backup);
                    backup = await continueBackup(backup);
                    if (!backup || backup.status !== 'complete' || !backup.verified) {
                        throw new Error(backup && backup.message ? backup.message : YPS_ADMIN.labels.failed);
                    }
                    setConsole('running', YPS_ADMIN.labels.badgeRunning || 'RUNNING', YPS_ADMIN.labels.shadowRunning, YPS_ADMIN.labels.shadowRunningDetail, YPS_ADMIN.labels.safeStepShadow, 72, true);
                    migration = await request('yps_migration_start', {mode: 'import'});
                    render(migration);
                    migration = await continueJob(migration);
                    if (migration && migration.conflicts > 0) { await loadConflictReview(); }
                    if (!migration || migration.status !== 'complete' || !migration.cutover_ready) {
                        throw new Error(migration && migration.message ? migration.message : YPS_ADMIN.labels.failed);
                    }
                    setConsole('complete', YPS_ADMIN.labels.badgeVerified || 'VERIFIED', YPS_ADMIN.labels.safePreparationComplete, YPS_ADMIN.labels.safePreparationCompleteDetail, YPS_ADMIN.labels.safePreparationCompleteMeta, 100, false);
                } catch (error) {
                    const message = error && error.message ? error.message : YPS_ADMIN.labels.failed;
                    if (lastMigrationState && lastMigrationState.status === 'blocked') {
                        setConsole('stopped', YPS_ADMIN.labels.badgeStopped || 'STOPPED', YPS_ADMIN.labels.migrationStopped, message, YPS_ADMIN.labels.progressSafe, 100, false);
                    } else { fail(error); }
                } finally { setBusy(false); }
            });
        }
        if (dryRun) { dryRun.addEventListener('click', function () { run('dry-run'); }); }
        if (backupStart) {
            backupStart.addEventListener('click', async function () {
                setBusy(true);
                try {
                    let state = await request('yps_backup_start'); renderBackup(state); state = await continueBackup(state);
                    if (!state || state.status !== 'complete' || !state.verified) { throw new Error(state && state.message ? state.message : YPS_ADMIN.labels.failed); }
                } catch (error) { fail(error); }
                finally { setBusy(false); }
            });
        }
        if (shadowImport) { shadowImport.addEventListener('click', function () { run('import'); }); }
        if (inspectConflicts) { inspectConflicts.addEventListener('click', loadConflictReview); }
        if (takeover) {
            takeover.addEventListener('click', async function () {
                if (!window.confirm(YPS_ADMIN.labels.confirmTakeover)) { return; }
                setBusy(true);
                try {
                    setConsole('running', YPS_ADMIN.labels.badgeRunning || 'RUNNING', YPS_ADMIN.labels.publicTakeoverRunning, YPS_ADMIN.labels.publicTakeoverRunningDetail, YPS_ADMIN.labels.progressSafe, 55, true);
                    const takeoverResult = await request('yps_takeover');
                    if (takeoverResult && takeoverResult.status === 'manual-deactivation-required') {
                        setConsole('neutral', YPS_ADMIN.labels.badgeActionRequired || 'ACTION REQUIRED', YPS_ADMIN.labels.manualYoastTitle || 'Manual Yoast deactivation required', takeoverResult.message || YPS_ADMIN.labels.manualYoastDetail || 'Deactivate Yoast SEO manually in Plugins, then return here.', YPS_ADMIN.labels.pluginsInstalled || 'Plugins → Installed Plugins', 85, false);
                        return;
                    }
                    setConsole('complete', YPS_ADMIN.labels.badgeLive || 'LIVE', YPS_ADMIN.labels.publicTakeoverComplete, YPS_ADMIN.labels.publicTakeoverCompleteDetail, YPS_ADMIN.labels.verifiedCutoverComplete, 100, false);
                    await sleep(650);
                    window.location.reload();
                } catch (error) { fail(error); }
                finally { setBusy(false); }
            });
        }
        if (resume) {
            resume.addEventListener('click', async function () {
                setBusy(true);
                try { let state = await request('yps_migration_step'); render(state); state = await continueJob(state); if (state && state.conflicts > 0) { await loadConflictReview(); } }
                catch (error) { fail(error); }
                finally { setBusy(false); }
            });
        }
        if (reset) {
            reset.addEventListener('click', async function () {
                if (!window.confirm(YPS_ADMIN.labels.resetMigrationConfirm)) { return; }
                setBusy(true);
                try {
                    render(await request('yps_migration_reset'));
                    renderBackup(await request('yps_backup_reset'));
                    if (conflictHost) { conflictHost.replaceChildren(); }
                    setConsole('neutral', YPS_ADMIN.labels.badgeReady || 'READY', YPS_ADMIN.labels.readySafeDryRun, YPS_ADMIN.labels.progressSafe, YPS_ADMIN.labels.currentVerifySource, 0, false);
                    setStage('source', 'is-pending', YPS_ADMIN.labels.required); setStage('backup', 'is-pending', YPS_ADMIN.labels.required); setStage('shadow', 'is-pending', YPS_ADMIN.labels.required); setStage('takeover', 'is-locked', YPS_ADMIN.labels.locked);
                } catch (error) { fail(error); }
                finally { setBusy(false); }
            });
        }
        updateControls();
    }

    initDashboardTabs();
    initEditorTabs();
    initPreview();
    initAnalysisFreshness();
    initMigration();
}());


    function syncSchemaEntityFields(root) {
        var selector = root.querySelector('#yps-schema_entity_type');
        if (!selector) { return; }
        var common = ['schema_entity_name', 'schema_entity_description'];
        var groups = {
            person: ['schema_person_job_title', 'schema_person_image_url', 'schema_person_same_as', 'schema_person_knows_about'],
            service: ['schema_service_type', 'schema_area_served'],
            software_application: ['schema_application_category', 'schema_operating_system', 'schema_offer_price', 'schema_offer_currency']
        };
        var all = common.concat(groups.person, groups.service, groups.software_application);
        var apply = function () {
            var active = selector.value || '';
            all.forEach(function (name) {
                var field = root.querySelector('[data-yps-field="' + name + '"]');
                if (!field) { return; }
                var visible = active !== '' && (common.indexOf(name) !== -1 || (groups[active] || []).indexOf(name) !== -1);
                field.hidden = !visible;
            });
        };
        selector.addEventListener('change', apply);
        apply();
    }

    document.querySelectorAll('.yps-seo-editor').forEach(syncSchemaEntityFields);

(function () {
    'use strict';

    function encode(data) {
        const body = new URLSearchParams();
        Object.keys(data).forEach(function (key) { body.append(key, data[key]); });
        return body;
    }

    async function post(url, nonce, action, extra) {
        const response = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
            body: encode(Object.assign({action: action, nonce: nonce}, extra || {}))
        });
        const runtimeLabels = typeof YPS_EDITOR !== 'undefined' ? YPS_EDITOR.labels : (typeof YPS_AI_SETTINGS !== 'undefined' ? YPS_AI_SETTINGS.labels : (typeof YPS_ADMIN !== 'undefined' ? YPS_ADMIN.labels : {}));
        let json = null;
        try { json = await response.json(); } catch (error) { throw new Error(runtimeLabels.invalidResponse || runtimeLabels.failed || 'Error'); }
        if (!response.ok || !json.success) {
            const message = json && json.data && json.data.message ? json.data.message : (runtimeLabels.failed || 'Error');
            const err = new Error(message);
            err.status = response.status;
            err.retryAfterMs = json && json.data && json.data.retry_after_ms ? Number(json.data.retry_after_ms) : 0;
            throw err;
        }
        return json.data;
    }

    function sleep(ms) { return new Promise(function (resolve) { window.setTimeout(resolve, ms); }); }

    function initLiveAnalysis() {
        if (typeof YPS_EDITOR === 'undefined') { return; }
        const watchedSelector = '[name="yps_focus_keyphrase"], [name="yps_focus_keyphrases"], [name="yps_seo_title"], [name="yps_meta_description"], [name="yps_noindex"], [name="yps_schema_page_type"]';

        function draftSnapshot() {
            const snapshot = {draft_title: '', draft_excerpt: '', draft_content: ''};
            try {
                if (window.wp && wp.data && typeof wp.data.select === 'function') {
                    const store = wp.data.select('core/editor');
                    if (store) {
                        if (typeof store.getEditedPostAttribute === 'function') {
                            snapshot.draft_title = String(store.getEditedPostAttribute('title') || '');
                            snapshot.draft_excerpt = String(store.getEditedPostAttribute('excerpt') || '');
                            snapshot.draft_content = String(store.getEditedPostAttribute('content') || '');
                        } else if (typeof store.getEditedPostContent === 'function') {
                            snapshot.draft_content = String(store.getEditedPostContent() || '');
                        }
                    }
                }
            } catch (error) { /* Fall through to classic-editor fields. */ }
            if (!snapshot.draft_title) {
                const title = document.querySelector('#title, [name="post_title"]');
                if (title) { snapshot.draft_title = title.value || ''; }
            }
            if (!snapshot.draft_excerpt) {
                const excerpt = document.querySelector('#excerpt');
                if (excerpt) { snapshot.draft_excerpt = excerpt.value || ''; }
            }
            if (!snapshot.draft_content) {
                const content = document.querySelector('#content');
                if (content) { snapshot.draft_content = content.value || ''; }
            }
            return snapshot;
        }

        function draftSignature(snapshot) {
            return [snapshot.draft_title, snapshot.draft_excerpt, snapshot.draft_content].join('\u001f');
        }

        document.querySelectorAll('.yps-seo-editor').forEach(function (editor) {
            const postId = Number(editor.dataset.ypsPostId || 0);
            if (!postId || editor.dataset.ypsLiveAnalysisBound === '1') { return; }
            editor.dataset.ypsLiveAnalysisBound = '1';
            const notice = editor.querySelector('[data-yps-analysis-stale]');
            let timer = 0;
            let requestSerial = 0;
            let savingWasActive = false;
            let lastDraftSignature = draftSignature(draftSnapshot());

            function value(name) {
                const node = editor.querySelector('[name="' + name + '"]');
                if (!node) { return ''; }
                if (node.type === 'checkbox') { return node.checked ? '1' : '0'; }
                return node.value || '';
            }

            function setBusy(active) {
                editor.classList.toggle('is-analysis-refreshing', !!active);
                if (!notice) { return; }
                if (active) {
                    notice.hidden = false;
                    notice.textContent = YPS_EDITOR.labels.analysisRefreshing || YPS_EDITOR.labels.working || 'Working…';
                }
            }

            function replaceAnalysis(data) {
                const summary = editor.querySelector('[data-yps-analysis-summary]');
                if (summary && data && data.summary_html) { summary.outerHTML = data.summary_html; }
                const dimensions = data && data.dimensions ? data.dimensions : {};
                Object.keys(dimensions).forEach(function (key) {
                    const current = editor.querySelector('[data-yps-analysis-dimension="' + key + '"]');
                    if (current && dimensions[key]) { current.outerHTML = dimensions[key]; }
                });
            }

            async function refresh(isConfirmedSave) {
                const serial = ++requestSerial;
                const snapshot = draftSnapshot();
                lastDraftSignature = draftSignature(snapshot);
                setBusy(true);
                try {
                    const data = await post(YPS_EDITOR.ajaxUrl, YPS_EDITOR.nonce, 'yseo_refresh_post_analysis', {
                        post_id: postId,
                        focus_keyphrase: value('yps_focus_keyphrase'),
                        focus_keyphrases: value('yps_focus_keyphrases'),
                        seo_title: value('yps_seo_title'),
                        meta_description: value('yps_meta_description'),
                        noindex: value('yps_noindex'),
                        schema_page_type: value('yps_schema_page_type'),
                        draft_title: snapshot.draft_title,
                        draft_excerpt: snapshot.draft_excerpt,
                        draft_content: snapshot.draft_content
                    });
                    if (serial !== requestSerial) { return; }
                    replaceAnalysis(data);
                    if (notice) {
                        notice.hidden = !!isConfirmedSave;
                        notice.textContent = isConfirmedSave ? '' : (YPS_EDITOR.labels.analysisPreview || 'Live analysis preview updated.');
                    }
                } catch (error) {
                    if (serial !== requestSerial) { return; }
                    if (notice) {
                        notice.hidden = false;
                        notice.textContent = (error && error.message) ? error.message : (YPS_EDITOR.labels.analysisRefreshFailed || YPS_EDITOR.labels.failed || 'Error');
                    }
                } finally {
                    if (serial === requestSerial) { setBusy(false); }
                }
            }

            function schedule(delay, confirmed) {
                window.clearTimeout(timer);
                timer = window.setTimeout(function () { refresh(!!confirmed); }, typeof delay === 'number' ? delay : 450);
            }

            // Delegate field events so live scoring survives Gutenberg/meta-box rerenders.
            editor.addEventListener('input', function (event) {
                if (event.target && event.target.matches && event.target.matches(watchedSelector)) { schedule(180, false); }
            });
            editor.addEventListener('change', function (event) {
                if (event.target && event.target.matches && event.target.matches(watchedSelector)) { schedule(80, false); }
            });
            editor.addEventListener('focusout', function (event) {
                if (event.target && event.target.matches && event.target.matches(watchedSelector)) { schedule(20, false); }
            });
            editor.addEventListener('yseo:analysis:refresh', function () { schedule(20, false); });
            editor.__yseoRefreshAnalysis = function (confirmed) { schedule(20, !!confirmed); };

            // Track the actual unsaved Gutenberg draft. This makes content, headings,
            // links and keyphrase evidence move while the editor is still open.
            function bindEditorStore(attempt) {
                if (window.wp && wp.data && typeof wp.data.subscribe === 'function' && typeof wp.data.select === 'function') {
                    const store = wp.data.select('core/editor');
                    if (store) {
                        wp.data.subscribe(function () {
                            const currentStore = wp.data.select('core/editor');
                            if (!currentStore) { return; }
                            const snapshot = draftSnapshot();
                            const signature = draftSignature(snapshot);
                            if (signature !== lastDraftSignature) {
                                lastDraftSignature = signature;
                                schedule(850, false);
                            }
                            const saving = typeof currentStore.isSavingPost === 'function' ? !!currentStore.isSavingPost() : false;
                            const autosaving = typeof currentStore.isAutosavingPost === 'function' ? !!currentStore.isAutosavingPost() : false;
                            if (savingWasActive && !saving && !autosaving) { schedule(180, true); }
                            savingWasActive = saving;
                        });
                        return;
                    }
                }
                if (attempt < 20) { window.setTimeout(function () { bindEditorStore(attempt + 1); }, 250); }
            }
            bindEditorStore(0);

            // Classic editor / textarea fallback.
            ['#title', '#excerpt', '#content'].forEach(function (selector) {
                const node = document.querySelector(selector);
                if (!node) { return; }
                node.addEventListener('input', function () { schedule(850, false); });
                node.addEventListener('change', function () { schedule(180, false); });
            });
        });
    }

    function aiProgressContainer(node) {
        if (!node || !node.closest) { return null; }
        return node.closest('.yps-setting-field') || node.closest('.yps-editor-field') || node.closest('.yseo-bulk-field') || node.closest('.yps-media-field') || node.parentElement;
    }

    function setAiFieldProgress(node, active) {
        const container = aiProgressContainer(node);
        if (!container) { return; }
        let progress = container.querySelector('.yseo-ai-field-progress');
        if (!progress) {
            progress = document.createElement('div');
            progress.className = 'yseo-ai-field-progress';
            progress.setAttribute('aria-hidden', 'true');
            const bar = document.createElement('i');
            progress.appendChild(bar);
            container.appendChild(progress);
        }
        progress.classList.toggle('is-active', !!active);
        container.classList.toggle('is-ai-generating', !!active);
    }

    function initEditorAssist() {
        if (typeof YPS_EDITOR === 'undefined') { return; }
        document.querySelectorAll('.yps-seo-editor').forEach(function (editor) {
            const postId = Number(editor.dataset.ypsPostId || 0);
            const sourceStatus = editor.querySelector('[data-yps-assist-status]');
            const aiStatus = editor.querySelector('[data-yps-ai-status]');
            const aiButtons = Array.from(editor.querySelectorAll('[data-yps-ai-field], [data-yps-ai-all]'));

            if (!YPS_EDITOR.aiConfigured) {
                aiButtons.forEach(function (button) {
                    button.disabled = true;
                    button.title = YPS_EDITOR.labels.aiUnavailable;
                });
            }

            editor.querySelectorAll('[data-yps-mirror-post]').forEach(function (button) {
                button.addEventListener('click', async function () {
                    if (!postId) { return; }
                    const buttons = Array.from(editor.querySelectorAll('[data-yps-mirror-post]'));
                    buttons.forEach(function (item) { item.disabled = true; });
                    if (sourceStatus) { sourceStatus.textContent = YPS_EDITOR.labels.working; }
                    try {
                        const result = await post(YPS_EDITOR.ajaxUrl, YPS_EDITOR.nonce, 'yseo_mirror_post_metadata', {post_id: postId, scope: button.dataset.ypsMirrorPost || 'search'});
                        if (sourceStatus) { sourceStatus.textContent = result.message || YPS_EDITOR.labels.mirrorDone; }
                        window.setTimeout(function () { window.location.reload(); }, 350);
                    } catch (error) {
                        if (sourceStatus) { sourceStatus.textContent = error && error.message ? error.message : YPS_EDITOR.labels.failed; }
                        buttons.forEach(function (item) { item.disabled = false; });
                    }
                });
            });

            async function generate(targetField) {
                if (!postId || !YPS_EDITOR.aiConfigured) {
                    if (aiStatus) { aiStatus.textContent = YPS_EDITOR.labels.aiUnavailable; }
                    return;
                }
                aiButtons.forEach(function (button) { button.disabled = true; });
                const progressFields = targetField ? [targetField] : ['focus_keyphrase', 'focus_keyphrases', 'seo_title', 'meta_description'];
                const progressNodes = progressFields.map(function (field) { return editor.querySelector('[name="yps_' + field + '"]'); }).filter(Boolean);
                progressNodes.forEach(function (node) { setAiFieldProgress(node, true); });
                if (aiStatus) { aiStatus.textContent = YPS_EDITOR.labels.working; }
                try {
                    const result = await post(YPS_EDITOR.ajaxUrl, YPS_EDITOR.nonce, 'yseo_ai_suggest_post', {post_id: postId, field: targetField || ''});
                    const suggestions = result && result.suggestions ? result.suggestions : {};
                    const fields = targetField ? [targetField] : ['focus_keyphrase', 'focus_keyphrases', 'seo_title', 'meta_description'];
                    fields.forEach(function (field) {
                        if (!suggestions[field]) { return; }
                        const input = editor.querySelector('[name="yps_' + field + '"]');
                        if (!input) { return; }
                        input.value = suggestions[field];
                        input.dispatchEvent(new Event('input', {bubbles: true}));
                        input.dispatchEvent(new Event('change', {bubbles: true}));
                    });
                    if (typeof editor.__yseoRefreshAnalysis === 'function') { editor.__yseoRefreshAnalysis(false); }
                    if (aiStatus) { aiStatus.textContent = result.message || YPS_EDITOR.labels.aiReview; }
                } catch (error) {
                    if (aiStatus) { aiStatus.textContent = error && error.message ? error.message : YPS_EDITOR.labels.failed; }
                } finally {
                    progressNodes.forEach(function (node) { setAiFieldProgress(node, false); });
                    aiButtons.forEach(function (button) { button.disabled = !YPS_EDITOR.aiConfigured; });
                }
            }

            editor.querySelectorAll('[data-yps-ai-field]').forEach(function (button) {
                button.addEventListener('click', function () { generate(button.dataset.ypsAiField || ''); });
            });
            const allButton = editor.querySelector('[data-yps-ai-all]');
            if (allButton) { allButton.addEventListener('click', function () { generate(''); }); }

            const contentAssist = editor.querySelector('[data-yps-ai-content-assist]');
            if (contentAssist) {
                const contentButtons = Array.from(contentAssist.querySelectorAll('[data-yps-ai-content]'));
                const contentStatus = contentAssist.querySelector('[data-yps-ai-content-status]');
                const contentOutput = contentAssist.querySelector('[data-yps-ai-content-output]');
                if (!YPS_EDITOR.aiConfigured) {
                    contentButtons.forEach(function (button) { button.disabled = true; button.title = YPS_EDITOR.labels.aiUnavailable; });
                }
                contentButtons.forEach(function (button) {
                    button.addEventListener('click', async function () {
                        if (!postId || !YPS_EDITOR.aiConfigured) { if (contentStatus) { contentStatus.textContent = YPS_EDITOR.labels.aiUnavailable; } return; }
                        contentButtons.forEach(function (item) { item.disabled = true; });
                        if (contentStatus) { contentStatus.textContent = YPS_EDITOR.labels.working; }
                        try {
                            const result = await post(YPS_EDITOR.ajaxUrl, YPS_EDITOR.nonce, 'yseo_ai_content_assist', {post_id: postId, mode: button.dataset.ypsAiContent || ''});
                            if (contentOutput) { contentOutput.value = result.text || ''; contentOutput.hidden = !result.text; }
                            if (contentStatus) { contentStatus.textContent = result.message || YPS_EDITOR.labels.aiContentReview; }
                        } catch (error) {
                            if (contentStatus) { contentStatus.textContent = error && error.message ? error.message : YPS_EDITOR.labels.failed; }
                        } finally {
                            contentButtons.forEach(function (item) { item.disabled = !YPS_EDITOR.aiConfigured; });
                        }
                    });
                });
            }

            const draftBox = editor.querySelector('[data-yps-ai-draft]');
            if (draftBox) {
                const draftStatus = draftBox.querySelector('[data-yps-ai-draft-status]');
                const loadDraft = draftBox.querySelector('[data-yps-ai-load-draft]');
                const discardDraft = draftBox.querySelector('[data-yps-ai-discard-draft]');
                let draftValues = {};
                try { draftValues = JSON.parse(draftBox.dataset.ypsAiDraftValues || '{}'); } catch (error) { draftValues = {}; }
                if (loadDraft) {
                    loadDraft.addEventListener('click', function () {
                        ['focus_keyphrase', 'focus_keyphrases', 'seo_title', 'meta_description'].forEach(function (field) {
                            if (!draftValues[field]) { return; }
                            const input = editor.querySelector('[name="yps_' + field + '"]');
                            if (!input) { return; }
                            input.value = draftValues[field];
                            input.dispatchEvent(new Event('input', {bubbles: true}));
                            input.dispatchEvent(new Event('change', {bubbles: true}));
                        });
                        if (typeof editor.__yseoRefreshAnalysis === 'function') { editor.__yseoRefreshAnalysis(false); }
                        if (draftStatus) { draftStatus.textContent = YPS_EDITOR.labels.draftLoaded; }
                    });
                }
                if (discardDraft) {
                    discardDraft.addEventListener('click', async function () {
                        discardDraft.disabled = true;
                        try {
                            const result = await post(YPS_EDITOR.ajaxUrl, YPS_EDITOR.nonce, 'yseo_ai_discard_draft', {post_id: postId});
                            if (draftStatus) { draftStatus.textContent = result.message || YPS_EDITOR.labels.draftDiscarded; }
                            window.setTimeout(function () { draftBox.remove(); }, 450);
                        } catch (error) {
                            if (draftStatus) { draftStatus.textContent = error && error.message ? error.message : YPS_EDITOR.labels.failed; }
                            discardDraft.disabled = false;
                        }
                    });
                }
            }
        });
    }

    function initMetadataMirror() {
        if (typeof YPS_ADMIN === 'undefined') { return; }
        const status = document.getElementById('yps-mirror-status');
        const search = document.getElementById('yps-mirror-search');
        const all = document.getElementById('yps-mirror-all');
        const resume = document.getElementById('yps-mirror-resume');
        const reset = document.getElementById('yps-mirror-reset');
        if (!status || (!search && !all)) { return; }
        function busy(value) {
            [search, all, resume, reset].forEach(function (button) { if (button) { button.disabled = value; } });
        }
        function render(state) {
            if (!state || state.status === 'reset') { status.textContent = YPS_ADMIN.labels.mirrorEmpty; return; }
            const banner = document.createElement('div');
            banner.className = 'yps-inline-job-state ' + (state.status === 'running' ? 'is-running' : (state.status === 'complete' ? 'is-complete' : 'is-stopped'));
            const title = document.createElement('strong');
            title.textContent = state.status === 'running' ? YPS_ADMIN.labels.mirrorRunning : (state.status === 'complete' ? YPS_ADMIN.labels.mirrorComplete : YPS_ADMIN.labels.migrationStopped);
            const bar = document.createElement('span'); bar.className = 'yps-inline-job-progress' + (state.status === 'running' ? ' is-indeterminate' : '');
            const fill = document.createElement('i'); fill.style.width = state.status === 'running' ? '45%' : '100%'; bar.appendChild(fill);
            banner.append(title, bar);
            const list = document.createElement('ul');
            [
                [YPS_ADMIN.labels.mirrorStatus, state.status],
                [YPS_ADMIN.labels.mirrorPhase, state.phase || ''],
                [YPS_ADMIN.labels.mirrorPosts, state.processed_posts || 0],
                [YPS_ADMIN.labels.mirrorCopied, state.copied_values || 0],
                [YPS_ADMIN.labels.mirrorMatched, state.matched_values || 0],
                [YPS_ADMIN.labels.mirrorPreserved, state.preserved_values || 0]
            ].forEach(function (item) { const li = document.createElement('li'); li.textContent = item[0] + ': ' + item[1]; list.appendChild(li); });
            status.replaceChildren(banner, list);
            if (state.message) { const p = document.createElement('p'); p.textContent = state.message; status.appendChild(p); }
        }
        async function continueRun(state) {
            while (state && state.status === 'running') {
                await sleep(Math.max(75, Number(state.recommended_delay_ms || 100)));
                try { state = await post(YPS_ADMIN.ajaxUrl, YPS_ADMIN.nonce, 'yseo_metadata_mirror_step'); }
                catch (error) { if (error.status === 429) { await sleep(Math.max(200, error.retryAfterMs || 400)); continue; } throw error; }
                render(state);
            }
            return state;
        }
        async function run(scope) {
            busy(true);
            try {
                let state = await post(YPS_ADMIN.ajaxUrl, YPS_ADMIN.nonce, 'yseo_metadata_mirror_start', {scope: scope});
                render(state);
                await continueRun(state);
            } catch (error) { status.textContent = error && error.message ? error.message : YPS_ADMIN.labels.failed; }
            finally { busy(false); }
        }
        if (search) { search.addEventListener('click', function () { run('search'); }); }
        if (all) { all.addEventListener('click', function () { run('all'); }); }
        if (resume) { resume.addEventListener('click', async function () { busy(true); try { let state = await post(YPS_ADMIN.ajaxUrl, YPS_ADMIN.nonce, 'yseo_metadata_mirror_step'); render(state); await continueRun(state); } catch (error) { status.textContent = error && error.message ? error.message : YPS_ADMIN.labels.failed; } finally { busy(false); } }); }
        if (reset) { reset.addEventListener('click', async function () { busy(true); try { render(await post(YPS_ADMIN.ajaxUrl, YPS_ADMIN.nonce, 'yseo_metadata_mirror_reset')); } catch (error) { status.textContent = error.message; } finally { busy(false); } }); }
    }

    function initPortableMetadataMirror() {
        if (typeof YPS_ADMIN === 'undefined') { return; }
        const status = document.getElementById('yseo-portable-mirror-status');
        const starts = Array.from(document.querySelectorAll('.yseo-portable-mirror-start'));
        const resume = document.getElementById('yseo-portable-mirror-resume');
        const reset = document.getElementById('yseo-portable-mirror-reset');
        if (!status) { return; }
        function busy(value) {
            starts.forEach(function (button) { button.disabled = value; });
            if (resume) { resume.disabled = value; }
            if (reset) { reset.disabled = value; }
        }
        function render(state) {
            if (!state || state.status === 'reset') { status.textContent = YPS_ADMIN.labels.portableEmpty; return; }
            const list = document.createElement('ul');
            [
                [YPS_ADMIN.labels.portableProvider, state.provider_label || state.provider_id || ''],
                [YPS_ADMIN.labels.mirrorStatus, state.status || ''],
                [YPS_ADMIN.labels.mirrorPhase, state.phase || ''],
                [YPS_ADMIN.labels.mirrorPosts, state.processed_posts || 0],
                [YPS_ADMIN.labels.terms, state.processed_terms || 0],
                [YPS_ADMIN.labels.mirrorCopied, state.copied_values || 0],
                [YPS_ADMIN.labels.mirrorMatched, state.matched_values || 0],
                [YPS_ADMIN.labels.mirrorPreserved, state.preserved_values || 0]
            ].forEach(function (item) { const li = document.createElement('li'); li.textContent = item[0] + ': ' + item[1]; list.appendChild(li); });
            status.replaceChildren(list);
            if (state.message) { const p = document.createElement('p'); p.textContent = state.message; status.appendChild(p); }
        }
        async function continueRun(state) {
            while (state && state.status === 'running') {
                await sleep(Math.max(90, Number(state.recommended_delay_ms || 120)));
                try { state = await post(YPS_ADMIN.ajaxUrl, YPS_ADMIN.nonce, 'yseo_portable_mirror_step'); }
                catch (error) { if (error.status === 429) { await sleep(Math.max(250, error.retryAfterMs || 400)); continue; } throw error; }
                render(state);
            }
            return state;
        }
        starts.forEach(function (button) {
            button.addEventListener('click', async function () {
                busy(true);
                try {
                    let state = await post(YPS_ADMIN.ajaxUrl, YPS_ADMIN.nonce, 'yseo_portable_mirror_start', {provider_id: button.dataset.yseoPortableProvider || ''});
                    render(state);
                    await continueRun(state);
                } catch (error) { status.textContent = error && error.message ? error.message : YPS_ADMIN.labels.failed; }
                finally { busy(false); }
            });
        });
        if (resume) { resume.addEventListener('click', async function () { busy(true); try { let state = await post(YPS_ADMIN.ajaxUrl, YPS_ADMIN.nonce, 'yseo_portable_mirror_step'); render(state); await continueRun(state); } catch (error) { status.textContent = error && error.message ? error.message : YPS_ADMIN.labels.failed; } finally { busy(false); } }); }
        if (reset) { reset.addEventListener('click', async function () { busy(true); try { render(await post(YPS_ADMIN.ajaxUrl, YPS_ADMIN.nonce, 'yseo_portable_mirror_reset')); } catch (error) { status.textContent = error && error.message ? error.message : YPS_ADMIN.labels.failed; } finally { busy(false); } }); }
    }

    function initAiMetadataBulk() {
        if (typeof YPS_ADMIN === 'undefined') { return; }
        const status = document.getElementById('yps-ai-metadata-status');
        const start = document.getElementById('yps-ai-metadata-start');
        const resume = document.getElementById('yps-ai-metadata-resume');
        const reset = document.getElementById('yps-ai-metadata-reset');
        const policy = document.getElementById('yps-ai-metadata-policy');
        const fieldInputs = Array.from(document.querySelectorAll('.yps-ai-metadata-field'));
        if (!status || !start || !policy) { return; }
        const aiAvailable = !start.disabled;
        function busy(value) {
            start.disabled = value || !aiAvailable;
            if (resume) { resume.disabled = value || !aiAvailable; }
            if (reset) { reset.disabled = value; }
            policy.disabled = value || !aiAvailable;
            fieldInputs.forEach(function (input) { input.disabled = value || !aiAvailable; });
        }
        function selectedFields() { return fieldInputs.filter(function (input) { return input.checked; }).map(function (input) { return input.value; }); }
        function render(state) {
            if (!state || state.status === 'reset') { status.textContent = YPS_ADMIN.labels.aiJobEmpty; return; }
            const list = document.createElement('ul');
            [
                [YPS_ADMIN.labels.aiJobStatus, state.status],
                [YPS_ADMIN.labels.aiJobPosts, state.processed_posts || 0],
                [YPS_ADMIN.labels.aiJobGenerated, state.generated_posts || 0],
                [YPS_ADMIN.labels.aiJobUpdated, state.updated_fields || 0],
                [YPS_ADMIN.labels.aiJobDrafted, state.drafted_fields || 0],
                [YPS_ADMIN.labels.aiJobPreserved, state.preserved_fields || 0],
                [YPS_ADMIN.labels.aiJobMirrored, state.mirrored_source_values || 0]
            ].forEach(function (item) { const li = document.createElement('li'); li.textContent = item[0] + ': ' + item[1]; list.appendChild(li); });
            status.replaceChildren(list);
            if (state.message) { const p = document.createElement('p'); p.textContent = state.message; status.appendChild(p); }
        }
        async function continueRun(state) {
            while (state && state.status === 'running') {
                await sleep(Math.max(100, Number(state.recommended_delay_ms || 150)));
                try { state = await post(YPS_ADMIN.ajaxUrl, YPS_ADMIN.nonce, 'yseo_ai_metadata_step'); }
                catch (error) { if (error.status === 429) { await sleep(Math.max(400, error.retryAfterMs || 750)); continue; } throw error; }
                render(state);
            }
            return state;
        }
        start.addEventListener('click', async function () {
            const fields = selectedFields();
            if (!fields.length) { status.textContent = YPS_ADMIN.labels.selectAiField; return; }
            const selected = policy.value || 'fill-missing';
            if (selected === 'replace' && !window.confirm(YPS_ADMIN.labels.confirmAiReplace)) { return; }
            busy(true);
            try {
                let state = await post(YPS_ADMIN.ajaxUrl, YPS_ADMIN.nonce, 'yseo_ai_metadata_start', {policy: selected, fields: fields.join(',')});
                render(state);
                await continueRun(state);
            } catch (error) { status.textContent = error && error.message ? error.message : YPS_ADMIN.labels.failed; }
            finally { busy(false); }
        });
        if (resume) { resume.addEventListener('click', async function () { busy(true); try { let state = await post(YPS_ADMIN.ajaxUrl, YPS_ADMIN.nonce, 'yseo_ai_metadata_step'); render(state); await continueRun(state); } catch (error) { status.textContent = error && error.message ? error.message : YPS_ADMIN.labels.failed; } finally { busy(false); } }); }
        if (reset) { reset.addEventListener('click', async function () { busy(true); try { render(await post(YPS_ADMIN.ajaxUrl, YPS_ADMIN.nonce, 'yseo_ai_metadata_reset')); } catch (error) { status.textContent = error.message; } finally { busy(false); } }); }
    }

    function initSettingsWorkspace() {
        const navItems = Array.from(document.querySelectorAll('[data-yps-settings-tab]'));
        const panels = Array.from(document.querySelectorAll('[data-yps-settings-panel]'));
        if (!navItems.length || !panels.length) { return; }
        const activeInput = document.getElementById('yps-settings-active-tab');
        const allowed = navItems.map(function (item) { return item.getAttribute('data-yps-settings-tab'); });
        function activate(name, syncHash) {
            if (!allowed.includes(name)) { name = 'general'; }
            navItems.forEach(function (item) {
                const active = item.getAttribute('data-yps-settings-tab') === name;
                item.classList.toggle('is-active', active);
                item.setAttribute('aria-selected', active ? 'true' : 'false');
            });
            panels.forEach(function (panel) {
                panel.hidden = panel.getAttribute('data-yps-settings-panel') !== name;
            });
            if (activeInput) { activeInput.value = name; }
            const saveActions = document.querySelector('[data-yps-settings-save-actions]');
            if (saveActions) { saveActions.hidden = name === 'portability' || name === 'developer'; }
            if (syncHash && window.history && window.history.replaceState) {
                window.history.replaceState(null, '', window.location.pathname + window.location.search + '#' + name);
            }
        }
        const hash = window.location.hash ? window.location.hash.substring(1) : '';
        activate(allowed.includes(hash) ? hash : 'general', false);
        navItems.forEach(function (item) {
            item.addEventListener('click', function () { activate(item.getAttribute('data-yps-settings-tab') || 'general', true); });
        });

        const ownerType = document.getElementById('yps-owner-type');
        function syncOwnerFields() {
            if (!ownerType) { return; }
            const person = ownerType.value === 'person';
            document.querySelectorAll('.yps-owner-person-only').forEach(function (field) { field.hidden = !person; });
            document.querySelectorAll('.yps-owner-organization-only').forEach(function (field) { field.hidden = person; });
        }
        if (ownerType) { ownerType.addEventListener('change', syncOwnerFields); syncOwnerFields(); }

        const homeTitle = document.getElementById('yps-home_title');
        const homeDescription = document.getElementById('yps-home_description');
        const previewTitle = document.getElementById('yps-home-preview-title');
        const previewDescription = document.getElementById('yps-home-preview-description');
        function syncHomePreview() {
            if (homeTitle && previewTitle) { previewTitle.textContent = homeTitle.value.trim() || previewTitle.getAttribute('data-fallback') || ''; }
            if (homeDescription && previewDescription) { previewDescription.textContent = homeDescription.value.trim() || previewDescription.getAttribute('data-fallback') || ''; }
        }
        if (homeTitle) { homeTitle.addEventListener('input', syncHomePreview); }
        if (homeDescription) { homeDescription.addEventListener('input', syncHomePreview); }
        syncHomePreview();

        const socialFields = {
            ogTitle: document.getElementById('yps-home_og_title'),
            ogDescription: document.getElementById('yps-home_og_description'),
            ogImage: document.getElementById('yps-home_og_image'),
            xTitle: document.getElementById('yps-home_twitter_title'),
            xDescription: document.getElementById('yps-home_twitter_description'),
            xImage: document.getElementById('yps-home_twitter_image'),
            defaultImage: document.getElementById('yps-default_social_image')
        };
        function setSocialImage(kind, url) {
            const box = document.querySelector('[data-yps-home-social-image="' + kind + '"]');
            if (!box) { return; }
            box.innerHTML = '';
            if (url) { const img = document.createElement('img'); img.src = url; img.alt = ''; box.appendChild(img); }
        }
        function syncHomeSocialPreview() {
            const fallbackTitle = homeTitle && homeTitle.value.trim() ? homeTitle.value.trim() : (previewTitle ? previewTitle.getAttribute('data-fallback') || '' : '');
            const fallbackDescription = homeDescription && homeDescription.value.trim() ? homeDescription.value.trim() : (previewDescription ? previewDescription.getAttribute('data-fallback') || '' : '');
            const ogTitle = socialFields.ogTitle && socialFields.ogTitle.value.trim() ? socialFields.ogTitle.value.trim() : fallbackTitle;
            const ogDescription = socialFields.ogDescription && socialFields.ogDescription.value.trim() ? socialFields.ogDescription.value.trim() : fallbackDescription;
            const defaultImage = socialFields.defaultImage ? socialFields.defaultImage.value.trim() : '';
            const ogImage = socialFields.ogImage && socialFields.ogImage.value.trim() ? socialFields.ogImage.value.trim() : defaultImage;
            const xTitle = socialFields.xTitle && socialFields.xTitle.value.trim() ? socialFields.xTitle.value.trim() : ogTitle;
            const xDescription = socialFields.xDescription && socialFields.xDescription.value.trim() ? socialFields.xDescription.value.trim() : ogDescription;
            const xImage = socialFields.xImage && socialFields.xImage.value.trim() ? socialFields.xImage.value.trim() : ogImage;
            const fbTitle = document.querySelector('[data-yps-home-social-title="facebook"]'); if (fbTitle) { fbTitle.textContent = ogTitle; }
            const fbDescription = document.querySelector('[data-yps-home-social-description="facebook"]'); if (fbDescription) { fbDescription.textContent = ogDescription; }
            const xTitleNode = document.querySelector('[data-yps-home-social-title="x"]'); if (xTitleNode) { xTitleNode.textContent = xTitle; }
            const xDescriptionNode = document.querySelector('[data-yps-home-social-description="x"]'); if (xDescriptionNode) { xDescriptionNode.textContent = xDescription; }
            setSocialImage('facebook', ogImage); setSocialImage('x', xImage);
        }
        Object.keys(socialFields).forEach(function (key) { const field = socialFields[key]; if (field) { field.addEventListener('input', syncHomeSocialPreview); field.addEventListener('change', syncHomeSocialPreview); } });
        if (homeTitle) { homeTitle.addEventListener('input', syncHomeSocialPreview); }
        if (homeDescription) { homeDescription.addEventListener('input', syncHomeSocialPreview); }
        syncHomeSocialPreview();

        const aeoProfile = document.getElementById('yps-aeo-analysis-profile');
        const aeoOpening = document.getElementById('yps-aeo-threshold-opening');
        const aeoParagraph = document.getElementById('yps-aeo-threshold-paragraph');
        const aeoDepth = document.getElementById('yps-aeo-threshold-depth');
        function syncAeoProfilePreview() {
            if (!aeoProfile) { return; }
            const option = aeoProfile.options[aeoProfile.selectedIndex];
            if (!option) { return; }
            if (aeoOpening) { aeoOpening.textContent = option.dataset.intro || ''; }
            if (aeoParagraph) { aeoParagraph.textContent = option.dataset.paragraph || ''; }
            if (aeoDepth) { aeoDepth.textContent = option.dataset.depth || ''; }
        }
        if (aeoProfile) { aeoProfile.addEventListener('change', syncAeoProfilePreview); }
        syncAeoProfilePreview();

        const creativity = document.getElementById('yseo-ai-creativity');
        const creativityValue = document.getElementById('yseo-ai-creativity-value');
        function syncCreativity() {
            if (!creativity || !creativityValue) { return; }
            const parsed = Number(creativity.value);
            creativityValue.textContent = Number.isFinite(parsed) ? parsed.toFixed(1) : creativity.value;
        }
        if (creativity) { creativity.addEventListener('input', syncCreativity); syncCreativity(); }
    }

    function initSearchableSelects() {
        function normalize(value) {
            return String(value || '').toLowerCase().replace(/[._/\-]+/g, ' ').replace(/\s+/g, ' ').trim();
        }
        const searchableSelects = new Set(Array.from(document.querySelectorAll('select[data-yps-search-select], .yps-wrap select')));
        document.querySelectorAll('.tablenav.top .actions select').forEach(function (select) {
            const nativeOptions = Array.from(select.options || []);
            const firstLabel = normalize(nativeOptions.length ? nativeOptions[0].textContent : '');
            const identity = normalize((select.name || '') + ' ' + (select.id || '') + ' ' + firstLabel);
            const languageCodes = nativeOptions.filter(function (option) { return /^[a-z]{2,3}(?:[-_][a-z]{2,4})?$/i.test(String(option.value || '').trim()); }).length;
            if (identity.includes('language') || identity.includes('lang') || languageCodes >= 3) { searchableSelects.add(select); }
        });
        searchableSelects.forEach(function (select) {
            if (select.dataset.ypsSearchEnhanced === '1') { return; }
            const options = Array.from(select.options || []);
            const listFilterIdentity = normalize((select.name || '') + ' ' + (select.id || '') + ' ' + (options[0] ? options[0].textContent : ''));
            const listLanguageCodes = options.filter(function (option) { return /^[a-z]{2,3}(?:[-_][a-z]{2,4})?$/i.test(String(option.value || '').trim()); }).length;
            const listLanguageFilter = !!select.closest('.tablenav.top .actions') && (listFilterIdentity.includes('lang') || listLanguageCodes >= 3);
            const forced = select.hasAttribute('data-yps-search-select') || listLanguageFilter;
            if (!options.length) { return; }
            select.dataset.ypsSearchEnhanced = '1';
            select.classList.add('yps-search-select-native');

            const root = document.createElement('div');
            root.className = 'yps-ai-model-picker yps-search-select';
            root.dataset.ypsSearchSelectRoot = '1';

            const toggle = document.createElement('button');
            toggle.type = 'button';
            toggle.className = 'yps-model-picker-button';
            toggle.setAttribute('aria-haspopup', 'listbox');
            toggle.setAttribute('aria-expanded', 'false');

            const current = document.createElement('span');
            current.dataset.ypsSearchSelectCurrent = '1';
            const caret = document.createElement('span');
            caret.className = 'yps-model-picker-caret';
            caret.setAttribute('aria-hidden', 'true');
            caret.textContent = '⌄';
            toggle.appendChild(current); toggle.appendChild(caret);

            const popover = document.createElement('div');
            popover.className = 'yps-model-popover';
            popover.hidden = true;
            const searchLabel = document.createElement('label');
            searchLabel.className = 'yps-model-search';
            const search = document.createElement('input');
            search.type = 'search';
            search.autocomplete = 'off';
            search.placeholder = select.dataset.ypsSearchPlaceholder || 'Search…';
            searchLabel.appendChild(search);
            popover.appendChild(searchLabel);

            const list = document.createElement('div');
            list.className = 'yps-model-list';
            list.setAttribute('role', 'listbox');
            list.setAttribute('aria-label', select.getAttribute('aria-label') || ((window.YPS_EDITOR && YPS_EDITOR.labels && YPS_EDITOR.labels.options) ? YPS_EDITOR.labels.options : 'Options'));
            popover.appendChild(list);

            const empty = document.createElement('div');
            empty.className = 'yps-model-empty';
            empty.hidden = true;
            empty.textContent = select.dataset.ypsNoMatches || '—';
            list.appendChild(empty);

            const buttons = options.map(function (option, index) {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'yps-model-option';
                button.setAttribute('role', 'option');
                button.dataset.value = option.value;
                button.dataset.label = option.textContent || option.value;
                button.dataset.search = normalize((option.textContent || '') + ' ' + option.value);
                button.dataset.order = String(index);
                const strong = document.createElement('strong');
                strong.textContent = option.textContent || option.value;
                button.appendChild(strong);
                if (option.value) {
                    const small = document.createElement('small');
                    small.textContent = option.value;
                    button.appendChild(small);
                }
                list.insertBefore(button, empty);
                return button;
            });

            function syncFromSelect() {
                const selected = options.find(function (option) { return option.value === select.value; }) || options[0];
                current.textContent = selected ? selected.textContent : '';
                buttons.forEach(function (button) {
                    const active = selected && button.dataset.value === selected.value;
                    button.classList.toggle('is-selected', !!active);
                    button.setAttribute('aria-selected', active ? 'true' : 'false');
                });
            }
            function close() {
                popover.hidden = true;
                toggle.classList.remove('is-open');
                toggle.setAttribute('aria-expanded', 'false');
            }
            function positionPopover() {
                popover.style.left = '0';
                popover.style.right = 'auto';
                popover.style.maxWidth = 'calc(100vw - 32px)';
                var rect = popover.getBoundingClientRect();
                if (rect.right > window.innerWidth - 16) {
                    popover.style.left = 'auto';
                    popover.style.right = '0';
                    rect = popover.getBoundingClientRect();
                }
                if (rect.left < 16) {
                    popover.style.left = '0';
                    popover.style.right = 'auto';
                }
            }
            function open() {
                document.querySelectorAll('[data-yps-search-select-root] .yps-model-popover').forEach(function (other) { if (other !== popover) { other.hidden = true; } });
                popover.hidden = false;
                toggle.classList.add('is-open');
                toggle.setAttribute('aria-expanded', 'true');
                positionPopover();
                search.focus(); search.select();
            }
            function filter() {
                const query = normalize(search.value);
                const terms = query ? query.split(' ').filter(Boolean) : [];
                const ranked = [];
                buttons.forEach(function (button) {
                    const haystack = normalize(button.dataset.search || button.textContent);
                    const label = normalize(button.dataset.label || '');
                    const value = normalize(button.dataset.value || '');
                    const matches = !terms.length || terms.every(function (term) { return haystack.includes(term); });
                    let rank = 2;
                    if (matches && query) {
                        if (label.startsWith(query) || value.startsWith(query)) { rank = 0; }
                        else if (label.split(' ').some(function (part) { return part.startsWith(terms[0]); }) || value.split(' ').some(function (part) { return part.startsWith(terms[0]); })) { rank = 1; }
                    } else if (!query) { rank = 0; }
                    button.hidden = !matches;
                    button.classList.toggle('is-filtered-out', !matches);
                    button.tabIndex = matches ? 0 : -1;
                    if (matches) { ranked.push({button: button, rank: rank, order: Number(button.dataset.order || 0)}); }
                });
                ranked.sort(function (a, b) { return a.rank - b.rank || a.order - b.order; });
                ranked.forEach(function (item) { list.insertBefore(item.button, empty); });
                list.scrollTop = 0;
                empty.hidden = ranked.length > 0;
            }
            function choose(button) {
                select.value = button.dataset.value || '';
                select.dispatchEvent(new Event('change', {bubbles: true}));
                syncFromSelect(); search.value = ''; filter(); close(); toggle.focus();
            }

            toggle.addEventListener('click', function () { popover.hidden ? open() : close(); });
            search.addEventListener('input', filter);
            search.addEventListener('keydown', function (event) {
                const visible = buttons.filter(function (button) { return !button.hidden; });
                if (event.key === 'Escape') { event.preventDefault(); close(); toggle.focus(); }
                else if ((event.key === 'ArrowDown' || event.key === 'ArrowUp') && visible.length) { event.preventDefault(); (event.key === 'ArrowDown' ? visible[0] : visible[visible.length - 1]).focus(); }
                else if (event.key === 'Enter' && visible.length) { event.preventDefault(); choose(visible[0]); }
            });
            buttons.forEach(function (button) { button.addEventListener('click', function () { choose(button); }); });
            select.addEventListener('change', syncFromSelect);
            document.addEventListener('click', function (event) { if (!root.contains(event.target)) { close(); } });

            select.insertAdjacentElement('afterend', root);
            root.appendChild(toggle); root.appendChild(popover);
            const listActions = root.closest('.tablenav.top .actions');
            if (listActions) { listActions.classList.add('yps-has-search-select'); }
            syncFromSelect(); filter();
        });
    }

    function initAiSettingsConnection() {
        if (typeof YPS_AI_SETTINGS === 'undefined') { return; }
        const button = document.getElementById('yseo-ai-test-connection');
        const status = document.getElementById('yseo-ai-connection-status');
        function normalizeModelSearch(value) {
            return String(value || '').toLowerCase().replace(/[._/\-]+/g, ' ').replace(/\s+/g, ' ').trim();
        }
        function initModelPicker(root) {
            const value = root.querySelector('[data-yps-model-value]');
            const toggle = root.querySelector('[data-yps-model-toggle]');
            const current = root.querySelector('[data-yps-model-current]');
            const popover = root.querySelector('[data-yps-model-popover]');
            const search = root.querySelector('[data-yps-model-search]');
            const list = root.querySelector('[data-yps-model-list]');
            const empty = root.querySelector('[data-yps-model-empty]');
            const options = Array.from(root.querySelectorAll('[data-yps-model-option]'));
            if (!value || !toggle || !current || !popover || !search || !list || !options.length) { return; }

            function visibleOptions() { return options.filter(function (option) { return !option.hidden; }); }
            function closePicker() {
                popover.hidden = true;
                toggle.setAttribute('aria-expanded', 'false');
                toggle.classList.remove('is-open');
            }
            function openPicker() {
                document.querySelectorAll('[data-yps-model-picker] [data-yps-model-popover]').forEach(function (other) {
                    if (other !== popover) { other.hidden = true; }
                });
                document.querySelectorAll('[data-yps-model-toggle]').forEach(function (other) {
                    if (other !== toggle) { other.setAttribute('aria-expanded', 'false'); other.classList.remove('is-open'); }
                });
                popover.hidden = false;
                toggle.setAttribute('aria-expanded', 'true');
                toggle.classList.add('is-open');
                search.focus();
                search.select();
            }
            function filterOptions() {
                const query = normalizeModelSearch(search.value);
                const terms = query ? query.split(' ').filter(Boolean) : [];
                const ranked = [];
                options.forEach(function (option) {
                    const label = normalizeModelSearch(option.dataset.modelLabel || option.textContent);
                    const id = normalizeModelSearch(option.dataset.modelId || '');
                    const haystack = normalizeModelSearch(option.dataset.modelSearch || (label + ' ' + id));
                    let rank = 0;
                    let matches = true;
                    if (terms.length) {
                        matches = terms.every(function (term) { return haystack.includes(term); });
                        if (matches) {
                            if (label.startsWith(query) || id.startsWith(query)) { rank = 0; }
                            else if (label.split(' ').some(function (part) { return part.startsWith(terms[0]); }) || id.split(' ').some(function (part) { return part.startsWith(terms[0]); })) { rank = 1; }
                            else { rank = 2; }
                        }
                    }
                    option.hidden = !matches;
                    option.classList.toggle('is-filtered-out', !matches);
                    option.tabIndex = matches ? 0 : -1;
                    if (matches) { ranked.push({option: option, rank: rank, order: Number(option.dataset.modelOrder || 0)}); }
                });
                ranked.sort(function (a, b) { return a.rank - b.rank || a.order - b.order; });
                ranked.forEach(function (item) { list.insertBefore(item.option, empty || null); });
                list.scrollTop = 0;
                if (empty) { empty.hidden = ranked.length > 0; }
            }
            function choose(option) {
                if (!option) { return; }
                value.value = option.dataset.modelId || '';
                current.textContent = option.dataset.modelLabel || option.textContent.trim();
                options.forEach(function (candidate) {
                    const selected = candidate === option;
                    candidate.classList.toggle('is-selected', selected);
                    candidate.setAttribute('aria-selected', selected ? 'true' : 'false');
                });
                search.value = '';
                filterOptions();
                closePicker();
                toggle.focus();
            }

            toggle.addEventListener('click', function () { popover.hidden ? openPicker() : closePicker(); });
            search.addEventListener('input', filterOptions);
            search.addEventListener('keydown', function (event) {
                const visible = visibleOptions();
                if ('Escape' === event.key) { event.preventDefault(); closePicker(); toggle.focus(); return; }
                if (('ArrowDown' === event.key || 'ArrowUp' === event.key) && visible.length) {
                    event.preventDefault();
                    ('ArrowDown' === event.key ? visible[0] : visible[visible.length - 1]).focus();
                    return;
                }
                if ('Enter' === event.key && visible.length) { event.preventDefault(); choose(visible[0]); }
            });
            options.forEach(function (option) {
                option.addEventListener('click', function () { choose(option); });
                option.addEventListener('keydown', function (event) {
                    if ('Enter' === event.key || ' ' === event.key) { event.preventDefault(); choose(option); return; }
                    if ('Escape' === event.key) { event.preventDefault(); closePicker(); toggle.focus(); return; }
                    if ('ArrowDown' !== event.key && 'ArrowUp' !== event.key) { return; }
                    event.preventDefault();
                    const visible = visibleOptions();
                    const index = visible.indexOf(option);
                    const delta = 'ArrowDown' === event.key ? 1 : -1;
                    const next = visible[index + delta];
                    if (next) { next.focus(); } else { search.focus(); }
                });
            });
            document.addEventListener('click', function (event) { if (!root.contains(event.target)) { closePicker(); } });
            filterOptions();
        }
        document.querySelectorAll('[data-yps-model-picker]').forEach(initModelPicker);
        if (!button || !status) { return; }
        button.addEventListener('click', async function () {
            button.disabled = true;
            status.textContent = YPS_AI_SETTINGS.labels.testing;
            try {
                const result = await post(YPS_AI_SETTINGS.ajaxUrl, YPS_AI_SETTINGS.nonce, 'yseo_ai_test_connection');
                const details = [
                    result.workspace,
                    result.plan,
                    result.credits !== null && result.credits !== undefined ? 'credits ' + String(result.credits) : '',
                    result.usage !== null && result.usage !== undefined ? 'usage ' + String(result.usage) : '',
                    result.usage_mode ? 'mode ' + String(result.usage_mode) : '',
                    result.session_reset_at ? 'reset ' + String(result.session_reset_at) : '',
                    Number.isFinite(Number(result.models)) ? String(result.models) + ' text' : '',
                    Number.isFinite(Number(result.image_models)) ? String(result.image_models) + ' image' : ''
                ].filter(Boolean).join(' · ');
                status.textContent = (result.message || YPS_AI_SETTINGS.labels.connected) + (details ? ' ' + details : '');
                window.setTimeout(function () { window.location.reload(); }, 650);
            } catch (error) {
                status.textContent = error && error.message ? error.message : YPS_AI_SETTINGS.labels.failed;
                button.disabled = false;
            }
        });
    }

    function initAiComputeCanary() {
        if (typeof YPS_AI_SETTINGS === 'undefined') { return; }
        const button = document.getElementById('yseo-ai-test-compute');
        const status = document.getElementById('yseo-ai-connection-status');
        if (!button || !status) { return; }
        button.addEventListener('click', async function () {
            button.disabled = true;
            status.textContent = YPS_AI_SETTINGS.labels.testingCompute || '';
            try {
                const data = await post(YPS_AI_SETTINGS.ajaxUrl, YPS_AI_SETTINGS.nonce, 'yseo_ai_test_compute', {});
                const details = [data.model ? 'model ' + String(data.model) : '', data.mode ? 'transport ' + String(data.mode) : '', data.cost !== null && data.cost !== undefined ? 'cost ' + String(data.cost) : ''].filter(Boolean).join(' · ');
                status.textContent = (data.message || YPS_AI_SETTINGS.labels.computeReady || '') + (details ? ' ' + details : '');
            } catch (error) {
                status.textContent = error && error.message ? error.message : YPS_AI_SETTINGS.labels.failed;
            } finally { button.disabled = false; }
        });
    }

    function initAiSettingsCopilot() {
        if (typeof YPS_AI_SETTINGS === 'undefined') { return; }
        const status = document.querySelector('[data-yseo-ai-settings-status]');
        const scopeButtons = Array.from(document.querySelectorAll('[data-yseo-ai-settings-scope]'));
        const fieldButtons = Array.from(document.querySelectorAll('[data-yseo-ai-setting-field]'));
        const imageButtons = Array.from(document.querySelectorAll('[data-yseo-ai-setting-image]'));
        const socialTextButtons = Array.from(document.querySelectorAll('[data-yseo-ai-social-template-field]'));
        const socialRowButtons = Array.from(document.querySelectorAll('[data-yseo-ai-social-template-row]'));
        const socialImageButtons = Array.from(document.querySelectorAll('[data-yseo-ai-social-template-image]'));
        const socialAllButton = document.querySelector('[data-yseo-ai-social-template-all]');
        const socialExpandButton = document.querySelector('[data-yseo-social-expand]');
        const socialCollapseButton = document.querySelector('[data-yseo-social-collapse]');
        const socialStatus = document.querySelector('[data-yseo-ai-social-template-status]');
        if (!scopeButtons.length && !fieldButtons.length && !imageButtons.length && !socialTextButtons.length && !socialRowButtons.length && !socialImageButtons.length && !socialAllButton) { return; }
        const scopes = {
            identity: ['site_display_name', 'site_description', 'owner_name', 'owner_job_title', 'owner_description', 'owner_knows_about'],
            homepage: ['home_title', 'home_description'],
            social: ['home_og_title', 'home_og_description', 'home_twitter_title', 'home_twitter_description'],
            all: ['site_display_name', 'site_description', 'owner_name', 'owner_job_title', 'owner_description', 'owner_knows_about', 'home_title', 'home_description', 'home_og_title', 'home_og_description', 'home_twitter_title', 'home_twitter_description']
        };
        function fieldNode(name) { return document.querySelector('[name="' + name + '"]'); }
        function setStatus(message) { if (status) { status.textContent = message || ''; } }
        async function generateSharedSocialImage(trigger) {
            const names = ['default_social_image', 'home_og_image', 'home_twitter_image'];
            const empty = names.map(fieldNode).filter(function (node) { return !!node && !node.value.trim(); });
            if (!empty.length) { return; }
            empty.forEach(function (node) { setAiFieldProgress(node, true); });
            setStatus(YPS_AI_SETTINGS.labels.generatingImage || '');
            try {
                const context = ['site_display_name', 'site_description', 'home_title', 'home_description', 'home_og_title', 'home_og_description'].map(function (name) { const node = fieldNode(name); return node && node.value.trim() ? node.value.trim() : ''; }).filter(Boolean).join(' · ');
                const data = await post(YPS_AI_SETTINGS.ajaxUrl, YPS_AI_SETTINGS.nonce, 'yseo_ai_generate_site_image', {field: 'default_social_image', prompt: context});
                if (!data.url) { throw new Error(YPS_AI_SETTINGS.labels.imageFailed || 'Error'); }
                empty.forEach(function (node) {
                    node.value = data.url;
                    node.dispatchEvent(new Event('input', {bubbles: true}));
                    node.dispatchEvent(new Event('change', {bubbles: true}));
                });
            } finally {
                empty.forEach(function (node) { setAiFieldProgress(node, false); });
                if (trigger) { trigger.classList.remove('is-working'); }
            }
        }
        async function request(fields, replaceExplicit, trigger) {
            const eligible = fields.filter(function (name) {
                const node = fieldNode(name);
                return !!node && (replaceExplicit || !node.value.trim());
            });
            if (!eligible.length) { setStatus(YPS_AI_SETTINGS.labels.settingsReview || ''); return; }
            const buttons = scopeButtons.concat(fieldButtons).concat(imageButtons);
            const progressNodes = eligible.map(fieldNode).filter(Boolean);
            progressNodes.forEach(function (node) { setAiFieldProgress(node, true); });
            buttons.forEach(function (button) { button.disabled = true; });
            if (trigger) { trigger.classList.add('is-working'); }
            setStatus(YPS_AI_SETTINGS.labels.draftingSettings || '');
            try {
                const data = await post(YPS_AI_SETTINGS.ajaxUrl, YPS_AI_SETTINGS.nonce, 'yseo_ai_suggest_settings', {fields: eligible.join(',')});
                const suggestions = data && data.suggestions ? data.suggestions : {};
                Object.keys(suggestions).forEach(function (name) {
                    const node = fieldNode(name);
                    if (!node || (!replaceExplicit && node.value.trim())) { return; }
                    node.value = suggestions[name] || '';
                    node.dispatchEvent(new Event('input', {bubbles: true}));
                    node.dispatchEvent(new Event('change', {bubbles: true}));
                });
                setStatus(data.message || YPS_AI_SETTINGS.labels.settingsReview || '');
            } catch (error) {
                setStatus(error && error.message ? error.message : (YPS_AI_SETTINGS.labels.failed || 'Error'));
            } finally {
                progressNodes.forEach(function (node) { setAiFieldProgress(node, false); });
                buttons.forEach(function (button) { button.disabled = false; });
                if (trigger) { trigger.classList.remove('is-working'); }
            }
        }
        function setSocialStatus(message) { if (socialStatus) { socialStatus.textContent = message || ''; } else { setStatus(message); } }
        async function requestSocialTemplate(group, key, slots, targets, replaceExplicit, trigger) {
            const eligible = [];
            const nodes = [];
            slots.forEach(function (slot, index) {
                const targetId = targets[index] || '';
                const node = targetId ? document.getElementById(targetId) : null;
                if (!node || (!replaceExplicit && node.value.trim())) { return; }
                eligible.push(slot); nodes.push(node);
            });
            if (!eligible.length) { setSocialStatus(YPS_AI_SETTINGS.labels.settingsReview || ''); return; }
            const allButtons = scopeButtons.concat(fieldButtons).concat(imageButtons).concat(socialTextButtons).concat(socialRowButtons).concat(socialImageButtons);
            nodes.forEach(function (node) { setAiFieldProgress(node, true); });
            allButtons.forEach(function (item) { item.disabled = true; });
            if (trigger) { trigger.classList.add('is-working'); }
            setSocialStatus(YPS_AI_SETTINGS.labels.draftingSettings || '');
            try {
                const data = await post(YPS_AI_SETTINGS.ajaxUrl, YPS_AI_SETTINGS.nonce, 'yseo_ai_suggest_social_template', {group: group, key: key, fields: eligible.join(',')});
                const suggestions = data && data.suggestions ? data.suggestions : {};
                eligible.forEach(function (slot, index) {
                    const node = nodes[index];
                    if (!node || (!replaceExplicit && node.value.trim()) || !Object.prototype.hasOwnProperty.call(suggestions, slot)) { return; }
                    node.value = suggestions[slot] || '';
                    node.dispatchEvent(new Event('input', {bubbles: true}));
                    node.dispatchEvent(new Event('change', {bubbles: true}));
                });
                setSocialStatus(data.message || YPS_AI_SETTINGS.labels.settingsReview || '');
            } catch (error) {
                setSocialStatus(error && error.message ? error.message : (YPS_AI_SETTINGS.labels.failed || 'Error'));
            } finally {
                nodes.forEach(function (node) { setAiFieldProgress(node, false); });
                allButtons.forEach(function (item) { item.disabled = false; });
                if (trigger) { trigger.classList.remove('is-working'); }
            }
        }
        socialTextButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                requestSocialTemplate(button.dataset.yseoAiSocialGroup || '', button.dataset.yseoAiSocialKey || '', [button.dataset.yseoAiSocialSlot || ''], [button.dataset.yseoAiSocialTarget || ''], true, button);
            });
        });
        socialRowButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                const row = button.closest('[data-yseo-ai-social-row]');
                if (!row) { return; }
                const fields = Array.from(row.querySelectorAll('[data-yseo-ai-social-template-field]'));
                requestSocialTemplate(button.dataset.yseoAiSocialGroup || '', button.dataset.yseoAiSocialKey || '', fields.map(function (item) { return item.dataset.yseoAiSocialSlot || ''; }), fields.map(function (item) { return item.dataset.yseoAiSocialTarget || ''; }), false, button);
            });
        });
        if (socialExpandButton) {
            socialExpandButton.addEventListener('click', function () { document.querySelectorAll('[data-yseo-ai-social-row]').forEach(function (row) { row.open = true; }); });
        }
        if (socialCollapseButton) {
            socialCollapseButton.addEventListener('click', function () { document.querySelectorAll('[data-yseo-ai-social-row]').forEach(function (row) { row.open = false; }); });
        }
        if (socialAllButton) {
            socialAllButton.addEventListener('click', async function () {
                const rows = socialRowButtons.filter(function (button) {
                    const row = button.closest('[data-yseo-ai-social-row]');
                    return !!row && Array.from(row.querySelectorAll('[data-yseo-ai-social-template-field]')).some(function (item) { const target = document.getElementById(item.dataset.yseoAiSocialTarget || ''); return !!target && !target.value.trim(); });
                });
                if (!rows.length) { setSocialStatus(YPS_AI_SETTINGS.labels.settingsReview || ''); return; }
                if (!window.confirm(YPS_AI_SETTINGS.labels.confirmSocialTemplateBatch || '')) { return; }
                socialAllButton.disabled = true;
                socialAllButton.classList.add('is-working');
                try {
                    for (const button of rows) {
                        const row = button.closest('[data-yseo-ai-social-row]');
                        if (!row) { continue; }
                        const fields = Array.from(row.querySelectorAll('[data-yseo-ai-social-template-field]'));
                        await requestSocialTemplate(button.dataset.yseoAiSocialGroup || '', button.dataset.yseoAiSocialKey || '', fields.map(function (item) { return item.dataset.yseoAiSocialSlot || ''; }), fields.map(function (item) { return item.dataset.yseoAiSocialTarget || ''; }), false, button);
                    }
                    setSocialStatus(YPS_AI_SETTINGS.labels.settingsReview || '');
                } finally {
                    socialAllButton.disabled = false;
                    socialAllButton.classList.remove('is-working');
                }
            });
        }
        socialImageButtons.forEach(function (button) {
            button.addEventListener('click', async function () {
                const targetId = button.getAttribute('data-yps-media-target') || '';
                const input = targetId ? document.getElementById(targetId) : null;
                if (!input) { return; }
                const row = button.closest('[data-yseo-ai-social-row]');
                const rowText = row ? Array.from(row.querySelectorAll('input[type="text"]')).map(function (node) { return node.value.trim(); }).filter(Boolean).join(' · ') : '';
                const siteContext = ['site_display_name', 'site_description', 'home_title', 'home_description'].map(function (name) { const node = fieldNode(name); return node && node.value.trim() ? node.value.trim() : ''; }).filter(Boolean).join(' · ');
                const allButtons = scopeButtons.concat(fieldButtons).concat(imageButtons).concat(socialTextButtons).concat(socialRowButtons).concat(socialImageButtons);
                allButtons.forEach(function (item) { item.disabled = true; });
                setAiFieldProgress(input, true);
                button.classList.add('is-working');
                setSocialStatus(YPS_AI_SETTINGS.labels.generatingImage || '');
                try {
                    const data = await post(YPS_AI_SETTINGS.ajaxUrl, YPS_AI_SETTINGS.nonce, 'yseo_ai_generate_social_template_image', {
                        group: button.dataset.yseoAiSocialGroup || '',
                        key: button.dataset.yseoAiSocialKey || '',
                        slot: button.dataset.yseoAiSocialSlot || '',
                        prompt: [siteContext, rowText].filter(Boolean).join(' · ')
                    });
                    if (!data.url) { throw new Error(YPS_AI_SETTINGS.labels.imageFailed || 'Error'); }
                    input.value = data.url;
                    input.dispatchEvent(new Event('input', {bubbles: true}));
                    input.dispatchEvent(new Event('change', {bubbles: true}));
                    setSocialStatus(data.message || YPS_AI_SETTINGS.labels.imageGeneratedReview || '');
                } catch (error) {
                    setSocialStatus(error && error.message ? error.message : (YPS_AI_SETTINGS.labels.imageFailed || 'Error'));
                } finally {
                    setAiFieldProgress(input, false);
                    allButtons.forEach(function (item) { item.disabled = false; });
                    button.classList.remove('is-working');
                }
            });
        });
        scopeButtons.forEach(function (button) {
            button.addEventListener('click', async function () {
                const scope = button.dataset.yseoAiSettingsScope || '';
                try {
                    await request(scopes[scope] || [], false, button);
                    if ('social' === scope || 'all' === scope) {
                        await generateSharedSocialImage(button);
                        setStatus(YPS_AI_SETTINGS.labels.settingsReview || '');
                    }
                } catch (error) {
                    setStatus(error && error.message ? error.message : (YPS_AI_SETTINGS.labels.failed || 'Error'));
                }
            });
        });
        fieldButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                request([button.dataset.yseoAiSettingField || ''], true, button);
            });
        });
        imageButtons.forEach(function (button) {
            button.addEventListener('click', async function () {
                const field = button.dataset.yseoAiSettingImage || '';
                const targetId = button.getAttribute('data-yps-media-target') || '';
                const input = targetId ? document.getElementById(targetId) : null;
                if (!field || !input) { return; }
                const buttons = scopeButtons.concat(fieldButtons).concat(imageButtons);
                buttons.forEach(function (item) { item.disabled = true; });
                setAiFieldProgress(input, true);
                button.classList.add('is-working');
                setStatus(YPS_AI_SETTINGS.labels.generatingImage || '');
                try {
                    const context = ['site_display_name', 'site_description', 'home_title', 'home_description', 'home_og_title', 'home_og_description', 'home_twitter_title', 'home_twitter_description'].map(function (name) { const node = fieldNode(name); return node && node.value.trim() ? node.value.trim() : ''; }).filter(Boolean).join(' · ');
                    const data = await post(YPS_AI_SETTINGS.ajaxUrl, YPS_AI_SETTINGS.nonce, 'yseo_ai_generate_site_image', {field: field, prompt: context});
                    if (!data.url) { throw new Error(YPS_AI_SETTINGS.labels.imageFailed || 'Error'); }
                    input.value = data.url;
                    input.dispatchEvent(new Event('input', {bubbles: true}));
                    input.dispatchEvent(new Event('change', {bubbles: true}));
                    setStatus(data.message || YPS_AI_SETTINGS.labels.imageGeneratedReview || '');
                } catch (error) {
                    setStatus(error && error.message ? error.message : (YPS_AI_SETTINGS.labels.imageFailed || 'Error'));
                } finally {
                    setAiFieldProgress(input, false);
                    buttons.forEach(function (item) { item.disabled = false; });
                    button.classList.remove('is-working');
                }
            });
        });
    }

    function initSeoImageStudio() {
        if (typeof YPS_EDITOR === 'undefined') { return; }
        const studio = document.querySelector('[data-yps-seo-image-studio]');
        if (!studio) { return; }
        const button = studio.querySelector('[data-yps-seo-image-generate]');
        const status = studio.querySelector('[data-yps-seo-image-status]');
        const model = studio.querySelector('[data-yps-seo-image-model]');
        const prompt = studio.querySelector('[data-yps-seo-image-prompt]');
        const ratio = studio.querySelector('[data-yps-seo-image-ratio]');
        const preview = studio.querySelector('[data-yps-seo-image-preview]');
        const editor = studio.closest('.yps-seo-editor');
        const postId = editor ? Number(editor.dataset.ypsPostId || 0) : 0;
        const ogImage = editor ? editor.querySelector('[name="yps_og_image"]') : null;
        const twitterImage = editor ? editor.querySelector('[name="yps_twitter_image"]') : null;
        if (!button || !status || !ogImage) { return; }
        button.addEventListener('click', async function () {
            button.disabled = true;
            setAiFieldProgress(ogImage, true);
            status.textContent = YPS_EDITOR.labels.generatingImage;
            try {
                const form = new URLSearchParams();
                form.set('action', 'yseo_ai_generate_seo_image');
                form.set('nonce', YPS_EDITOR.nonce);
                form.set('post_id', String(postId));
                form.set('model', model ? model.value : '');
                form.set('prompt', prompt ? prompt.value : '');
                form.set('aspect_ratio', ratio ? ratio.value : '16:9');
                const response = await fetch(YPS_EDITOR.ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }, body: form.toString() });
                const json = await response.json();
                if (!response.ok || !json || !json.success) { throw new Error(json && json.data && json.data.message ? json.data.message : YPS_EDITOR.labels.imageFailed); }
                const data = json.data || {};
                if (!data.url) { throw new Error(YPS_EDITOR.labels.imageNoUrl); }
                ogImage.value = data.url;
                ogImage.dispatchEvent(new Event('input', { bubbles: true }));
                if (twitterImage && !twitterImage.value.trim()) { twitterImage.value = data.url; twitterImage.dispatchEvent(new Event('input', { bubbles: true })); }
                if (preview) { preview.innerHTML = ''; const img = document.createElement('img'); img.src = data.url; img.alt = ''; preview.appendChild(img); const label = document.createElement('span'); label.textContent = YPS_EDITOR.labels.imageGeneratedReview; preview.appendChild(label); }
                status.textContent = data.message || YPS_EDITOR.labels.imageSavedReview;
            } catch (error) {
                status.textContent = error && error.message ? error.message : YPS_EDITOR.labels.imageFailed;
            } finally { button.disabled = false; }
        });
    }


    function initBulkSeoAi() {
        if (typeof YSEO_BULK === 'undefined') { return; }
        const rows = Array.from(document.querySelectorAll('[data-yseo-bulk-row]'));
        function setStatus(row, message) {
            const status = row.querySelector('[data-yseo-bulk-ai-status]');
            if (status) { status.textContent = message || ''; }
        }
        function apply(row, suggestions, onlyEmpty) {
            Object.keys(suggestions || {}).forEach(function (field) {
                const node = row.querySelector('[data-yseo-bulk-field="' + field + '"]');
                if (!node || (onlyEmpty && node.value.trim())) { return; }
                node.value = suggestions[field] || '';
                node.dispatchEvent(new Event('input', {bubbles: true}));
            });
        }
        function missingFields(row) {
            return Array.from(row.querySelectorAll('[data-yseo-bulk-field]')).filter(function (node) { return !node.value.trim(); })
                .map(function (node) { return node.dataset.yseoBulkField || ''; }).filter(Boolean);
        }
        function duration(seconds) {
            seconds = Math.max(0, Number(seconds || 0));
            if (seconds < 60) { return Math.round(seconds) + 's'; }
            const minutes = Math.floor(seconds / 60); const secs = Math.round(seconds % 60);
            if (minutes < 60) { return minutes + 'm ' + secs + 's'; }
            const hours = Math.floor(minutes / 60); return hours + 'h ' + (minutes % 60) + 'm';
        }
        async function draft(row, field, onlyEmpty, trigger) {
            if (!YSEO_BULK.aiConfigured) { setStatus(row, YSEO_BULK.labels.unavailable); return; }
            const postId = Number(row.dataset.yseoBulkRow || 0); if (!postId) { return; }
            const requestedFields = field ? [field] : (onlyEmpty ? missingFields(row) : []);
            if (onlyEmpty && !requestedFields.length) { setStatus(row, YSEO_BULK.labels.pageNothing); return; }
            const buttons = Array.from(row.querySelectorAll('[data-yseo-bulk-ai-row], [data-yseo-bulk-ai-field]'));
            buttons.forEach(function (button) { button.disabled = true; }); if (trigger) { trigger.classList.add('is-working'); }
            setStatus(row, YSEO_BULK.labels.working);
            try {
                const data = await post(YSEO_BULK.ajaxUrl, YSEO_BULK.nonce, 'yseo_bulk_ai_suggest', {post_id: postId, field: field || '', fields: requestedFields.join(',')});
                apply(row, data.suggestions || {}, onlyEmpty); setStatus(row, data.message || YSEO_BULK.labels.review);
            } catch (error) { setStatus(row, error && error.message ? error.message : YSEO_BULK.labels.failed); }
            finally { buttons.forEach(function (button) { button.disabled = false; }); if (trigger) { trigger.classList.remove('is-working'); } }
        }
        rows.forEach(function (row) {
            row.querySelectorAll('[data-yseo-bulk-ai-field]').forEach(function (button) { button.addEventListener('click', function () { draft(row, button.dataset.yseoBulkAiField || '', false, button); }); });
            const rowButton = row.querySelector('[data-yseo-bulk-ai-row]'); if (rowButton) { rowButton.addEventListener('click', function () { draft(row, '', true, rowButton); }); }
        });

        const pageButton = document.querySelector('[data-yseo-bulk-ai-page]');
        const pageStatus = document.querySelector('[data-yseo-bulk-ai-page-status]');
        const pageProgress = document.querySelector('[data-yseo-bulk-page-progress]');
        const pageBar = document.querySelector('[data-yseo-bulk-page-progress-bar]');
        const pagePercent = document.querySelector('[data-yseo-bulk-page-progress-percent]');
        const pageProgressLabel = document.querySelector('[data-yseo-bulk-page-progress-label]');
        const pageElapsed = document.querySelector('[data-yseo-bulk-page-elapsed]');
        const pageEta = document.querySelector('[data-yseo-bulk-page-eta]');
        function renderPageProgress(done, total, startedAt) {
            const percent = total > 0 ? Math.min(100, Math.round((done / total) * 100)) : 0;
            const elapsed = Math.max(0, (Date.now() - startedAt) / 1000);
            const eta = done > 0 && total > done ? (elapsed / done) * (total - done) : 0;
            if (pageProgress) { pageProgress.hidden = false; }
            if (pageBar) { pageBar.style.width = percent + '%'; }
            if (pagePercent) { pagePercent.textContent = percent + '%'; }
            if (pageProgressLabel) { pageProgressLabel.textContent = (YSEO_BULK.labels.pageWorking || '') + ' ' + done + '/' + total; }
            if (pageElapsed) { pageElapsed.textContent = (YSEO_BULK.labels.elapsed || 'Elapsed') + ': ' + duration(elapsed); }
            if (pageEta) { pageEta.textContent = eta > 0 ? (YSEO_BULK.labels.remaining || 'Estimated remaining') + ': ' + duration(eta) : ''; }
        }
        const selectedButton = document.querySelector('[data-yseo-bulk-ai-selected]');
        if (selectedButton) {
            selectedButton.addEventListener('click', async function () {
                if (!YSEO_BULK.aiConfigured) { if (pageStatus) { pageStatus.textContent = YSEO_BULK.labels.unavailable; } return; }
                const selectedRows = rows.filter(function (row) { const box = row.querySelector('[data-yseo-row-check]'); return !!box && box.checked; });
                const pending = selectedRows.filter(function (row) { return missingFields(row).length > 0; });
                if (!pending.length) { if (pageStatus) { pageStatus.textContent = YSEO_BULK.labels.pageNothing; } return; }
                const allButtons = Array.from(document.querySelectorAll('[data-yseo-bulk-ai-row], [data-yseo-bulk-ai-field], [data-yseo-bulk-ai-page], [data-yseo-bulk-ai-selected]'));
                allButtons.forEach(function (button) { button.disabled = true; }); selectedButton.classList.add('is-working');
                const startedAt = Date.now(); renderPageProgress(0, pending.length, startedAt);
                try {
                    let done = 0;
                    for (let offset = 0; offset < pending.length; offset += 5) {
                        const batchRows = pending.slice(offset, offset + 5);
                        const ids = batchRows.map(function (row) { return Number(row.dataset.yseoBulkRow || 0); }).filter(Boolean);
                        const requestedFields = Array.from(new Set(batchRows.flatMap(function (row) { return missingFields(row); })));
                        const data = await post(YSEO_BULK.ajaxUrl, YSEO_BULK.nonce, 'yseo_bulk_ai_batch', {post_ids: ids.join(','), fields: requestedFields.join(',')});
                        const suggestions = data.suggestions || {};
                        batchRows.forEach(function (row) { const id = String(Number(row.dataset.yseoBulkRow || 0)); if (suggestions[id]) { apply(row, suggestions[id], true); setStatus(row, YSEO_BULK.labels.review); } });
                        done += batchRows.length; renderPageProgress(done, pending.length, startedAt);
                    }
                    if (pageStatus) { pageStatus.textContent = YSEO_BULK.labels.pageReview; }
                } catch (error) { if (pageStatus) { pageStatus.textContent = error && error.message ? error.message : YSEO_BULK.labels.failed; } }
                finally { allButtons.forEach(function (button) { button.disabled = false; }); selectedButton.classList.remove('is-working'); }
            });
        }

        if (pageButton) {
            pageButton.addEventListener('click', async function () {
                if (!YSEO_BULK.aiConfigured) { if (pageStatus) { pageStatus.textContent = YSEO_BULK.labels.unavailable; } return; }
                const pending = rows.filter(function (row) { return missingFields(row).length > 0; });
                if (!pending.length) { if (pageStatus) { pageStatus.textContent = YSEO_BULK.labels.pageNothing; } return; }
                const allButtons = Array.from(document.querySelectorAll('[data-yseo-bulk-ai-row], [data-yseo-bulk-ai-field], [data-yseo-bulk-ai-page]'));
                allButtons.forEach(function (button) { button.disabled = true; }); pageButton.classList.add('is-working');
                const startedAt = Date.now(); renderPageProgress(0, pending.length, startedAt);
                try {
                    let done = 0;
                    for (let offset = 0; offset < pending.length; offset += 5) {
                        const batchRows = pending.slice(offset, offset + 5);
                        const ids = batchRows.map(function (row) { return Number(row.dataset.yseoBulkRow || 0); }).filter(Boolean);
                        const requestedFields = Array.from(new Set(batchRows.flatMap(function (row) { return missingFields(row); })));
                        const data = await post(YSEO_BULK.ajaxUrl, YSEO_BULK.nonce, 'yseo_bulk_ai_batch', {post_ids: ids.join(','), fields: requestedFields.join(',')});
                        const suggestions = data.suggestions || {};
                        batchRows.forEach(function (row) { const id = String(Number(row.dataset.yseoBulkRow || 0)); if (suggestions[id]) { apply(row, suggestions[id], true); setStatus(row, YSEO_BULK.labels.review); } });
                        done += batchRows.length; renderPageProgress(done, pending.length, startedAt);
                    }
                    if (pageStatus) { pageStatus.textContent = YSEO_BULK.labels.pageReview; }
                } catch (error) { if (pageStatus) { pageStatus.textContent = error && error.message ? error.message : YSEO_BULK.labels.failed; } }
                finally { allButtons.forEach(function (button) { button.disabled = false; }); pageButton.classList.remove('is-working'); }
            });
        }

        // Durable site-wide campaign. Each server call is bounded and the same
        // persisted job can continue from WP-Cron when the browser disappears.
        const campaign = document.querySelector('[data-yseo-bulk-campaign]');
        if (!campaign || !YSEO_BULK.jobNonce) { return; }
        const jobStart = campaign.querySelector('[data-yseo-job-start]');
        const jobResume = campaign.querySelector('[data-yseo-job-resume]');
        const jobPause = campaign.querySelector('[data-yseo-job-pause]');
        const jobReset = campaign.querySelector('[data-yseo-job-reset]');
        const jobPolicy = campaign.querySelector('[data-yseo-job-policy]');
        const languageInputs = Array.from(campaign.querySelectorAll('[data-yseo-job-language]'));
        const fieldInputs = Array.from(campaign.querySelectorAll('[data-yseo-job-field]'));
        const languageToggle = campaign.querySelector('[data-yseo-language-toggle]');
        const languagePopover = campaign.querySelector('[data-yseo-language-popover]');
        const languageSearch = campaign.querySelector('[data-yseo-language-search]');
        const languageOptions = Array.from(campaign.querySelectorAll('[data-yseo-language-option]'));
        const languageApply = campaign.querySelector('[data-yseo-language-apply]');
        const languageCancel = campaign.querySelector('[data-yseo-language-cancel]');
        const languageCurrent = campaign.querySelector('[data-yseo-language-current]');
        const languageState = campaign.querySelector('[data-yseo-language-state]');
        const languageSummary = campaign.querySelector('[data-yseo-language-summary]');
        const languageChips = campaign.querySelector('[data-yseo-language-chips]');
        const metadataApply = campaign.querySelector('[data-yseo-metadata-apply]');
        const metadataCancel = campaign.querySelector('[data-yseo-metadata-cancel]');
        const metadataState = campaign.querySelector('[data-yseo-metadata-state]');
        const jobBar = campaign.querySelector('[data-yseo-job-progress-bar]');
        const jobPercent = campaign.querySelector('[data-yseo-job-progress-percent]');
        const jobLabel = campaign.querySelector('[data-yseo-job-progress-label]');
        const jobCounts = campaign.querySelector('[data-yseo-job-counts]');
        const jobTimes = campaign.querySelector('[data-yseo-job-times]');
        const jobMessage = campaign.querySelector('[data-yseo-job-message]');
        let stopLoop = false;
        let languageDirty = false;
        let metadataDirty = false;
        const allLanguage = languageInputs.find(function (input) { return input.value === 'all'; });
        let appliedLanguages = Array.isArray(YSEO_BULK.jobState && YSEO_BULK.jobState.languages) && YSEO_BULK.jobState.languages.length ? YSEO_BULK.jobState.languages.slice() : ['all'];
        let appliedFields = Array.isArray(YSEO_BULK.jobState && YSEO_BULK.jobState.fields) && YSEO_BULK.jobState.fields.length ? YSEO_BULK.jobState.fields.slice() : fieldInputs.filter(function (input) { return input.checked; }).map(function (input) { return input.value; });
        let appliedPolicy = (YSEO_BULK.jobState && YSEO_BULK.jobState.policy) || (jobPolicy ? jobPolicy.value : 'fill-missing');

        function normalizeCampaignSearch(value) { return String(value || '').toLowerCase().replace(/[._/\-]+/g, ' ').replace(/\s+/g, ' ').trim(); }
        function syncLanguageChecks(values) {
            const wanted = new Set(values && values.length ? values : ['all']);
            languageInputs.forEach(function (input) { input.checked = wanted.has(input.value); });
            if (wanted.has('all') && allLanguage) { allLanguage.checked = true; languageInputs.forEach(function (input) { if (input !== allLanguage) { input.checked = false; } }); }
        }
        function draftLanguages() { return languageInputs.filter(function (input) { return input.checked; }).map(function (input) { return input.value; }); }
        function languageName(value) {
            if ('all' === value) { return YSEO_BULK.labels.allLanguages || 'All resource languages'; }
            const option = languageInputs.find(function (input) { return input.value === value; });
            const row = option ? option.closest('[data-yseo-language-option]') : null;
            const strong = row ? row.querySelector('strong') : null;
            return strong ? strong.textContent.trim() : value;
        }
        function renderLanguageChips(values) {
            if (!languageChips) { return; }
            const selected = Array.isArray(values) ? values.slice() : [];
            languageChips.innerHTML = '';
            if (!selected.length) {
                const emptyChip = document.createElement('span');
                emptyChip.className = 'yseo-language-chip is-empty';
                emptyChip.textContent = YSEO_BULK.labels.noLanguages || 'No languages selected';
                languageChips.appendChild(emptyChip);
                return;
            }
            selected.forEach(function (value) {
                const chip = document.createElement('span');
                chip.className = 'yseo-language-chip';
                const text = document.createElement('span');
                text.textContent = languageName(value);
                chip.appendChild(text);
                const remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'yseo-language-chip-remove';
                remove.dataset.yseoRemoveLanguage = value;
                remove.setAttribute('aria-label', (YSEO_BULK.labels.removeLanguage || 'Remove language') + ': ' + languageName(value));
                remove.textContent = '×';
                chip.appendChild(remove);
                languageChips.appendChild(chip);
            });
        }
        function renderLanguageSummary(values) {
            const selectedValues = Array.isArray(values) ? values.slice() : appliedLanguages.slice();
            let label = (YSEO_BULK.labels.allLanguages || 'All resource languages');
            if (!selectedValues.includes('all')) {
                const names = selectedValues.map(languageName);
                label = names.length <= 2 ? names.join(', ') : (YSEO_BULK.labels.selected || 'Selected') + ': ' + names.length;
            }
            if (!selectedValues.length) { label = YSEO_BULK.labels.noLanguages || 'No languages selected'; }
            if (languageCurrent) { languageCurrent.textContent = label; }
            if (languageSummary) { languageSummary.textContent = label; }
            renderLanguageChips(selectedValues);
        }
        function setLanguageDirty(dirty) {
            languageDirty = dirty;
            if (languageState) { languageState.textContent = dirty ? (YSEO_BULK.labels.notApplied || 'Not applied') : (YSEO_BULK.labels.applied || 'Applied'); languageState.classList.toggle('good', !dirty); languageState.classList.toggle('attention', dirty); }
            if (jobStart) { jobStart.disabled = languageDirty || metadataDirty; }
        }
        function setMetadataDirty(dirty) {
            metadataDirty = dirty;
            if (metadataState) { metadataState.textContent = dirty ? (YSEO_BULK.labels.notApplied || 'Not applied') : (YSEO_BULK.labels.applied || 'Applied'); metadataState.classList.toggle('good', !dirty); metadataState.classList.toggle('attention', dirty); }
            if (jobStart) { jobStart.disabled = languageDirty || metadataDirty; }
        }
        function closeLanguagePicker() {
            if (!languagePopover || !languageToggle) { return; }
            languagePopover.hidden = true; languageToggle.classList.remove('is-open'); languageToggle.setAttribute('aria-expanded', 'false');
        }
        function openLanguagePicker() {
            if (!languagePopover || !languageToggle) { return; }
            syncLanguageChecks(appliedLanguages); setLanguageDirty(false);
            languagePopover.hidden = false; languageToggle.classList.add('is-open'); languageToggle.setAttribute('aria-expanded', 'true');
            if (languageSearch) { languageSearch.value = ''; filterLanguageOptions(); languageSearch.focus(); }
        }
        function filterLanguageOptions() {
            if (!languageSearch) { return; }
            const query = normalizeCampaignSearch(languageSearch.value);
            const terms = query ? query.split(' ').filter(Boolean) : [];
            languageOptions.forEach(function (option) {
                const haystack = normalizeCampaignSearch(option.dataset.languageSearch || option.textContent);
                const hidden = !!terms.length && !terms.every(function (term) { return haystack.includes(term); });
                option.hidden = hidden;
                option.classList.toggle('is-filtered-out', hidden);
            });
        }
        languageInputs.forEach(function (input) {
            input.addEventListener('change', function () {
                if (input.value === 'all' && input.checked) { languageInputs.forEach(function (other) { if (other !== input) { other.checked = false; } }); }
                else if (input.checked && allLanguage) { allLanguage.checked = false; }
                if (!languageInputs.some(function (item) { return item.checked; }) && allLanguage) { allLanguage.checked = true; }
                const draft = draftLanguages();
                setLanguageDirty(JSON.stringify(draft.slice().sort()) !== JSON.stringify(appliedLanguages.slice().sort()));
                renderLanguageSummary(draft);
            });
        });
        if (languageToggle) { languageToggle.addEventListener('click', function () { languagePopover && languagePopover.hidden ? openLanguagePicker() : closeLanguagePicker(); }); }
        if (languageSearch) { languageSearch.addEventListener('input', filterLanguageOptions); languageSearch.addEventListener('keydown', function (event) { if (event.key === 'Escape') { event.preventDefault(); closeLanguagePicker(); languageToggle && languageToggle.focus(); } }); }
        if (languageApply) { languageApply.addEventListener('click', function () { appliedLanguages = draftLanguages(); setLanguageDirty(false); renderLanguageSummary(appliedLanguages); closeLanguagePicker(); }); }
        if (languageCancel) { languageCancel.addEventListener('click', function () { syncLanguageChecks(appliedLanguages); setLanguageDirty(false); renderLanguageSummary(appliedLanguages); closeLanguagePicker(); }); }
        if (languageChips) {
            languageChips.addEventListener('click', function (event) {
                const button = event.target.closest('[data-yseo-remove-language]');
                if (!button) { return; }
                const valueToRemove = button.dataset.yseoRemoveLanguage || '';
                const input = languageInputs.find(function (item) { return item.value === valueToRemove; });
                if (input) { input.checked = false; }
                if ('all' === valueToRemove && allLanguage) { allLanguage.checked = false; }
                const draft = draftLanguages();
                setLanguageDirty(JSON.stringify(draft.slice().sort()) !== JSON.stringify(appliedLanguages.slice().sort()));
                renderLanguageSummary(draft);
            });
        }
        document.addEventListener('click', function (event) { if (languagePopover && !languagePopover.hidden && !campaign.querySelector('[data-yseo-language-multi]').contains(event.target)) { closeLanguagePicker(); } });

        function syncMetadataControls() {
            const wanted = new Set(appliedFields);
            fieldInputs.forEach(function (input) { input.checked = wanted.has(input.value); });
            if (jobPolicy) { jobPolicy.value = appliedPolicy; }
        }
        function draftFields() { return fieldInputs.filter(function (input) { return input.checked; }).map(function (input) { return input.value; }); }
        function refreshMetadataDirty() {
            const fieldsChanged = JSON.stringify(draftFields().slice().sort()) !== JSON.stringify(appliedFields.slice().sort());
            const policyChanged = (jobPolicy ? jobPolicy.value : 'fill-missing') !== appliedPolicy;
            setMetadataDirty(fieldsChanged || policyChanged);
        }
        fieldInputs.forEach(function (input) { input.addEventListener('change', refreshMetadataDirty); });
        if (jobPolicy) { jobPolicy.addEventListener('change', refreshMetadataDirty); }
        if (metadataApply) { metadataApply.addEventListener('click', function () {
            const fields = draftFields();
            if (!fields.length) { if (jobMessage) { jobMessage.textContent = YSEO_BULK.labels.selectField || 'Select at least one metadata field.'; } return; }
            appliedFields = fields; appliedPolicy = jobPolicy ? jobPolicy.value : 'fill-missing'; setMetadataDirty(false);
        }); }
        if (metadataCancel) { metadataCancel.addEventListener('click', function () { syncMetadataControls(); setMetadataDirty(false); }); }
        syncLanguageChecks(appliedLanguages); renderLanguageSummary(appliedLanguages); syncMetadataControls(); setLanguageDirty(false); setMetadataDirty(false);
        function renderJob(state) {
            if (!state || state.status === 'reset') {
                if (jobBar) { jobBar.style.width = '0%'; } if (jobPercent) { jobPercent.textContent = '0%'; }
                if (jobLabel) { jobLabel.textContent = (YSEO_BULK.labels.noCampaign || 'No campaign running'); } if (jobCounts) { jobCounts.textContent = ''; }
                if (jobTimes) { jobTimes.textContent = ''; } if (jobMessage) { jobMessage.textContent = ''; } return;
            }
            const percent = Math.max(0, Math.min(100, Number(state.progress_percent || 0)));
            const inventory = state.phase === 'inventory';
            const done = inventory ? Number(state.candidate_scanned || 0) : Number(state.processed_posts || 0);
            const total = inventory ? Number(state.candidate_total || 0) : Number(state.total_posts || 0);
            if (jobBar) { jobBar.style.width = percent + '%'; } if (jobPercent) { jobPercent.textContent = percent + '%'; }
            if (jobLabel) { jobLabel.textContent = state.status === 'paused' ? (YSEO_BULK.labels.paused || 'Paused safely') : (inventory ? (YSEO_BULK.labels.preparing || 'Preparing language scope') : (YSEO_BULK.labels.processing || 'Processing resources')); }
            if (jobCounts) { jobCounts.textContent = done + ' / ' + total + (inventory ? ' ' + (YSEO_BULK.labels.candidates || 'candidates') : ' ' + (YSEO_BULK.labels.resources || 'resources')) + ' · ' + Number(state.generated_posts || 0) + ' ' + (YSEO_BULK.labels.aiGenerated || 'AI generated'); }
            if (jobTimes) { jobTimes.textContent = (YSEO_BULK.labels.elapsed || 'Elapsed') + ': ' + duration(state.elapsed_seconds || 0) + (Number(state.eta_seconds || 0) > 0 ? ' · ' + (YSEO_BULK.labels.remaining || 'Estimated remaining') + ': ' + duration(state.eta_seconds) : ''); }
            if (jobMessage) { jobMessage.textContent = state.message || ''; }
            if (jobResume) { jobResume.disabled = state.status === 'complete' || state.status === 'blocked'; }
            if (jobPause) { jobPause.disabled = state.status !== 'running'; }
        }
        async function continueJob(state) {
            stopLoop = false;
            while (!stopLoop && state && state.status === 'running') {
                await sleep(Math.max(200, Number(state.recommended_delay_ms || 250)));
                try { state = await post(YSEO_BULK.ajaxUrl, YSEO_BULK.jobNonce, 'yseo_ai_metadata_step'); }
                catch (error) { if (error.status === 429) { await sleep(Math.max(750, error.retryAfterMs || 1000)); continue; } throw error; }
                renderJob(state);
            }
            return state;
        }
        if (YSEO_BULK.jobState) { renderJob(YSEO_BULK.jobState); }
        if (jobStart) { jobStart.addEventListener('click', async function () {
            const fields = appliedFields.slice(); if (!fields.length || languageDirty || metadataDirty) { return; }
            const languages = appliedLanguages.slice(); if (!languages.length) { return; }
            jobStart.disabled = true;
            try { let state = await post(YSEO_BULK.ajaxUrl, YSEO_BULK.jobNonce, 'yseo_ai_metadata_start', {policy: appliedPolicy, fields: fields.join(','), languages: languages.join(',')}); renderJob(state); await continueJob(state); }
            catch (error) { if (jobMessage) { jobMessage.textContent = error && error.message ? error.message : YSEO_BULK.labels.failed; } }
            finally { jobStart.disabled = false; }
        }); }
        if (jobResume) { jobResume.addEventListener('click', async function () { stopLoop = false; jobResume.disabled = true; try { let state = await post(YSEO_BULK.ajaxUrl, YSEO_BULK.jobNonce, 'yseo_ai_metadata_step'); renderJob(state); await continueJob(state); } catch (error) { if (jobMessage) { jobMessage.textContent = error.message || YSEO_BULK.labels.failed; } } finally { jobResume.disabled = false; } }); }
        if (jobPause) { jobPause.addEventListener('click', async function () { stopLoop = true; try { const state = await post(YSEO_BULK.ajaxUrl, YSEO_BULK.jobNonce, 'yseo_ai_metadata_pause'); renderJob(state); } catch (error) { if (jobMessage) { jobMessage.textContent = error.message || YSEO_BULK.labels.failed; } } }); }
        if (jobReset) { jobReset.addEventListener('click', async function () { stopLoop = true; try { renderJob(await post(YSEO_BULK.ajaxUrl, YSEO_BULK.jobNonce, 'yseo_ai_metadata_reset')); } catch (error) { if (jobMessage) { jobMessage.textContent = error.message || YSEO_BULK.labels.failed; } } }); }
    }

    function initMediaLibraryPickers() {
        if (typeof wp === 'undefined' || !wp.media) { return; }

        function openPicker(button, initialMode) {
            const targetId = button.getAttribute('data-yps-media-target') || '';
            const input = targetId ? document.getElementById(targetId) : null;
            if (!input) { return; }
            const frame = wp.media({
                title: typeof YPS_MEDIA !== 'undefined' ? YPS_MEDIA.title : 'Select or upload image',
                button: { text: typeof YPS_MEDIA !== 'undefined' ? YPS_MEDIA.button : 'Use image' },
                library: { type: 'image' },
                multiple: false
            });
            const forceMode = function () {
                if (!frame.content || typeof frame.content.mode !== 'function') { return; }
                try { frame.content.mode(initialMode); } catch (e) {}
            };
            frame.on('open', function () {
                // MediaFrame restores its previous tab from a user setting. Apply the
                // requested mode only after the frame has rendered so Upload image and
                // Media Library never collapse into the same remembered destination.
                window.setTimeout(forceMode, 0);
                window.setTimeout(forceMode, 60);
            });
            frame.on('select', function () {
                const attachment = frame.state().get('selection').first();
                if (!attachment) { return; }
                const data = attachment.toJSON();
                if (!data || !data.url) { return; }
                input.value = data.url;
                input.dispatchEvent(new Event('change', { bubbles: true }));
            });
            frame.open();
        }

        document.querySelectorAll('[data-yps-media-select]').forEach(function (button) {
            button.addEventListener('click', function () { openPicker(button, 'browse'); });
        });
        document.querySelectorAll('[data-yps-media-upload]').forEach(function (button) {
            button.addEventListener('click', function () { openPicker(button, 'upload'); });
        });
        document.querySelectorAll('[data-yps-media-clear]').forEach(function (button) {
            button.addEventListener('click', function () {
                const targetId = button.getAttribute('data-yps-media-target') || '';
                const input = targetId ? document.getElementById(targetId) : null;
                if (!input) { return; }
                input.value = '';
                input.dispatchEvent(new Event('change', { bubbles: true }));
            });
        });
        const preferredSearchInput = document.getElementById('yps-home_search_image');
        const preferredSearchPreview = document.querySelector('[data-yps-home-search-preview-image]');
        if (preferredSearchInput && preferredSearchPreview) {
            const syncPreferredSearchPreview = function () {
                const url = preferredSearchInput.value.trim();
                preferredSearchPreview.src = url;
                preferredSearchPreview.hidden = !url;
            };
            preferredSearchInput.addEventListener('input', syncPreferredSearchPreview);
            preferredSearchInput.addEventListener('change', syncPreferredSearchPreview);
            syncPreferredSearchPreview();
        }
    }

    function initSettingsPortability() {
        const form = document.querySelector('[data-yseo-settings-import]');
        if (!form) { return; }
        const fileInput = form.querySelector('[data-yseo-settings-import-file]');
        const jsonField = form.querySelector('[data-yseo-settings-import-json]');
        const name = form.querySelector('[data-yseo-settings-import-name]');
        if (!fileInput || !jsonField) { return; }
        fileInput.addEventListener('change', function () {
            const file = fileInput.files && fileInput.files[0] ? fileInput.files[0] : null;
            jsonField.value = '';
            if (name) { name.textContent = file ? file.name : ''; }
            if (!file) { return; }
            const reader = new FileReader();
            reader.addEventListener('load', function () {
                jsonField.value = typeof reader.result === 'string' ? reader.result : '';
            });
            reader.addEventListener('error', function () { jsonField.value = ''; });
            reader.readAsText(file);
        });
        form.addEventListener('submit', function (event) {
            if ('' === jsonField.value.trim()) {
                event.preventDefault();
                fileInput.focus();
            }
        });
    }

    function initContentPlanner() {
        if (typeof YPS_ADMIN === 'undefined') { return; }
        const root = document.querySelector('[data-yps-content-planner]');
        if (!root) { return; }
        const button = root.querySelector('[data-yps-content-plan-generate]');
        const language = root.querySelector('[data-yps-content-plan-language]');
        const status = root.querySelector('[data-yps-content-plan-status]');
        const results = root.querySelector('[data-yps-content-plan-results]');
        if (!button || !results) { return; }
        button.addEventListener('click', async function () {
            button.disabled = true;
            if (status) { status.textContent = YPS_ADMIN.labels.contentPlanning || YPS_ADMIN.labels.failed; }
            results.hidden = true; results.innerHTML = '';
            try {
                const data = await post(YPS_ADMIN.ajaxUrl, YPS_ADMIN.nonce, 'yseo_ai_content_plan', {language: language ? language.value : ''});
                const list = document.createElement('ol');
                (data.items || []).forEach(function (item) {
                    const li = document.createElement('li');
                    const strong = document.createElement('strong'); strong.textContent = item.title || '';
                    const meta = document.createElement('span'); meta.textContent = [item.intent || '', item.focus_keyphrase || ''].filter(Boolean).join(' · ');
                    const p = document.createElement('p'); p.textContent = item.rationale || '';
                    li.appendChild(strong); if (meta.textContent) { li.appendChild(meta); } if (p.textContent) { li.appendChild(p); } list.appendChild(li);
                });
                results.appendChild(list); results.hidden = false;
                if (status) { status.textContent = data.message || YPS_ADMIN.labels.contentPlanReady; }
            } catch (error) {
                if (status) { status.textContent = error && error.message ? error.message : YPS_ADMIN.labels.failed; }
            } finally { button.disabled = false; }
        });
    }

    function initSitemapTransportTest() {
        const form = document.querySelector('[data-yseo-sitemap-transport-test]');
        if (!form || typeof YPS_ADMIN === 'undefined') { return; }
        const button = form.querySelector('[data-yseo-sitemap-transport-button]');
        const live = document.querySelector('[data-yseo-sitemap-transport-live]');
        const state = live ? live.querySelector('[data-yseo-sitemap-transport-state]') : null;
        const elapsed = live ? live.querySelector('[data-yseo-sitemap-transport-elapsed]') : null;
        const progress = live ? live.querySelector('[data-yseo-sitemap-transport-progress]') : null;
        let timer = null;
        form.addEventListener('submit', async function (event) {
            if (!window.fetch) { return; }
            event.preventDefault();
            const started = Date.now();
            if (button) { button.disabled = true; }
            if (live) { live.hidden = false; }
            if (state) { state.textContent = YPS_ADMIN.labels.running || 'Running'; }
            if (progress) { progress.classList.add('is-indeterminate'); progress.setAttribute('aria-valuenow', '0'); }
            const tick = function () {
                if (elapsed) { elapsed.textContent = (YPS_ADMIN.labels.elapsed || 'Elapsed') + ': ' + Math.max(0, Math.floor((Date.now() - started) / 1000)) + 's'; }
            };
            tick();
            timer = window.setInterval(tick, 1000);
            try {
                await post(YPS_ADMIN.ajaxUrl, YPS_ADMIN.nonce, 'yseo_sitemap_transport_probe');
                if (state) { state.textContent = YPS_ADMIN.labels.phaseComplete || 'Complete'; }
                if (progress) { progress.classList.remove('is-indeterminate'); progress.setAttribute('aria-valuenow', '100'); const bar = progress.querySelector('span'); if (bar) { bar.style.width = '100%'; } }
                window.location.hash = '#yps-sitemaps';
                window.location.reload();
            } catch (error) {
                if (state) { state.textContent = error && error.message ? error.message : (YPS_ADMIN.labels.failed || 'Error'); }
                if (progress) { progress.classList.remove('is-indeterminate'); }
                if (button) { button.disabled = false; }
            } finally {
                if (timer) { window.clearInterval(timer); timer = null; }
                tick();
            }
        });
    }

    function initTableBulkSelection() {
        document.querySelectorAll('[data-yseo-bulk-form]').forEach(function (form) {
            const masters = Array.from(form.querySelectorAll('[data-yseo-check-all]'));
            const boxes = Array.from(form.querySelectorAll('[data-yseo-row-check]'));
            const counters = Array.from(form.querySelectorAll('[data-yseo-selection-count]'));
            const applyButtons = Array.from(form.querySelectorAll('[data-yseo-bulk-apply]'));
            const action = form.querySelector('[data-yseo-bulk-action]');
            const target = form.querySelector('[data-yseo-conditional-target]');
            function selectedCount() { return boxes.filter(function (box) { return box.checked; }).length; }
            function hasRequiredActionInput() {
                if (!action) { return true; }
                if (!action.value) { return false; }
                if (!target) { return true; }
                const requiredValue = target.dataset.yseoConditionalTarget || 'redirect';
                return action.value !== requiredValue || target.value.trim().length > 0;
            }
            function sync() {
                const count = selectedCount();
                masters.forEach(function (master) {
                    master.checked = boxes.length > 0 && count === boxes.length;
                    master.indeterminate = count > 0 && count < boxes.length;
                });
                counters.forEach(function (node) {
                    const emptyLabel = node.dataset.yseoEmptyLabel || 'None selected';
                    const selectedLabel = node.dataset.yseoSelectedLabel || 'selected';
                    node.setAttribute('aria-live', 'polite');
                    node.textContent = count > 0 ? count + ' ' + selectedLabel : emptyLabel;
                });
                form.classList.toggle('has-yseo-selection', count > 0);
                boxes.forEach(function (box) {
                    const row = box.closest('tr');
                    if (row) { row.classList.toggle('is-yseo-selected', box.checked); }
                });
                applyButtons.forEach(function (button) { button.disabled = count < 1 || !hasRequiredActionInput(); });
            }
            masters.forEach(function (master) {
                master.addEventListener('change', function () { boxes.forEach(function (box) { box.checked = master.checked; }); sync(); });
            });
            boxes.forEach(function (box) { box.addEventListener('change', sync); });
            sync();

            if (action && target) {
                const syncTarget = function () {
                    const requiredValue = target.dataset.yseoConditionalTarget || 'redirect';
                    const needsTarget = action.value === requiredValue;
                    target.hidden = !needsTarget;
                    target.disabled = !needsTarget;
                    if (!needsTarget) { target.value = ''; }
                };
                action.addEventListener('change', function () { syncTarget(); sync(); });
                target.addEventListener('input', sync);
                syncTarget();
            } else if (action) {
                action.addEventListener('change', sync);
            }
            form.addEventListener('submit', function (event) {
                const submitter = event.submitter || null;
                const selectionRequired = !!action || !!(submitter && submitter.hasAttribute('data-yseo-bulk-apply'));
                if (selectionRequired && selectedCount() < 1) { event.preventDefault(); return; }
                if (action && !action.value) { event.preventDefault(); return; }
                const dangerous = String(action ? action.value : '');
                if (['delete','dismiss','gone','redirect'].indexOf(dangerous) !== -1) {
                    const message = form.dataset.yseoBulkConfirm || '';
                    if (message && !window.confirm(message)) { event.preventDefault(); }
                }
            });
        });
    }

    initSearchableSelects();
    initEditorAssist();
    initLiveAnalysis();
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initLiveAnalysis, {once: true});
    } else {
        window.setTimeout(initLiveAnalysis, 0);
    }
    initMetadataMirror();
    initPortableMetadataMirror();
    initAiMetadataBulk();
    initMediaLibraryPickers();
    initSettingsPortability();
    initContentPlanner();
    initTableBulkSelection();
    initSitemapTransportTest();
    initBulkSeoAi();
    initSettingsWorkspace();
    initAiSettingsConnection();
    initAiComputeCanary();
    initAiSettingsCopilot();
    initSeoImageStudio();

  document.addEventListener('change', function (event) {
    var target = event.target;
    if (!target || !target.matches || !target.matches('[data-yseo-page-size]')) { return; }
    var form = target.closest('form');
    if (form) { form.submit(); }
  });
}());


// Developer API copy controls.
document.addEventListener('click', function(event){
    const button = event.target && event.target.closest ? event.target.closest('[data-yseo-copy-value]') : null;
    if (!button) return;
    const value = button.getAttribute('data-yseo-copy-value') || '';
    if (!value || !navigator.clipboard) return;
    navigator.clipboard.writeText(value).then(function(){
        const original = button.textContent;
        button.textContent = (window.YSEO_COMMERCE && YSEO_COMMERCE.labels && YSEO_COMMERCE.labels.copied) ? YSEO_COMMERCE.labels.copied : 'Copied';
        window.setTimeout(function(){ button.textContent = original; }, 1400);
    });
});

// YSEO 5.76.0 — country-first Commerce GEO Compatibility Atlas.
// The map, searchable selector, platform groups and counters share one state machine.
document.addEventListener('DOMContentLoaded', function () {
    var root = document.querySelector('[data-yseo-commerce-atlas]');
    if (!root) return;
    var select = root.querySelector('#yseo-commerce-country');
    var grid = root.querySelector('#yseo-commerce-atlas-grid');
    if (!select || !grid) return;
    var cards = Array.prototype.slice.call(grid.querySelectorAll('.yseo-atlas-platform'));
    var svg = root.querySelector('.yseo-world-map');
    var paths = Array.prototype.slice.call(root.querySelectorAll('.yseo-world-map [data-country]'));
    var regionButtons = Array.prototype.slice.call(root.querySelectorAll('[data-yseo-atlas-region]'));
    var selection = root.querySelector('[data-yseo-atlas-selection]');
    var count = root.querySelector('[data-yseo-atlas-platform-count]');
    var empty = root.querySelector('[data-yseo-atlas-empty]');
    var zoomIn = root.querySelector('[data-yseo-map-zoom="in"]');
    var zoomOut = root.querySelector('[data-yseo-map-zoom="out"]');
    var zoomReset = root.querySelector('[data-yseo-map-reset]');
    var region = 'ALL';
    var statusRank = {native: 0, verified: 1, compatible: 2, import: 3, bridge: 4, mapping: 5, different: 6};
    var commerceLabels = (window.YSEO_COMMERCE && YSEO_COMMERCE.labels) ? YSEO_COMMERCE.labels : {};
    function commerceLabel(key, fallback) {
        return Object.prototype.hasOwnProperty.call(commerceLabels, key) && commerceLabels[key] ? String(commerceLabels[key]) : fallback;
    }
    var displayNames = null;
    try {
        if (window.Intl && Intl.DisplayNames) {
            displayNames = new Intl.DisplayNames([document.documentElement.lang || 'en'], {type: 'region'});
        }
    } catch (e) { displayNames = null; }
    function localizedCountryName(code, fallback) {
        if (!displayNames || !code || code === 'ALL') return fallback;
        try { return displayNames.of(code) || fallback; } catch (e) { return fallback; }
    }
    Array.prototype.slice.call(select.options || []).forEach(function (option) {
        var code = String(option.value || '').toUpperCase();
        if (code && code !== 'ALL') option.textContent = localizedCountryName(code, String(option.textContent || code));
    });
    if (svg) {
        Array.prototype.slice.call(svg.querySelectorAll('[data-country]')).forEach(function (path) {
            var code = String(path.getAttribute('data-country') || '').toUpperCase();
            var fallback = String(path.getAttribute('aria-label') || code);
            if (code) path.setAttribute('aria-label', localizedCountryName(code, fallback));
        });
    }

    function csv(value) { return String(value || '').split(',').map(function (item) { return item.trim().toUpperCase(); }).filter(Boolean); }
    function status(card) { return String(card.getAttribute('data-status') || 'mapping').toLowerCase(); }
    function isGlobal(card) { return card.getAttribute('data-global') === '1'; }
    function supportsCountry(card, country) {
        var countries = csv(card.getAttribute('data-countries'));
        return countries.indexOf('*') !== -1 || countries.indexOf(country) !== -1;
    }
    function specificallySupportsCountry(card, country) {
        var countries = csv(card.getAttribute('data-countries'));
        return countries.indexOf(country) !== -1;
    }
    function supportsRegion(card, requestedRegion) { return csv(card.getAttribute('data-regions')).indexOf(requestedRegion) !== -1; }
    function reusable(card) { return ['native', 'verified', 'compatible', 'import', 'bridge'].indexOf(status(card)) !== -1; }
    function direct(card) { return ['native', 'verified'].indexOf(status(card)) !== -1; }
    function localCount(country) { return cards.filter(function (card) { return !isGlobal(card) && specificallySupportsCountry(card, country); }).length; }
    function globalCount(country) { return cards.filter(function (card) { return isGlobal(card) && supportsCountry(card, country); }).length; }
    function localDirectCount(country) { return cards.filter(function (card) { return !isGlobal(card) && specificallySupportsCountry(card, country) && direct(card); }).length; }
    function cardPriority(card) { return Number(card.getAttribute('data-priority') || 0); }
    function cardCoverage(card) { return Number(card.getAttribute('data-country-count') || 0); }
    function cardName(card) { var strong = card.querySelector('strong'); return String(strong ? strong.textContent : ''); }
    function rank(card) { return Object.prototype.hasOwnProperty.call(statusRank, status(card)) ? statusRank[status(card)] : 99; }
    function compareCards(a, b) {
        if (rank(a) !== rank(b)) return rank(a) - rank(b);
        if (cardPriority(a) !== cardPriority(b)) return cardPriority(b) - cardPriority(a);
        if (cardCoverage(a) !== cardCoverage(b)) return cardCoverage(b) - cardCoverage(a);
        return cardName(a).localeCompare(cardName(b));
    }
    function clearGroupLabels() { Array.prototype.slice.call(grid.querySelectorAll('.yseo-atlas-group-label')).forEach(function (label) { label.remove(); }); }
    function addGroupLabel(text, detail) {
        var label = document.createElement('div');
        label.className = 'yseo-atlas-group-label';
        label.innerHTML = '<strong></strong><span></span>';
        label.querySelector('strong').textContent = text;
        label.querySelector('span').textContent = detail || '';
        grid.appendChild(label);
    }
    function sortAndGroup(country) {
        clearGroupLabels();
        if (country && country !== 'ALL') {
            var local = cards.filter(function (card) { return !isGlobal(card) && specificallySupportsCountry(card, country); }).sort(compareCards);
            var global = cards.filter(function (card) { return isGlobal(card); }).sort(compareCards);
            var other = cards.filter(function (card) { return local.indexOf(card) === -1 && global.indexOf(card) === -1; }).sort(compareCards);
            if (local.length) {
                addGroupLabel(commerceLabel('localRegionalChannels', 'Local & regional channels'), local.length + ' ' + commerceLabel(local.length === 1 ? 'match' : 'matches', local.length === 1 ? 'match' : 'matches'));
                local.forEach(function (card) { grid.appendChild(card); });
            }
            if (global.length) {
                addGroupLabel(commerceLabel('globalChannels', 'Global channels'), commerceLabel('marketAvailability', 'Market availability still applies'));
                global.forEach(function (card) { grid.appendChild(card); });
            }
            other.forEach(function (card) { grid.appendChild(card); });
            return;
        }
        cards.slice().sort(function (a, b) {
            if (isGlobal(a) !== isGlobal(b)) return isGlobal(a) ? -1 : 1;
            return compareCards(a, b);
        }).forEach(function (card) { grid.appendChild(card); });
    }

    // Map badges represent country-specific registry depth, not generic global channels.
    paths.forEach(function (path) {
        var country = String(path.getAttribute('data-country') || '').toUpperCase();
        var local = localCount(country);
        var global = globalCount(country);
        path.classList.toggle('is-supported', local > 0);
        path.setAttribute('data-platform-count', String(local));
        var existingTitle = path.querySelector('title');
        if (!existingTitle) { existingTitle = document.createElementNS('http://www.w3.org/2000/svg', 'title'); path.insertBefore(existingTitle, path.firstChild); }
        var label = path.getAttribute('aria-label') || country;
        existingTitle.textContent = label + ': ' + local + ' ' + commerceLabel('localRegionalShort', 'local/regional') + ' · ' + global + ' ' + commerceLabel('globalChannels', 'global channels').toLowerCase();
    });
    if (svg) {
        var badgeLayer = document.createElementNS('http://www.w3.org/2000/svg', 'g');
        badgeLayer.setAttribute('class', 'yseo-world-map-counts');
        paths.forEach(function (path) {
            var country = String(path.getAttribute('data-country') || '').toUpperCase();
            var local = localCount(country);
            if (local < 1) return;
            var box; try { box = path.getBBox(); } catch (error) { return; }
            if (!box || box.width <= 0 || box.height <= 0) return;
            var x = box.x + box.width / 2, y = box.y + box.height / 2;
            var group = document.createElementNS('http://www.w3.org/2000/svg', 'g');
            group.setAttribute('class', 'yseo-map-count-badge');
            group.setAttribute('data-country', country);
            group.setAttribute('role', 'button');
            group.setAttribute('tabindex', '0');
            group.setAttribute('aria-label', (path.getAttribute('aria-label') || country) + ': ' + local + ' ' + commerceLabel('localOrRegionalChannels', 'local or regional channels'));
            group.setAttribute('data-x', String(x));
            group.setAttribute('data-y', String(y));
            group.setAttribute('transform', 'translate(' + x + ' ' + y + ') scale(1)');
            group.addEventListener('keydown', function (event) {
                if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); selectCountry(country); }
            });
            var circle = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
            circle.setAttribute('r', '7.8');
            var textNode = document.createElementNS('http://www.w3.org/2000/svg', 'text');
            textNode.setAttribute('x', '0'); textNode.setAttribute('y', '0'); textNode.setAttribute('text-anchor', 'middle'); textNode.setAttribute('dominant-baseline', 'central'); textNode.setAttribute('alignment-baseline', 'central'); textNode.textContent = String(local);
            group.appendChild(circle); group.appendChild(textNode); badgeLayer.appendChild(group);
        });
        svg.appendChild(badgeLayer);
    }

    function selectedOptionText() {
        var option = select.options && select.selectedIndex >= 0 ? select.options[select.selectedIndex] : null;
        return option ? String(option.textContent || '') : commerceLabel('allMarkets', 'All markets');
    }
    function apply() {
        var country = String(select.value || 'ALL').toUpperCase();
        sortAndGroup(country);
        var visible = 0, localVisible = 0, globalVisible = 0, reusableVisible = 0;
        cards.forEach(function (card) {
            var show;
            if (country !== 'ALL') show = (!isGlobal(card) && specificallySupportsCountry(card, country)) || (isGlobal(card) && supportsCountry(card, country));
            else if (region !== 'ALL') show = supportsRegion(card, region);
            else show = true;
            card.hidden = !show;
            if (show) {
                visible += 1;
                if (country !== 'ALL' && !isGlobal(card) && specificallySupportsCountry(card, country)) localVisible += 1;
                if (country !== 'ALL' && isGlobal(card) && supportsCountry(card, country)) globalVisible += 1;
                if (reusable(card)) reusableVisible += 1;
            }
        });
        Array.prototype.slice.call(grid.querySelectorAll('.yseo-atlas-group-label')).forEach(function (label) {
            var next = label.nextElementSibling, hasVisibleCard = false;
            while (next && !next.classList.contains('yseo-atlas-group-label')) {
                if (next.classList.contains('yseo-atlas-platform') && !next.hidden) { hasVisibleCard = true; break; }
                next = next.nextElementSibling;
            }
            label.hidden = !hasVisibleCard;
        });
        paths.forEach(function (path) {
            var pathCountry = String(path.getAttribute('data-country') || '').toUpperCase();
            var pathRegion = String(path.getAttribute('data-region') || '').toUpperCase();
            path.classList.toggle('is-selected', country !== 'ALL' && pathCountry === country);
            path.classList.toggle('is-region-active', country === 'ALL' && region !== 'ALL' && pathRegion === region);
            path.classList.toggle('is-region-muted', country === 'ALL' && region !== 'ALL' && pathRegion !== region);
        });
        Array.prototype.slice.call(root.querySelectorAll('.yseo-map-count-badge')).forEach(function (badge) {
            var badgeCountry = String(badge.getAttribute('data-country') || '').toUpperCase();
            badge.classList.toggle('is-selected', country !== 'ALL' && badgeCountry === country);
            badge.classList.toggle('is-context-hidden', country !== 'ALL' && badgeCountry !== country);
            if (country === 'ALL' && region !== 'ALL') {
                var badgePath = paths.filter(function (path) { return String(path.getAttribute('data-country') || '').toUpperCase() === badgeCountry; })[0];
                badge.classList.toggle('is-context-hidden', !badgePath || String(badgePath.getAttribute('data-region') || '').toUpperCase() !== region);
            }
        });
        regionButtons.forEach(function (button) {
            var value = String(button.getAttribute('data-yseo-atlas-region') || 'ALL').toUpperCase();
            button.classList.toggle('is-active', country === 'ALL' && value === region);
        });
        if (selection) selection.textContent = country !== 'ALL' ? selectedOptionText() : (region === 'ALL' ? commerceLabel('allMarkets', 'All markets') : regionButtons.reduce(function (label, button) { return String(button.getAttribute('data-yseo-atlas-region') || '').toUpperCase() === region ? String(button.textContent || region) : label; }, region));
        if (count) {
            count.textContent = country !== 'ALL'
                ? localVisible + ' ' + commerceLabel('localRegionalShort', 'local/regional') + ' · ' + globalVisible + ' ' + commerceLabel('globalShort', 'global') + ' · ' + reusableVisible + ' ' + commerceLabel('reusablePaths', 'reusable paths')
                : reusableVisible + ' ' + commerceLabel('reusablePaths', 'reusable paths') + ' · ' + visible + ' ' + commerceLabel('registryEntries', 'registry entries');
        }
        if (empty) empty.hidden = country === 'ALL' || localVisible > 0;
    }
    function syncStyledCountrySelect() {
        var rootPicker = select.nextElementSibling && select.nextElementSibling.matches('[data-yps-search-select-root]') ? select.nextElementSibling : null;
        if (!rootPicker) return;
        var current = rootPicker.querySelector('[data-yps-search-select-current]');
        var selected = select.options && select.selectedIndex >= 0 ? select.options[select.selectedIndex] : null;
        if (current && selected) current.textContent = selected.textContent || selected.value;
        rootPicker.querySelectorAll('.yps-model-option').forEach(function (button) {
            var active = selected && button.dataset.value === selected.value;
            button.classList.toggle('is-selected', !!active);
            button.setAttribute('aria-selected', active ? 'true' : 'false');
        });
    }
    function selectCountry(country) {
        country = String(country || '').toUpperCase();
        if (!country || !Array.prototype.some.call(select.options || [], function (option) { return option.value === country; })) return;
        select.value = country;
        region = 'ALL';
        syncStyledCountrySelect();
        apply();
        focusCountry(country);
    }
    function countryFromTarget(target) {
        if (!target || !target.closest) return '';
        var node = target.closest('[data-country]');
        return node ? String(node.getAttribute('data-country') || '').toUpperCase() : '';
    }

    var focusCountry = function () {};
    var resetMapView = function () {};

    // SVG viewBox zoom/pan + tap/click selection. Selection happens on pointer-up only
    // when the pointer did not move enough to become a pan gesture.
    if (svg && svg.viewBox && svg.viewBox.baseVal) {
        var base = {x: svg.viewBox.baseVal.x, y: svg.viewBox.baseVal.y, w: svg.viewBox.baseVal.width, h: svg.viewBox.baseVal.height};
        var view = {x: base.x, y: base.y, w: base.w, h: base.h};
        var dragging = false, moved = false, downCountry = '', dragX = 0, dragY = 0, startX = 0, startY = 0;
        function renderView() {
            svg.setAttribute('viewBox', [view.x, view.y, view.w, view.h].join(' '));
            var zoomLevel = Math.max(1, base.w / Math.max(1, view.w));
            var screenGrowth = Math.min(2.20, 1 + (Math.log(zoomLevel) / Math.LN2) * 0.40);
            var inverseZoom = Math.max(0.10, Math.min(1, view.w / base.w));
            Array.prototype.slice.call(root.querySelectorAll('.yseo-map-count-badge')).forEach(function (badge) {
                var x = Number(badge.getAttribute('data-x') || 0), y = Number(badge.getAttribute('data-y') || 0);
                var selectedBoost = badge.classList.contains('is-selected') ? 1.18 : 1;
                var appliedScale = inverseZoom * screenGrowth * selectedBoost;
                badge.setAttribute('transform', 'translate(' + x + ' ' + y + ') scale(' + appliedScale.toFixed(4) + ')');
            });
        }
        function zoom(factor) {
            var nw = Math.max(base.w / 10, Math.min(base.w, view.w * factor));
            var nh = Math.max(base.h / 10, Math.min(base.h, view.h * factor));
            var cx = view.x + view.w / 2, cy = view.y + view.h / 2;
            view.x = cx - nw / 2; view.y = cy - nh / 2; view.w = nw; view.h = nh; renderView();
        }
        focusCountry = function (country) {
            country = String(country || '').toUpperCase();
            var path = paths.filter(function (item) { return String(item.getAttribute('data-country') || '').toUpperCase() === country; })[0];
            if (!path) return;
            var box; try { box = path.getBBox(); } catch (error) { return; }
            if (!box || box.width <= 0 || box.height <= 0) return;
            var rect = svg.getBoundingClientRect();
            var aspect = rect.width > 0 && rect.height > 0 ? rect.width / rect.height : (base.w / base.h);
            var padX = Math.max(10, box.width * 1.2), padY = Math.max(8, box.height * 1.2);
            var targetW = Math.max(base.w / 8, Math.min(base.w, box.width + padX * 2));
            var targetH = Math.max(base.h / 8, Math.min(base.h, box.height + padY * 2));
            if (targetW / targetH > aspect) targetH = targetW / aspect; else targetW = targetH * aspect;
            targetW = Math.min(base.w, targetW); targetH = Math.min(base.h, targetH);
            // Even geographically large or multi-part countries (for example the US with Alaska)
            // should visibly focus instead of remaining at an almost world-scale view.
            var maxFocusW = base.w * 0.62, maxFocusH = base.h * 0.62;
            if (targetW > maxFocusW || targetH > maxFocusH) {
                var focusScale = Math.min(maxFocusW / targetW, maxFocusH / targetH);
                targetW *= focusScale; targetH *= focusScale;
            }
            var cx = box.x + box.width / 2, cy = box.y + box.height / 2;
            var targetX = cx - targetW / 2, targetY = cy - targetH / 2;
            targetX = Math.max(base.x, Math.min(base.x + base.w - targetW, targetX));
            targetY = Math.max(base.y, Math.min(base.y + base.h - targetH, targetY));
            view = {x: targetX, y: targetY, w: targetW, h: targetH};
            renderView();
        };
        resetMapView = function () { view = {x: base.x, y: base.y, w: base.w, h: base.h}; renderView(); };
        if (zoomIn) zoomIn.addEventListener('click', function () { zoom(0.72); });
        if (zoomOut) zoomOut.addEventListener('click', function () { zoom(1.38); });
        if (zoomReset) zoomReset.addEventListener('click', function () { view = {x: base.x, y: base.y, w: base.w, h: base.h}; renderView(); });
        svg.addEventListener('pointerdown', function (event) {
            downCountry = countryFromTarget(event.target);
            moved = false;
            dragX = event.clientX; dragY = event.clientY;
            if (view.w >= base.w - 0.01) return;
            dragging = true; startX = view.x; startY = view.y;
            svg.setPointerCapture(event.pointerId); svg.classList.add('is-dragging');
        });
        svg.addEventListener('pointermove', function (event) {
            if (Math.abs(event.clientX - dragX) > 4 || Math.abs(event.clientY - dragY) > 4) moved = true;
            if (!dragging) return;
            var rect = svg.getBoundingClientRect();
            view.x = startX - (event.clientX - dragX) * (view.w / Math.max(1, rect.width));
            view.y = startY - (event.clientY - dragY) * (view.h / Math.max(1, rect.height));
            view.x = Math.max(base.x, Math.min(base.x + base.w - view.w, view.x));
            view.y = Math.max(base.y, Math.min(base.y + base.h - view.h, view.y));
            renderView();
        });
        function stopDrag(event) {
            var country = downCountry;
            var shouldSelect = !moved && !!country;
            dragging = false; svg.classList.remove('is-dragging'); downCountry = '';
            if (shouldSelect) selectCountry(country);
        }
        svg.addEventListener('pointerup', stopDrag);
        svg.addEventListener('pointercancel', function () { dragging = false; moved = true; downCountry = ''; svg.classList.remove('is-dragging'); });
        svg.addEventListener('wheel', function (event) { if (!event.ctrlKey && !event.metaKey) return; event.preventDefault(); zoom(event.deltaY < 0 ? 0.85 : 1.18); }, {passive: false});
    }

    select.addEventListener('change', function () { var country = String(select.value || 'ALL').toUpperCase(); if (country !== 'ALL') { region = 'ALL'; apply(); focusCountry(country); } else { apply(); resetMapView(); } });
    regionButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            region = String(button.getAttribute('data-yseo-atlas-region') || 'ALL').toUpperCase();
            select.value = 'ALL'; syncStyledCountrySelect(); apply(); resetMapView();
        });
    });
    paths.forEach(function (path) {
        path.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); selectCountry(String(path.getAttribute('data-country') || '').toUpperCase()); }
        });
    });
    apply();
});

// YSEO 5.77.2 — compact accessible WordPress content-list headings.
// Screen Options keeps the server-rendered text labels because only the actual table TH nodes are transformed.
document.addEventListener('DOMContentLoaded', function () {
    var columns = [
        {key: 'yseo_score', icon: 'dashicons-search', fallback: 'SEO'},
        {key: 'yseo_readability', icon: 'dashicons-editor-alignleft', fallback: 'Readability'},
        {key: 'yseo_inbound', icon: 'dashicons-arrow-down-alt', fallback: 'Incoming internal links'},
        {key: 'yseo_outbound', icon: 'dashicons-arrow-up-alt', fallback: 'Outgoing internal links'}
    ];
    columns.forEach(function (column) {
        document.querySelectorAll('.wp-list-table th.column-' + column.key).forEach(function (header) {
            var label = String(header.textContent || '').trim() || column.fallback;
            header.setAttribute('title', label);
            header.setAttribute('aria-label', label);
            header.textContent = '';
            var wrapper = document.createElement('span');
            wrapper.className = 'yseo-column-header-icon';
            var icon = document.createElement('span');
            icon.className = 'dashicons ' + column.icon;
            icon.setAttribute('aria-hidden', 'true');
            var sr = document.createElement('span');
            sr.className = 'screen-reader-text';
            sr.textContent = label;
            wrapper.appendChild(icon);
            wrapper.appendChild(sr);
            header.appendChild(wrapper);
        });
    });
});
