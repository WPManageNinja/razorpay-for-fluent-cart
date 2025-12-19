class RazorpayCheckout {
    constructor(form, response, paymentLoader) {
        this.form = form;
        this.data = response;
        this.paymentArgs = response?.payment_args || {};
        this.paymentLoader = paymentLoader;
        this.container = document.querySelector('.fluent-cart-checkout_embed_payment_container_razorpay');
        this.$t = (s) => s;
        window.is_razorpay_ready = false;
    }

    init() {
        if (!this.container) return;
        this.container.innerHTML = '<button class="fct-razorpay-button">Loading...</button><div class="fct-razorpay-errors"></div>';
        this.button = this.container.querySelector('.fct-razorpay-button');
        this.button.textContent = this.paymentArgs.button_text || 'Pay with Razorpay';
        this.button.style.cssText = 'padding:12px 20px;border-radius:6px;border:0;cursor:pointer;background:#3399cc;color:#fff';
        this.button.addEventListener('click', () => this.startCheckoutFlow());
        window.fluentCartRazorpayInstance = this;
        this.registerEvents();
    }

    async startCheckoutFlow() {
        try {
            this.button.disabled = true;
            this.button.textContent = 'Initializing...';
            this.openPopup(this.paymentArgs);

        } catch (err) {
            this.showError(err.message || 'Failed to initialize payment');
            this.button.disabled = false;
            this.button.textContent = this.paymentArgs.button_text || 'Pay with Razorpay';
            console.error(err);
        }
    }

    openPopup(paymentArgs) {
        const options = {
            key: paymentArgs.key,
            amount: paymentArgs.amount,
            currency: paymentArgs.currency || 'INR',
            name: paymentArgs.name || 'Store',
            description: paymentArgs.description || '',
            order_id: paymentArgs.order_id,
            // handler: (response) => this.onRazorpaySuccess(response, paymentArgs),
            handler: (response) => this.handleResponse(response, paymentArgs),
            modal: {
                escape: true,
                ondismiss: () => {
                    window.is_razorpay_ready = false;
                    this.button.disabled = false;
                    this.button.textContent = this.paymentArgs.button_text || 'Pay with Razorpay';
                }
            },
            theme: { color: (paymentArgs.theme && paymentArgs.theme.color) || '#3399cc' }
        };

        const rzp = new Razorpay(options);
        rzp.open();
    }

    handleResponse(response, paymentArgs) {
        if (!response || !response.razorpay_payment_id) {
            this.showError(this.$t('Payment failed or was canceled.'));
            window.is_razorpay_ready = false;
            return;
        }

        window.is_razorpay_ready = true;

        // Optional: Show loader or message while processing
        if (this.paymentLoader) this.paymentLoader.changeLoaderStatus('Verifying payment...');

        // Send payment verification request to your server
        fetch(paymentArgs.custom_payment_url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                razorpay_payment_id: response.razorpay_payment_id,
                razorpay_order_id: response.razorpay_order_id,
                razorpay_signature: response.razorpay_signature,
            }),
        })
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                console.log(data)
                this.onRazorpaySuccess(data.data, paymentArgs)
            } else {
                // Redirect to failure page or show error
                alert(data.message || 'Payment verification failed.');
            }
        })
        .catch(() => {
            alert('Something went wrong while verifying payment.');
        });
    }

    async onRazorpaySuccess(response, paymentArgs) {
        const paymentId = response.payment_id;
        console.log('razorpay = ', response);
        if (!response || !response.payment_id) {
            this.showError('Payment failed or cancelled');
            return;
        }

        // Attach hidden inputs for checkout
        this.attachHiddenField('note', response.payment_id);
        

        // Trigger FluentCart place-order
        const formData = new FormData(this.form);
        formData.append('action', 'fluent_cart_place_order');
        formData.append('razorpay_payment_id', response.payment_id);
        formData.append('note', response.payment_id);
        console.log(formData)

        try {
            const response = await fetch(window.fluentcart_checkout_vars.ajaxurl, {
                method: 'POST',
                body: formData,
                credentials: 'include',
            });

            const result = await response.json();
            console.log('result = ',result)
            if (result.status == 'success') {
                 await fetch(window.fluentcart_checkout_vars.ajaxurl, {
                        method: "POST",
                        credentials: "include",
                        body: new URLSearchParams({
                            action: "fluent_cart_confirm_razorpay_payment",
                            // payment_id: result.response.id,
                            payment_id: paymentId,
                        })
                    });


                // success, redirect or show message
                if (result.payment_args.success_url) {
                    window.location.href = result.payment_args.success_url;
                } else {
                    console.log('Order placed successfully', result);
                }
            }
            else {
                console.error('Order failed', result);
            } 
        } catch (err) {
            console.error('AJAX error', err);
        }
    }

    attachHiddenField(name, value) {
        let input = this.form.querySelector(`input[name="${name}"]`);
        if (!input) {
            input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            this.form.appendChild(input);
        }
        input.value = value;
    }

    registerEvents() {
        window.addEventListener('fluent_cart_validate_checkout_razorpay', () => {});
    }

    showError(msg) {
        const el = this.container.querySelector('.fct-razorpay-errors');
        if (el) el.textContent = msg;
        console.error(msg);
    }
}

/* Loader for Razorpay payment method */
window.addEventListener('fluent_cart_load_payments_razorpay', (event) => {
    console.log(event.detail.form)
    console.log(event.detail.paymentInfoUrl)

    const container = document.querySelector('.fluent-cart-checkout_embed_payment_container_razorpay');
    if (!container) return;

    container.innerHTML = '<div id="fct_loading_payment_processor">Loading Payment Processor...</div>';

    fetch(event.detail.paymentInfoUrl, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': event.detail.nonce || ''
        },
        credentials: 'include',
        body: JSON.stringify({})
    })
    .then(r => r.json())
    .then(response => {
        const paymentArgs = response?.payment_args || response?.data?.payment_args || response;
        new RazorpayCheckout(event.detail.form, { payment_args: paymentArgs }, event.detail.paymentLoader).init();
    })
    .catch(err => {
        container.innerHTML = '<div>Failed to load Razorpay checkout.</div>';
        console.error(err);
    });
});
