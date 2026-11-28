/* Shared progressive-enhancement form. Credentials and tickets live only in memory. */
(() => {
    'use strict';

    class RequestError extends Error {
        constructor(code, message) {
            super(message);
            this.code = code;
        }
    }

    function initialize(root) {
        const get = (name) => root.querySelector('[data-ach-' + name + ']');
        const strings = JSON.parse(root.dataset.strings);
        const form = get('form');
        const mobile = get('mobile');
        const password = get('password');
        const code = get('code');
        const submit = get('submit');
        const edit = get('edit');
        const resend = get('resend');
        const status = get('status');
        const desc = get('description');
        const current = get('current');
        const surface = root.dataset.surface;
        let model = null;
        let purpose = 'login';
        let phase = 'start';
        let ticket = '';
        let masked = '';
        let busy = false;
        let resendAt = 0;
        let expiresAt = 0;
        let timer = null;
        let forceLinkPassword = false;

        const announce = (message, error = false) => {
            status.textContent = message;
            status.dataset.error = error ? '1' : '0';
        };
        const setField = (field, input, visible, required = visible) => {
            field.hidden = !visible;
            input.disabled = !visible;
            input.required = required;
        };
        const setTitle = (title) => {
            get('title').textContent = title;
        };
        const safeUrl = (raw) => {
            const url = new URL(raw, window.location.href);
            if (url.origin !== window.location.origin || url.username || url.password) {
                throw new RequestError('invalid_context', strings.unavailable);
            }
            return url;
        };
        async function request(operation, values = {}) {
            const url = safeUrl(root.dataset.endpoint);
            const data = new URLSearchParams({ action: 'ach_wp_accounts', operation, context: root.dataset.context, ...values });
            let response;
            let result;
            try {
                response = await fetch(url, {
                    method: 'POST',
                    credentials: 'same-origin',
                    cache: 'no-store',
                    redirect: 'error',
                    headers: { 'X-ACh-Accounts': '1', 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
                    body: data,
                });
                result = await response.json();
            } catch (_) {
                throw new RequestError('network', strings.network);
            }
            if (!response.ok || result.success !== true) {
                throw new RequestError(result.data?.code || 'request_failed', result.data?.message || strings.unavailable);
            }
            return result.data;
        }

        function updateTimer() {
            const now = Date.now();
            const seconds = Math.max(0, Math.ceil((resendAt - now) / 1000));
            resend.disabled = busy || seconds > 0;
            resend.textContent = strings.resend + (seconds ? ' (' + seconds + ')' : '');
            if (phase === 'code' && expiresAt > 0 && now >= expiresAt) {
                announce(strings.expired, true);
                submit.disabled = true;
            } else {
                submit.disabled = busy;
            }
        }

        function lock(value) {
            busy = value;
            form.setAttribute('aria-busy', value ? 'true' : 'false');
            edit.disabled = value;
            mobile.readOnly = value;
            code.readOnly = value;
            password.readOnly = value;
            updateTimer();
        }

        function stopTimer() {
            if (timer !== null) {
                window.clearInterval(timer);
                timer = null;
            }
        }

        function mode(next, { focus = false, forcePassword = false } = {}) {
            stopTimer();
            purpose = next;
            forceLinkPassword = forcePassword;
            phase = 'start';
            ticket = '';
            masked = '';
            expiresAt = 0;
            resendAt = 0;
            code.value = '';
            password.value = '';
            get('masked').textContent = '';
            get('change').hidden = true;
            get('password-change').hidden = !(surface === 'profile' && next === 'step_up');
            setField(get('mobile-field'), mobile, next !== 'step_up');
            setField(get('password-field'), password, next === 'link' && (forcePassword || !model.can_link));
            setField(get('code-field'), code, false);
            form.hidden = false;
            submit.hidden = false;
            submit.textContent = strings.send;
            edit.hidden = true;
            resend.hidden = true;
            if (surface === 'login') {
                setTitle(model.registration_allowed ? strings.register : strings.login);
            }
            desc.textContent = next === 'login' ? strings.phone
                : next === 'step_up' ? strings.step : model.has_mobile ? strings.change : strings.link;
            current.hidden = next !== 'step_up';
            current.querySelector('bdi').textContent = model.masked_mobile || '';
            announce('');
            lock(false);
            if (focus) {
                (next === 'step_up' ? submit : mobile).focus();
            }
        }

        function codeMode(result) {
            phase = 'code';
            expiresAt = Date.now() + Math.max(0, Number(result.expires_in) || 0) * 1000;
            resendAt = Date.now() + Math.max(0, Number(result.retry_after) || 0) * 1000;
            if (result.state === 'failed') {
                phase = 'failed';
            }
            setField(get('mobile-field'), mobile, false);
            setField(get('password-field'), password, false);
            password.value = '';
            setField(get('code-field'), code, phase === 'code');
            get('masked').textContent = masked;
            current.hidden = true;
            desc.textContent = phase === 'failed' ? strings.failed : strings.sent;
            submit.hidden = phase === 'failed';
            submit.textContent = strings.continue;
            edit.hidden = purpose === 'step_up';
            resend.hidden = false;
            announce(result.state === 'uncertain' ? strings.uncertain : '');
            stopTimer();
            timer = window.setInterval(updateTimer, 1000);
            updateTimer();
            if (phase === 'code') {
                code.focus();
            } else {
                resend.focus();
            }
        }

        async function send() {
            // A lost HTTP response is not evidence of failed delivery. Keep the
            // ticket and retry that exact issue request, never silently resend.
            if (ticket === '') {
                const started = await request('start', { purpose, mobile: mobile.value, password: password.value });
                ticket = started.ticket;
                masked = started.masked_mobile;
                password.value = '';
                phase = 'issue';
            }
            const result = await request('issue', { ticket });
            codeMode(result);
        }

        function failure(error) {
            if (phase === 'issue') {
                // Every issue retry is bound to the original signed ticket.
                // Storage errors or lost responses are not evidence of no send.
                submit.textContent = strings.retry;
                mobile.readOnly = true;
                setField(get('password-field'), password, false);
                password.value = '';
                edit.hidden = purpose === 'step_up';
            }
            if (error.code === 'identity_changed' || error.code === 'invalid_context') {
                ticket = '';
                get('retry').hidden = false;
                submit.hidden = true;
                resend.hidden = true;
            }
            announce(error.message || strings.unavailable, true);
        }

        async function submitFlow(event) {
            event.preventDefault();
            if (busy || phase === 'failed') return;
            lock(true);
            try {
                if (phase === 'code') {
                    await request('complete', { ticket, code: code.value });
                    ticket = '';
                    code.value = '';
                    announce(strings.success);
                    window.location.assign(safeUrl(root.dataset.return).href);
                    return;
                }
                await send();
            } catch (error) {
                if (phase === 'code' && error.code === 'network') {
                    try {
                        const observed = await request('status');
                        if (purpose === 'login' && observed.authenticated && observed.qualified ||
                            purpose === 'step_up' && observed.qualified) {
                            window.location.assign(safeUrl(root.dataset.return).href);
                            return;
                        }
                    } catch (_) {
                        // No new send or verification request.
                    }
                    get('retry').hidden = false;
                }
                failure(error);
            } finally {
                lock(false);
                if (phase === 'issue') mobile.readOnly = true;
            }
        }

        async function repeat() {
            if (busy || Date.now() < resendAt) return;
            // A deliberate resend creates a fresh ticket. Native password
            // authority may be required again; do not retain it in the DOM.
            mode(purpose, { focus: true, forcePassword: forceLinkPassword });
            if (purpose === 'link' && (forceLinkPassword || !model.can_link)) return;
            lock(true);
            try {
                await send();
            } catch (error) {
                failure(error);
            } finally {
                lock(false);
                if (phase === 'issue') mobile.readOnly = true;
            }
        }

        function configureRecovery() {
            const recovery = get('recovery');
            if (!recovery || !model.authenticated || !model.managed_account) return;
            const nativeEmail = document.querySelector('form.woocommerce-EditAccountForm #account_email');
            if (nativeEmail) {
                nativeEmail.readOnly = true;
                nativeEmail.required = false;
                nativeEmail.removeAttribute('aria-required');
                const marker = nativeEmail.closest('p')?.querySelector('.required');
                if (marker) marker.hidden = true;
                nativeEmail.title = strings.manage_email;
            }
            recovery.hidden = !model.recent_proof;
            get('email').value = model.recovery_email || '';
            get('username').textContent = model.native_username || '';
        }

        async function recoverySubmit(event, operation) {
            event.preventDefault();
            const target = event.currentTarget;
            const button = target.querySelector('button[type="submit"]');
            if (button.disabled) return;
            const output = get('recovery-status');
            output.textContent = '';
            button.disabled = true;
            target.setAttribute('aria-busy', 'true');
            try {
                let values;
                if (operation === 'recovery_email') {
                    values = { email: get('email').value };
                } else {
                    const next = get('new-password');
                    const confirm = get('confirm-password');
                    if (next.value !== confirm.value) {
                        throw new RequestError('invalid_password', strings.password_mismatch);
                    }
                    values = { new_password: next.value, confirmation: confirm.value };
                }
                const result = await request(operation, values);
                output.textContent = result.outcome === 'already_confirmed' ? strings.email_confirmed
                    : operation === 'recovery_email' ? strings.email_requested : strings.password_saved;
                if (operation === 'recovery_password') {
                    get('new-password').value = '';
                    get('confirm-password').value = '';
                    if (result.session_qualified === false) {
                        get('retry').hidden = false;
                    }
                }
            } catch (error) {
                output.textContent = error.message || strings.unavailable;
            } finally {
                button.disabled = false;
                target.setAttribute('aria-busy', 'false');
            }
        }

        async function load() {
            if (root.dataset.enabled !== '1') return;
            get('retry').hidden = true;
            submit.hidden = false;
            form.hidden = true;
            announce('');
            try {
                model = await request('status');
                configureRecovery();
                if (model.authenticated) {
                    const native = get('native-link');
                    if (native) native.hidden = true;
                    if (surface === 'login') {
                        desc.textContent = strings.signed_in;
                        return;
                    }
                    if (surface === 'checkout' && model.qualified) {
                        desc.textContent = strings.verified;
                        const link = document.createElement('a');
                        link.className = 'button';
                        link.textContent = strings.continue_checkout;
                        link.href = safeUrl(root.dataset.return).href;
                        status.replaceChildren(link);
                        return;
                    }
                    if (surface === 'profile' && model.has_mobile && model.recent_proof) {
                        desc.textContent = strings.verified;
                        current.hidden = false;
                        current.querySelector('bdi').textContent = model.masked_mobile;
                        get('change').hidden = false;
                        return;
                    }
                    if (surface === 'checkout' && model.has_mobile && get('profile-link')) {
                        get('profile-link').hidden = false;
                    }
                    mode(model.has_mobile ? 'step_up' : 'link');
                } else if (surface !== 'profile') {
                    mode('login');
                }
            } catch (error) {
                announce(error.message || strings.unavailable, true);
                get('retry').hidden = false;
            }
        }

        form.addEventListener('submit', submitFlow);
        edit.addEventListener('click', () => {
            mode(purpose, { focus: true, forcePassword: forceLinkPassword });
        });
        resend.addEventListener('click', repeat);
        get('change').addEventListener('click', () => mode('link', { focus: true }));
        get('password-change').addEventListener('click', () => mode('link', { focus: true, forcePassword: true }));
        get('retry').addEventListener('click', load);
        if (get('recovery')) {
            get('recovery-email').addEventListener('submit', event => recoverySubmit(event, 'recovery_email'));
            get('recovery-password').addEventListener('submit', event => recoverySubmit(event, 'recovery_password'));
        }
        load();
    }

    function boot() {
        document.querySelectorAll('[data-ach-accounts]').forEach(initialize);
        document.querySelectorAll('.ach-accounts-popup').forEach((wrapper) => {
            const dialog = wrapper.querySelector('dialog');
            const open = wrapper.querySelector('[data-ach-popup-open]');
            const close = wrapper.querySelector('[data-ach-popup-close]');
            open.addEventListener('click', () => {
                if (typeof dialog.showModal === 'function') dialog.showModal();
                else dialog.setAttribute('open', '');
            });
            close.addEventListener('click', () => {
                if (typeof dialog.close === 'function') dialog.close();
                else dialog.removeAttribute('open');
            });
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
    else boot();
})();
