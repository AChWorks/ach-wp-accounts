/* Extend the native Woo report table, not its rendered DOM or pagination. */
(() => {
    'use strict';
    const hooks = window.wp?.hooks;
    const labels = window.achAccountsDirectory;
    if (!hooks || !labels) return;
    hooks.addFilter('woocommerce_admin_report_server_side_search_item_types',
        'ach-wp-accounts/customer-directory', types => [...new Set([...types, 'customers'])]);
    hooks.addFilter('woocommerce_admin_report_table', 'ach-wp-accounts/customer-directory', table => {
        if (table.endpoint !== 'customers') return table;
        const items = table.items?.data || [];
        if (!Array.isArray(table.headers) || !Array.isArray(table.rows)) return table;
        const cell = value => ({ display: value || '—', value: value || '' });
        return {
            ...table,
            headers: [
                ...table.headers,
                { key: 'ach_accounts_mobile', label: labels.mobile },
                { key: 'ach_accounts_verified', label: labels.verification },
            ],
            rows: (table.rows || []).map((row, index) => {
                const identity = items[index]?.ach_accounts_identity;
                const mobile = identity?.mobile || '';
                return [...row, { ...cell(mobile), display: mobile
                    ? window.wp.element.createElement('bdi', { dir: 'ltr' }, mobile) : '—' },
                cell(identity?.verification)];
            }),
        };
    });
})();
