/**
 * Razorpay Checkout Handler for FluentCart (Vanilla JS)
 */
(function() {
    'use strict';

    window.FluentCartRazorpay = {
        /**
         * Initialize Razorpay checkout
         */
        init: function(paymentArgs, formElement) {
            const checkoutType = paymentArgs.checkout_type || 'modal';
            const modalData = paymentArgs.modal_data;

            if (checkoutType === 'modal' && modalData) {
                // Modal checkout
                this.initModalCheckout(modalData, paymentArgs);
            } else if (paymentArgs.checkout_url) {
                // Hosted checkout (redirect)
                window.location.href = paymentArgs.checkout_url;
            } else {
                console.error('Razorpay checkout data not found');
            }
        },

        /**
         * Initialize modal checkout with Razorpay
         */
        initModalCheckout: function(modalData, paymentArgs) {
            // Check if Razorpay checkout script is loaded
            if (typeof Razorpay === 'undefined') {
                console.error('Razorpay checkout script not loaded');
                // Fallback to hosted checkout if available
                if (paymentArgs.checkout_url) {
                    window.location.href = paymentArgs.checkout_url;
                }
                return;
            }

            // Hide processing div
            var processingDiv = document.querySelector('.fc-order-processing');
            if (processingDiv) {
                processingDiv.classList.add('hidden');
                processingDiv.style.display = 'none';
            }

            // Create Razorpay checkout options
            var options = {
                key: modalData.key,
                amount: modalData.amount,
                currency: modalData.currency,
                name: modalData.name || 'Payment',
                description: modalData.description || '',
                order_id: modalData.order_id,
                handler: function(response) {
                    // Payment successful - verify with backend
                    window.FluentCartRazorpay.confirmPayment(response, paymentArgs);
                },
                prefill: modalData.prefill || {},
                theme: modalData.theme || {
                    color: '#3399cc'
                },
                modal: {
                    ondismiss: function() {
                        // User closed the modal
                        console.log('Razorpay modal closed');
                        // Re-enable checkout button
                        if (window.fluentCartCheckout && window.fluentCartCheckout.checkoutHandler) {
                            var handler = window.fluentCartCheckout.checkoutHandler;
                            handler.cleanupAfterProcessing();
                        }
                    }
                }
            };

            // Open Razorpay checkout
            var razorpay = new Razorpay(options);
            razorpay.open();
        },

        /**
         * Confirm payment with backend
         */
        confirmPayment: function(razorpayResponse, paymentArgs) {
            // Show loading state
            var processingDiv = document.querySelector('.fc-order-processing');
            if (processingDiv) {
                processingDiv.classList.remove('hidden');
                processingDiv.style.display = '';
            }

            // Get AJAX URL
            var ajaxUrl = window.fct_razorpay_data?.ajax_url || window.ajaxurl || '/wp-admin/admin-ajax.php';

            // Prepare data
            var data = {
                action: 'fluent_cart_razorpay_confirm_payment',
                razorpay_payment_id: razorpayResponse.razorpay_payment_id,
                razorpay_order_id: razorpayResponse.razorpay_order_id,
                razorpay_signature: razorpayResponse.razorpay_signature,
                transaction_hash: paymentArgs.transaction_hash || ''
            };

            // Add nonce if available
            if (window.fluentCartRestVars?.rest?.nonce) {
                data.nonce = window.fluentCartRestVars.rest.nonce;
            }

            // Make AJAX call
            var xhr = new XMLHttpRequest();
            xhr.open('POST', ajaxUrl, true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            
            xhr.onload = function() {
                if (xhr.status === 200) {
                    try {
                        var response = JSON.parse(xhr.responseText);
                        
                        if (response.success) {
                            // Payment confirmed - redirect to success page
                            if (response.data.redirect_url) {
                                window.location.href = response.data.redirect_url;
                            } else {
                                // Reload to show success message
                                window.location.reload();
                            }
                        } else {
                            // Payment verification failed
                            alert(response.data?.message || 'Payment verification failed. Please contact support.');
                            window.location.reload();
                        }
                    } catch (e) {
                        console.error('Error parsing response:', e);
                        alert('An error occurred. Please contact support.');
                        window.location.reload();
                    }
                } else {
                    alert('Payment verification failed. Please contact support.');
                    window.location.reload();
                }
            };

            xhr.onerror = function() {
                alert('Network error. Please try again.');
                window.location.reload();
            };

            // Convert data to URL-encoded format
            var params = [];
            for (var key in data) {
                if (data.hasOwnProperty(key)) {
                    params.push(encodeURIComponent(key) + '=' + encodeURIComponent(data[key]));
                }
            }

            xhr.send(params.join('&'));
        }
    };

    // Register with FluentCart payment system
    if (window.fluentCartCheckout) {
        window.fluentCartCheckout.registerPaymentHandler('razorpay', function(paymentArgs, formElement) {
            window.FluentCartRazorpay.init(paymentArgs, formElement);
        });
    }

    // Listen for modal action trigger
    window.addEventListener('fluent_cart_payment_next_action_razorpay', function(e) {
        try {
            var payload = e?.detail?.response;
            var paymentArgs = payload?.payment_args || {};
            window.FluentCartRazorpay.init(paymentArgs, null);
        } catch (err) {
            console.error('Razorpay modal init failed', err);
        }
    });

})();

