/* Arkon Forms runtime 2. Publish as an immutable asset; new behavior requires a new version. */
(() => {
    function boot() {
        document.querySelectorAll('form.ak-form4').forEach(form => {
            if (form.dataset.formsReady) return;
            let rules;
            try { rules = JSON.parse(form.dataset.formRules); } catch { return; }
            form.dataset.formsReady = 'true';
            let busy = false, pending = null;
            const key = form.elements.namedItem('requestKey');
            const button = form.querySelector('button[type=submit]');
            const label = button.textContent;
            const status = form.querySelector('[role=status]');
            const controls = () => Array.from(form.querySelectorAll('[data-field] input,[data-field] select,[data-field] textarea'));
            const freshKey = () => 'form-' + Date.now().toString(36) + '-' + Array.from(crypto.getRandomValues(new Uint32Array(3))).map(n => n.toString(36)).join('-');
            if (key && !key.value) key.value = freshKey();
            function value(id) {
                const inputs = Array.from(form.querySelectorAll('[name="fields[' + id + ']"],[name="fields[' + id + '][]"]'));
                if (inputs.some(c => c.type === 'checkbox' || c.type === 'radio')) return inputs.filter(c => c.checked).map(c => c.value);
                return inputs[0] ? inputs[0].value : '';
            }
            function match(c, vals) {
                if (!c) return true;
                const list = c.rules.map(r => {
                    const raw = vals[r.fieldId] ?? '', v = Array.isArray(raw) ? raw.join(', ') : String(raw), t = r.value;
                    switch (r.operator) {
                        case 'is': return Array.isArray(raw) ? raw.includes(t) : v === t;
                        case 'is_not': return Array.isArray(raw) ? !raw.includes(t) : v !== t;
                        case 'contains': return v.includes(t);
                        case 'not_contains': return !v.includes(t);
                        case 'greater': return v !== '' && Number.isFinite(Number(v)) && Number(v) > Number(t);
                        case 'less': return v !== '' && Number.isFinite(Number(v)) && Number(v) < Number(t);
                        case 'empty': return v === '';
                        case 'not_empty': return v !== '';
                        default: return false;
                    }
                });
                return c.mode === 'all' ? list.every(Boolean) : list.some(Boolean);
            }
            function update() {
                const visible = {}, visiting = new Set();
                function resolve(id) {
                    if (id in visible) return visible[id];
                    if (visiting.has(id)) return false;
                    visiting.add(id);
                    const rule = rules.find(r => r.id === id), vals = {};
                    if (!rule) return false;
                    for (const dep of rule.condition?.rules ?? []) vals[dep.fieldId] = resolve(dep.fieldId) ? value(dep.fieldId) : '';
                    visiting.delete(id);
                    return visible[id] = match(rule.condition, vals);
                }
                for (const rule of rules) {
                    const wrapper = Array.from(form.querySelectorAll('[data-field]')).find(e => e.dataset.field === rule.id);
                    if (!wrapper) continue;
                    const shown = resolve(rule.id);
                    wrapper.hidden = !shown;
                    wrapper.querySelectorAll('input,select,textarea').forEach(c => {
                        c.disabled = !shown || busy || !!pending;
                        c.required = shown && rule.required && rule.type !== 'checkboxes';
                    });
                }
                form.querySelectorAll('.ak-form4__row').forEach(row => row.hidden = !Array.from(row.querySelectorAll('[data-field]')).some(e => !e.hidden));
            }
            function clearErrors(wrapper = form) {
                wrapper.querySelectorAll('[data-server-error]').forEach(e => e.remove());
                wrapper.querySelectorAll('[data-form-description]').forEach(c => {
                    if (c.dataset.formDescription) c.setAttribute('aria-describedby', c.dataset.formDescription);
                    else c.removeAttribute('aria-describedby');
                    delete c.dataset.formDescription;
                    c.removeAttribute('aria-invalid');
                });
            }
            function showErrors(issues) {
                for (const issue of issues ?? []) {
                    const wrapper = Array.from(form.querySelectorAll('[data-field]')).find(e => e.dataset.field === issue.path.split('.')[0]);
                    if (!wrapper) continue;
                    const hint = document.createElement('p');
                    hint.dataset.serverError = 'true'; hint.className = 'ak-form4__hint';
                    hint.id = 'form-error-' + issue.path.replace(/[^a-z0-9_-]/gi, ''); hint.textContent = issue.message;
                    wrapper.append(hint);
                    wrapper.querySelectorAll('input,textarea,select').forEach(c => {
                        c.dataset.formDescription = c.getAttribute('aria-describedby') ?? '';
                        c.setAttribute('aria-invalid', 'true'); c.setAttribute('aria-describedby', hint.id);
                    });
                }
            }
            form.addEventListener('input', event => { clearErrors(event.target.closest('[data-field]') ?? form); update(); });
            form.addEventListener('change', update);
            update();
            form.addEventListener('submit', async event => {
                if (!window.fetch || !window.AbortController) return;
                event.preventDefault();
                if (busy) return;
                // A retry uses the original immutable data; typing cannot change an unconfirmed submission.
                pending ??= new FormData(form);
                busy = true; update(); clearErrors(); button.disabled = true; button.textContent = 'Submitting…'; status.textContent = '';
                const controller = new AbortController(), timer = setTimeout(() => controller.abort(), 20000);
                try {
                    const token = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);
                    const response = await fetch(form.action, { method: 'POST', body: pending, signal: controller.signal,
                        headers: { Accept: 'application/json', ...(token ? {'X-XSRF-TOKEN': decodeURIComponent(token[1])} : {}) }, credentials: 'same-origin' });
                    const result = await response.json();
                    if (!result.ok) {
                        if (response.status >= 500) throw new Error('Unconfirmed');
                        pending = null; showErrors(result.issues); status.textContent = result.message; status.tabIndex = -1; status.focus(); return;
                    }
                    pending = null;
                    if (result.redirect) { window.location.assign(result.redirect); return; }
                    status.textContent = result.message; form.reset(); key.value = freshKey();
                } catch {
                    status.textContent = 'The submission could not be confirmed. Your input is kept. Retry to check the same submission.';
                } finally {
                    clearTimeout(timer); busy = false; update(); button.disabled = false; button.textContent = pending ? 'Retry submission' : label;
                }
            });
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
    window.addEventListener('pageshow', () => document.querySelectorAll('form.ak-form4 button[type=submit]').forEach(b => b.disabled = false));
})();
