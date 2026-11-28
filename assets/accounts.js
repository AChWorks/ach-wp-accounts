/* Shared progressive-enhancement form. Credentials and tickets live only in memory. */
(() => {
    'use strict';

    class RequestError extends Error {
        constructor(code, message) {
            super(message);
            this.code = code;
        }
    }

    function clearPasswordInput(input, visibility) {
        if (!input) return;
        input.value = '';
        input.type = 'password';
        visibility?.setAttribute('aria-pressed', 'false');
    }
    const controllers = new WeakMap();
    function initialize(root) {
        if (controllers.has(root)) return;
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
        const initialPurpose=root.dataset.initialPurpose||'login';
        const initialCapabilities=JSON.parse(root.dataset.capabilities||'{}');
        let model = null;
        let purpose = initialPurpose;
        let phase = 'start';
        let ticket = '';
        let proof = '';
        let profileIntent = '';
        let disposed = false;
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
        let paymentRevision = 0;
        let pageAuthenticated = document.body.classList.contains('logged-in');
        controllers.set(root,{
            clearCredentials,
            dispose(){disposed=true;stopTimer();ticket='';proof='';clearCredentials();clearContinuations();}
        });
        const capabilities=()=>model?.capabilities||initialCapabilities;

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
        // Only compare native identifiers and consent, never raw card details,
        // arbitrary gateway data or provider credentials. Native offline
        // gateways have no extension-owned instrument state.
        const offlineMethods = new Set(['bacs', 'cheque', 'cod']);
        const nativeToken = value => {
            const token = typeof value === 'string' || typeof value === 'number' ? String(value) : '';
            return /^(?:[1-9][0-9]*|new)$/.test(token) ? token : null;
        };
        function formValues(native, accepts) {
            const fields = [];
            for (const field of native.elements) {
                if (!field.name || !accepts(field.name) || field.matches(':disabled') ||
                    ['button', 'submit', 'reset', 'image', 'file'].includes(field.type) ||
                    (['radio', 'checkbox'].includes(field.type) && !field.checked)) continue;
                if (field.multiple && field.options) {
                    for (const option of field.selectedOptions) fields.push([field.name, option.value]);
                } else fields.push([field.name, field.value]);
            }
            return fields;
        }
        function classicPayment(native) {
            const method = formValues(native, key => key === 'payment_method')[0]?.[1] || '';
            const tokenKey = 'wc-' + method + '-payment-token';
            const saveKey = 'wc-' + method + '-new-payment-method';
            const tokens = formValues(native, key => key === tokenKey).map(([, value]) => nativeToken(value));
            const consent = formValues(native, key => key === saveKey).map(([, value]) => value === 'true' ? true : null);
            const selected = [...native.elements].find(field => field.name === 'payment_method' && field.value === method);
            const box = selected?.closest('.wc_payment_method');
            const opaqueControls = box && (box.querySelector('iframe, [contenteditable="true"]') ||
                [...box.querySelectorAll('input,select,textarea')].some(field =>
                    !field.matches(':disabled') && !['payment_method', tokenKey, saveKey].includes(field.name)));
            const saved = tokens.length === 1 && tokens[0] !== null && tokens[0] !== 'new';
            return {
                value: [method, tokens, consent],
                comparable: !!method && !opaqueControls && !tokens.includes(null) && !consent.includes(null) &&
                    (offlineMethods.has(method) || saved)
            };
        }
        function blocksPayment(payment) {
            if (!payment) return { value: null, comparable: false };
            const method = payment.getActivePaymentMethod?.() || '';
            const tokenKey = 'wc-' + method + '-payment-token';
            const saveKey = 'wc-' + method + '-new-payment-method';
            const data = payment.getPaymentMethodData?.();
            const known = new Set(['token', 'payment_method', 'isSavedToken', tokenKey, saveKey]);
            const opaque = !data || typeof data !== 'object' || Object.keys(data).some(key => !known.has(key));
            // The generic Blocks data key "token" may instead belong to a
            // provider. Accept numeric IDs only when WC lists them as saved
            // native instruments for the selected gateway.
            const savedIds = new Set(Object.values(payment.getSavedPaymentMethods?.() || {}).flatMap(methods =>
                Array.isArray(methods) ? methods.filter(entry => entry.method?.gateway === method)
                    .map(entry => String(entry.tokenId)) : []));
            const savedToken = value => {
                const token = nativeToken(value);
                return token === 'new' || savedIds.has(token) ? token : null;
            };
            const active = payment.getActiveSavedToken?.();
            const token = active === '' ? '' : savedToken(active);
            const save = payment.getShouldSavePaymentMethod?.();
            const dataToken = data?.token === undefined ? '' : savedToken(data.token);
            const methodToken = data?.[tokenKey] === undefined ? '' : savedToken(data[tokenKey]);
            const saveData = data?.[saveKey] === undefined ? '' :
                ([true, 'true', false, 'false'].includes(data[saveKey]) ? String(data[saveKey]) : null);
            const savedFlag = data?.isSavedToken === undefined ? '' :
                (typeof data.isSavedToken === 'boolean' ? data.isSavedToken : null);
            const dataMethod = data?.payment_method === undefined ? '' : (data.payment_method === method ? method : null);
            const values = [method, token, typeof save === 'boolean' ? save : null, dataToken, methodToken, saveData, savedFlag, dataMethod];
            const saved = token !== '' && token !== null && token !== 'new';
            return {
                value: values,
                comparable: !!method && !opaque && !values.includes(null) && !payment.isExpressPaymentMethodActive?.() &&
                    !payment.getAvailablePaymentMethods?.()?.[method]?.savedTokenComponent && (offlineMethods.has(method) || saved)
            };
        }
        function snapshot() {
            if (kind === 'blocks') {
                const state = stores();
                if (!state) return null;
                const cart = state.cart.getCartData();
                const payment = blocksPayment(state.payment);
                return { value: JSON.stringify([state.cart.getCustomerData(), cart.totals,
                    cart.items?.map(i => [i.key, i.quantity, i.totals]), cart.coupons, cart.shippingRates,
                    payment.value, state.checkout?.getOrderNotes()]), comparable: payment.comparable };
            }
            const native = classicForm();
            if (!native) return null;
            const payment = classicPayment(native);
            const fields = formValues(native, key =>
                /^(billing_|shipping_|ship_to_different_address|order_comments|terms)/.test(key));
            return { value: JSON.stringify([fields, payment.value,
                native.querySelector('.woocommerce-checkout-review-order-table, .shop_table')?.textContent]),
                comparable: payment.comparable };
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
            if (get('use-account-mobile')) get('use-account-mobile').hidden=true;
            stopTimer();
            ticket = '';proof='';
            clearCredentials();clearContinuations();
            form.hidden = true;
            get('retry').hidden = true;
            if (allowed) root.hidden = true;
            else if (message) {
                clearSuccess();get('cancel').hidden=false;
                reveal();
                announce(message, true);
            }
            action?.resolve(allowed);
        }

        async function refreshCheckout(afterProof) {
            let paymentStateKnown = true;
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
                    // Woo's native cart subscription recalculates available
                    // methods asynchronously. Await that same native action
                    // before comparing the selected method/token.
                    const paymentActions = state.data.dispatch(state.wc.paymentStore);
                    if (typeof paymentActions.__internalUpdateAvailablePaymentMethods === 'function') {
                        await paymentActions.__internalUpdateAvailablePaymentMethods();
                    } else paymentStateKnown = false;
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
            return paymentStateKnown;
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
                const reconciled = needsReconcile;
                const paymentStateKnown = await refreshCheckout(reconciled);
                needsReconcile = false;
                pageAuthenticated = true;
                if (!pending) return;
                // Compare native choice and consent as well as checkout state.
                // For opaque gateway state, do not replay a pre-authentication
                // payment action across a session/cart refresh. The next
                // deliberate native attempt needs no additional OTP.
                const current = snapshot();
                if (pending.snapshot?.value !== current?.value || pending.mobile !== billingMobile() ||
                    pending.paymentRevision !== paymentRevision ||
                    (reconciled && (!paymentStateKnown || !pending.snapshot?.comparable || !current?.comparable))) {
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
            get('cancel').hidden=false;
            const attempt = { promise, resolve, snapshot: snapshot(), mobile: billingMobile(), userId: 0, paymentRevision };
            if (get('profile-link')) get('profile-link').hidden = true;
            if (get('use-account-mobile')) get('use-account-mobile').hidden=true;
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
                        if (get('use-account-mobile') && model.account_mobile && kind!=='order-pay') get('use-account-mobile').hidden=false;
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
            // Observe actual interaction in opaque payment UI without reading
            // its values. Native programmatic updates are compared via state.
            const paymentInput = event => {
                if (pending && event.isTrusted && !root.contains(event.target) && event.target.closest?.(
                    '#payment, .wc-block-checkout__payment-method, .wc-block-checkout__express-payment, .wc-block-components-payment-methods')) {
                    paymentRevision++;
                }
            };
            document.addEventListener('input', paymentInput, true);
            document.addEventListener('change', paymentInput, true);
            get('use-account-mobile')?.addEventListener('click',()=>{
                if(!model?.authenticated||!model.account_mobile||pending||refreshing)return;
                if(kind==='blocks'){
                    const state=stores(),action=state?.data.dispatch(state.wc.cartStore);
                    if(typeof action?.setBillingAddress!=='function'){announce(strings.checkout_mismatch,true);return;}
                    action.setBillingAddress({...state.cart.getCustomerData().billingAddress,phone:model.account_mobile});
                }else{
                    const input=classicForm()?.querySelector('[name="billing_phone"]');
                    if(!input)return;
                    input.value=model.account_mobile;
                    input.dispatchEvent(new Event('input',{bubbles:true}));
                    input.dispatchEvent(new Event('change',{bubbles:true}));
                }
                get('use-account-mobile').hidden=true;
                get('profile-link').hidden=true;
                announce(strings.changed_billing);
            });
            get('cancel').addEventListener('click', () => {
                if (busy || refreshing) return;
                finishPayment(false);
                root.hidden = true;
            });
            if (kind === 'blocks') {
                const observer = window.wc?.blocksCheckoutEvents?.checkoutEvents?.onCheckoutValidation;
                if (typeof observer === 'function') {
                    observer(async () => {
                        if (await paymentAttempt()) return { type: 'success' };
                        // WC's validation rejection returns checkout to idle,
                        // but does not end an express attempt. Abort only the
                        // pre-payment express state; never restore opaque
                        // provider data or restart payment on the user's behalf.
                        const state = stores();
                        if (state?.payment?.isExpressPaymentStarted?.()) {
                            const actions = state.data.dispatch(state.wc.paymentStore);
                            if (typeof actions.__internalSetActivePaymentMethod === 'function' &&
                                typeof actions.__internalSetPaymentIdle === 'function') {
                                actions.__internalSetActivePaymentMethod('');
                                actions.__internalSetPaymentIdle();
                            }
                        }
                        return { type: 'error' };
                    }, 1);
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
            root.setAttribute('data-ach-busy',value?'1':'0');
            form.setAttribute('aria-busy', value ? 'true' : 'false');
            // No competing flow transitions while a request is settling.
            for (const name of ['edit','cancel-profile','password-change','toggle-password','change','verify-profile','enroll','recover','retry','success-profile']) {
                const control = get(name);
                if (control) control.disabled = value;
            }
            if (checkout) get('cancel').disabled = value || refreshing;
            mobile.readOnly = value || (checkout && Boolean(billingMobile()));
            code.readOnly = value;
            password.readOnly = value;
            login.readOnly = value;
            for(const input of root.querySelectorAll('[data-ach-name],[data-ach-reset-new],[data-ach-reset-confirm]')) input.readOnly=value;
            updateTimer();
            syncPopupDismissal(root);
        }

        function stopTimer() {
            if (timer !== null) {
                window.clearInterval(timer);
                timer = null;
            }
        }

        function clearPassword() {
            clearPasswordInput(password,get('toggle-password'));
        }
        function clearCredentials() {
            clearPassword();
            code.value = '';
            for (const name of ['new-password','confirm-password','reset-new','reset-confirm']) {
                clearPasswordInput(get(name));
            }
        }
        function clearContinuations() {
            get('profile-fields').hidden=true;
            for(const field of root.querySelectorAll('[data-ach-name-field]')){
                field.hidden=true;const input=field.querySelector('input');
                input.disabled=true;input.required=false;input.value='';
            }
            get('reset-fields').hidden=true;
            for(const input of [get('reset-new'),get('reset-confirm')]){
                input.disabled=true;input.required=false;clearPasswordInput(input);
            }
        }
        function clearSuccess() {
            get('success').hidden=true;
            get('success-continue').hidden=true;
            get('title').hidden=false;desc.hidden=false;
        }
        function hideMethods() {
            for(const name of ['recover','reset','native-link','change','password-change','verify-profile','enroll','cancel-profile','retry']){
                if(get(name))get(name).hidden=true;
            }
        }
        function mode(next, { focus = false, forcePassword = false } = {}) {
            stopTimer();clearSuccess();clearContinuations();hideMethods();
            purpose = next;
            forceLinkPassword = forcePassword;
            const cap=capabilities();
            if (get('recover')) {
                get('recover').hidden = model.authenticated || !cap.first_link || !['recover','login'].includes(next);
                get('recover').textContent = next === 'recover' ? strings.return_mobile : strings.recover;
                get('reset').hidden = model.authenticated || !(cap.email_reset||cap.mobile_reset) || next==='password_reset';
            }
            if(get('native-link'))get('native-link').hidden=model.authenticated||next!=='login';
            phase = 'start';
            ticket = '';proof='';
            masked = '';
            expiresAt = 0;
            resendAt = 0;
            clearCredentials();
            get('masked').textContent = '';
            get('password-change').hidden = !(surface === 'profile' && next === 'step_up' && profileIntent==='change');
            get('cancel-profile').hidden=surface!=='profile';
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
                setTitle(next==='recover'?strings.recover_title:next==='password_reset'?strings.reset_title:
                    model.registration_allowed ? strings.register : strings.login);
            }
            desc.textContent = next === 'recover' ? strings.recover_description : next==='password_reset'?strings.reset_phone:next === 'login' ? strings.phone
                : next === 'step_up' ? strings.step : model.has_mobile ? strings.change : strings.link;
            current.hidden = next !== 'step_up';
            current.querySelector('bdi').textContent = model.masked_mobile || '';
            get('identity-icon').textContent='';
            if(surface==='profile'&&get('recovery'))get('recovery').hidden=true;
            announce('');
            lock(false);
            if (focus) (next === 'recover' ? login : next === 'step_up' ? submit : mobile).focus();
        }

        function codeMode(result) {
            phase = 'code';
            expiresAt = Date.now() + Math.max(0, Number(result.expires_in) || 0) * 1000;
            resendAt = Date.now() + Math.max(0, Number(result.retry_after) || 0) * 1000;
            if (result.state === 'failed') phase = 'failed';
            hideMethods();
            if(surface==='profile')get('cancel-profile').hidden=false;
            setField(get('mobile-field'), mobile, false);
            setField(get('password-field'), password, false);
            setField(get('login-field'), login, false);
            clearPassword();
            setField(get('code-field'), code, phase === 'code');
            get('masked').textContent = masked;
            current.hidden = true;
            desc.textContent = phase === 'failed' ? strings.failed : strings.sent;
            submit.hidden = phase === 'failed';
            submit.textContent = checkout ? strings.verify_pay : purpose==='password_reset'?strings.reset_code:strings.continue;
            edit.hidden = purpose === 'step_up';
            resend.hidden = false;
            announce(result.state === 'uncertain' ? strings.uncertain : '');
            stopTimer();
            timer = window.setInterval(updateTimer, 1000);
            updateTimer();
            if (phase === 'code') code.focus(); else resend.focus();
        }

        function continuationMode(result) {
            stopTimer();hideMethods();clearContinuations();
            proof=result.proof;phase=result.outcome==='profile_required'?'profile_fields':'reset_fields';
            expiresAt=0;resendAt=0;clearCredentials();
            for(const [name,input] of [['mobile',mobile],['password',password],['login',login],['code',code]])setField(get(name+'-field'),input,false);
            edit.hidden=true;resend.hidden=true;current.hidden=true;
            if(phase==='profile_fields'){
                if(!checkout)setTitle(strings.profile_required);
                desc.textContent=strings.profile_required;get('profile-fields').hidden=false;
                for(const node of root.querySelectorAll('[data-ach-name-field]')){
                    const field=result.fields?.[node.dataset.achNameField],input=node.querySelector('input');
                    node.hidden=!field;input.disabled=!field;input.required=field==='required'&&!checkout;
                    input.dataset.achRequired=field==='required'?'1':'0';
                }
                submit.textContent=checkout?strings.verify_pay:strings.continue;
                root.querySelector('[data-ach-name]:not(:disabled)')?.focus();
            }else{
                setTitle(strings.new_password);desc.textContent=strings.new_password;get('reset-fields').hidden=false;
                for(const input of [get('reset-new'),get('reset-confirm')]){input.disabled=false;input.required=true;}
                submit.textContent=strings.new_password;get('reset-new').focus();
            }
            announce('');
        }

        function successState(message, {redirect=false,reset=false}={}) {
            stopTimer();hideMethods();clearContinuations();
            phase='success';ticket='';proof='';clearCredentials();
            form.hidden=true;get('title').hidden=true;desc.hidden=true;current.hidden=true;
            if(get('recovery'))get('recovery').hidden=true;
            if(get('cancel'))get('cancel').hidden=true;
            announce('');
            const success=get('success');
            get('success-title').textContent=strings.success_title;
            get('success-message').textContent=message;
            success.hidden=false;success.focus();
            const link=get('success-continue');
            if(reset){
                // A reset does not sign in. Return to the ordinary login method.
                const loginUrl=safeUrl(root.dataset.loginUrl||root.dataset.return);
                link.href=loginUrl.href;link.textContent=strings.login;link.hidden=false;
            }else if(redirect){
                link.href=safeUrl(root.dataset.return).href;link.hidden=false;
                window.location.assign(link.href);
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
                clearPassword();
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
                clearPassword();
                edit.hidden = purpose === 'step_up';
            }
            if (error.code === 'existing_account_required' && capabilities().first_link) {
                mode('recover', { focus: true });
                announce(error.message, true);
                return;
            }
            if (error.code === 'identity_changed' || error.code === 'invalid_context' || (['profile_fields','reset_fields'].includes(phase)&&error.code==='invalid_proof')) {
                ticket = '';proof='';
                get('retry').hidden = false;
                submit.hidden = true;
                resend.hidden = true;
            }
            announce(error.message || strings.unavailable, true);
        }

        async function completeOutcome(result) {
            if(disposed)return;
            if(result.outcome==='profile_required'||result.outcome==='password_required'){
                continuationMode(result);return;
            }
            if(result.outcome==='password_reset'){
                successState(strings.reset_done,{reset:true});return;
            }
            if(surface==='profile'){
                if(profileIntent==='change' && purpose==='step_up'){
                    model=await request('status');mode('link',{focus:true});return;
                }
                if(profileIntent==='enroll' && purpose==='step_up'){
                    profileIntent='';await load();get('recovery').open=true;get('recovery').querySelector('summary').focus();return;
                }
                profileIntent='';successState(strings.profile_saved);return;
            }
            if(checkout){
                successState(strings.success);
                await confirmPayment(true);
            }else successState(strings.success,{redirect:true});
        }
        async function submitFlow(event) {
            event.preventDefault();
            if (busy || disposed || root.closest('[data-ach-auth-shell],[data-ach-embedded]')?.getAttribute('aria-busy')==='true' || ['failed','success'].includes(phase) || (checkout && !pending)) return;
            if (checkout) {
                for (const input of [mobile,login,password,code,...root.querySelectorAll('[data-ach-name]')]) {
                    if (!input.disabled && input.dataset.achRequired === '1' && !input.value.trim()) {
                        input.focus();announce(strings.required,true);return;
                    }
                }
            }
            lock(true);
            try {
                if (phase === 'code') {
                    await completeOutcome(await request('complete', {ticket,code:code.value}));return;
                }
                if(phase==='profile_fields'){
                    const names=Object.fromEntries([...root.querySelectorAll('[data-ach-name]:not(:disabled)')].map(input=>[input.dataset.achName,input.value]));
                    await completeOutcome(await request('continue_registration',{ticket,proof,...names}));return;
                }
                if(phase==='reset_fields'){
                    const next=get('reset-new'),confirm=get('reset-confirm');
                    if(next.value!==confirm.value)throw new RequestError('invalid_password',strings.password_mismatch);
                    await completeOutcome(await request('reset_password',{ticket,proof,new_password:next.value,confirmation:confirm.value}));return;
                }
                await send();
            } catch (error) {
                if (['code','profile_fields'].includes(phase) && error.code === 'network') {
                    try {
                        const observed = await request('status', checkout ? { mobile: billingMobile() || pending?.proofMobile || '' } :
                            purpose==='link'?{mobile:mobile.value}:{});
                        if (observed.authenticated && observed.qualified &&
                            (checkout || purpose === 'login' || purpose === 'step_up' || purpose === 'recover' ||
                                (purpose==='link'&&observed.has_mobile&&observed.phone_matches))) {
                            await completeOutcome({outcome:'verified'});return;
                        }
                    } catch (_) { /* No blind resend or mutation retry. */ }
                    get('retry').hidden = false;
                }
                if(phase==='reset_fields'&&error.code==='network'){
                    clearContinuations();ticket='';proof='';form.hidden=true;get('retry').hidden=false;
                    announce(strings.reset_uncertain,true);return;
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
            const cap=capabilities();
            const nativeEmail = document.querySelector('form.woocommerce-EditAccountForm #account_email');
            if (nativeEmail) {
                nativeEmail.readOnly = true;
                nativeEmail.required = false;
                nativeEmail.removeAttribute('aria-required');
                const marker = nativeEmail.closest('p')?.querySelector('.required');
                if (marker) marker.hidden = true;
                nativeEmail.title = strings.manage_email;
            }
            recovery.hidden = !model.recent_proof || !(cap.email_enrollment||cap.password_enrollment);
            get('recovery-email').hidden=!cap.email_enrollment;
            get('recovery-password').hidden=!cap.password_enrollment;
            get('enroll').hidden=Boolean(model.recent_proof)||!model.has_mobile||!(cap.email_enrollment||cap.password_enrollment);
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

        function profileView() {
            clearSuccess();clearContinuations();hideMethods();stopTimer();
            phase='profile';ticket='';proof='';clearCredentials();form.hidden=true;
            desc.textContent=model.has_mobile?(model.mobile_verified?strings.verified:strings.unverified):strings.no_mobile;
            current.hidden=!model.has_mobile;
            current.querySelector('bdi').textContent=model.masked_mobile||'';
            const icon=get('identity-icon');
            icon.textContent=model.mobile_verified?'✓':model.has_mobile?'!':'';
            icon.dataset.verified=model.mobile_verified?'1':'0';
            get('change').hidden=!model.has_mobile;
            get('verify-profile').hidden=Boolean(model.has_mobile&&model.mobile_verified);
            get('verify-profile').textContent=model.has_mobile?strings.verify_mobile:strings.add_mobile;
            configureRecovery();announce('');
        }
        async function load() {
            if (root.dataset.enabled !== '1'||disposed) return;
            get('retry').hidden = true;
            clearSuccess();submit.hidden = false;
            if(surface!=='login')form.hidden=true;
            submit.disabled=true;
            // The embedded shell may be visible before its status arrives.
            // Do not offer a transition which has no initialized model yet.
            const recover=get('recover');
            if(recover)recover.disabled=true;
            announce('');
            // Standalone guest document is emitted server-side, noncacheable,
            // and already has effective public method policy. No status
            // round trip is needed before rendering/using its initial form.
            if(surface==='login'&&root.closest('[data-ach-auth-shell]')&&!model){
                model={authenticated:false,registration_allowed:initialCapabilities.mobile_registration,capabilities:initialCapabilities};
                mode(initialPurpose);return;
            }
            try {
                model = await request('status');
                if(disposed)return;
                if (model.authenticated) {
                    const native = get('native-link');if(native)native.hidden=true;
                    if(surface==='login'){form.hidden=true;hideMethods();desc.textContent=strings.signed_in;return;}
                    if(surface==='profile'){profileView();return;}
                } else if (surface !== 'profile') mode(initialPurpose);
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
            if (busy || refreshing || !model || !capabilities().first_link || (checkout && !pending)) return;
            mode(purpose === 'recover' ? 'login' : 'recover', { focus: true });
        });
        get('change').addEventListener('click',()=>{
            profileIntent='change';
            mode(model.can_link?'link':'step_up',{focus:true});
        });
        get('verify-profile').addEventListener('click',()=>{profileIntent='';mode(model.has_mobile?'step_up':'link',{focus:true});});
        get('cancel-profile').addEventListener('click',()=>{
            if(busy||disposed)return;
            const target=profileIntent==='enroll'?'enroll':profileIntent==='change'?'change':'verify-profile';
            profileIntent='';profileView();
            const control=get(target);
            if(control&&!control.hidden)control.focus();
            else if(!get('change').hidden)get('change').focus();
        });
        get('enroll')?.addEventListener('click',()=>{profileIntent='enroll';mode('step_up',{focus:true});});
        get('success-profile')?.addEventListener('click',()=>load());
        get('toggle-password')?.addEventListener('click',event=>{
            const visible=password.type==='password';password.type=visible?'text':'password';
            event.currentTarget.setAttribute('aria-pressed',String(visible));
        });
        get('password-change').addEventListener('click', () => mode('link', { focus: true, forcePassword: true }));
        get('retry').addEventListener('click', () => {
            if (checkout) finishPayment(false, strings.checkout_changed);
            else if(surface==='login'&&model&&!model.authenticated)mode(initialPurpose,{focus:true});
            else load();
        });
        if (get('recovery')) {
            get('recovery-email').addEventListener('submit', event => recoverySubmit(event, 'recovery_email'));
            get('recovery-password').addEventListener('submit', event => recoverySubmit(event, 'recovery_password'));
        }
        if (checkout) installCheckout();
        else load();
    }

    // Ordinary method navigation uses the actual server-rendered shell.
    // Native extension-owned forms can keep their ordinary POST/navigation.
    let sequence=0;
    const navigations=new WeakSet();
    const drafts=new WeakMap();
    const shellSelector='[data-ach-auth-shell],[data-ach-embedded]';
    function local(raw){
        const url=new URL(raw,location.href);
        return url.origin===location.origin&&!url.username&&!url.password?url:null;
    }
    function shellFor(node){return node.closest(shellSelector);}
    function shellBusy(shell){
        return Boolean(shell&&(navigations.has(shell)||shell.querySelector('[data-ach-busy="1"]')));
    }
    function syncPopupDismissal(node){
        const popup=node.closest('.ach-accounts-popup');
        if(popup)popup.querySelector('[data-ach-popup-close]').disabled=shellBusy(popup.querySelector('[data-ach-embedded]'));
    }
    function clearNativeCredentials(form){
        for(const input of form.querySelectorAll('input[type="password"],input[autocomplete="current-password"],input[autocomplete="new-password"]')){
            clearPasswordInput(input);
        }
        for(const toggle of form.querySelectorAll('[data-ach-password-visibility]')){
            const input=document.getElementById(toggle.dataset.achPasswordVisibility);
            if(input&&form.contains(input))clearPasswordInput(input,toggle);
        }
    }
    function clearShellCredentials(shell){
        for(const root of shell.querySelectorAll('[data-ach-accounts]'))controllers.get(root)?.clearCredentials();
        for(const form of shell.querySelectorAll('form[data-ach-native-method]'))clearNativeCredentials(form);
    }
    function shellMessage(shell,message){
        let notice=shell.querySelector('[data-ach-navigation-status]');
        if(!notice){
            notice=document.createElement('p');notice.dataset.achNavigationStatus='1';
            notice.setAttribute('role','status');notice.className='ach-auth-message';
            shell.prepend(notice);
        }
        notice.hidden=!message;notice.textContent=message;
    }
    function shellStrings(shell){
        try{return {...JSON.parse(shell.dataset.achStrings||'{}'),...JSON.parse(shell.querySelector('[data-ach-accounts]')?.dataset.strings||'{}')};}
        catch(_){return {};}
    }
    function preserveDraft(shell){
        const previous=drafts.get(shell)||{};
        const mobile=shell.querySelector('[data-ach-mobile]'),login=shell.querySelector('[data-ach-login],input[autocomplete="username"]');
        if(mobile)previous.mobile=mobile.value;
        if(login&&!login.disabled)previous.login=login.value;
        return previous;
    }
    function replaceIds(node){
        const map=new Map(),prefix='ach-embedded-'+(++sequence)+'-';
        for(const field of node.querySelectorAll('[id]')){map.set(field.id,prefix+field.id);field.id=prefix+field.id;}
        for(const field of node.querySelectorAll('[for],[aria-labelledby],[aria-describedby],[data-ach-password-visibility]')){
            for(const attr of ['for','aria-labelledby','aria-describedby','data-ach-password-visibility']){
                if(field.hasAttribute(attr))field.setAttribute(attr,field.getAttribute(attr).split(/\s+/).map(x=>map.get(x)||x).join(' '));
            }
        }
    }
    function initializeWithin(shell){
        shell.querySelectorAll('[data-ach-accounts]').forEach(initialize);
    }
    function installShell(shell,parsed,draft){
        const embedded=shell.hasAttribute('data-ach-embedded');
        for(const root of shell.querySelectorAll('[data-ach-accounts]'))controllers.get(root)?.dispose();
        const replacement=document.importNode(parsed,true);
        if(embedded){
            replacement.querySelector('.ach-auth-brand')?.remove();
            replacement.querySelector('.ach-auth-legal')?.remove();
            // Retain the original container and unique IDs. Do not import
            // executable scripts or a second native document.
            replaceIds(replacement);
            shell.replaceChildren(...replacement.childNodes);
            shell.dataset.achStrings=replacement.dataset.achStrings||'{}';
            shell.dataset.achReturn=replacement.dataset.achReturn||'';
        }else{
            shell.replaceWith(replacement);shell=replacement;
        }
        drafts.set(shell,draft);
        initializeWithin(shell);
        const mobile=shell.querySelector('[data-ach-mobile]'),login=shell.querySelector('[data-ach-login],input[autocomplete="username"]');
        if(mobile&&draft.mobile)mobile.value=draft.mobile;
        if(login&&draft.login&&!login.value)login.value=draft.login;
        const title=shell.querySelector('h2');
        if(title){title.tabIndex=-1;title.focus({preventScroll:true});}
        return shell;
    }
    async function navigateShell(shell,raw,{body=null,push=true}={}){
        const url=local(raw);
        if(!url||shellBusy(shell))return;
        const draft=preserveDraft(shell),strings=shellStrings(shell),embedded=shell.hasAttribute('data-ach-embedded');
        const original=shell;
        navigations.add(shell);shell.setAttribute('aria-busy','true');syncPopupDismissal(shell);
        shellMessage(shell,strings.checking||'…');
        try{
            const response=await fetch(url,{method:body?'POST':'GET',body,credentials:'same-origin',cache:'no-store'});
            const destination=local(response.url);
            if(!response.ok||!destination)throw Error();
            const doc=new DOMParser().parseFromString(await response.text(),'text/html');
            const parsed=doc.querySelector('[data-ach-auth-shell]');
            if(!parsed){
                // A successful native login/reset may legitimately leave
                // the shell. Navigate by GET only, never replay the POST.
                window.location.assign(destination.href);return;
            }
            // If a native extension emitted scripts/extra interactive form
            // controls, preserve its actual document rather than injecting
            // executable code or silently bypassing its validation.
            if(parsed.querySelector('script,iframe,object,embed')||[...parsed.querySelectorAll('form[data-ach-native-method]')].some(form=>!simpleNativeForm(form))){
                if(!body){window.location.assign(destination.href);return;}
                throw Error();
            }
            shell=installShell(shell,parsed,draft);
            if(!embedded&&push)history.pushState({achAuth:true},'',destination.href);
        }catch(_){
            // An uncertain native POST is not retried. Offer a deliberate
            // safe GET to reconcile session or return to the form.
            shellMessage(shell,body?(strings.native_uncertain||strings.network):(strings.network||'…'));
            const notice=shell.querySelector('[data-ach-navigation-status]');
            if(notice){
                const link=document.createElement('a');link.href=body?(shell.dataset.achReturn||url.href):url.href;
                if(local(link.href)){link.textContent=strings.retry_status||'↻';notice.append(' ',link);}
            }
        }finally{
            navigations.delete(original);shell.removeAttribute('aria-busy');syncPopupDismissal(shell);
        }
    }
    function simpleNativeForm(form){
        if(form.dataset.achAsync==='0'||form.querySelector('iframe,script,object,embed,[contenteditable]'))return false;
        const supported=new Set(['username','password','rememberme','email','woocommerce-login-nonce','woocommerce-register-nonce',
            '_wp_http_referer','redirect','login','register','log','pwd','user_login','user_email','_ach_nonce','ach_accounts_native_form','wp-submit']);
        return [...form.elements].every(field=>!field.name||supported.has(field.name));
    }
    function boot() {
        document.querySelectorAll('[data-ach-accounts]').forEach(initialize);
        document.querySelectorAll('.ach-accounts-popup').forEach((wrapper) => {
            const dialog = wrapper.querySelector('dialog');
            const open = wrapper.querySelector('[data-ach-popup-open]');
            const close = wrapper.querySelector('[data-ach-popup-close]');
            const shell=wrapper.querySelector('[data-ach-embedded]');
            const modal=typeof dialog.showModal==='function';
            if(!modal)dialog.setAttribute('data-ach-popup-fallback','');
            let previous=null;
            let presented=false;
            const isOpen=()=>dialog.hasAttribute('open');
            const dismissed=()=>{
                // The close event is queued by native dialogs. Do not let an
                // earlier dismissal clear or refocus a newly opened popup.
                if(!presented||isOpen())return;
                presented=false;
                clearShellCredentials(shell);
                previous?.focus();
                previous=null;
            };
            const dismiss=()=>{
                // Closing a presentation is not a rollback of a dispatched
                // account operation. Keep the popup visible while it settles.
                if(!isOpen()||shellBusy(shell))return;
                if(modal&&typeof dialog.close==='function')dialog.close();
                else dialog.removeAttribute('open');
                // Clear secrets immediately. Keep safe drafts and the same
                // live challenge/continuation, subject to server expiry.
                dismissed();
            };
            open.addEventListener('click', () => {
                if(isOpen())return;
                previous=document.activeElement;
                presented=true;
                if(modal)dialog.showModal();
                else dialog.setAttribute('open','');
                syncPopupDismissal(shell);
                if(!modal)close.focus();
            });
            close.addEventListener('click',dismiss);
            dialog.addEventListener('cancel',event=>{
                event.preventDefault();dismiss();
            });
            dialog.addEventListener('keydown',event=>{
                if(event.key==='Escape'&&!modal){
                    event.preventDefault();dismiss();
                }
            });
            dialog.addEventListener('close',dismissed);
        });
        document.addEventListener('click',event=>{
            const visibility=event.target.closest('[data-ach-password-visibility]');
            if(visibility){
                const input=document.getElementById(visibility.dataset.achPasswordVisibility);
                if(input){const show=input.type==='password';input.type=show?'text':'password';visibility.setAttribute('aria-pressed',String(show));}
                return;
            }
            const link=event.target.closest('a[data-ach-method],a[data-ach-reset],a[data-ach-native-link]');
            if(!link||event.defaultPrevented||event.button!==0||event.metaKey||event.ctrlKey||event.altKey||event.shiftKey||
                link.target==='_blank'||!local(link.href))return;
            const shell=shellFor(link);
            if(!shell)return;
            event.preventDefault();navigateShell(shell,link.href);
        });
        document.addEventListener('submit',event=>{
            const form=event.target;
            if(event.defaultPrevented||!form.matches('form[data-ach-native-method]')||!simpleNativeForm(form))return;
            const shell=shellFor(form);
            if(!shell||!local(form.action))return;
            event.preventDefault();
            if(shellBusy(shell))return;
            const body=new FormData(form);
            if(event.submitter?.name)body.append(event.submitter.name,event.submitter.value);
            // Do not retain passwords in a draft, history, or cached HTML.
            clearNativeCredentials(form);
            navigateShell(shell,form.action,{body});
        });
        const standalone=document.querySelector('[data-ach-auth-shell]');
        if(standalone){
            history.replaceState({achAuth:true},'',location.href);
            window.addEventListener('popstate',event=>{
                const shell=document.querySelector('[data-ach-auth-shell]');
                if(shell&&event.state?.achAuth)navigateShell(shell,location.href,{push:false});
            });
        }
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
    else boot();
})();
