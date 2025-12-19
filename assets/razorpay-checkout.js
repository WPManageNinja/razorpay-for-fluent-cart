class RazorpayCheckout {
    #publicKey = null;
    #checkoutType = null;

    constructor(form, orderHandler, response, paymentLoader) {
        this.form = form;
        this.orderHandler = orderHandler;
        this.data = response;
        this.paymentLoader = paymentLoader;
        this.$t = this.translate.bind(this);
        this.submitButton = window.fluentcart_checkout_vars?.submit_button;
        this.#publicKey = response?.payment_args?.key;
        this.#checkoutType = response?.payment_args?.checkout_type || 'modal';
    }

    init() {
        this.paymentLoader.enableCheckoutButton(this.translate(this.submitButton.text));
        const razorpayContainer = document.querySelector('.fluent-cart-checkout_embed_payment_container_razorpay');
        if (razorpayContainer) {
            razorpayContainer.innerHTML = '';
        }

        this.renderPaymentInfo();

        this.#publicKey = this.data?.payment_args?.key;

        window.addEventListener("fluent_cart_payment_next_action_razorpay", async(e) => {
            const remoteResponse = e.detail?.response;
            const checkoutType = remoteResponse?.payment_args?.checkout_type;

            if (checkoutType === 'modal') {
                this.handleModalCheckout(remoteResponse);
            } else if (checkoutType === 'hosted') {
                this.handleHostedCheckout(remoteResponse);
            }
        });
    }

    translate(string) {
        const translations = window.fct_razorpay_data?.translations || {};
        return translations[string] || string;
    }

    renderPaymentInfo() {
        let html = '<div class="fct-razorpay-info">';
        
        // Simple header
        html += '<div class="fct-razorpay-header">';
        html += '<p class="fct-razorpay-subheading">' + this.$t('Available payment methods on Checkout') + '</p>';
        html += '</div>';
        
        // Payment methods
        html += '<div class="fct-razorpay-methods">';
        html += '<div class="fct-razorpay-method">';
        html += '<span class="fct-method-name">' + this.$t('Credit/Debit Cards') + '</span>';
        html += '</div>';
        html += '<div class="fct-razorpay-method">';
        html += '<span class="fct-method-name">' + this.$t('UPI') + '</span>';
        html += '</div>';
        html += '<div class="fct-razorpay-method">';
        html += '<span class="fct-method-name">' + this.$t('Net Banking') + '</span>';
        html += '</div>';
        html += '<div class="fct-razorpay-method">';
        html += '<span class="fct-method-name">' + this.$t('Wallets') + '</span>';
        html += '</div>';
        html += '<div class="fct-razorpay-method">';
        html += '<span class="fct-method-name">' + this.$t('EMI') + '</span>';
        html += '</div>';
        html += '</div>';
        
        html += '</div>';
        
        // Add CSS styles
        html += `<style>
            .fct-razorpay-info {
                padding: 20px;
                border: 1px solid #e0e0e0;
                border-radius: 8px;
                background: #f9f9f9;
                margin-bottom: 20px;
            }
            
            .fct-razorpay-header {
                text-align: center;
                margin-bottom: 16px;
            }
            
            .fct-razorpay-heading {
                margin: 0 0 4px 0;
                font-size: 18px;
                font-weight: 600;
                color: #3399cc;
            }
            
            .fct-razorpay-subheading {
                margin: 0;
                font-size: 12px;
                color: #999;
                font-weight: 400;
            }
            
            .fct-razorpay-methods {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(80px, 1fr));
                gap: 10px;
            }
            
            .fct-razorpay-method {
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 10px;
                background: white;
                border: 1px solid #ddd;
                border-radius: 6px;
                transition: all 0.2s ease;
                cursor: text;
            }
            
            .fct-method-name {
                font-size: 12px;
                font-weight: 500;
                color: #333;
            }
            
            @media (max-width: 768px) {
                .fct-razorpay-info {
                    padding: 16px;
                }
                
                .fct-razorpay-heading {
                    font-size: 16px;
                }
                
                .fct-razorpay-methods {
                    grid-template-columns: repeat(2, 1fr);
                    gap: 8px;
                }
                
                .fct-razorpay-method {
                    padding: 8px;
                }
            }
        </style>`;

        let container = document.querySelector('.fluent-cart-checkout_embed_payment_container_razorpay');
        if (container) {
            container.innerHTML = html;
        }
    }

    async handleModalCheckout(remoteResponse) {
        const modalData = remoteResponse?.payment_args?.modal_data;
        const transactionHash = remoteResponse?.payment_args?.transaction_hash;

        if (!modalData || !modalData.order_id) {
            this.handleRazorpayError(new Error('Invalid modal data'));
            return;
        }

        try {
            await this.loadRazorpayScript();
        } catch (error) {
            console.error('Razorpay script failed to load:', error);
            this.handleRazorpayError(error);
            return;
        }

        try {
            const options = {
                key: modalData.key,
                amount: modalData.amount,
                currency: modalData.currency,
                name: modalData.name,
                description: modalData.description,
                order_id: modalData.order_id,
                prefill: modalData.prefill,
                theme: modalData.theme,
                handler: (response) => {
                    this.handlePaymentSuccess(response, transactionHash);
                },
                modal: {
                    escape: true,
                    ondismiss: () => {
                        this.handlePaymentCancel();
                    }
                }
            };

            const rzp = new Razorpay(options);
            rzp.open();
            
        } catch (error) {
            console.error('Error opening Razorpay modal:', error);
            this.handleRazorpayError(error);
        }
    }

    handleHostedCheckout(remoteResponse) {
        const checkoutUrl = remoteResponse?.payment_args?.checkout_url;

        if (!checkoutUrl) {
            this.handleRazorpayError(new Error('Invalid checkout URL'));
            return;
        }

        this.paymentLoader?.changeLoaderStatus(this.$t('Redirecting to Razorpay...'));
        
        setTimeout(() => {
            window.location.href = checkoutUrl;
        }, 500);
    }

    loadRazorpayScript() {
        return new Promise((resolve, reject) => {
            if (typeof Razorpay !== 'undefined') {
                resolve();
                return;
            }

            const script = document.createElement('script');
            script.src = 'https://checkout.razorpay.com/v1/checkout.js';
            script.onload = () => {
                resolve();
            };
            script.onerror = () => {
                reject(new Error('Failed to load Razorpay script'));
            };

            document.head.appendChild(script);
        });
    }

    handlePaymentSuccess(response, transactionHash) {
        const paymentId = response.razorpay_payment_id;
        
        if (!paymentId) {
            this.handleRazorpayError(new Error('Payment ID not found'));
            return;
        }

        this.paymentLoader?.changeLoaderStatus(this.$t('Verifying payment...'));

        const params = new URLSearchParams({
            action: 'fluent_cart_razorpay_confirm_payment',
            razorpay_payment_id: paymentId,
            razorpay_order_id: response.razorpay_order_id || '',
            razorpay_signature: response.razorpay_signature || '',
            transaction_hash: transactionHash
        });

        const that = this;
        const xhr = new XMLHttpRequest();
        xhr.open('POST', window.fluentcart_checkout_vars.ajaxurl, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');

        xhr.onload = function () {
            if (xhr.status >= 200 && xhr.status < 300) {
                try {
                    const res = JSON.parse(xhr.responseText);
                    
                    if (res?.data?.redirect_url || res?.redirect_url) {
                        const redirectUrl = res?.data?.redirect_url || res?.redirect_url;
                        that.paymentLoader.triggerPaymentCompleteEvent(res);
                        that.paymentLoader?.changeLoaderStatus(that.$t('Payment successful! Redirecting...'));
                        
                        window.location.href = redirectUrl;
                    } else {
                        that.handleRazorpayError(new Error(res?.data?.message || res?.message || 'Payment verification failed'));
                    }
                } catch (error) {
                    console.error('Error parsing response:', error);
                    that.handleRazorpayError(error);
                }
            } else {
                that.handleRazorpayError(new Error('Network error: ' + xhr.status));
            }
        };

        xhr.onerror = function () {
            try {
                const err = JSON.parse(xhr.responseText);
                that.handleRazorpayError(err);
            } catch (e) {
                console.error('An error occurred:', e);
                that.handleRazorpayError(e);
            }
        };

        xhr.send(params.toString());
    }

    handlePaymentCancel() {
        this.paymentLoader.hideLoader();
        this.paymentLoader.enableCheckoutButton(this.submitButton.text);
    }

    handleRazorpayError(err) {
        let errorMessage = this.$t('An unknown error occurred');

        if (err?.message) {
            try {
                const jsonMatch = err.message.match(/{.*}/s);
                if (jsonMatch) {
                    errorMessage = JSON.parse(jsonMatch[0]).message || errorMessage;
                } else {
                    errorMessage = err.message;
                }
            } catch {
                errorMessage = err.message || errorMessage;
            }
        }

        let razorpayContainer = document.querySelector('.fluent-cart-checkout_embed_payment_container_razorpay');
        let tempMessage = this.$t('Something went wrong');

        if (razorpayContainer) {
            razorpayContainer.innerHTML += '<div id="fct_loading_payment_processor">' + this.$t(tempMessage) + '</div>';
            razorpayContainer.style.display = 'block';
            razorpayContainer.querySelector('#fct_loading_payment_processor').style.color = '#dc3545';
            razorpayContainer.querySelector('#fct_loading_payment_processor').style.fontSize = '14px';
            razorpayContainer.querySelector('#fct_loading_payment_processor').style.padding = '10px';
        }
         
        this.paymentLoader.hideLoader();
        this.paymentLoader?.enableCheckoutButton(this.submitButton?.text || this.$t('Place Order'));
    }
}

window.addEventListener("fluent_cart_load_payments_razorpay", function (e) {
    const translate = window.fluentcart.$t;
    addLoadingText();
    fetch(e.detail.paymentInfoUrl, {
        method: "POST",
        headers: {
            "Content-Type": "application/json",
            "X-WP-Nonce": e.detail.nonce,
        },
        credentials: 'include'
    }).then(async (response) => {
        response = await response.json();
        if (response?.status === 'failed') {
            displayErrorMessage(response?.message);
            return;
        }
        new RazorpayCheckout(e.detail.form, e.detail.orderHandler, response, e.detail.paymentLoader).init();
    }).catch(error => {
        const translations = window.fct_razorpay_data?.translations || {};
        function $t(string) {
            return translations[string] || string;
        }
        let message = error?.message || $t('An error occurred while loading Razorpay.');
        displayErrorMessage(message);
    });

    function displayErrorMessage(message) {
        const errorDiv = document.createElement('div');
        errorDiv.style.color = 'red';
        errorDiv.style.padding = '10px';
        errorDiv.style.fontSize = '14px';
        errorDiv.className = 'fct-error-message';
        errorDiv.textContent = message;

        const razorpayContainer = document.querySelector('.fluent-cart-checkout_embed_payment_container_razorpay');
        if (razorpayContainer) {
            razorpayContainer.appendChild(errorDiv);
        }

        const loadingElement = document.getElementById('fct_loading_payment_processor');
        if (loadingElement) {
            loadingElement.remove();
        }
        return;
    }

    function addLoadingText() {
        let razorpayButtonContainer = document.querySelector('.fluent-cart-checkout_embed_payment_container_razorpay');
        if (razorpayButtonContainer) {
            const loadingMessage = document.createElement('p');
            loadingMessage.id = 'fct_loading_payment_processor';
            const translations = window.fct_razorpay_data?.translations || {};
            function $t(string) {
                return translations[string] || string;
            }
            loadingMessage.textContent = $t('Loading Payment Processor...');
            razorpayButtonContainer.appendChild(loadingMessage);
        }
    }
});
