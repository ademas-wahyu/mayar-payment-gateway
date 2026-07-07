/**
 * Mayar Payment Checkout Modal
 *
 * Intercepts WooCommerce checkout redirect for Mayar.id payments
 * and shows a modal with the payment page instead of a full redirect.
 *
 * @package Mayar_With_Vodeco
 */
(function ($) {
    'use strict';

    var MayarPaymentModal = {

        /** @var {Object} Localized data from PHP */
        config: window.MayarCheckoutData || {},

        /** @var {jQuery|null} Modal overlay element */
        $modal: null,

        /** @var {number|null} Polling interval timer ID */
        pollTimer: null,

        /** @var {number} Current poll count (for interval adjustment) */
        pollCount: 0,

        /** @var {number} Poll interval in ms */
        pollInterval: 3000,

        /** @var {number} Max polls before timeout (10 min = 200 polls at 3s) */
        maxPolls: 200,

        /** @var {number|null} Timeout timer ID */
        timeoutTimer: null,

        /** @var {number} Payment timeout in ms (10 minutes) */
        paymentTimeout: 600000,

        /** @var {boolean} Whether payment is currently pending */
        isPending: false,

        /** @var {string|null} Current order ID being paid */
        currentOrderId: null,

        /** @var {Window|null} Popup window reference (fallback) */
        popupWindow: null,

        /**
         * Initialize — bind to WooCommerce checkout events
         */
        init: function () {
            var self = this;

            // Intercept checkout success redirect
            $('form.checkout').on('checkout_place_order_success', function (e, result, checkoutForm) {
                return self.onCheckoutSuccess(e, result, checkoutForm);
            });

            // Handle pending payment on page load
            self.checkPendingPayment();
        },

        /**
         * Called when checkout AJAX succeeds
         *
         * @param {jQuery.Event} e
         * @param {Object} result  WC checkout result {result, redirect, order_id}
         * @param {Object} form    WC checkout form handler
         * @return {boolean|undefined} false to prevent redirect, undefined to allow
         */
        onCheckoutSuccess: function (e, result, form) {
            // Only intercept Mayar payments
            var selectedMethod = $('input[name="payment_method"]:checked').val();
            if (selectedMethod !== 'mayar') {
                return; // allow normal redirect
            }

            if (!result.redirect) {
                return;
            }

            var orderId = result.order_id || '';

            if (typeof console !== 'undefined') {
                console.log('[Mayar] Checkout success — redirect:', result.redirect, 'order_id:', orderId);
            }

            // Show the payment modal
            this.showModal(result.redirect, orderId);

            // Prevent the default WC redirect
            return false;
        },

        /**
         * Show the payment modal with iframe
         *
         * @param {string} paymentUrl  Mayar payment page URL
         * @param {string} orderId     WooCommerce order ID
         */
        showModal: function (paymentUrl, orderId) {
            var self = this;

            // Remove any existing modal
            this.hideModal(false);

            // Build and inject modal DOM
            this.createModalDOM();

            // Set state
            this.isPending = true;
            this.currentOrderId = orderId;
            this.pollCount = 0;

            // Load payment page in iframe
            this.loadIframe(paymentUrl);

            // Show modal
            this.$modal.removeAttr('hidden');

            // Start polling for payment status
            this.startPolling(orderId);

            // Start payment timeout
            this.startTimeout();

            // Attach beforeunload warning
            this.attachBeforeUnload();

            // Re-init lucide icons if available
            if (typeof lucide !== 'undefined') {
                lucide.createIcons();
            }
        },

        /**
         * Hide and remove the modal
         *
         * @param {boolean} notify Whether to show toast notification
         */
        hideModal: function (notify) {
            if (notify === undefined) {
                notify = true;
            }

            this.stopPolling();
            this.stopTimeout();
            this.detachBeforeUnload();
            this.closePopup();

            if (this.$modal) {
                this.$modal.attr('hidden', '');
                this.$modal.remove();
                this.$modal = null;
            }

            this.isPending = false;
            this.currentOrderId = null;

            if (notify && this.config.i18n) {
                this.showToast(this.config.i18n.paymentPending, 'warning');
            }
        },

        /**
         * Create the modal DOM structure and append to #oct-modal-container
         */
        createModalDOM: function () {
            var i18n = this.config.i18n || {};

            var html = '' +
                '<div class="oct-modal-overlay" id="mayar-payment-modal" hidden>' +
                '  <div class="oct-modal oct-modal--lg" role="dialog" aria-modal="true" aria-labelledby="mayar-modal-title">' +
                '    <div class="oct-modal__header">' +
                '      <h3 class="oct-modal__title" id="mayar-modal-title">' +
                '        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: -3px; margin-right: 6px;"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/></svg>' +
                '        ' + (i18n.title || 'Pembayaran Mayar.id') +
                '      </h3>' +
                '      <button type="button" class="oct-modal__close mayar-modal-close" aria-label="Tutup">' +
                '        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>' +
                '      </button>' +
                '    </div>' +
                '    <div class="oct-modal__body mayar-modal-body">' +
                '      <div class="mayar-payment-status" id="mayar-payment-status">' +
                '        <div class="mayar-payment-status__spinner"></div>' +
                '        <span class="mayar-payment-status__text">' + (i18n.waiting || 'Menunggu pembayaran...') + '</span>' +
                '      </div>' +
                '      <div class="mayar-payment-iframe-wrap" id="mayar-iframe-wrap">' +
                '        <iframe id="mayar-payment-iframe" title="Halaman Pembayaran Mayar" loading="eager"></iframe>' +
                '        <div class="mayar-payment-fallback" id="mayar-payment-fallback" hidden>' +
                '          <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="color: var(--oct-text-muted, #999); margin-bottom: 12px;"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/></svg>' +
                '          <p>' + (i18n.iframeError || 'Gagal memuat halaman pembayaran di dalam popup.') + '</p>' +
                '          <a class="oct-btn oct-btn--primary mayar-open-tab" href="#" target="_blank" rel="noopener">' +
                '            ' + (i18n.openInNewTab || 'Buka Halaman Pembayaran') +
                '          </a>' +
                '        </div>' +
                '      </div>' +
                '    </div>' +
                '  </div>' +
                '</div>';

            var $container = $('#oct-modal-container');
            if (!$container.length) {
                $container = $('<div id="oct-modal-container"></div>').appendTo('body');
            }

            this.$modal = $(html).appendTo($container);

            // Bind close events
            var self = this;
            this.$modal.find('.mayar-modal-close').on('click', function () {
                self.handleModalClose();
            });
            this.$modal.on('click', function (e) {
                if ($(e.target).hasClass('oct-modal-overlay')) {
                    self.handleModalClose();
                }
            });
            $(document).on('keydown.mayarModal', function (e) {
                if (e.key === 'Escape' && self.$modal && !self.$modal.attr('hidden')) {
                    self.handleModalClose();
                }
            });

            // Bind fallback open-in-tab link
            this.$modal.find('.mayar-open-tab').on('click', function () {
                self.openPopup($(this).attr('href'));
            });
        },

        /**
         * Load the Mayar payment URL in the iframe
         *
         * @param {string} url Payment page URL
         */
        loadIframe: function (url) {
            var self = this;
            var $iframe = $('#mayar-payment-iframe');
            var $fallback = $('#mayar-payment-fallback');
            var loadTimer = null;

            // Set fallback link
            this.$modal.find('.mayar-open-tab').attr('href', url);

            // Detect iframe load failure (fires too fast = blocked)
            $iframe.on('load', function () {
                if (loadTimer) {
                    clearTimeout(loadTimer);
                }
                // If load fires almost immediately, iframe was likely blocked
                loadTimer = setTimeout(function () {
                    // Try to detect if iframe is actually blank
                    try {
                        var iframeDoc = $iframe[0].contentDocument || $iframe[0].contentWindow.document;
                        if (!iframeDoc || !iframeDoc.body || !iframeDoc.body.innerHTML) {
                            self.showFallback(url);
                        }
                    } catch (e) {
                        // Cross-origin = iframe loaded successfully (good!)
                    }
                }, 1000);
            });

            $iframe.on('error', function () {
                self.showFallback(url);
            });

            // Set the src
            $iframe.attr('src', url);

            // Safety net: if iframe doesn't fire load within 5s, show fallback
            setTimeout(function () {
                if ($iframe.attr('src') && $fallback.attr('hidden')) {
                    try {
                        var doc = $iframe[0].contentDocument;
                        if (!doc || !doc.body || !doc.body.innerHTML) {
                            self.showFallback(url);
                        }
                    } catch (e) {
                        // Cross-origin = it's working
                    }
                }
            }, 5000);
        },

        /**
         * Show the fallback UI when iframe fails
         *
         * @param {string} url Payment URL
         */
        showFallback: function (url) {
            $('#mayar-iframe-wrap').hide();
            $('#mayar-payment-fallback').removeAttr('hidden').show();
            this.$modal.find('.mayar-open-tab').attr('href', url);

            // Also try popup
            this.openPopup(url);
        },

        /**
         * Open payment page in a popup window (fallback)
         *
         * @param {string} url Payment URL
         */
        openPopup: function (url) {
            var self = this;

            // Close existing popup
            this.closePopup();

            // Open new popup
            this.popupWindow = window.open(url, 'mayar_payment', 'width=500,height=700,scrollbars=yes,resizable=yes');

            // Monitor popup closure
            var popupCheck = setInterval(function () {
                if (self.popupWindow && self.popupWindow.closed) {
                    clearInterval(popupCheck);
                    // Don't hide modal — polling is still running
                }
            }, 1000);
        },

        /**
         * Close popup window if open
         */
        closePopup: function () {
            if (this.popupWindow && !this.popupWindow.closed) {
                this.popupWindow.close();
            }
            this.popupWindow = null;
        },

        /**
         * Handle modal close request (with confirmation if pending)
         */
        handleModalClose: function () {
            var self = this;

            if (this.isPending) {
                var confirmed = window.confirm(this.config.i18n.closeConfirm || 'Pembayaran belum selesai. Yakin ingin menutup?');
                if (!confirmed) {
                    return;
                }

                // Notify server
                if (this.currentOrderId) {
                    $.post(this.config.ajaxUrl, {
                        action: 'mayar_cancel_payment',
                        order_id: this.currentOrderId,
                        nonce: this.config.nonce
                    });
                }
            }

            this.hideModal(this.isPending);
        },

        /**
         * Start polling for payment status
         *
         * @param {string} orderId
         */
        startPolling: function (orderId) {
            var self = this;
            this.pollCount = 0;

            this.pollTimer = setInterval(function () {
                self.checkStatus(orderId);
            }, this.pollInterval);
        },

        /**
         * Stop polling
         */
        stopPolling: function () {
            if (this.pollTimer) {
                clearInterval(this.pollTimer);
                this.pollTimer = null;
            }
        },

        /**
         * AJAX call to check payment status
         *
         * @param {string} orderId
         */
        checkStatus: function (orderId) {
            var self = this;

            if (!this.isPending) {
                self.stopPolling();
                return;
            }

            self.pollCount++;

            // Adjust polling interval after 20 polls (60 seconds)
            if (self.pollCount === 20) {
                self.stopPolling();
                self.pollTimer = setInterval(function () {
                    self.checkStatus(orderId);
                }, 5000);
            }

            // Stop after max polls
            if (self.pollCount > self.maxPolls) {
                self.onTimeout();
                return;
            }

            $.ajax({
                url: self.config.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'mayar_check_payment_status',
                    order_id: orderId,
                    nonce: self.config.nonce
                },
                success: function (response) {
                    if (!response.success || !response.data) {
                        return;
                    }

                    var status = response.data.status;
                    var orderUrl = response.data.order_url;

                    if (typeof console !== 'undefined') {
                        console.log('[Mayar Poll #' + self.pollCount + ']', 'status:', status, 'order_url:', orderUrl);
                    }

                    // Check for paid/success (be flexible with status values)
                    var paidStatuses = ['paid', 'settlement', 'capture', 'completed', 'success'];
                    if (paidStatuses.indexOf(status) !== -1) {
                        self.onPaymentComplete(orderUrl);
                    } else if (status === 'expired' || status === 'closed' || status === 'failed') {
                        self.onPaymentFailed(response.data.status_label);
                    }
                    // 'pending' and others → keep polling
                },
                error: function (xhr, status, error) {
                    if (typeof console !== 'undefined') {
                        console.log('[Mayar Poll #' + self.pollCount + ']', 'AJAX error:', status, error);
                    }
                }
            });
        },

        /**
         * Update the status indicator bar UI
         *
         * @param {string} state   pending|success|error|warning
         * @param {string} message Status text to display
         */
        updateStatusUI: function (state, message) {
            var $status = $('#mayar-payment-status');
            $status.removeClass('mayar-payment-status--pending mayar-payment-status--success mayar-payment-status--error mayar-payment-status--warning');

            if (state === 'pending') {
                $status.addClass('mayar-payment-status--pending');
                $status.find('.mayar-payment-status__spinner').show();
            } else if (state === 'success') {
                $status.addClass('mayar-payment-status--success');
                $status.find('.mayar-payment-status__spinner').hide();
            } else if (state === 'error') {
                $status.addClass('mayar-payment-status--error');
                $status.find('.mayar-payment-status__spinner').hide();
            } else if (state === 'warning') {
                $status.addClass('mayar-payment-status--warning');
                $status.find('.mayar-payment-status__spinner').hide();
            }

            $status.find('.mayar-payment-status__text').text(message);
        },

        /**
         * Called when payment is confirmed complete
         *
         * @param {string} orderUrl Thank-you page URL
         */
        onPaymentComplete: function (orderUrl) {
            var self = this;

            self.stopPolling();
            self.stopTimeout();
            self.isPending = false;

            if (typeof console !== 'undefined') {
                console.log('[Mayar] Payment complete! Redirecting to:', orderUrl);
            }

            // Update UI
            var i18n = self.config.i18n || {};
            self.updateStatusUI('success', i18n.success || 'Pembayaran Berhasil!');

            // Hide iframe, show success
            $('#mayar-iframe-wrap').hide();
            $('#mayar-payment-fallback').hide();

            // Remove beforeunload warning
            self.detachBeforeUnload();

            // Redirect after delay (give server time to finalize order)
            setTimeout(function () {
                if (orderUrl) {
                    // Add cache-busting param to ensure fresh page load
                    var separator = orderUrl.indexOf('?') === -1 ? '?' : '&';
                    window.location.href = orderUrl + separator + '_t=' + Date.now();
                } else {
                    // Fallback: reload current page (which is checkout, order should now be paid)
                    window.location.reload();
                }
            }, 2500);
        },

        /**
         * Called when payment failed or expired
         *
         * @param {string} message Status message
         */
        onPaymentFailed: function (message) {
            var self = this;

            self.stopPolling();
            self.stopTimeout();
            self.isPending = false;

            // Update UI
            self.updateStatusUI('error', message);

            // Hide iframe
            $('#mayar-iframe-wrap').hide();
            $('#mayar-payment-fallback').hide();

            // Show retry button
            var i18n = self.config.i18n || {};
            var $retry = $('<button type="button" class="oct-btn oct-btn--primary mayar-retry-btn">' + (i18n.retry || 'Coba Lagi') + '</button>');
            $retry.on('click', function () {
                self.hideModal(false);
                // Re-enable checkout form
                $(document.body).trigger('update_checkout');
                // Scroll to payment methods
                $('form.checkout').find('#payment').show();
            });
            self.$modal.find('.oct-modal__body').append($retry);
        },

        /**
         * Called when payment times out
         */
        onTimeout: function () {
            var i18n = this.config.i18n || {};
            this.onPaymentFailed(i18n.timeout || 'Waktu Habis');
        },

        /**
         * Start the payment timeout timer
         */
        startTimeout: function () {
            var self = this;
            this.timeoutTimer = setTimeout(function () {
                self.onTimeout();
            }, this.paymentTimeout);
        },

        /**
         * Stop the timeout timer
         */
        stopTimeout: function () {
            if (this.timeoutTimer) {
                clearTimeout(this.timeoutTimer);
                this.timeoutTimer = null;
            }
        },

        /**
         * Attach beforeunload warning
         */
        attachBeforeUnload: function () {
            var self = this;
            this._beforeUnloadHandler = function (e) {
                if (self.isPending) {
                    e.preventDefault();
                    e.returnValue = '';
                }
            };
            $(window).on('beforeunload', this._beforeUnloadHandler);
        },

        /**
         * Detach beforeunload warning
         */
        detachBeforeUnload: function () {
            if (this._beforeUnloadHandler) {
                $(window).off('beforeunload', this._beforeUnloadHandler);
                this._beforeUnloadHandler = null;
            }
        },

        /**
         * Check for pending payment on page load (handles page reload)
         */
        checkPendingPayment: function () {
            var self = this;

            // Look for mayar-pending-payment-banner in the page
            var $banner = $('.mayar-pending-payment-banner');
            if (!$banner.length) {
                return;
            }

            var paymentUrl = $banner.data('payment-url');
            var orderId = $banner.data('order-id');

            if (!paymentUrl || !orderId) {
                return;
            }

            // Show banner
            $banner.show();

            $banner.find('.mayar-continue-pay').on('click', function (e) {
                e.preventDefault();
                self.showModal(paymentUrl, orderId);
                $banner.hide();
            });

            $banner.find('.mayar-dismiss-pay').on('click', function (e) {
                e.preventDefault();
                $banner.hide();
            });
        },

        /**
         * Show a toast notification
         *
         * @param {string} message
         * @param {string} type     success|warning|error
         */
        showToast: function (message, type) {
            if (!message) {
                return;
            }

            var $toast = $('<div class="mayar-toast mayar-toast--' + (type || 'info') + '">' + message + '</div>');
            $('body').append($toast);

            setTimeout(function () {
                $toast.addClass('mayar-toast--visible');
            }, 50);

            setTimeout(function () {
                $toast.removeClass('mayar-toast--visible');
                setTimeout(function () {
                    $toast.remove();
                }, 300);
            }, 4000);
        }
    };

    // Initialize when DOM is ready
    $(function () {
        MayarPaymentModal.init();
    });

})(jQuery);
