/* Fixture-only express adapter, using Woo's actual express component contract.
 * The backend payment method is the native non-charging BACS fixture. */
(() => {
    const {createElement,useEffect} = window.wp.element;
    const registry = window.wc.wcBlocksRegistry;
    function FixtureExpress({onClick,onSubmit,eventRegistration,emitResponse}) {
        useEffect(() => eventRegistration.onPaymentSetup(() => {
            const payment=window.wp.data.select(window.wc.wcBlocksData.paymentStore);
            if (payment.getActivePaymentMethod()!=='fixture_express') return;
            return {type:emitResponse.responseTypes.SUCCESS,meta:{paymentMethodData:{}}};
        }), [eventRegistration.onPaymentSetup,emitResponse.responseTypes.SUCCESS]);
        return createElement('button',{type:'button',id:'fixture-express-payment',onClick:()=>{
            onClick();
            onSubmit();
        }},'Fixture express payment');
    }
    registry.registerExpressPaymentMethod({
        name:'fixture_express',gatewayId:'bacs',paymentMethodId:'bacs',
        title:'Fixture express payment',content:createElement(FixtureExpress),edit:createElement('span'),
        canMakePayment:()=>true,supports:{features:['products']}
    });
})();
