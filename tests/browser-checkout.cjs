/* Actual isolated HTTPS WordPress + WooCommerce + Chromium integration. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const {chromium} = require('playwright');
const base = process.env.ACH_BROWSER_URL;
const dir = process.env.ACH_TEST_DIR;
assert.match(base, /^https:\/\/127\.0\.0\.1:\d+$/);
const fixture = JSON.parse(fs.readFileSync(path.join(dir,'browser-fixture.json'),'utf8'));
const php = process.env.PHP_BIN || 'php';
const command = (mode,...args) => execFileSync(php,[path.join(__dirname,'browser-fixture.php'),mode,...args.map(String)],{encoding:'utf8'});
Object.assign(fixture,JSON.parse(command('routes')));
const otpFile = path.join(dir,'browser-otp.json');
const codeField = '[data-ach-code]';
const otpRoot = '[data-ach-accounts][data-surface="checkout"]';
const addresses = {first_name:'Fixture',last_name:'Customer',address_1:'123 Main Street',city:'Los Angeles',postcode:'90001'};
async function otp(page,mobile) {
    await page.locator(codeField).waitFor({state:'visible',timeout:20000});
    const record = JSON.parse(fs.readFileSync(otpFile,'utf8'));
    assert.ok([mobile,'+98'+mobile.slice(1)].includes(record.mobile),'Wrong synthetic SMS destination');
    await page.locator(codeField).fill(record.code);
    await page.locator('[data-ach-submit]').click();
}
async function setFields(page,fields) {
    for (const [selector,value] of Object.entries(fields)) {
        await page.locator(selector).fill(value);
    }
}
async function addCart(page,checkout) {
    await page.goto(base+'/?add-to-cart='+fixture.product);
    await page.goto(checkout);
}
async function paid(page,phone,email,priorUrl,selector='#place_order, .wc-block-components-checkout-place-order-button') {
    const changed = page.locator(otpRoot+' [data-ach-status]');
    // A changed total/customer/cart may deliberately require confirmation.
    await page.waitForFunction(({url,root})=>location.href!==url ||
        (document.querySelector(root+' [data-ach-status]')?.dataset.error==='1'),
        {url:priorUrl,root:otpRoot},{timeout:20000}).catch(()=>{});
    if (page.url()===priorUrl) {
        const message=await changed.textContent();
        console.log('Checkout reconciliation:',message);
        assert.match(message,/checkout has changed|review your order/i);
        const button=page.locator(selector);
        await button.click();
    }
    await page.waitForURL(/order-received/, {timeout:25000});
    const orders=JSON.parse(command('state'));
    const order=orders.find(o=>o.phone===phone && o.email===email && o.status==='on-hold');
    assert.ok(order && order.customer>0 && order.items===1 && order.gateway==='bacs', 'native order not paid by fixture gateway');
    return order;
}
async function signIn(page,mobile) {
    await page.locator('[data-ach-mobile]').fill(mobile);
    await page.locator('[data-ach-submit]').click();
    await otp(page,mobile);
    await page.locator('.woocommerce-MyAccount-navigation').waitFor({state:'visible',timeout:20000});
}
async function standalone(page,url) {
    await page.goto(url);
    await page.locator('.ach-auth-shell [data-ach-mobile]').waitFor({state:'visible'});
    assert.equal(await page.locator('header,footer,aside,form.woocommerce-form-login,form.woocommerce-form-register').count(),0);
    assert.equal(await page.locator('a[href*="ach_accounts_method=password"],a[href*="ach_accounts_method=email"]').count(),0);
}
async function requireReconfirmation(page,url,email) {
    await page.waitForFunction(()=>document.querySelector('[data-ach-status]')?.textContent.includes('checkout has changed'),
        {},{timeout:25000});
    assert.equal(page.url(),url,'Payment continued without reconfirmation');
    assert.ok(!JSON.parse(command('state')).some(o=>o.email===email&&o.calls>0),'Payment executed before reconfirmation');
}
async function tokenPaid(page,button,email,expectedToken,save=false,id=0) {
    const sms=fs.readFileSync(otpFile,'utf8');
    await page.locator(button).click();
    await page.waitForURL(/order-received/,{timeout:25000});
    const order=JSON.parse(command('state')).find(o=>id?o.id===id:o.email===email&&o.gateway==='fixture_token'&&o.status==='on-hold');
    assert.ok(order,'Synthetic native token order not paid');
    assert.equal(order.token,String(expectedToken));
    assert.equal(order.save,save);
    assert.equal(order.calls,1,'Payment duplicated');
    assert.equal(fs.readFileSync(otpFile,'utf8'),sms,'Qualified customer was asked for another OTP');
}
(async()=>{
    const browser=await chromium.launch({headless:true});
    try {
        const context=await browser.newContext({ignoreHTTPSErrors:true,viewport:{width:390,height:844}});
        const page=await context.newPage();
        page.on('pageerror',e=>console.log('Browser exception:',e.message));
        await page.goto(fixture.account);
        await page.locator('[data-ach-mobile]').waitFor({state:'visible'});
        assert.equal(await page.locator('header,footer,aside,form.woocommerce-form-login,form.woocommerce-form-register').count(),0,'theme or duplicate native form on auth page');
        assert.equal(await page.locator('a[href*="ach_accounts_method=password"],a[href*="ach_accounts_method=email"]').count(),0,'disabled methods visible');
        assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true,'mobile auth page overflow');
        await page.goto(base+'/wp-login.php?redirect_to='+encodeURIComponent(base+'/wp-admin/'));
        await page.locator('#user_login').waitFor();
        console.log('PASS: standalone narrow auth page and native privileged access');
        // Direct native destinations use the same guest shell and return to
        // the requested account content after authentication.
        const routes=await browser.newContext({ignoreHTTPSErrors:true,viewport:{width:390,height:844}});
        const route=await routes.newPage();
        for (const target of [fixture.orders,fixture.downloads,fixture.editAccount]) {
            await standalone(route,target);
            assert.equal(await route.locator('.ach-auth-shell [data-ach-accounts]').getAttribute('data-return'),target);
            const disabled=new URL(target); disabled.searchParams.set('ach_accounts_method','password');
            await standalone(route,disabled.href);
            const email=new URL(target); email.searchParams.set('ach_accounts_method','email');
            await standalone(route,email.href);
        }
        await route.goto(fixture.lostPassword);
        await route.locator('form.woocommerce-ResetPassword').waitFor({state:'visible'});
        assert.equal(await route.locator('.ach-auth-shell').count(),0);
        await route.goto(base+'/wp-login.php?action=lostpassword');
        await route.locator('#lostpasswordform').waitFor({state:'visible'});
        await standalone(route,fixture.orders);
        await signIn(route,'09125557777');
        assert.equal(route.url(),fixture.orders,'Deep link lost after OTP');
        for (const target of [fixture.downloads,fixture.editAccount]) {
            await route.goto(target);
            await route.locator('.woocommerce-MyAccount-navigation').waitFor({state:'visible'});
            assert.equal(await route.locator('.ach-auth-shell').count(),0,'Logged-in native account replaced');
        }
        await route.locator('.woocommerce-MyAccount-navigation-link--customer-logout a').click();
        await route.locator('.ach-auth-shell [data-ach-mobile]').waitFor({state:'visible'});
        await routes.close();
        // Native WC email-confirmation must still run, with no password-only
        // guest login exception when the public password method is disabled.
        for (const enabled of [true,false]) {
            const confirmation=JSON.parse(command(enabled?'email-confirm-enabled':'email-confirm-disabled'));
            const mailContext=await browser.newContext({ignoreHTTPSErrors:true,viewport:{width:390,height:844}});
            const mail=await mailContext.newPage();
            await mail.goto(confirmation.url);
            assert.equal(JSON.parse(command('email-confirm-state',confirmation.id)),false,'Opening link confirmed email');
            if (!enabled) {
                await mail.locator('.ach-auth-shell [data-ach-mobile]').waitFor({state:'visible'});
                assert.equal(await mail.locator('#wc_confirm_password').count(),0,'Guest password-only confirmation presented');
                assert.equal(await mail.locator('.ach-auth-shell [data-ach-accounts]').getAttribute('data-return'),confirmation.url);
                await mail.locator('[data-ach-mobile]').fill(confirmation.mobile);
                await mail.locator('[data-ach-submit]').click();
                await otp(mail,confirmation.mobile);
            }
            await mail.locator('form.woocommerce-form-confirm-email #wc_confirm_password').waitFor({state:'visible',timeout:20000});
            assert.equal(await mail.locator('.ach-auth-shell').count(),0,'Native confirmation replaced');
            assert.equal(JSON.parse(command('email-confirm-state',confirmation.id)),false);
            await mail.locator('#wc_confirm_password').fill(confirmation.password);
            await mail.locator('button[name="wc_confirm_email"]').click();
            await mail.waitForURL(/wc_verify_notice=confirmed/,{timeout:20000});
            await mail.locator('.woocommerce-MyAccount-navigation').waitFor({state:'visible'});
            assert.equal(JSON.parse(command('email-confirm-state',confirmation.id)),true,'Native email was not confirmed');
            await mailContext.close();
        }
        console.log('PASS: direct account destinations and OTP return, disabled native methods, native password reset/logout and native email confirmation in both password policies');

        command('classic');
        await addCart(page,fixture.classic);
        await page.locator('form.checkout #place_order').waitFor();
        assert.equal(await page.locator(otpRoot).isVisible(),false,'OTP displayed before payment attempt');
        await page.locator('#billing_country').selectOption('US');
        await page.locator('#billing_state').selectOption('CA');
        const mobile='09125557771',email='classic@example.invalid';
        const fields=Object.fromEntries(Object.entries(addresses).map(([k,v])=>['#billing_'+k,v]));
        await setFields(page,{...fields,'#billing_phone':mobile,'#billing_email':email});
        await page.locator('#order_comments').fill('Keep my checkout notes');
        await page.locator('a.showcoupon').click();
        await page.locator('form.checkout_coupon #coupon_code').fill('fixture5');
        await page.locator('form.checkout_coupon button[name="apply_coupon"]').click();
        await page.locator('.cart-discount.coupon-fixture5').waitFor({state:'visible'});
        await page.locator('#payment_method_bacs').check();
        await page.waitForFunction(()=>!document.querySelector('form.checkout .blockUI'),{},{timeout:20000});
        const before=page.url();
        await page.locator('#place_order').click();
        await page.locator(codeField).waitFor({state:'visible'});
        const sentCode=JSON.parse(fs.readFileSync(otpFile,'utf8')).code;
        await page.locator(codeField).fill((sentCode[0]==='0'?'1':'0')+sentCode.slice(1));
        await page.locator('[data-ach-submit]').click();
        await page.waitForFunction(()=>document.querySelector('[data-ach-status]')?.dataset.error==='1');
        assert.equal(page.url(),before);
        await page.locator('#order_comments').fill('Changed after OTP started');
        await otp(page,mobile);
        await page.waitForFunction(()=>document.querySelector('[data-ach-status]')?.textContent.includes('checkout has changed'));
        assert.equal(page.url(),before,'verification reloaded checkout');
        assert.equal(await page.locator('#billing_phone').inputValue(),mobile);
        assert.equal(await page.locator('#billing_email').inputValue(),email);
        assert.equal(await page.locator('#order_comments').inputValue(),'Changed after OTP started');
        assert.equal(await page.locator('#payment_method_bacs').isChecked(),true);
        assert.equal(await page.locator('.cart-discount.coupon-fixture5').count(),1);
        const order=await paid(page,mobile,email,before);
        assert.equal(order.total,'20.00');
        assert.equal(order.notes,'Changed after OTP started');
        console.log('PASS: Classic guest inline OTP, wrong-code retry, changed checkout requires reconfirmation, native fields/coupon/gateway/session preserved and successful order');
        const noRepeat=JSON.parse(command('order-pay'));
        await page.goto(noRepeat.url);
        await page.locator('form#order_review #place_order').waitFor({state:'visible'});
        const priorSms=fs.readFileSync(otpFile,'utf8');
        await page.locator('#place_order').click();
        await page.waitForURL(/order-received/,{timeout:25000});
        assert.equal(fs.readFileSync(otpFile,'utf8'),priorSms,'Default verified login sent another SMS');
        assert.equal(JSON.parse(command('state')).find(o=>o.id===noRepeat.id).status,'on-hold');
        console.log('PASS: default verified native login with no session OTP pays without another SMS');
        const payable=JSON.parse(command('order-pay-strict'));
        await page.goto(payable.url);
        await page.locator('form#order_review #place_order').waitFor({state:'visible'});
        assert.equal(await page.locator(otpRoot).isVisible(),false);
        await page.locator('#place_order').click();
        await otp(page,mobile);
        await page.waitForURL(/order-received/,{timeout:25000});
        const historical=JSON.parse(command('state')).find(o=>o.id===payable.id);
        assert.equal(historical.customer,order.customer);
        assert.equal(historical.phone,'09129999999');
        assert.equal(historical.status,'on-hold');
        console.log('PASS: actual native order-pay browser step-up, ownership and historical billing preserved');
        await context.close();
        command('default-checkout-policy');
        command('blocks');
        const blockContext=await browser.newContext({ignoreHTTPSErrors:true,viewport:{width:1280,height:900}});
        const block=await blockContext.newPage();
        block.on('pageerror',e=>console.log('Blocks exception:',e.message));
        await addCart(block,fixture.blocks);
        await block.locator('.wc-block-components-checkout-place-order-button').waitFor({state:'visible',timeout:30000});
        assert.equal(await block.locator(otpRoot).isVisible(),false,'Blocks OTP displayed before payment');
        assert.equal(await block.evaluate(()=>typeof window.wc?.blocksCheckoutEvents?.checkoutEvents?.onCheckoutValidation),'function','Native Blocks validation hook unavailable');
        await block.locator('#billing-country').selectOption('US');
        await block.locator('#billing-state').selectOption('CA');
        const blockMobile='09125557772',blockEmail='blocks@example.invalid';
        const blockFields=Object.fromEntries(Object.entries(addresses).map(([k,v])=>['#billing-'+k,v]));
        await setFields(block,{...blockFields,'#billing-phone':blockMobile,'#email':blockEmail});
        const blockBefore=block.url();
        await block.locator('.wc-block-components-checkout-place-order-button').click();
        await otp(block,blockMobile);
        const blockOrder=await paid(block,blockMobile,blockEmail,blockBefore);
        assert.equal(blockOrder.total,'25.00');
        console.log('PASS: Blocks guest inline OTP, native Store API cart/customer/nonce reconciliation and successful order');
        await blockContext.close();
        const expressContext=await browser.newContext({ignoreHTTPSErrors:true,viewport:{width:1280,height:900}});
        const express=await expressContext.newPage();
        express.on('pageerror',e=>console.log('Express exception:',e.message));
        await addCart(express,fixture.blocks);
        await express.locator('#fixture-express-payment').waitFor({state:'visible',timeout:15000});
        await express.locator('#billing-country').selectOption('US');
        await express.locator('#billing-state').selectOption('CA');
        const expressMobile='09125557774',expressEmail='express@example.invalid';
        await setFields(express,{...blockFields,'#billing-phone':expressMobile,'#email':expressEmail});
        const expressBefore=express.url();
        await express.locator('#fixture-express-payment').click();
        await express.locator(codeField).waitFor({state:'visible',timeout:15000});
        assert.equal(express.url(),expressBefore);
        assert.ok(!JSON.parse(command('state')).some(o=>o.email===expressEmail&&o.status==='on-hold'),'Express paid before proof');
        await otp(express,expressMobile);
        await requireReconfirmation(express,expressBefore,expressEmail);
        const expressSms=fs.readFileSync(otpFile,'utf8');
        const expressOrder=await paid(express,expressMobile,expressEmail,expressBefore,'#fixture-express-payment');
        assert.equal(expressOrder.total,'25.00');
        assert.equal(fs.readFileSync(otpFile,'utf8'),expressSms,'Express retry sent another OTP');
        assert.equal(JSON.parse(command('state')).filter(o=>o.email===expressEmail&&o.status==='on-hold').length,1);
        console.log('PASS: native Blocks express abort/restart after proof without stale provider state, extra OTP or duplicate payment');
        await expressContext.close();
        // Exercise native saved token fields in Classic and order-pay,
        // and native saved token selectors in the Blocks payment store.
        const tokens=JSON.parse(command('token-fixture'));
        const tokenContext=await browser.newContext({ignoreHTTPSErrors:true,viewport:{width:1280,height:900}});
        const tokenPage=await tokenContext.newPage();
        tokenPage.on('pageerror',e=>console.log('Token exception:',e.message));
        await tokenPage.goto(fixture.account);
        await signIn(tokenPage,mobile);
        const tokenSelector=id=>'[name="wc-fixture_token-payment-token"][value="'+id+'"]';
        async function classicTokenCheckout(email,id) {
            command('classic');
            command('strict-checkout-policy');
            await addCart(tokenPage,fixture.classic);
            await tokenPage.locator('form.checkout #place_order').waitFor();
            await tokenPage.locator('#billing_country').selectOption('US');
            await tokenPage.locator('#billing_state').selectOption('CA');
            await setFields(tokenPage,{...fields,'#billing_phone':mobile,'#billing_email':email});
            await tokenPage.locator('#payment_method_fixture_token').check();
            await tokenPage.locator(tokenSelector(id)).check();
            await tokenPage.waitForFunction(()=>!document.querySelector('form.checkout .blockUI'),{},{timeout:20000});
            assert.equal(await tokenPage.locator(tokenSelector(id)).isChecked(),true);
            const url=tokenPage.url();
            await tokenPage.locator('#place_order').click();
            await tokenPage.locator(codeField).waitFor({state:'visible'});
            return url;
        }
        let tokenEmail='token-change@example.invalid';
        let tokenUrl=await classicTokenCheckout(tokenEmail,tokens[0]);
        await tokenPage.locator(tokenSelector(tokens[1])).check();
        await otp(tokenPage,mobile);
        await requireReconfirmation(tokenPage,tokenUrl,tokenEmail);
        assert.equal(await tokenPage.locator(tokenSelector(tokens[1])).isChecked(),true);
        await tokenPaid(tokenPage,'#place_order',tokenEmail,tokens[1]);
        console.log('PASS: Classic same-gateway saved-instrument change requires deliberate payment, no second OTP or duplicate');
        // Order-pay does not change historic order fields. Consent can be
        // changed independently of the selected payment method.
        const consentOrder=JSON.parse(command('order-pay-token'));
        await tokenPage.goto(consentOrder.url);
        await tokenPage.locator('#payment_method_fixture_token').check();
        await tokenPage.locator(tokenSelector('new')).check();
        const save=tokenPage.locator('[name="wc-fixture_token-new-payment-method"]');
        await save.uncheck();
        await tokenPage.locator('#place_order').click();
        await tokenPage.locator(codeField).waitFor({state:'visible'});
        await save.check();
        await otp(tokenPage,mobile);
        await requireReconfirmation(tokenPage,consentOrder.url,'historical@example.invalid');
        assert.equal(await save.isChecked(),true);
        await tokenPaid(tokenPage,'#place_order','historical@example.invalid','new',true,consentOrder.id);
        console.log('PASS: native order-pay save-instrument consent change requires reconfirmation');
        // Blocks native saved instruments use the WC payment-store token
        // and method data, not arbitrary extension card fields.
        command('blocks');
        command('strict-checkout-policy');
        await addCart(tokenPage,fixture.blocks);
        const blockButton='.wc-block-components-checkout-place-order-button';
        const savedSelector=id=>'input[id^="radio-control-wc-payment-method-saved-tokens"][value="'+id+'"]';
        await tokenPage.locator(blockButton).waitFor({state:'visible',timeout:30000});
        // Logged-in customers with a populated address use Woo's collapsed
        // address card. Open its native editor, not hidden controls.
        if (!await tokenPage.locator('#billing-country').isVisible()) {
            await tokenPage.locator('[aria-label="Edit billing address"]').click();
        }
        await tokenPage.locator('#billing-country').selectOption('US');
        await tokenPage.locator('#billing-state').selectOption('CA');
        tokenEmail='block-token@example.invalid';
        await setFields(tokenPage,{...blockFields,'#billing-phone':mobile,'#email':tokenEmail});
        await tokenPage.locator(savedSelector(tokens[0])).check();
        await tokenPage.waitForFunction(id=>window.wp.data.select(window.wc.wcBlocksData.paymentStore).getActiveSavedToken()===String(id),tokens[0]);
        tokenUrl=tokenPage.url();
        await tokenPage.locator(blockButton).click();
        await tokenPage.locator(codeField).waitFor({state:'visible'});
        // Native state transition (e.g. a method reset during asynchronous
        // payment validation); use the actual WC action and selectors.
        await tokenPage.evaluate(id=>{
            const store=window.wc.wcBlocksData.paymentStore;
            window.wp.data.dispatch(store).__internalSetActivePaymentMethod('fixture_token',{
                token:String(id),payment_method:'fixture_token','wc-fixture_token-payment-token':String(id),isSavedToken:true
            });
        },tokens[1]);
        await otp(tokenPage,mobile);
        await requireReconfirmation(tokenPage,tokenUrl,tokenEmail);
        await tokenPage.locator(savedSelector(tokens[1])).check();
        await tokenPaid(tokenPage,blockButton,tokenEmail,tokens[1]);
        console.log('PASS: Blocks native same-gateway saved-token state change requires reconfirmation');
        // Removing the selected instrument while OTP is pending changes the
        // native Classic recalculation fragment even with no browser input.
        tokenEmail='token-reset@example.invalid';
        tokenUrl=await classicTokenCheckout(tokenEmail,tokens[1]);
        command('token-remove',tokens[1]);
        await otp(tokenPage,mobile);
        await requireReconfirmation(tokenPage,tokenUrl,tokenEmail);
        assert.equal(await tokenPage.locator(tokenSelector(tokens[1])).count(),0,'Removed instrument retained after recalculation');
        await tokenPage.locator(tokenSelector(tokens[0])).check();
        await tokenPaid(tokenPage,'#place_order',tokenEmail,tokens[0]);
        console.log('PASS: native recalculation reset of selected instrument requires deliberate reconfirmation');
        // Exact unchanged saved-instrument order-pay must continue through
        // the original deliberate attempt after successful verification.
        const unchanged=JSON.parse(command('order-pay-token'));
        await tokenPage.goto(unchanged.url);
        await tokenPage.locator('#payment_method_fixture_token').check();
        await tokenPage.locator(tokenSelector(tokens[0])).check();
        await tokenPage.locator('#place_order').click();
        await otp(tokenPage,mobile);
        await tokenPage.waitForURL(/order-received/,{timeout:25000});
        const unchangedOrder=JSON.parse(command('state')).find(o=>o.id===unchanged.id);
        assert.equal(unchangedOrder.token,String(tokens[0]));
        assert.equal(unchangedOrder.calls,1);
        console.log('PASS: unchanged native saved instrument continues exactly once');
        await tokenContext.close();
        command('token-disable');
        command('default-checkout-policy');
        const adminContext=await browser.newContext({ignoreHTTPSErrors:true,viewport:{width:1280,height:900}});
        const admin=await adminContext.newPage();
        await admin.goto(base+'/wp-login.php?redirect_to='+encodeURIComponent(base+'/wp-admin/'));
        await admin.locator('#user_login').fill('fixture_admin');
        await admin.locator('#user_pass').fill('fixture-only-admin-password-123');
        await admin.locator('#wp-submit').click();
        await admin.waitForURL(/wp-admin/);
        await admin.goto(base+'/wp-admin/users.php?s='+encodeURIComponent('۰۹۱۲۵۵۵۷۷۷۱'));
        await admin.locator('th#ach_accounts_mobile').waitFor({state:'visible'});
        assert.equal(await admin.locator('tbody .column-ach_accounts_mobile').count(),1);
        assert.match(await admin.locator('tbody .column-ach_accounts_mobile').innerText(),/09125557771/);
        assert.match(await admin.locator('tbody .column-ach_accounts_verified').innerText(),/Verified/);
        console.log('PASS: actual native WordPress users-list mobile columns and Persian-digit search');
        await admin.goto(base+'/wp-admin/admin.php?page=wc-admin&path=%2Fcustomers');
        await admin.getByText('Mobile verification',{exact:true}).first().waitFor({state:'visible',timeout:30000});
        await admin.locator('table tbody bdi').filter({hasText:/^09125557771$/}).first().waitFor({state:'visible',timeout:30000});
        // Woo's native search placeholder only appears after focus. Use
        // the native combobox and select its actual free-text option.
        const search = admin.locator('.woocommerce-report-table .woocommerce-search input[role="combobox"]').first();
        await search.click();
        await search.fill('۰۹۱۲۵۵۵۷۷۷۱');
        const freeText = admin.getByText(/All customers with names that include/).first();
        await freeText.waitFor({state:'visible',timeout:15000});
        await freeText.click();
        await admin.waitForFunction(()=>document.querySelector('table tbody')?.innerText.includes('09125557771') &&
            !document.querySelector('table tbody')?.innerText.includes('09125557772'),{},{timeout:15000});
        console.log('PASS: actual WooCommerce Customers native table and mobile search UI');
        await adminContext.close();


        if (process.env.ACH_TEST_PERSIAN==='1') {
            command('persian');
            const rtl=await browser.newContext({ignoreHTTPSErrors:true,viewport:{width:390,height:844}});
            const auth=await rtl.newPage();
            await auth.goto(fixture.account);
            await auth.locator('[data-ach-mobile]').waitFor({state:'visible'});
            assert.equal(await auth.locator('html').getAttribute('dir'),'rtl');
            assert.equal(await auth.locator('[data-ach-mobile]').getAttribute('dir'),'ltr');
            assert.match(await auth.locator('[data-ach-title]').textContent(),/[\u0600-\u06ff]/);
            assert.equal(await auth.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
            await auth.locator('[data-ach-mobile]').fill('۰۹۱۲۵۵۵۷۷۷۳');
            await auth.locator('[data-ach-submit]').click();
            await auth.locator(codeField).waitFor({state:'visible'});
            assert.equal(await auth.locator(codeField).evaluate(el=>el===document.activeElement),true,'OTP autofocus missing');
            assert.equal(await auth.locator(codeField).getAttribute('dir'),'ltr');
            const record=JSON.parse(fs.readFileSync(otpFile,'utf8'));
            await auth.locator(codeField).fill(record.code.replace(/[0-9]/g,n=>'۰۱۲۳۴۵۶۷۸۹'[n]));
            await auth.locator('[data-ach-submit]').click();
            await auth.locator('.woocommerce-MyAccount-navigation').waitFor({state:'visible',timeout:20000});
            console.log('PASS: official Persian locale, RTL at 390px, LTR number/code input, keyboard focus and Persian-digit OTP sign-in');
            await rtl.close();
        }
    } finally { await browser.close(); }
})().catch(e=>{console.error(e);process.exitCode=1;});
