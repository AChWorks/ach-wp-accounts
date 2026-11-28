/* Interactive UX checks in the disposable HTTPS WordPress fixture. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const {chromium} = require('playwright');
const base = process.env.ACH_BROWSER_URL;
const dir = process.env.ACH_TEST_DIR;
assert.match(base, /^https:\/\/127\.0\.0\.1:\d+$/);
const command = (...args) => execFileSync(process.env.PHP_BIN || 'php',
    [path.join(__dirname,'browser-ux-fixture.php'),...args.map(String)], {encoding:'utf8'});
const fixture = JSON.parse(command('prepare'));
const phone = n => fixture.phones[n-1];
const q = (root,name) => root.locator('[data-ach-'+name+']');
const failures = [];
async function screenshot(page,name) {
    if (!process.env.ACH_UI_EVIDENCE_DIR) return;
    fs.mkdirSync(process.env.ACH_UI_EVIDENCE_DIR,{recursive:true});
    await page.screenshot({path:path.join(process.env.ACH_UI_EVIDENCE_DIR,name+'.jpg'),type:'jpeg',quality:65});
}
async function fits(page) {
    assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true,'Horizontal page overflow');
}
async function probe(browser,label,fn) {
    const context=await browser.newContext({ignoreHTTPSErrors:true,viewport:{width:390,height:844}});
    const page=await context.newPage();
    const exceptions=[];
    page.on('pageerror',e=>exceptions.push(e.message));
    try {
        await fn(page);
        assert.deepEqual(exceptions,[],'Browser exceptions');
        console.log('PASS: '+label);
    } catch(error) {
        failures.push(label+': '+error.message);
        console.error('FAIL: '+label+': '+error.message);
        await screenshot(page,'failure-'+label.replace(/\W+/g,'-')).catch(()=>{});
    } finally { await context.close(); }
}
// Hold a real fixture request at the browser boundary. Always release it,
// including assertion failures; the normal server response must still settle.
async function duringRequest(page,predicate,trigger,check) {
    const pattern='**/*';
    let release;
    const gate=new Promise(resolve=>{release=resolve;});
    let matched=false,forwarded;
    const done=new Promise(resolve=>{forwarded=resolve;});
    const handler=async route=>{
        if(matched||!predicate(route.request())){
            await route.continue();
            return;
        }
        matched=true;
        try {
            await gate;
            await route.continue();
        } finally {forwarded();}
    };
    await page.route(pattern,handler);
    try {
        await Promise.all([page.waitForRequest(predicate,{timeout:10000}),trigger()]);
        await check();
    } finally {
        release();
        if(matched)await done;
        await page.unrouteAll({behavior:'wait'});
    }
}
async function assertPopupBusy(page,dialog) {
    const close=q(dialog,'popup-close');
    assert.equal(await close.isDisabled(),true,'Close remains actionable during settling request');
    const box=await close.boundingBox();
    assert.ok(box,'Close control is not visible');
    await page.mouse.click(box.x+box.width/2,box.y+box.height/2);
    assert.equal(await dialog.isVisible(),true,'Pointer dismissed a pending flow');
    await page.keyboard.press('Escape');
    assert.equal(await dialog.isVisible(),true,'Escape dismissed a pending flow');
}
async function profile(page) {
    const url=new URL(fixture.account);
    url.searchParams.set('ach_accounts_method','password');
    await page.goto(url.href);
    const form=page.locator('form[data-ach-native-method=password]');
    await form.locator('[name=username]').fill(fixture.login);
    await form.locator('[name=password]').fill(fixture.password);
    await form.locator('button[type=submit]').click();
    await page.locator('.woocommerce-MyAccount-navigation').waitFor({state:'visible'});
    await page.goto(fixture.profile);
    const root=page.locator('[data-surface=profile]');
    await q(root,'change').waitFor({state:'visible'});
    return root;
}
async function changeWithPassword(root) {
    await q(root,'change').click();
    await q(root,'password-change').click();
    await q(root,'password').waitFor({state:'visible'});
}
(async()=>{
    const browser=await chromium.launch({headless:true});
    try {
        await probe(browser,'cancel profile change restores verified identity and focus',async page=>{
            const root=await profile(page);
            await screenshot(page,'profile-verified');
            await changeWithPassword(root);
            await q(root,'mobile').fill(phone(2));
            await q(root,'password').fill(fixture.password);
            await q(root,'toggle-password').click();
            await q(root,'cancel-profile').click();
            assert.equal(await q(root,'form').isVisible(),false);
            assert.ok((await q(root,'password').inputValue())==='','Cancelled form retains credential');
            assert.equal(await q(root,'password').getAttribute('type'),'password');
            assert.equal(await q(root,'toggle-password').getAttribute('aria-pressed'),'false');
            assert.equal(await q(root,'change').evaluate(e=>e===document.activeElement),true,'Focus lost on cancel');
            await changeWithPassword(root);
            assert.ok((await q(root,'password').inputValue())==='','Reopened form retains credential');
            assert.equal(await q(root,'toggle-password').getAttribute('aria-pressed'),'false');
        });

        await probe(browser,'multiple embeds and popup close restore independent drafts',async page=>{
            await page.goto(fixture.shortcode);
            const embeds=page.locator('[data-ach-embedded]');
            assert.equal(await embeds.count(),3);
            const first=embeds.nth(0),last=embeds.nth(2);
            await q(first,'mobile').waitFor();
            await q(last,'mobile').fill(phone(3));
            await q(first,'mobile').fill(phone(4));
            const open=page.locator('[data-ach-popup-open]');
            await open.click();
            await page.locator('dialog[open]').waitFor();
            await screenshot(page,'popup-mobile');
            await fits(page);
            await page.keyboard.press('Escape');
            await page.locator('dialog').waitFor({state:'hidden'});
            assert.equal(await open.evaluate(e=>e===document.activeElement),true,'Popup focus not restored');
            const switchPassword=async()=> {
                await first.locator('a[data-ach-native-link],a[data-ach-method]').filter({hasText:'Sign in with password'}).first().click();
                await first.locator('form[data-ach-native-method=password]').waitFor();
            };
            const switchMobile=async()=>{
                await first.locator('a[data-ach-method]').filter({hasText:'Sign in with mobile'}).click();
                await q(first,'mobile').waitFor();
            };
            await switchPassword();
            assert.equal(await q(last,'mobile').inputValue(),phone(3));
            await switchMobile();
            assert.equal(await q(first,'mobile').inputValue(),phone(4));
            await q(first,'mobile').fill('');
            await switchPassword();
            await switchMobile();
            assert.equal(await q(first,'mobile').inputValue(),'','Cleared mobile draft resurrected');
            const ids=await embeds.evaluateAll(items=>items.flatMap(e=>[...e.querySelectorAll('[id]')].map(n=>n.id)));
            assert.equal(new Set(ids).size,ids.length,'Duplicate embedded control IDs');
            await fits(page);
        });

        await probe(browser,'popup dismissal clears shared and native passwords while preserving safe drafts',async page=>{
            await page.goto(fixture.shortcode);
            const open=page.locator('[data-ach-popup-open]');
            const dialog=page.locator('dialog');
            const close=q(dialog,'popup-close');
            const root=q(dialog,'accounts');
            const sibling=page.locator('[data-ach-embedded]').first();
            await q(sibling,'mobile').fill(phone(3));
            const openPopup=async()=>{await open.click();await dialog.waitFor();};
            const closePopup=async escape=>{
                if(escape)await page.keyboard.press('Escape');else await close.click();
                await dialog.waitFor({state:'hidden'});
                assert.ok(await open.evaluate(e=>e===document.activeElement),'Popup focus not restored');
            };
            await openPopup();
            await q(root,'recover').click();
            await q(root,'login').fill(fixture.login);
            await q(root,'mobile').fill(phone(7));
            await q(root,'password').fill(fixture.password);
            await q(root,'toggle-password').click();
            assert.equal(await q(root,'password').getAttribute('type'),'text');
            await closePopup(false);
            assert.ok(await q(root,'password').inputValue()==='','Dismissed linking password remains');
            await openPopup();
            assert.equal(await q(root,'password').getAttribute('type'),'password');
            assert.equal(await q(root,'toggle-password').getAttribute('aria-pressed'),'false');
            assert.equal(await q(root,'mobile').inputValue(),phone(7));
            assert.equal(await q(root,'login').inputValue(),fixture.login);
            await q(root,'password').fill(fixture.password);
            await q(root,'toggle-password').click();
            await closePopup(true);
            await openPopup();
            assert.ok(await q(root,'password').inputValue()==='','Escape retains linking password');
            assert.equal(await q(root,'password').getAttribute('type'),'password');
            await q(root,'login').fill('');
            await q(root,'mobile').fill('');
            await closePopup(false);
            await openPopup();
            assert.equal(await q(root,'login').inputValue(),'');
            assert.equal(await q(root,'mobile').inputValue(),'');
            await q(root,'recover').click();
            await dialog.locator('a[data-ach-native-link],a[data-ach-method]').filter({hasText:'Sign in with password'}).first().click();
            const native=dialog.locator('form[data-ach-native-method=password]');
            await native.waitFor();
            const credential=native.locator('[autocomplete=current-password]');
            const visibility=q(native,'password-visibility');
            await native.locator('[autocomplete=username]').fill(fixture.login);
            for(const escape of [false,true]){
                await credential.fill(fixture.password);
                await visibility.click();
                assert.equal(await credential.getAttribute('type'),'text');
                await closePopup(escape);
                assert.ok(await credential.inputValue()==='','Dismissed native password remains');
                await openPopup();
                assert.equal(await credential.getAttribute('type'),'password');
                assert.equal(await visibility.getAttribute('aria-pressed'),'false');
                assert.equal(await native.locator('[autocomplete=username]').inputValue(),fixture.login);
            }
            await native.locator('[autocomplete=username]').fill('');
            await closePopup(false);
            await openPopup();
            assert.equal(await native.locator('[autocomplete=username]').inputValue(),'');
            await closePopup(true);
            assert.equal(await q(sibling,'mobile').inputValue(),phone(3));
        });

        await probe(browser,'popup native navigation and login settle before dismissal',async page=>{
            await page.goto(fixture.shortcode);
            const dialog=page.locator('dialog');
            const open=page.locator('[data-ach-popup-open]');
            await open.click();
            const nativeLink=dialog.locator('a[data-ach-native-link],a[data-ach-method]').filter({hasText:'Sign in with password'}).first();
            const nativeRequest=req=>new URL(req.url()).searchParams.get('ach_accounts_method')==='password';
            await duringRequest(page,req=>req.method()==='GET'&&nativeRequest(req),()=>nativeLink.click(),()=>assertPopupBusy(page,dialog));
            const form=dialog.locator('form[data-ach-native-method=password]');
            await form.waitFor();
            await form.locator('[name=username]').fill(fixture.login);
            await form.locator('[name=password]').fill('fixture-wrong-password');
            await q(form,'password-visibility').click();
            await duringRequest(page,req=>req.method()==='POST'&&nativeRequest(req),()=>form.locator('button[type=submit]').click(),()=>assertPopupBusy(page,dialog));
            await dialog.getByText('Invalid credentials.',{exact:true}).waitFor();
            await q(dialog,'popup-close').click();
            await dialog.waitFor({state:'hidden'});
            assert.ok(await open.evaluate(e=>e===document.activeElement),'Popup focus not restored after rejected login');
            await open.click();
            assert.ok(await form.locator('[name=password]').inputValue()==='','Submitted credential retained');
            await form.locator('[name=username]').fill(fixture.login);
            await form.locator('[name=password]').fill(fixture.password);
            await duringRequest(page,req=>req.method()==='POST'&&nativeRequest(req),()=>form.locator('button[type=submit]').click(),()=>assertPopupBusy(page,dialog));
            await page.waitForFunction(()=>document.body.classList.contains('logged-in'));
        });

        await probe(browser,'popup pending OTP issue and completion keep the interaction visible',async page=>{
            await page.goto(fixture.shortcode);
            const dialog=page.locator('dialog');
            const root=q(dialog,'accounts');
            const open=page.locator('[data-ach-popup-open]');
            const operation=req=>new URLSearchParams(req.postData()||'').get('operation');
            let issues=0,completions=0;
            page.on('request',req=>{
                if(operation(req)==='issue')issues++;
                if(operation(req)==='complete')completions++;
            });
            await open.click();
            await q(root,'mobile').fill(phone(9));
            await duringRequest(page,req=>operation(req)==='issue',()=>q(root,'submit').click(),()=>assertPopupBusy(page,dialog));
            await q(root,'code').waitFor();
            await q(dialog,'popup-close').click();
            await dialog.waitFor({state:'hidden'});
            await open.click();
            assert.equal(issues,1);
            const record=JSON.parse(fs.readFileSync(path.join(dir,'browser-otp.json'),'utf8'));
            assert.ok([phone(9),'+98'+phone(9).slice(1)].includes(record.mobile),'Unexpected fixture delivery');
            await q(root,'code').fill(record.code);
            await duringRequest(page,req=>operation(req)==='complete',()=>q(root,'submit').click(),()=>assertPopupBusy(page,dialog));
            await page.waitForFunction(()=>document.body.classList.contains('logged-in'));
            assert.equal(issues,1,'Unexpected resend');
            assert.equal(completions,1,'Unexpected completion replay');
        });

        await probe(browser,'nonmodal fallback Close and Escape clear credentials',async page=>{
            await page.addInitScript(()=>{
                Object.defineProperty(HTMLDialogElement.prototype,'showModal',{value:undefined,configurable:true});
                Object.defineProperty(HTMLDialogElement.prototype,'close',{value:undefined,configurable:true});
            });
            await page.goto(fixture.shortcode);
            const open=page.locator('[data-ach-popup-open]');
            const dialog=page.locator('dialog');
            const root=q(dialog,'accounts');
            await open.click();
            assert.equal(await dialog.getAttribute('data-ach-popup-fallback'),'');
            assert.equal(await dialog.evaluate(e=>getComputedStyle(e).position),'relative');
            assert.ok(await q(dialog,'popup-close').evaluate(e=>e===document.activeElement),'Fallback did not receive focus');
            await fits(page);
            await q(root,'recover').click();
            await q(root,'login').fill(fixture.login);
            for(const escape of [false,true]){
                await q(root,'password').fill(fixture.password);
                await q(root,'toggle-password').click();
                if(escape)await page.keyboard.press('Escape');else await q(dialog,'popup-close').click();
                await dialog.waitFor({state:'hidden',timeout:3000});
                assert.ok(await q(root,'password').inputValue()==='','Fallback retained password');
                assert.ok(await open.evaluate(e=>e===document.activeElement),'Fallback focus not restored');
                await open.click();
                assert.equal(await q(root,'password').getAttribute('type'),'password');
                assert.equal(await q(root,'toggle-password').getAttribute('aria-pressed'),'false');
                assert.equal(await q(root,'login').inputValue(),fixture.login);
            }
        });

        await probe(browser,'popup resumes the same OTP and reset continuation without retaining secrets or resending',async page=>{
            await page.goto(fixture.shortcode);
            const open=page.locator('[data-ach-popup-open]');
            const dialog=page.locator('dialog');
            const root=q(dialog,'accounts');
            let issues=0;
            page.on('request',req=>{
                if(new URLSearchParams(req.postData()||'').get('operation')==='issue')issues++;
            });
            const codeFor=mobile=>{
                const record=JSON.parse(fs.readFileSync(path.join(dir,'browser-otp.json'),'utf8'));
                assert.ok([mobile,'+98'+mobile.slice(1)].includes(record.mobile),'Unexpected fixture delivery');
                return record.code;
            };
            await open.click();
            await q(root,'mobile').fill(phone(1));
            await q(root,'submit').click();
            await q(root,'code').waitFor();
            const code=codeFor(phone(1));
            await q(root,'code').fill(code);
            await page.keyboard.press('Escape');
            await dialog.waitFor({state:'hidden'});
            assert.ok(await q(root,'code').inputValue()==='','Dismissed OTP remains');
            await open.click();
            assert.equal(await q(root,'code').isVisible(),true);
            assert.equal(issues,1,'Popup dismissal resent OTP');
            await q(dialog,'popup-close').click();
            await dialog.waitFor({state:'hidden'});
            // Expiry continues while the presentation is closed. Reopening
            // must not extend the challenge or issue another code.
            await page.waitForFunction(()=>document.querySelector('dialog [data-ach-status]')?.textContent.includes('The code has expired'),{},{timeout:30000});
            await open.click();
            assert.equal(await q(root,'submit').isDisabled(),true);
            assert.equal(await q(root,'resend').isEnabled(),true);
            assert.equal(issues,1,'Expired hidden challenge was silently resent');
            // A password reset has its own purpose.
            await q(root,'edit').click();
            await q(root,'reset').click();
            await dialog.getByText('Reset by mobile',{exact:true}).click();
            await dialog.locator('[data-initial-purpose=password_reset]').waitFor();
            await q(root,'mobile').fill(phone(1));
            await q(root,'submit').click();
            await q(root,'code').waitFor();
            await q(root,'code').fill(codeFor(phone(1)));
            await q(root,'submit').click();
            await q(root,'reset-new').waitFor();
            const next='fixture-only-popup-reset-value-456';
            await q(root,'reset-new').fill(next);
            await q(root,'reset-confirm').fill(next);
            await q(dialog,'popup-close').click();
            await dialog.waitFor({state:'hidden'});
            assert.ok(await q(root,'reset-new').inputValue()==='','Dismissed new password remains');
            assert.ok(await q(root,'reset-confirm').inputValue()==='','Dismissed confirmation remains');
            await open.click();
            assert.equal(await q(root,'reset-new').isVisible(),true,'Valid continuation discarded');
            assert.equal(issues,2,'Popup reset continuation triggered another send');
            await q(root,'reset-new').fill(next);
            await q(root,'reset-confirm').fill(next);
            await duringRequest(page,req=>new URLSearchParams(req.postData()||'').get('operation')==='reset_password',
                ()=>q(root,'submit').click(),()=>assertPopupBusy(page,dialog));
            await q(root,'success').waitFor();
            assert.equal(issues,2);
            assert.match(await q(root,'success-message').innerText(),/password was reset/);
        });

        await probe(browser,'delivery failure and explicit resend preserve the mobile entry',async page=>{
            try {
                command('sms','failed');
                await page.goto(fixture.account);
                const root=page.locator('[data-ach-accounts]');
                await q(root,'mobile').fill(phone(5));
                await q(root,'submit').click();
                await q(root,'description').filter({hasText:'The code could not be sent'}).waitFor();
                assert.equal(await q(root,'code').isVisible(),false);
                await screenshot(page,'delivery-failed');
                command('sms','accepted');
                await q(root,'resend').click();
                await q(root,'code').waitFor({state:'visible'});
                await screenshot(page,'delivery-code');
                await q(root,'edit').click();
                assert.equal(await q(root,'mobile').inputValue(),phone(5));
                assert.equal(await q(root,'mobile').evaluate(e=>e===document.activeElement),true);
            } finally { command('sms','accepted'); }
        });

        await probe(browser,'uncertain delivery remains actionable and expiry disables verification',async page=>{
            try {
                command('sms','uncertain');
                await page.goto(fixture.account);
                const root=page.locator('[data-ach-accounts]');
                await q(root,'mobile').fill(phone(6));
                await q(root,'submit').click();
                await q(root,'status').filter({hasText:'The message may have been sent'}).waitFor();
                await q(root,'code').waitFor({state:'visible'});
                await screenshot(page,'delivery-uncertain');
                await q(root,'status').filter({hasText:'The code has expired'}).waitFor({timeout:30000});
                assert.equal(await q(root,'submit').isDisabled(),true);
                assert.equal(await q(root,'resend').isEnabled(),true);
                await screenshot(page,'delivery-expired');
            } finally { command('sms','accepted'); }
        });

        await probe(browser,'recovery methods follow the configured policies',async page=>{
            try {
                command('policy','all-recovery-off');
                await page.goto(fixture.account);
                const root=page.locator('[data-ach-accounts]');
                assert.equal(await q(root,'reset').isVisible(),false);
                assert.equal(await q(root,'recover').isVisible(),false);
                command('policy','mobile-only-reset');
                await page.reload();
                await q(root,'reset').click();
                await page.locator('[data-initial-purpose=password_reset]').waitFor({state:'visible'});
                await screenshot(page,'mobile-reset');
                command('policy','email-only-reset');
                await page.goto(fixture.account);
                await q(root,'reset').click();
                await page.locator('[data-ach-auth-shell]').getByText('Reset password by email',{exact:true}).waitFor();
                await screenshot(page,'email-reset');
            } finally { command('policy','restore'); }
        });

        if(process.env.ACH_TEST_PERSIAN==='1'){
            command('locale','fa_IR');
            try {
                await probe(browser,'Persian login on narrow phone, tablet and desktop',async page=>{
                    for(const width of [320,390,768,1280]){
                        await page.setViewportSize({width,height:844});
                        await page.goto(fixture.account);
                        await page.locator('[data-ach-mobile]').waitFor();
                        assert.equal(await page.locator('html').getAttribute('dir'),'rtl');
                        assert.equal(await page.locator('[data-ach-mobile]').getAttribute('dir'),'ltr');
                        await fits(page);
                        await screenshot(page,'rtl-'+width);
                    }
                });
            } finally { command('locale',''); }
        }
        if(failures.length)throw new Error(failures.join('\n'));
    } finally { await browser.close(); }
})().catch(e=>{console.error(e.message);process.exitCode=1;});
