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
        await route.locator('form[data-ach-native-method=reset-email]').waitFor({state:'visible'});
        assert.equal(await route.locator('.ach-auth-shell').count(),1);
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

        command('experience-policy','on');
        for(const width of [390,768,1280]){
            const initial=await browser.newContext({ignoreHTTPSErrors:true,javaScriptEnabled:false,viewport:{width,height:900}});
            const first=await initial.newPage();
            await first.goto(fixture.account);
            assert.equal(await first.locator('[data-ach-mobile]').isVisible(),true);
            assert.equal(await first.locator('[data-ach-submit]').isVisible(),true);
            assert.equal(await first.locator('[data-ach-submit]').isDisabled(),true);
            assert.equal(await first.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
            const center=await first.evaluate(()=>{
                const logo=document.querySelector('.ach-auth-logo').getBoundingClientRect();
                const brand=document.querySelector('.ach-auth-brand').getBoundingClientRect();
                return Math.abs(logo.x+logo.width/2-brand.x-brand.width/2);
            });
            assert.ok(center<2,'Logo not centered');
            await first.locator('a[href*="ach_accounts_method=password"]').click();
            await first.locator('form[data-ach-native-method=password]').waitFor({state:'visible'});
            await initial.close();
        }
        console.log('PASS: stable first paint and centered brand at mobile/tablet/desktop, native password navigation without JS');
        const experienceContext=await browser.newContext({ignoreHTTPSErrors:true,viewport:{width:390,height:844}});
        const experience=await experienceContext.newPage();
        await experience.goto(fixture.account);
        await experience.locator('[data-ach-mobile]').fill('09125557780');
        await experience.evaluate(()=>window.achFixtureDocument='same-document');
        await experience.locator('a[href*="ach_accounts_method=password"]').click();
        await experience.locator('form[data-ach-native-method=password]').waitFor({state:'visible'});
        assert.equal(await experience.evaluate(()=>window.achFixtureDocument),'same-document');
        await experience.locator('a[href*="ach_accounts_method=forgot"]').click();
        await experience.locator('a[href*="ach_accounts_method=reset-email"]').waitFor({state:'visible'});
        await experience.locator('a[href*="ach_accounts_method=reset-email"]').click();
        await experience.locator('form[data-ach-native-method=reset-email]').waitFor({state:'visible'});
        await experience.locator('form[data-ach-native-method=reset-email] input[name=user_login]').fill('unknown-fixture@example.invalid');
        await experience.locator('form[data-ach-native-method=reset-email] button[type=submit]').click();
        await experience.getByText('If the account is eligible for recovery, a password reset link will be sent.',{exact:true}).waitFor();
        assert.equal(await experience.evaluate(()=>window.achFixtureDocument),'same-document');
        await experience.locator('a[href*="ach_accounts_method=mobile"]').first().click();
        await experience.locator('[data-ach-mobile]').waitFor({state:'visible'});
        assert.equal(await experience.locator('[data-ach-mobile]').inputValue(),'09125557780');
        await experience.locator('[data-ach-submit]').click();
        await otp(experience,'09125557780');
        await experience.locator('[data-ach-name=first_name]').waitFor({state:'visible'});
        assert.equal(await experience.locator('[data-ach-name=first_name]').evaluate(el=>el.required),true);
        await experience.locator('[data-ach-name=first_name]').fill('Fixture');
        await experience.locator('[data-ach-name=last_name]').fill('Experience');
        await experience.locator('[data-ach-submit]').click();
        await experience.locator('.woocommerce-MyAccount-navigation').waitFor({state:'visible',timeout:20000});
        await experience.goto(fixture.editAccount);
        await experience.locator('[data-ach-change]').waitFor({state:'visible'});
        assert.match(await experience.locator('[data-ach-description]').innerText(),/verified/i);
        await experienceContext.close();
        // Actual ordinary WooCommerce credentials: generic failures, policy
        // denial and native extension restriction must remain distinct.
        const credentialContext=await browser.newContext({ignoreHTTPSErrors:true,viewport:{width:390,height:844}});
        const credential=await credentialContext.newPage();
        const passwordUrl=new URL(fixture.account);passwordUrl.searchParams.set('ach_accounts_method','password');
        async function attempt(target,login,password){
            await target.goto(passwordUrl.href);
            const form=target.locator('form[data-ach-native-method=password]');
            await form.locator('[name=username]').fill(login);
            await form.locator('[name=password]').fill(password);
            await form.locator('button[type=submit]').click();
        }
        command('email-policy','on');
        for(const login of ['fixture_missing_customer','fixture_browser_legacy','missing@example.invalid','legacy@example.invalid']){
            await attempt(credential,login,'fixture-wrong-password-123');
            await credential.getByText('Invalid credentials.',{exact:false}).first().waitFor({state:'visible'});
        }
        command('auth-restriction','on');
        await attempt(credential,'fixture_browser_legacy','fixture-only-legacy-password-123');
        await credential.getByText('Fixture native restriction.',{exact:false}).first().waitFor({state:'visible'});
        command('auth-restriction','off');
        command('email-policy','off');
        await attempt(credential,'legacy@example.invalid','fixture-only-legacy-password-123');
        await credential.getByText('Password sign-in is unavailable for this account.',{exact:false}).first().waitFor({state:'visible'});
        await attempt(credential,'fixture_browser_legacy','fixture-only-legacy-password-123');
        await credential.locator('.woocommerce-MyAccount-navigation').waitFor({state:'visible'});
        await credentialContext.close();
        command('email-policy','on');
        console.log('PASS: actual WC generic credential errors, independent email identifier, native restriction and exact username success');

        // Dedicated complete reset: existing native session, wrong OTP,
        // correct OTP, password confirmation, reset without login and revocation.
        const reset=JSON.parse(command('reset-fixture'));
        const priorContext=await browser.newContext({ignoreHTTPSErrors:true,viewport:{width:390,height:844}});
        const prior=await priorContext.newPage();
        await attempt(prior,reset.login,reset.password);
        await prior.locator('.woocommerce-MyAccount-navigation').waitFor({state:'visible'});
        const resetContext=await browser.newContext({ignoreHTTPSErrors:true,viewport:{width:390,height:844}});
        const recovery=await resetContext.newPage();
        await recovery.goto(fixture.account);
        await recovery.locator('a[data-ach-reset]').click();
        await recovery.locator('a[href*="ach_accounts_method=reset-mobile"]').click();
        await recovery.locator('[data-ach-mobile]').fill(reset.mobile);
        await recovery.locator('[data-ach-submit]').click();
        await recovery.locator(codeField).waitFor({state:'visible'});
        const goodCode=JSON.parse(fs.readFileSync(otpFile,'utf8')).code;
        await recovery.locator(codeField).fill((goodCode[0]==='0'?'1':'0')+goodCode.slice(1));
        await recovery.locator('[data-ach-submit]').click();
        await recovery.waitForFunction(()=>document.querySelector('[data-ach-status]')?.dataset.error==='1');
        await otp(recovery,reset.mobile);
        await recovery.locator('[data-ach-reset-new]').waitFor({state:'visible'});
        const newPassword='fixture-reset-new-password-456';
        await recovery.locator('[data-ach-reset-new]').fill(newPassword);
        await recovery.locator('[data-ach-reset-confirm]').fill('fixture-mismatch-password-456');
        await recovery.locator('[data-ach-submit]').click();
        await recovery.waitForFunction(()=>document.querySelector('[data-ach-status]')?.dataset.error==='1');
        await recovery.locator('[data-ach-reset-confirm]').fill(newPassword);
        await recovery.locator('[data-ach-submit]').click();
        await recovery.getByText('Your password was reset. Sign in again.',{exact:true}).waitFor({state:'visible'});
        assert.equal(await recovery.locator('.woocommerce-MyAccount-navigation').count(),0);
        await prior.goto(fixture.account);
        await prior.locator('[data-ach-mobile]').waitFor({state:'visible'});
        await priorContext.close();
        await recovery.locator('[data-ach-success-continue]').click();
        await recovery.locator('[data-ach-mobile]').waitFor({state:'visible'});
        await attempt(recovery,reset.login,reset.password);
        await recovery.getByText('Invalid credentials.',{exact:false}).first().waitFor({state:'visible'});
        await attempt(recovery,reset.login,newPassword);
        await recovery.locator('.woocommerce-MyAccount-navigation').waitFor({state:'visible'});
        await resetContext.close();
        console.log('PASS: dedicated mobile password-reset browser, wrong OTP, mismatch, native revocation and new-password sign-in');
        command('experience-policy','off');
        console.log('PASS: same-document native method navigation, generic email-reset result, preserved phone draft, proof-bound registration name fields, native profile');
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
        await tokenPage.locator(codeField).waitFor({state:'visible'}).catch(async error=>{
            console.log('Blocks token verification status:',await tokenPage.locator(otpRoot+' [data-ach-status]').textContent());
            throw error;
        });
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
        await admin.goto(base+'/wp-admin/admin.php?page=ach-wp-accounts');
        await admin.locator('.ach-accounts-admin').waitFor();
        await admin.evaluate(()=>window.achAdminDocument='same-document');
        await admin.locator('.ach-admin-tabs a[href*="tab=fields"]').click();
        await admin.locator('[data-ach-field-preview]').waitFor({state:'visible'});
        assert.equal(await admin.evaluate(()=>window.achAdminDocument),'same-document');
        await admin.locator('select[name=first_name]').selectOption('required');
        await admin.locator('.ach-admin-tabs a[href*="tab=appearance"]').click();
        await admin.locator('.ach-admin-dirty-dialog').waitFor({state:'visible'});
        await admin.locator('.ach-admin-dirty-dialog button').last().click();
        assert.equal(await admin.locator('select[name=first_name]').inputValue(),'required');
        await admin.locator('.ach-admin-tabs a[href*="tab=appearance"]').click();
        await admin.locator('.ach-admin-dirty-dialog button').first().click();
        await admin.locator('[data-ach-style-preview]').waitFor({state:'visible'});
        assert.equal(await admin.evaluate(()=>window.achAdminDocument),'same-document');
        await admin.locator('.ach-admin-tabs a[href*="tab=fields"]').click();
        await admin.locator('select[name=first_name]').waitFor({state:'visible'});
        assert.equal(await admin.locator('select[name=first_name]').inputValue(),'required');
        await admin.locator('select[name=first_name]').selectOption('off');
        await admin.locator('form[data-ach-admin-form=fields] input[type=submit]').click();
        await admin.waitForFunction(()=>!document.querySelector('.ach-accounts-admin')?.hasAttribute('aria-busy'));
        console.log('PASS: native admin progressive tabs, dirty stay/save navigation and persisted profile-field policy');
        const adminRoot=admin.locator('.ach-accounts-admin');
        const adminForm=action=>admin.locator('form[data-ach-admin-form="'+action+'"]');
        const currentRevision=()=>adminRoot.getAttribute('data-ach-revision');
        const formRevision=action=>adminForm(action).locator('[name=revision]').inputValue();
        const saved=()=>JSON.parse(command('settings-state'));
        await admin.locator('.ach-admin-tabs a[href*="tab=sms"]').click();
        await adminForm('provider').waitFor();
        const staleRevision=await currentRevision();
        const provider=adminForm('provider');
        const verification=adminForm('verification');
        await provider.locator('[name="default[template]"]').fill('fixture-draft-template');
        await provider.locator('[name="credentials[api_key]"]').fill('fixture-unsent-secret');
        await verification.locator('[name=cooldown]').fill('9');
        const external=JSON.parse(command('concurrent-admin')).revision;
        await verification.locator('input[type=submit]').click();
        await admin.waitForFunction(rev=>document.querySelector('.ach-accounts-admin')?.dataset.achRevision===rev,external);
        assert.equal(await formRevision('verification'),staleRevision);
        assert.equal(await formRevision('provider'),staleRevision);
        assert.equal(await verification.locator('[name=cooldown]').inputValue(),'9');
        assert.equal(await verification.locator('[name=codeLifetime]').inputValue(),'240','Untouched field copied from stale snapshot');
        assert.equal(await provider.locator('[name="default[template]"]').inputValue(),'fixture-draft-template');
        assert.equal(await provider.locator('[name="credentials[api_key]"]').inputValue(),'fixture-unsent-secret');
        await adminForm('selection').locator('input[type=submit]').click();
        await admin.waitForFunction(rev=>document.querySelector('.ach-accounts-admin')?.dataset.achRevision!==rev,external);
        assert.equal(await formRevision('provider'),staleRevision);
        assert.equal(await formRevision('verification'),staleRevision);
        const latest=await currentRevision();
        // Stale provider submit must be rejected and submitted credentials
        // must not appear in a returned fragment or be persisted.
        const conflictResponse=admin.waitForResponse(r=>r.request().method()==='POST'&&r.url().includes('ach_fragment=1'));
        await provider.locator('input[type=submit]').click();
        const response=await conflictResponse;
        const payload=await response.json();
        assert.equal(payload.data.saved,false);
        assert.ok(!JSON.stringify(payload).includes('fixture-unsent-secret'));
        await admin.waitForFunction(()=>!document.querySelector('.ach-accounts-admin')?.hasAttribute('aria-busy'));
        assert.equal(await provider.locator('[name="credentials[api_key]"]').inputValue(),'');
        assert.equal(await provider.locator('[name="default[template]"]').inputValue(),'fixture-draft-template');
        assert.equal(await formRevision('provider'),staleRevision);
        assert.equal(saved().verification.cooldown,7);
        assert.equal(saved().verification.codeLifetime,240);
        assert.equal(saved().template,'fixture-template');
        assert.equal(saved().credentialPreserved,true);
        await provider.getByRole('button',{name:'Reload saved settings'}).click();
        await admin.locator('.ach-admin-dirty-dialog button').last().click();
        assert.equal(await provider.locator('[name="default[template]"]').inputValue(),'fixture-draft-template');
        await provider.getByRole('button',{name:'Reload saved settings'}).click();
        await admin.locator('.ach-admin-dirty-dialog button').nth(1).click();
        await admin.waitForFunction(rev=>document.querySelector('form[data-ach-admin-form=provider] [name=revision]')?.value===rev,latest);
        assert.equal(await provider.locator('[name="default[template]"]').inputValue(),'fixture-template');
        assert.equal(await verification.locator('[name=cooldown]').inputValue(),'7');
        // Same-transition independent draft can safely advance when no
        // intervening write exists; save only intended edits.
        await verification.locator('[name=cooldown]').fill('8');
        await adminForm('selection').locator('input[type=submit]').click();
        await admin.waitForFunction(rev=>document.querySelector('.ach-accounts-admin')?.dataset.achRevision!==rev,latest);
        assert.equal(await formRevision('verification'),await currentRevision());
        assert.equal(await verification.locator('[name=cooldown]').inputValue(),'8');
        await verification.locator('input[type=submit]').click();
        await admin.waitForFunction(()=>!document.querySelector('.ach-accounts-admin')?.hasAttribute('aria-busy'));
        assert.equal(saved().verification.cooldown,8);
        assert.equal(saved().credentialPreserved,true);
        console.log('PASS: actual multiple dirty forms, concurrent CAS denial, fresh save, secret protection and explicit discard/reconciliation');

        const media=JSON.parse(command('appearance-media'));
        await admin.locator('.ach-admin-tabs a[href*="tab=appearance"]').click();
        const style=admin.locator('[data-ach-style-preview]');
        const appearance=adminForm('appearance');
        const logo=style.locator('[data-ach-preview-logo]');
        await style.waitFor({state:'visible'});
        await admin.waitForFunction(()=>document.querySelector('[data-ach-preview-logo]')?.naturalWidth>0);
        assert.match(await logo.getAttribute('src'),/fixture-inherited-logo/);
        await appearance.locator('[name=logo_mode]').selectOption('custom');
        await appearance.locator('[name=logo_id]').fill(String(media.logo));
        await appearance.locator('[name=font_id]').fill(String(media.font));
        await admin.waitForFunction(()=>document.querySelector('[data-ach-preview-logo]')?.src.includes('fixture-custom-logo') &&
            document.querySelector('[data-ach-preview-logo]').naturalWidth>0 &&
            getComputedStyle(document.querySelector('[data-ach-style-preview]')).fontFamily.includes('ACHAccountPreview'));
        await appearance.locator('[name=accent]').fill('#abcdef');
        await appearance.locator('[name=background]').fill('#fedcba');
        await appearance.locator('[name=radius]').fill('17');
        assert.equal(await style.locator('[data-ach-preview-primary]').evaluate(e=>getComputedStyle(e).backgroundColor),'rgb(171, 205, 239)');
        assert.equal(await style.evaluate(e=>getComputedStyle(e).backgroundColor),'rgb(254, 220, 186)');
        assert.equal(await style.locator('[data-ach-preview-primary]').evaluate(e=>getComputedStyle(e).borderRadius),'17px');
        await appearance.locator('[data-ach-preview-context]').selectOption('embedded');
        assert.equal(await style.locator('[data-ach-preview-brand]').isVisible(),false);
        assert.notEqual(await style.evaluate(e=>getComputedStyle(e).backgroundColor),'rgb(254, 220, 186)');
        await appearance.locator('[data-ach-preview-context]').selectOption('standalone');
        await appearance.locator('[name=logo_id]').fill('999999');
        await appearance.locator('[name=font_id]').fill('999999');
        await admin.waitForFunction(()=>document.querySelector('[data-ach-preview-logo]')?.src.includes('fixture-inherited-logo') &&
            !getComputedStyle(document.querySelector('[data-ach-style-preview]')).fontFamily.includes('ACHAccountPreview'));
        await appearance.locator('[name=logo_mode]').selectOption('name');
        await style.locator('[data-ach-preview-name]').waitFor({state:'visible'});
        await appearance.locator('[name=reset_appearance][type=checkbox]').check();
        await admin.waitForFunction(()=>document.querySelector('[data-ach-preview-logo]')?.src.includes('fixture-inherited-logo') &&
            !getComputedStyle(document.querySelector('[data-ach-style-preview]')).fontFamily.includes('ACHAccountPreview'));
        assert.notEqual(await style.locator('[data-ach-preview-primary]').evaluate(e=>getComputedStyle(e).borderRadius),'17px');
        await appearance.locator('input[type=submit]').click();
        await admin.waitForFunction(()=>!document.querySelector('.ach-accounts-admin')?.hasAttribute('aria-busy'));
        assert.equal(saved().appearance.logo_mode,'inherit');
        assert.equal(saved().appearance.font_id,0);
        assert.equal(saved().appearance.accent,'');
        console.log('PASS: inherited/custom logo and local font, missing attachment fallback, accent/background/radius, embedded context and reset');
        // Native fallback: settings remain usable without the controller.
        const nativeAdminContext=await browser.newContext({ignoreHTTPSErrors:true,javaScriptEnabled:false,viewport:{width:1280,height:900}});
        const nativeAdmin=await nativeAdminContext.newPage();
        await nativeAdmin.goto(base+'/wp-login.php?redirect_to='+encodeURIComponent(base+'/wp-admin/'));
        await nativeAdmin.locator('#user_login').fill('fixture_admin');
        await nativeAdmin.locator('#user_pass').fill('fixture-only-admin-password-123');
        await nativeAdmin.locator('#wp-submit').click();
        await nativeAdmin.waitForURL(/wp-admin/);
        await nativeAdmin.goto(base+'/wp-admin/admin.php?page=ach-wp-accounts&tab=appearance');
        const nativeAppearance=nativeAdmin.locator('form[data-ach-admin-form=appearance]');
        await nativeAppearance.locator('[name=accent]').fill('#112233');
        await nativeAppearance.locator('input[type=submit]').click();
        await nativeAdmin.waitForURL(/saved=/);
        assert.equal(saved().appearance.accent,'#112233');
        await nativeAppearance.locator('[name=reset_appearance][type=checkbox]').check();
        const beforeReset=nativeAdmin.url();
        await nativeAppearance.locator('input[type=submit]').click();
        await nativeAdmin.waitForURL(url=>url.href!==beforeReset&&url.searchParams.has('saved'));
        assert.equal(saved().appearance.accent,'');
        await nativeAdminContext.close();
        console.log('PASS: native appearance settings save/reset without JavaScript');
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
            const rtlAdmin=await browser.newContext({ignoreHTTPSErrors:true,viewport:{width:390,height:844}});
            const rtlSettings=await rtlAdmin.newPage();
            await rtlSettings.goto(base+'/wp-login.php?redirect_to='+encodeURIComponent(base+'/wp-admin/'));
            await rtlSettings.locator('#user_login').fill('fixture_admin');
            await rtlSettings.locator('#user_pass').fill('fixture-only-admin-password-123');
            await rtlSettings.locator('#wp-submit').click();
            await rtlSettings.waitForURL(/wp-admin/);
            await rtlSettings.goto(base+'/wp-admin/admin.php?page=ach-wp-accounts&tab=appearance');
            const rtlPreview=rtlSettings.locator('[data-ach-style-preview]');
            await rtlPreview.waitFor({state:'visible'});
            assert.equal(await rtlSettings.locator('html').getAttribute('dir'),'rtl');
            assert.equal(await rtlPreview.evaluate(e=>getComputedStyle(e).direction),'rtl');
            await rtlSettings.locator('[data-ach-preview-context]').selectOption('embedded');
            assert.equal(await rtlPreview.locator('[data-ach-preview-brand]').isVisible(),false);
            await rtlSettings.locator('[data-ach-preview-width]').selectOption('680');
            assert.equal(await rtlPreview.evaluate(e=>e.scrollWidth<=e.clientWidth),true);
            await rtlAdmin.close();
            console.log('PASS: representative RTL mobile admin appearance preview and responsive embedded state');
            await rtl.close();
        }
    } finally { await browser.close(); }
})().catch(e=>{console.error(e);process.exitCode=1;});
