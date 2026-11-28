/* Native forms remain usable. Fetch only the selected authorized admin fragment. */
(() => {
    'use strict';
    let root = document.querySelector('.ach-accounts-admin');
    if (!root) return;
    let busy = false;
    let uncertainSave=false;
    let activeUrl = new URL(location.href);
    let historyIndex=0,restoring=false;
    history.replaceState({...history.state,achAdminIndex:historyIndex},'',activeUrl);
    const drafts = new Set();
    let pendingUrl = null;
    let dirtyDialog = null;
    const strings = () => JSON.parse(root.dataset.achNavigation);
    const fragmentUrl = raw => {
        const url = new URL(raw,location.href);
        if (url.origin!==location.origin || url.searchParams.get('page')!=='ach-wp-accounts') return null;
        url.searchParams.set('ach_fragment','1');
        return url;
    };
    function markStatus(message, retry) {
        const status = root.querySelector('[data-ach-admin-status]');
        status.hidden = !message;
        status.textContent = message;
        if (retry) {
            const button = document.createElement('button');
            button.type='button';button.className='button';button.textContent=strings().retry;
            button.addEventListener('click',retry);status.append(' ',button);
        }
    }
    function init() {
        for (const route of root.querySelectorAll('[data-ach-route]')) {
            const select = route.querySelector('[data-ach-mode]');
            const refresh = () => {
                for (const panel of route.querySelectorAll('[data-ach-mode-panel]')) panel.hidden=panel.dataset.achModePanel!==select.value;
            };
            select.addEventListener('change',refresh);refresh();
        }
        for (const override of root.querySelectorAll('[data-ach-override]')) {
            const checkbox=override.querySelector('input[type=checkbox]'),fields=override.querySelector('[data-ach-override-fields]');
            const refresh=()=>fields.hidden=!checkbox.checked;
            checkbox.addEventListener('change',refresh);refresh();
        }
        const receipt=root.querySelector('[data-ach-new-receipt]');
        if(receipt) receipt.focus();
        root.addEventListener('invalid',event=>{
            for(let p=event.target.parentElement;p&&p!==root;p=p.parentElement) if(p.tagName==='DETAILS')p.open=true;
        },true);
        root.addEventListener('input',event=>{
            const form=event.target.closest('[data-ach-admin-form]');
            if(form && event.target.name && !['connection','test_send'].includes(form.dataset.achAdminForm)) drafts.add(form);
            preview();
        });
        root.addEventListener('change',event=>{
            const form=event.target.closest('[data-ach-admin-form]');
            if(form && event.target.name && !['connection','test_send'].includes(form.dataset.achAdminForm)) drafts.add(form);
            preview();
        });
        root.addEventListener('click',event=>{
            const a=event.target.closest('.ach-admin-tabs a,.ach-admin-provider-nav a,.ach-admin-pagination a');
            if(!a||event.defaultPrevented||event.button!==0||event.metaKey||event.ctrlKey||event.shiftKey||event.altKey||!fragmentUrl(a.href))return;
            event.preventDefault();navigate(new URL(a.href));
        });
        root.addEventListener('submit',event=>{
            const form=event.target;
            if(!form.matches('[data-ach-admin-form]') || ['connection','test_send'].includes(form.dataset.achAdminForm)) {
                // Native deliberate external effects: do not retry asynchronously.
                if (drafts.size) {
                    event.preventDefault();
                    markStatus(strings().multiple);
                }
                return;
            }
            if(!fragmentUrl(form.action))return;
            event.preventDefault();
            if(uncertainSave){markStatus(strings().save_failed,()=>load(activeUrl,false));return;}
            save(form);
        });
        preview();
    }
    function preview() {
        const fields=root.querySelector('[data-ach-field-preview]');
        if(fields)for(const field of fields.querySelectorAll('[data-ach-preview-field]')){
            const value=root.querySelector('select[name="'+field.dataset.achPreviewField+'"]').value;
            field.hidden=value==='off';field.querySelector('[data-ach-preview-required]').textContent=value==='required'?' *':'';
        }
        const style=root.querySelector('[data-ach-style-preview]');
        if(style){
            const width=root.querySelector('[data-ach-preview-width]')?.value;
            style.style.maxInlineSize=width==='680'?'680px':'390px';
            const val=name=>root.querySelector('[name="'+name+'"]').value;
            const color=value=>/^#[a-f0-9]{6}$/i.test(value)?value:'';
            const accent=color(val('accent')),background=color(val('background')),radius=val('radius');
            const primary=style.querySelector('[data-ach-preview-primary]');
            const luminance=hex=>[1,3,5].map(i=>parseInt(hex.slice(i,i+2),16)/255).map(x=>x<=.04045?x/12.92:((x+.055)/1.055)**2.4).reduce((a,x,i)=>a+x*[.2126,.7152,.0722][i],0);
            const foreground=hex=>hex&&luminance(hex)>.179?'#000':'#fff';
            style.style.backgroundColor=background;style.style.color=background?foreground(background):'';
            primary.style.backgroundColor=accent;primary.style.color=accent?foreground(accent):'';
            const r=/^\d+$/.test(radius)&&Number(radius)<=64?radius+'px':'';
            style.style.borderRadius=r;primary.style.borderRadius=r;
        }
    }
    async function exchange(raw,options={},uncertain=false,submitted=null) {
        if(busy)return null;
        const url=fragmentUrl(raw);
        if(!url)return null;
        busy=true;root.setAttribute('aria-busy','true');markStatus(strings().loading);root.inert=true;
        try{
            const response=await fetch(url,{credentials:'same-origin',cache:'no-store',redirect:'error',...options});
            const result=await response.json();
            if(!response.ok||result.success!==true||typeof result.data?.html!=='string')throw Error();
            const parsed=new DOMParser().parseFromString(result.data.html,'text/html');
            const replacement=parsed.querySelector('.ach-accounts-admin');
            if(!replacement)throw Error();
            // Preserve independent forms edited on this same tab. Keep the
            // original live inputs (including unsent credentials) only in
            // this document; never serialize them into history or storage.
            const retained=[];
            if(submitted)for(const form of drafts){
                if(form===submitted)continue;
                const action=form.dataset.achAdminForm;
                const next=[...replacement.querySelectorAll('[data-ach-admin-form]')].find(candidate=>candidate.dataset.achAdminForm===action);
                if(!next)throw Error();
                if(!result.data.saved){
                    const revision=form.querySelector('[name=revision]')?.value;
                    if(revision)next.querySelector('[name=revision]').value=revision;
                }
                // Copy only control values into the newly rendered structure.
                // This avoids retaining obsolete listeners or markup.
                for(const field of form.elements){
                    if(!field.name||field.type==='hidden'||['submit','button'].includes(field.type))continue;
                    const target=[...next.elements].find(item=>item.name===field.name&&item.type===field.type&&
                        (!['checkbox','radio'].includes(field.type)||item.value===field.value));
                    if(target){if(['checkbox','radio'].includes(field.type))target.checked=field.checked;else target.value=field.value;}
                }
                retained.push(next);
            }
            root.replaceWith(replacement);root=replacement;uncertainSave=false;drafts.clear();for(const form of retained)drafts.add(form);init();
            root.querySelector('h1').focus({preventScroll:true});
            return result.data;
        }catch(_){
            // Never repeat a POST after an uncertain outcome.
            if(uncertain)uncertainSave=true;
            markStatus(uncertain?strings().save_failed:strings().failed,()=>uncertain?load(activeUrl,false):navigate(activeUrl));
            return null;
        }finally{busy=false;root.inert=false;root.removeAttribute('aria-busy');}
    }
    async function load(url,push=true) {
        const result=await exchange(url);
        if(!result)return false;
        activeUrl=new URL(url);
        activeUrl.searchParams.delete('ach_fragment');
        if(push)history.pushState({achAdminIndex:++historyIndex},'',activeUrl);
        return true;
    }
    async function save(form) {
        if(busy)return;
        const next=pendingUrl;pendingUrl=null;
        const result=await exchange(form.action,{method:'POST',body:new FormData(form)},true,form);
        if(result?.saved && next)await navigate(next);
        if(result?.error) {
            const submitted=root.querySelector('[data-ach-admin-form="'+form.dataset.achAdminForm+'"]');
            if(submitted)drafts.add(submitted);
        }
    }
    function navigate(url) {
        if(busy)return;
        if(!drafts.size){load(url);return;}
        if(dirtyDialog?.open)return;
        const dialog=document.createElement('dialog');
        dirtyDialog=dialog;
        dialog.className='ach-admin-dirty-dialog';
        const text=document.createElement('p');text.textContent=strings().dirty;dialog.append(text);
        const choices=[['save',()=>{
            if(drafts.size!==1){markStatus(strings().multiple);return;}
            pendingUrl=url;
            const form=[...drafts][0];
            if(!form.reportValidity()){pendingUrl=null;return;}
            form.requestSubmit();
        }],['discard',()=>load(url)],['stay',()=>{}]];
        for(const [label,action] of choices){
            const button=document.createElement('button');button.type='button';button.className='button';
            button.textContent=strings()[label];button.addEventListener('click',()=>{dialog.close();action();});dialog.append(' ',button);
        }
        dialog.addEventListener('close',()=>{dialog.remove();dirtyDialog=null;},{once:true});
        root.append(dialog);
        if(typeof dialog.showModal==='function')dialog.showModal();
        else {dialog.remove();dirtyDialog=null;markStatus(strings().dirty);}
    }
    window.addEventListener('popstate',event=>{
        if(restoring){restoring=false;return;}
        const target=new URL(location.href),index=event.state?.achAdminIndex;
        if(!fragmentUrl(target)||!Number.isInteger(index))return;
        if(drafts.size||busy){
            const delta=historyIndex-index;
            if(delta){restoring=true;history.go(delta);}
            if(!busy)navigate(target);
        }else{
            const oldIndex=historyIndex;historyIndex=index;
            load(target,false).then(ok=>{if(!ok){historyIndex=oldIndex;restoring=true;history.go(oldIndex-index);}});
        }
    });
    window.addEventListener('beforeunload',event=>{
        if(!drafts.size)return;
        event.preventDefault();event.returnValue='';
    });
    init();
})();
