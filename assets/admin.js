/* Progressive disclosure only. PHP owns all settings and authorization. */
(() => {
    'use strict';
    function boot() {
        const root = document.querySelector('.ach-accounts-admin');
        if (!root) return;
        for (const route of root.querySelectorAll('[data-ach-route]')) {
            const select = route.querySelector('[data-ach-mode]');
            const refresh = () => {
                for (const panel of route.querySelectorAll('[data-ach-mode-panel]')) {
                    panel.hidden = panel.dataset.achModePanel !== select.value;
                }
            };
            select.addEventListener('change', refresh);
            refresh();
        }
        for (const override of root.querySelectorAll('[data-ach-override]')) {
            const checkbox = override.querySelector('input[type=checkbox]');
            const fields = override.querySelector('[data-ach-override-fields]');
            const refresh = () => { fields.hidden = !checkbox.checked; };
            checkbox.addEventListener('change', refresh);
            refresh();
        }
        const receipt = root.querySelector('[data-ach-new-receipt]');
        if (receipt) {
            // Return focus to the result of the just-submitted operation,
            // not to historical diagnostic entries.
            receipt.focus();
        }
        // Native validation must be able to focus a field inside a disclosure.
        root.addEventListener('invalid', (event) => {
            for (let parent = event.target.parentElement; parent && parent !== root; parent = parent.parentElement) {
                if (parent.tagName === 'DETAILS') parent.open = true;
            }
        }, true);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
    else boot();
})();
