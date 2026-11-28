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
    // Per-form intent stays in memory only. Capture baseline values before
    // editing, including the original CAS revision; do not copy whole forms.
    const baselines = new WeakMap();
    const controls = form => [...form.elements].filter(field=>field.name &&
        !['hidden','submit','button','reset','file'].includes(field.type));
    const key = field => JSON.stringify([field.name,field.type,
        ['checkbox','radio'].includes(field.type)?field.value:'']);
    const value = field => ['checkbox','radio'].includes(field.type)?field.checked:field.value;
    const snapshot = form => new Map(controls(form).map(field=>[key(field),value(field)]));
    const revision = form => form.querySelector('[name=revision]')?.value;
    const changed = form => controls(form).filter(field=>baselines.get(form)?.get(key(field))!==value(field));
    function markDraft(form) {
        if(changed(form).length || revision(form)!==root.dataset.achRevision) drafts.add(form);
        else drafts.delete(form);
    }
    function showConflicts() {
        for(const form of drafts){
            if(revision(form)===root.dataset.achRevision)continue;
            const status=document.createElement('p');status.className='description';status.setAttribute('role','status');
            status.textContent=strings().conflict;
            const reload=document.createElement('button');reload.type='button';reload.className='button';
            reload.textContent=strings().reload;reload.addEventListener('click',()=>navigate(activeUrl));
            status.append(' ',reload);form.prepend(status);
        }
    }
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
        for(const form of root.querySelectorAll('[data-ach-admin-form]')) if(!baselines.has(form))baselines.set(form,snapshot(form));
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
            if(form && event.target.name && !['connection','test_send'].includes(form.dataset.achAdminForm)) markDraft(form);
            preview();
        });
        root.addEventListener('change',event=>{
            const form=event.target.closest('[data-ach-admin-form]');
            if(form && event.target.name && !['connection','test_send'].includes(form.dataset.achAdminForm)) markDraft(form);
            preview();
        });
        root.addEventListener('click',event=>{
            const a=event.target.closest('.ach-admin-tabs a,.ach-admin-provider-nav a,.ach-admin-pagination a,[data-ach-reload]');
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
            if(uncertainSave){markStatus(strings().save_failed,()=>navigate(activeUrl));return;}
            save(form);
        });
        preview();
    }
    let previewRequest=null,previewTimer=null,previewFace=null;
    function mediaPreview(style,media) {
        const brand=style.querySelector('[data-ach-preview-logo]');
        const name=style.querySelector('[data-ach-preview-name]');
        brand.onerror=()=>{brand.hidden=true;name.hidden=false;};
        if(media.logo){
            if(brand.getAttribute('src')!==media.logo)brand.src=media.logo;
            brand.hidden=false;name.hidden=true;
            if(brand.complete&&!brand.naturalWidth)brand.onerror();
        }else{brand.removeAttribute('src');brand.hidden=true;name.hidden=false;}
        const fallback=media.themeFont||'system-ui,sans-serif';
        if(style.dataset.previewFont===media.font && style.dataset.previewFallback===fallback)return;
        style.dataset.previewFont=media.font;style.dataset.previewFallback=fallback;
        if(previewFace){document.fonts.delete(previewFace);previewFace=null;}
        style.style.fontFamily=fallback;
        if(media.font && typeof FontFace==='function'){
            const face=new FontFace('ACHAccountPreview','url("'+media.font+'")');
            previewFace=face;
            face.load().then(()=>{
                if(previewFace!==face||!style.isConnected)return;
                document.fonts.add(face);style.style.fontFamily='ACHAccountPreview,system-ui,sans-serif';
            }).catch(()=>{});
        }
    }
    function preview() {
        const fields=root.querySelector('[data-ach-field-preview]');
        if(fields)for(const field of fields.querySelectorAll('[data-ach-preview-field]')){
            const value=root.querySelector('select[name="'+field.dataset.achPreviewField+'"]').value;
            field.hidden=value==='off';field.querySelector('[data-ach-preview-required]').textContent=value==='required'?' *':'';
        }
        const style=root.querySelector('[data-ach-style-preview]');
        if(!style){if(previewRequest)previewRequest.abort();clearTimeout(previewTimer);if(previewFace){document.fonts.delete(previewFace);previewFace=null;}}
        if(style){
            const width=root.querySelector('[data-ach-preview-width]')?.value;
            style.style.maxInlineSize=width==='680'?'680px':'390px';
            const val=name=>root.querySelector('[name="'+name+'"]').value;
            const reset=root.querySelector('[name=reset_appearance][type=checkbox]').checked;
            const context=root.querySelector('[data-ach-preview-context]').value;
            style.dataset.context=context;
            const inherited=JSON.parse(style.dataset.achPreviewInherited);
            const color=value=>/^#[a-f0-9]{6}$/i.test(value)?value:'';
            const accent=(reset?'':color(val('accent'))) || (context==='standalone'?inherited.themeAccent:'');
            const background=context==='standalone'?((reset?'':color(val('background'))) || inherited.themeBackground):'';
            const digits=value=>value.trim().replace(/[\u06f0-\u06f9\u0660-\u0669]/g,ch=>String(ch.charCodeAt(0)&15));
            const radius=reset?'':digits(val('radius'));
            const primary=style.querySelector('[data-ach-preview-primary]');
            const luminance=hex=>[1,3,5].map(i=>parseInt(hex.slice(i,i+2),16)/255).map(x=>x<=.04045?x/12.92:((x+.055)/1.055)**2.4).reduce((a,x,i)=>a+x*[.2126,.7152,.0722][i],0);
            const foreground=hex=>hex&&luminance(hex)>.179?'#000':'#fff';
            style.style.backgroundColor=background;style.style.color='';
            primary.style.backgroundColor=accent;primary.style.color=accent?foreground(accent):'';
            const r=/^\d+$/.test(radius)&&Number(radius)<=64?radius+'px':'';
            style.style.setProperty('--ach-preview-radius',r);
            const selection=reset?['inherit','0','0']:[val('logo_mode'),digits(val('logo_id')),digits(val('font_id'))];
            const mediaKey=JSON.stringify(selection);
            if(style.dataset.mediaKey===mediaKey)return;
            style.dataset.mediaKey=mediaKey;
            if(previewRequest)previewRequest.abort();
            clearTimeout(previewTimer);
            if(mediaKey===style.dataset.achPreviewSelection){
                mediaPreview(style,JSON.parse(style.dataset.achPreviewMedia));return;
            }
            mediaPreview(style,{...inherited,logo:selection[0]==='name'?'':inherited.logo});
            if(selection[0]!=='custom'&&!Number(selection[2]))return;
            // Resolve attachment IDs using the authorized native admin
            // endpoint. No provider values or credentials enter this request.
            previewTimer=setTimeout(async()=>{
                if(!style.isConnected||style.dataset.mediaKey!==mediaKey)return;
                const url=fragmentUrl(root.querySelector('[data-ach-admin-form=appearance]').action);
                url.searchParams.set('ach_preview','1');
                ['logo_mode','logo_id','font_id'].forEach((key,i)=>url.searchParams.set(key,selection[i]));
                const controller=new AbortController();previewRequest=controller;
                try{
                    const response=await fetch(url,{credentials:'same-origin',cache:'no-store',redirect:'error',signal:controller.signal});
                    const result=await response.json();
                    if(response.ok&&result.success===true&&style.isConnected&&style.dataset.mediaKey===mediaKey)mediaPreview(style,result.data);
                }catch(_){}
            },200);
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
            if(submitted)for(const form of new Set([...drafts,submitted])){
                const failed=form===submitted && !result.data.saved;
                if(form===submitted && !failed)continue;
                const action=form.dataset.achAdminForm;
                const next=[...replacement.querySelectorAll('[data-ach-admin-form]')].find(candidate=>candidate.dataset.achAdminForm===action);
                if(!next)throw Error();
                const baseline=snapshot(next), edits=changed(form).filter(field=>!failed || field.type!=='password');
                const origin=revision(form);
                // Rebase only for a proven transition from this exact
                // revision, with no intervening write or conflicting edit.
                const sameTransition=result.data.saved && origin===root.dataset.achRevision &&
                    revision(submitted)===origin && result.data.savedRevision===replacement.dataset.achRevision;
                const canRebase=sameTransition && submitted.dataset.achAdminForm!=='remove' &&
                    edits.every(field=>baseline.has(key(field)) &&
                        (baseline.get(key(field))===baselines.get(form)?.get(key(field)) ||
                         baseline.get(key(field))===value(field)));
                if(!canRebase && origin)next.querySelector('[name=revision]').value=origin;
                baselines.set(next,baseline);
                for(const field of edits){
                    const target=controls(next).find(item=>key(item)===key(field));
                    if(target){if(['checkbox','radio'].includes(field.type))target.checked=field.checked;else target.value=field.value;}
                }
                if(edits.length || failed)retained.push(next);
            }
            root.replaceWith(replacement);root=replacement;uncertainSave=false;drafts.clear();for(const form of retained)drafts.add(form);init();
            showConflicts();
            root.querySelector('h1').focus({preventScroll:true});
            return result.data;
        }catch(_){
            // Never repeat a POST after an uncertain outcome.
            if(uncertain)uncertainSave=true;
            markStatus(uncertain?strings().save_failed:strings().failed,()=>navigate(activeUrl));
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
