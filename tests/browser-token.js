/* Fixture-only native saved-instrument and consent integration. */
(() => {
    const {createElement,useEffect} = window.wp.element;
    const {registerPaymentMethod} = window.wc.wcBlocksRegistry;
    function FixtureToken({eventRegistration,emitResponse,shouldSavePayment}) {
        useEffect(() => eventRegistration.onPaymentSetup(() => {
            const payment=window.wp.data.select(window.wc.wcBlocksData.paymentStore);
            // Hidden gateway components must not override another payment
            // choice. For saved cards, let WC's native token data proceed.
            if (payment.getActivePaymentMethod()!=='fixture_token' || payment.getActiveSavedToken()) return;
            return {type:emitResponse.responseTypes.SUCCESS,
                meta:{paymentMethodData:{'wc-fixture_token-payment-token':'new',
                    'wc-fixture_token-new-payment-method':shouldSavePayment?'true':'false'}}};
        }), [eventRegistration.onPaymentSetup,emitResponse.responseTypes.SUCCESS,shouldSavePayment]);
        return createElement('span',null,'Synthetic instrument only');
    }
    registerPaymentMethod({
        name:'fixture_token',gatewayId:'fixture_token',paymentMethodId:'fixture_token',
        label:createElement('span',null,'Fixture saved instrument'),ariaLabel:'Fixture saved instrument',
        content:createElement(FixtureToken),edit:createElement('span'),
        canMakePayment:()=>true,supports:{features:['products'],showSavedCards:true,showSaveOption:true}
    });
})();
