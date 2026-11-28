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
        const login = get('login');
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
        const checkout = surface === 'checkout';
        const kind = root.dataset.checkoutKind;
        let pending = null;
        let permit = false;
        let refreshing = false;
        let needsReconcile = false;
        let pageAuthenticated = document.body.classList.contains('logged-in');

        function classicForm() {
            return kind === 'order-pay' ? document.querySelector('form#order_review') : document.querySelector('form.checkout');
        }
        function stores() {
            const wc = window.wc?.wcBlocksData;
            const data = window.wp?.data;
            return wc && data ? { wc, data, cart: data.select(wc.cartStore),
                payment: data.select(wc.paymentStore), checkout: data.select(wc.checkoutStore) } : null;
        }
        function billingMobile() {
            if (kind === 'order-pay') return '';
            if (kind === 'blocks') {
                return stores()?.cart?.getCustomerData()?.billingAddress?.phone ||
                    document.querySelector('#billing-phone')?.value || '';
            }
            return classicForm()?.querySelector('[name="billing_phone"]')?.value || '';
        }
        function billingEmail() {
            if (kind === 'order-pay') return '';
            return kind === 'blocks' ? stores()?.cart?.getCustomerData()?.billingAddress?.email || ''
                : classicForm()?.querySelector('[name="billing_email"]')?.value || '';
        }
        function snapshot() {
            if (kind === 'blocks') {
                const state = stores();
                if (!state) return '';
                const cart = state.cart.getCartData();
                return JSON.stringify([state.cart.getCustomerData(), cart.totals, cart.items?.map(i => [i.key, i.quantity, i.totals]),
                    cart.coupons, cart.shippingRates, state.payment?.getActivePaymentMethod(), state.checkout?.getOrderNotes()]);
            }
            const native = classicForm();
            if (!native) return '';
            const fields = [...new FormData(native)].filter(([key]) =>
                /^(billing_|shipping_|ship_to_different_address|order_comments|payment_method|terms)/.test(key));
            return JSON.stringify([fields, native.querySelector('.woocommerce-checkout-review-order-table, .shop_table')?.textContent]);
        }
        function reveal() {
            root.hidden = false;
            // Keep the verification surface outside React's owned DOM.
            // Classic uses the native after-order-review hook outside replaced fragments.
            root.scrollIntoView({ block: 'nearest', behavior: 'auto' });
        }
        function finishPayment(allowed, message = '') {
            const action = pending;
            pending = null;
            if (get('recover')) get('recover').hidden = true;
            if (get('reset')) get('reset').hidden = true;
            if (get('profile-link')) get('profile-link').hidden = true;
            stopTimer();
            ticket = '';
            code.value = '';
            password.value = '';
            form.hidden = true;
            get('retry').hidden = true;
            if (allowed) root.hidden = true;
            else if (message) {
                reveal();
                announce(message, true);
            }
            action?.resolve(allowed);
        }

        async function refreshCheckout(afterProof) {
            const mobileValue = billingMobile() || pending?.proofMobile || '';
            const context = await request('checkout_context', { mobile: mobileValue });
            if (!pending || context.user_id !== pending.userId) {
                throw new RequestError('identity_changed', strings.checkout_changed);
            }
            const native = classicForm();
            const field = native?.querySelector('[name="woocommerce-process-checkout-nonce"]');
            if (field) field.value = context.checkout_nonce;
            const payNonce = native?.querySelector('[name="woocommerce-pay-nonce"]');
            if (payNonce) payNonce.value = context.order_pay_nonce;
            const params = window.wc_checkout_params;
            if (params) {
                params.update_order_review_nonce = context.update_order_review_nonce;
                params.apply_coupon_nonce = context.apply_coupon_nonce;
                params.remove_coupon_nonce = context.remove_coupon_nonce;
            }
            const apiFetch = window.wp?.apiFetch;
            if (apiFetch?.nonceMiddleware) apiFetch.nonceMiddleware.nonce = context.rest_nonce;
            if (kind === 'blocks') {
                if (typeof apiFetch?.setNonce !== 'function') throw new Error(strings.checkout_refresh_failed);
                apiFetch.setNonce({ Nonce: context.store_nonce, 'Nonce-Timestamp': context.nonce_timestamp });
                if (afterProof) {
                    const state = stores();
                    if (!state) throw new Error(strings.checkout_refresh_failed);
                    const current = state.cart.getCustomerData();
                    // Use Woo's native Store API recalculation, not a copied totals
                    // implementation; addresses remain in the active store.
                    const response = await apiFetch({ path: '/wc/store/v1/cart/update-customer', method: 'POST',
                        data: { billing_address: current.billingAddress, shipping_address: current.shippingAddress },
                        parse: false });
                    apiFetch.setNonce(response.headers);
                    apiFetch.setCartHash?.(response.headers);
                    const cart = await response.json();
                    const customerId = Number(response.headers.get('User-ID') || context.user_id);
                    if (customerId !== context.user_id) throw new Error(strings.checkout_changed);
                    const checkoutActions = state.data.dispatch(state.wc.checkoutStore);
                    // WC's native response adapter updates its customer id from
                    // the Store API User-ID response header using this action.
                    if (typeof checkoutActions.__internalSetCustomerId !== 'function') throw new Error(strings.checkout_refresh_failed);
                    checkoutActions.__internalSetCustomerId(customerId);
                    await state.data.dispatch(state.wc.cartStore).receiveCart(cart);
                }
            } else if (kind === 'classic' && afterProof) {
                const jq = window.jQuery;
                if (!jq || !params) throw new Error(strings.checkout_refresh_failed);
                await new Promise((resolve, reject) => {
                    const body = jq(document.body);
                    const timeout = window.setTimeout(() => {
                        body.off('updated_checkout.achAccountsRefresh', done);
                        reject(new Error(strings.checkout_refresh_failed));
                    }, 15000);
                    const done = (_event, data) => {
                        window.clearTimeout(timeout);
                        body.off('updated_checkout.achAccountsRefresh', done);
                        if (!data || data.has_errors === true || data.reload || (data.result === 'failure' && !data.messages)) {
                            reject(new Error(strings.checkout_refresh_failed));
                        } else resolve();
                    };
                    body.one('updated_checkout.achAccountsRefresh', done);
                    body.trigger('update_checkout');
                });
                const current = classicForm()?.querySelector('[name="woocommerce-process-checkout-nonce"]');
                if (current) current.value = context.checkout_nonce;
            }
        }

        async function confirmPayment(afterProof) {
            if (!pending || refreshing) return;
            refreshing = true;
            try {
                const observed = await request('status', { mobile: billingMobile() || pending?.proofMobile || '' });
                if (!pending || !observed.authenticated || !observed.qualified ||
                    (pending.userId > 0 && pending.userId !== observed.user_id)) {
                    finishPayment(false, strings.checkout_changed);
                    return;
                }
                pending.userId = observed.user_id;
                needsReconcile = needsReconcile || afterProof || !pageAuthenticated;
                await refreshCheckout(needsReconcile);
                needsReconcile = false;
                pageAuthenticated = true;
                if (!pending) return;
                // Never pay silently after changed account/cart/address/gateway/
                // totals. The native checkout stays visible for reconfirmation.
                if (pending.snapshot !== snapshot() || pending.mobile !== billingMobile()) {
                    finishPayment(false, strings.checkout_changed);
                } else {
                    finishPayment(true);
                }
            } catch (error) {
                finishPayment(false, strings.checkout_refresh_failed);
            } finally {
                refreshing = false;
            }
        }

        function paymentAttempt() {
            if (pending) return pending.promise;
            if (refreshing) return Promise.resolve(false);
            let resolve;
            const promise = new Promise(done => { resolve = done; });
            const attempt = { promise, resolve, snapshot: snapshot(), mobile: billingMobile(), userId: 0 };
            if (get('profile-link')) get('profile-link').hidden = true;
            get('retry').hidden = true;
            pending = attempt;
            (async () => {
                try {
                    if (root.dataset.enabled !== '1') throw new Error(strings.unavailable);
                    model = await request('status', { mobile: attempt.mobile });
                    if (pending !== attempt) return;
                    attempt.userId = model.user_id;
                    const native = get('native-link');
                    if (native) native.hidden = model.authenticated;
                    if (model.qualified) {
                        await confirmPayment(needsReconcile);
                        return;
                    }
                    reveal();
                    if (!model.phone_matches) {
                        finishPayment(false, strings.checkout_mismatch);
                        if (get('profile-link')) get('profile-link').hidden = false;
                        return;
                    }
                    mobile.value = attempt.mobile;
                    mode(model.authenticated ? (model.has_mobile ? 'step_up' : 'link') : 'login', { focus: true });
                    // Billing mobile is already visible in checkout. Reuse it
                    // rather than require the customer to type it twice.
                    if (attempt.mobile && purpose !== 'step_up') {
                        mobile.readOnly = true;
                    }
                    if (purpose === 'step_up' || (attempt.mobile && password.dataset.achRequired !== '1')) {
                        lock(true);
                        try { await send(); } catch (error) { failure(error); }
                        finally { lock(false); }
                    }
                } catch (error) {
                    finishPayment(false, error.message || strings.unavailable);
                }
            })();
            return promise;
        }

        function installCheckout() {
            get('cancel').addEventListener('click', () => {
                if (busy || refreshing) return;
                finishPayment(false);
                root.hidden = true;
            });
            if (kind === 'blocks') {
                const observer = window.wc?.blocksCheckoutEvents?.checkoutEvents?.onCheckoutValidation;
                if (typeof observer === 'function') {
                    observer(async () => ({
                        type: await paymentAttempt() ? 'success' : 'error'
                    }), 1);
                }
                // Unsupported JS adapters still have server enforcement;
                // expose the same verification after a direct button attempt.
                else document.addEventListener('click', event => {
                    if (!event.target.closest?.('.wc-block-components-checkout-place-order-button')) return;
                    if (permit) { permit = false; return; }
                    event.preventDefault();
                    event.stopImmediatePropagation();
                    const button = event.target.closest('.wc-block-components-checkout-place-order-button');
                    if (pending) return;
                    paymentAttempt().then(ok => { if (ok) { permit = true; button.click(); permit = false; } });
                }, true);
                return;
            }
            function intercept(event) {
                const native = classicForm();
                if (!native || event.target !== native) return;
                if (permit) return;
                event.preventDefault();
                event.stopImmediatePropagation?.();
                if (pending) return;
                const submitter = event.submitter;
                paymentAttempt().then(ok => {
                    if (!ok || !native.isConnected) return;
                    permit = true;
                    try {
                        if (kind === 'order-pay' || !window.jQuery) {
                            if (submitter && submitter.form === native) native.requestSubmit(submitter);
                            else native.requestSubmit();
                        } else window.jQuery(native).trigger('submit');
                    } finally { permit = false; }
                });
            }
            document.addEventListener('submit', intercept, true);
            const jq = window.jQuery;
            if (kind === 'classic' && jq && classicForm()) {
                // Woo also exposes programmatic checkout submission through
                // checkout_place_order (e.g. custom payment buttons).
                jq(classicForm()).on('checkout_place_order.achAccounts', () => {
                    if (permit) return true;
                    if (!pending) paymentAttempt().then(ok => {
                        if (!ok) return;
                        permit = true;
                        try { jq(classicForm()).trigger('submit'); } finally { permit = false; }
                    });
                    return false;
                });
            }
        }

        const announce = (message, error = false) => {
            status.textContent = message;
            status.dataset.error = error ? '1' : '0';
        };
        const setField = (field, input, visible, required = visible) => {
            field.hidden = !visible;
            input.disabled = !visible;
            input.required = required && !checkout;
            input.dataset.achRequired = required ? '1' : '0';
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
            if (checkout) get('cancel').disabled = value || refreshing;
            mobile.readOnly = value || (checkout && Boolean(billingMobile()));
            code.readOnly = value;
            password.readOnly = value;
            login.readOnly = value;
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
            if (get('recover')) {
                get('recover').hidden = model.authenticated;
                get('recover').textContent = next === 'recover' ? strings.return_mobile : strings.recover;
                get('reset').hidden = next !== 'recover';
            }
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
            setField(get('login-field'), login, next === 'recover');
            setField(get('password-field'), password, next === 'recover' || (next === 'link' && (forcePassword || !model.can_link)));
            if (next === 'recover' && checkout) login.value = billingEmail();
            setField(get('code-field'), code, false);
            form.hidden = false;
            submit.hidden = false;
            submit.textContent = strings.send;
            edit.hidden = true;
            resend.hidden = true;
            if (surface === 'login') {
                setTitle(model.registration_allowed ? strings.register : strings.login);
            }
            desc.textContent = next === 'recover' ? strings.recover_description : next === 'login' ? strings.phone
                : next === 'step_up' ? strings.step : model.has_mobile ? strings.change : strings.link;
            current.hidden = next !== 'step_up';
            current.querySelector('bdi').textContent = model.masked_mobile || '';
            announce('');
            lock(false);
            if (focus) {
                (next === 'recover' ? login : next === 'step_up' ? submit : mobile).focus();
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
            setField(get('login-field'), login, false);
            password.value = '';
            setField(get('code-field'), code, phase === 'code');
            get('masked').textContent = masked;
            current.hidden = true;
            desc.textContent = phase === 'failed' ? strings.failed : strings.sent;
            submit.hidden = phase === 'failed';
            submit.textContent = checkout ? strings.verify_pay : strings.continue;
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
                const entered = checkout && billingMobile() ? billingMobile() : mobile.value;
                if (checkout && pending) pending.proofMobile = entered;
                const started = await request('start', { purpose, mobile: entered, password: password.value,
                    login: purpose === 'recover' ? login.value : '', contact_email: checkout ? billingEmail() : '' });
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
            if (error.code === 'existing_account_required') {
                mode('recover', { focus: true });
                announce(error.message, true);
                return;
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
            if (busy || phase === 'failed' || (checkout && !pending)) return;
            if (checkout) {
                for (const input of [mobile, login, password, code]) {
                    if (!input.disabled && input.dataset.achRequired === '1' && !input.value.trim()) {
                        input.focus();
                        announce(strings.required, true);
                        return;
                    }
                }
            }
            lock(true);
            try {
                if (phase === 'code') {
                    await request('complete', { ticket, code: code.value });
                    ticket = '';
                    code.value = '';
                    announce(strings.success);
                    if (checkout) await confirmPayment(true);
                    else window.location.assign(safeUrl(root.dataset.return).href);
                    return;
                }
                await send();
            } catch (error) {
                if (phase === 'code' && error.code === 'network') {
                    try {
                        const observed = await request('status', checkout ? { mobile: billingMobile() || pending?.proofMobile || '' } : {});
                        if (observed.authenticated && observed.qualified &&
                            (checkout || purpose === 'login' || purpose === 'step_up' || purpose === 'recover')) {
                            if (checkout) await confirmPayment(true);
                            else window.location.assign(safeUrl(root.dataset.return).href);
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
            if (purpose === 'recover' || (purpose === 'link' && (forceLinkPassword || !model.can_link))) return;
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
                    if (surface === 'profile' && model.has_mobile && model.recent_proof) {
                        desc.textContent = strings.verified;
                        current.hidden = false;
                        current.querySelector('bdi').textContent = model.masked_mobile;
                        get('change').hidden = false;
                        return;
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

        if (checkout) {
            submit.addEventListener('click', submitFlow);
            form.addEventListener('keydown', event => {
                if (event.key === 'Enter' && event.target instanceof HTMLInputElement) submitFlow(event);
            });
        } else form.addEventListener('submit', submitFlow);
        edit.addEventListener('click', () => {
            mode(purpose, { focus: true, forcePassword: forceLinkPassword });
        });
        resend.addEventListener('click', repeat);
        get('recover')?.addEventListener('click', () => {
            if (busy || refreshing || (checkout && !pending)) return;
            mode(purpose === 'recover' ? 'login' : 'recover', { focus: true });
        });
        get('change').addEventListener('click', () => mode('link', { focus: true }));
        get('password-change').addEventListener('click', () => mode('link', { focus: true, forcePassword: true }));
        get('retry').addEventListener('click', () => {
            if (checkout) finishPayment(false, strings.checkout_changed);
            else load();
        });
        if (get('recovery')) {
            get('recovery-email').addEventListener('submit', event => recoverySubmit(event, 'recovery_email'));
            get('recovery-password').addEventListener('submit', event => recoverySubmit(event, 'recovery_password'));
        }
        if (checkout) installCheckout();
        else load();
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
