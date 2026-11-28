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
