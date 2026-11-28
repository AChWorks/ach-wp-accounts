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
const command = (mode) => execFileSync(php,[path.join(__dirname,'browser-fixture.php'),mode],{encoding:'utf8'});
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
async function paid(page,phone,email,priorUrl) {
    const changed = page.locator(otpRoot+' [data-ach-status]');
    // A changed total/customer/cart may deliberately require confirmation.
    await page.waitForFunction(({url,root})=>location.href!==url ||
        (document.querySelector(root+' [data-ach-status]')?.dataset.error==='1'),
        {url:priorUrl,root:otpRoot},{timeout:20000}).catch(()=>{});
    if (page.url()===priorUrl) {
        const message=await changed.textContent();
        console.log('Checkout reconciliation:',message);
        assert.match(message,/checkout has changed|review your order/i);
        const button=page.locator('#place_order, .wc-block-components-checkout-place-order-button');
        await button.click();
    }
    await page.waitForURL(/order-received/, {timeout:25000});
    const orders=JSON.parse(command('state'));
    const order=orders.find(o=>o.phone===phone && o.email===email && o.status==='on-hold');
    assert.ok(order && order.customer>0 && order.items===1 && order.gateway==='bacs', 'native order not paid by fixture gateway');
    return order;
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
        const expressOrder=await paid(express,expressMobile,expressEmail,expressBefore);
        assert.equal(expressOrder.total,'25.00');
        console.log('PASS: native registered Blocks express method held until mobile proof, then native fixture payment');
        await expressContext.close();
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
        const search = admin.locator('.woocommerce-report-table input.woocommerce-select-control__control-input[role="combobox"][type="search"]').first();
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
