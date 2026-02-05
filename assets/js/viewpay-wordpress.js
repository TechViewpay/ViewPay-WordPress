(function($) {
    'use strict';

    // Helper function for debug logging
    function debugLog(message) {
        if (typeof viewpayVars !== 'undefined' && viewpayVars.debugEnabled) {
            console.log('ViewPay Debug: ' + message);
        }
    }

    // Fonctions utilitaires pour gérer les cookies avec durée personnalisée
    function setCookie(name, value, minutes) {
        var expires = "";
        if (minutes) {
            var date = new Date();
            date.setTime(date.getTime() + (minutes * 60 * 1000));
            expires = "; expires=" + date.toUTCString();
            debugLog('Cookie côté client défini pour ' + minutes + ' minutes. Expiration: ' + date.toISOString());
        }
        document.cookie = name + "=" + (value || "") + expires + "; path=/";
        debugLog('Cookie défini pour ' + minutes + ' minutes');
    }

    function getCookie(name) {
        var nameEQ = name + "=";
        var ca = document.cookie.split(';');
        for(var i = 0; i < ca.length; i++) {
            var c = ca[i];
            while (c.charAt(0) === ' ') c = c.substring(1, c.length);
            if (c.indexOf(nameEQ) === 0) {
                return decodeURIComponent(c.substring(nameEQ.length, c.length));
            }
        }
        return null;
    }

    // Déclaration des éléments DOM de ViewPay en dehors des fonctions
    var divVPmodal, divCadreJokerlyADS;

    // Fonctions ViewPay
    function VPinitVideo(){
        if (typeof JKFBASQ !== 'undefined') {
            JKFBASQ.init({
                site_id: viewpayVars.siteId,
                load_callback: VPexistAds,
                noads_callback: VPnoAds,
                complete_callback: VPcompleteAds,
                close_callback: VPcloseAds,
                play_callback: VPplayAds,
                cover: false,
            });
        } else {
            console.error('ViewPay: Le script JKFBASQ n\'a pas été chargé correctement');
            hideViewPayButton();
        }
    }

    function VPexistAds(){
        debugLog('Publicité disponible, affichage du bouton et du séparateur');

        // Show button
        $('#viewpay-button').css('display', 'inline').attr('style', 'display: inline !important');
        $('.viewpay-separator').css('display', 'inline').attr('style', 'display: inline !important');

        // Add body class
        $('body').addClass('viewpay-ads-available');

        // For PyMag compatibility
        $('.option--viewpay').css('display', 'block').attr('style', 'display: block !important');
        $('.viewpay-separator-pymag').css('display', 'block').attr('style', 'display: block !important');

        // For custom integration
        $('.viewpay-custom-container').css('display', 'block').attr('style', 'display: block !important');

        // For SwG integration
        $('.viewpay-swg-container').css('display', 'block').attr('style', 'display: block !important');

        // For RRM (Reader Revenue Manager) integration
        $('.viewpay-rrm-container').css('display', 'block').attr('style', 'display: block !important');

        // For TSA Algérie integration
        $('.viewpay-tsa-container').css('display', 'block').attr('style', 'display: block !important');
    }

    function VPnoAds(){
        debugLog('Aucune publicité disponible, masquage du bouton et du séparateur');
        hideViewPayButton();
    }

    function hideViewPayButton() {
        $('#viewpay-button').hide();
        $('.viewpay-separator').hide();
        $('body').removeClass('viewpay-ads-available');
        $('.option--viewpay').hide();
        $('.viewpay-separator-pymag').hide();
        $('.viewpay-custom-container').hide();
        $('.viewpay-swg-container').hide();
        $('.viewpay-rrm-container').hide();
        $('.viewpay-tsa-container').hide();
    }

    function VPloadAds(){
        var modal = document.getElementById("VPmodal");
        if (modal) {
            modal.style.setProperty('display', 'block', 'important');
            modal.classList.add('viewpay-visible');
            if (typeof JKFBASQ !== 'undefined') {
                JKFBASQ.loadAds();
            }
        } else {
            console.error('ViewPay: L\'élément modal n\'existe pas');
        }
    }

    function VPcompleteAds(){
        var modal = document.getElementById("VPmodal");
        if (modal) {
            modal.style.setProperty('display', 'none', 'important');
            modal.classList.remove('viewpay-visible');
        }

        var postId = $('#viewpay-button').data('post-id');
        var nonce = $('#viewpay-button').data('nonce');

        $.ajax({
            url: viewpayVars.ajaxurl,
            type: 'POST',
            data: {
                action: 'viewpay_content',
                post_id: postId,
                nonce: viewpayVars.nonce
            },
            success: function(response) {
                if (response.success) {
                    debugLog('Contenu déverrouillé avec succès. Durée: ' + (response.data.duration_minutes || 'inconnue') + ' minutes');

                    if (response.data.post_id && response.data.duration_minutes) {
                        try {
                            var existingCookie = getCookie('viewpay_unlocked_posts');
                            var cookieData = [];

                            if (existingCookie) {
                                try {
                                    cookieData = JSON.parse(existingCookie);
                                } catch(e) {
                                    cookieData = [];
                                }

                                if (!Array.isArray(cookieData)) {
                                    cookieData = [];
                                }
                            }

                            if (!cookieData.includes(response.data.post_id)) {
                                cookieData.push(response.data.post_id);
                            }

                            setCookie('viewpay_unlocked_posts', JSON.stringify(cookieData), response.data.duration_minutes);
                            debugLog('Cookie client défini avec durée: ' + response.data.duration_minutes + ' minutes');
                        } catch(e) {
                            console.error('ViewPay: Erreur lors de la définition du cookie', e);
                        }
                    }

                    setTimeout(function() {
                        window.location.reload();
                    }, 1000);
                }
            },
            error: function(xhr, status, error) {
                console.error('ViewPay: Erreur lors du déverrouillage du contenu', error);
            }
        });
    }

    function VPcloseAds(){
        var modal = document.getElementById("VPmodal");
        if (modal) {
            modal.style.setProperty('display', 'none', 'important');
            modal.classList.remove('viewpay-visible');
        }
    }

    function VPplayAds(){
        debugLog('Lecture de la publicité démarrée');
    }

    /**
     * Inject ViewPay button for custom paywall integration
     * This handles the JavaScript-based injection for custom paywalls
     */
    function injectCustomPaywallButton() {
        if (viewpayVars.paywallType !== 'custom' || !viewpayVars.customPaywallSelector) {
            return;
        }

        var selector = viewpayVars.customPaywallSelector;
        var $paywall = $(selector);

        if ($paywall.length === 0) {
            debugLog('Custom paywall selector not found: ' + selector);
            return;
        }

        debugLog('Custom paywall found: ' + selector);

        // Check if button already exists
        if ($('#viewpay-button').length > 0) {
            debugLog('ViewPay button already exists, skipping injection');
            return;
        }

        // Get post ID from page (try multiple methods)
        var postId = getPostId();
        if (!postId) {
            debugLog('Could not determine post ID');
            return;
        }

        // Create button HTML
        var buttonHtml = createButtonHtml(postId);

        // Inject based on location setting
        var location = viewpayVars.customButtonLocation || 'after';
        debugLog('Injecting button with location: ' + location);

        switch (location) {
            case 'before':
                $paywall.before(buttonHtml);
                break;
            case 'after':
                $paywall.after(buttonHtml);
                break;
            case 'inside_start':
                $paywall.prepend(buttonHtml);
                break;
            case 'inside_end':
                $paywall.append(buttonHtml);
                break;
            case 'replace':
                $paywall.html(buttonHtml);
                break;
            default:
                $paywall.after(buttonHtml);
        }

        debugLog('ViewPay button injected successfully');
    }

    /**
     * Get post ID from page
     */
    function getPostId() {
        // Try body class
        var bodyClasses = $('body').attr('class');
        if (bodyClasses) {
            var match = bodyClasses.match(/postid-(\d+)/);
            if (match) {
                return parseInt(match[1]);
            }
        }

        // Try article element
        var $article = $('article[id^="post-"]');
        if ($article.length) {
            var articleId = $article.attr('id');
            var match = articleId.match(/post-(\d+)/);
            if (match) {
                return parseInt(match[1]);
            }
        }

        // Try hidden input
        var $postIdInput = $('input[name="post_id"], input[name="comment_post_ID"]');
        if ($postIdInput.length) {
            return parseInt($postIdInput.val());
        }

        // Try data attribute on existing button (if any)
        var $existingButton = $('[data-post-id]');
        if ($existingButton.length) {
            return parseInt($existingButton.data('post-id'));
        }

        return null;
    }

    /**
     * Create button HTML
     */
    function createButtonHtml(postId) {
        var buttonText = viewpayVars.buttonText || 'Débloquer en regardant une publicité';
        var orText = viewpayVars.orText || 'OU';
        var nonce = viewpayVars.nonce;

        var html = '<div class="viewpay-custom-container" style="display: none;">';
        html += '<div class="viewpay-separator">' + escapeHtml(orText) + '</div>';
        html += '<button id="viewpay-button" class="viewpay-button" ';
        html += 'data-post-id="' + postId + '" ';
        html += 'data-nonce="' + nonce + '" ';
        html += 'style="display: none;">';
        html += '<span class="viewpay-icon"></span>';
        html += escapeHtml(buttonText);
        html += '</button>';
        html += '</div>';

        return html;
    }

    /**
     * Escape HTML entities
     */
    function escapeHtml(text) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(text));
        return div.innerHTML;
    }

    function initViewPayElements() {
        if (document.getElementById('VPmodal')) {
            debugLog('Les éléments DOM existent déjà');
            return;
        }

        // Créer les éléments DOM nécessaires pour ViewPay
        divVPmodal = document.createElement('div');
        divCadreJokerlyADS = document.createElement('div');
        divVPmodal.setAttribute('id', 'VPmodal');
        divCadreJokerlyADS.setAttribute('id', 'cadreJokerlyADS');
        divVPmodal.appendChild(divCadreJokerlyADS);

        divVPmodal.style.cssText = 'width: 100% !important; height: 100% !important; display: none; position: fixed !important; top: 0 !important; left: 0 !important; background-color: rgba(0, 0, 0, 0.85) !important; z-index: 9999999 !important;';
        divCadreJokerlyADS.style.cssText = 'margin: auto; top: 0; right: 0; left: 0; position: fixed; bottom: 0; width: 650px !important; height: 450px !important; z-index: 9999999 !important;';

        document.body.appendChild(divVPmodal);

        // Charger le script ViewPay s'il n'existe pas déjà
        if (!document.querySelector('script[src="https://cdn.jokerly.com/scripts/jkFbASQ.js"]')) {
            var vpScript = document.createElement('script');
            vpScript.src = 'https://cdn.jokerly.com/scripts/jkFbASQ.js';
            vpScript.defer = true;
            vpScript.addEventListener('load', VPinitVideo);
            vpScript.addEventListener('error', function() {
                console.error('ViewPay: Erreur lors du chargement du script ViewPay');
                hideViewPayButton();
            });
            document.body.appendChild(vpScript);
        } else {
            VPinitVideo();
        }
    }

    $(document).ready(function() {
        debugLog('Initialisation du plugin');
        debugLog('Paywall type: ' + viewpayVars.paywallType);

        // For custom paywall, inject the button via JS
        if (viewpayVars.paywallType === 'custom') {
            debugLog('Mode custom détecté, injection du bouton via JS');
            injectCustomPaywallButton();
        }

        // For SwG paywall, the button is injected via PHP but we need to handle visibility
        if (viewpayVars.paywallType === 'swg') {
            debugLog('Mode SwG détecté');
            // The SwG integration handles button injection via PHP
            // We just need to make sure the container shows when ads are available
        }

        // For RRM (Reader Revenue Manager) paywall, similar to SwG
        if (viewpayVars.paywallType === 'rrm') {
            debugLog('Mode RRM (Reader Revenue Manager) détecté');
            // The RRM integration handles button injection via PHP
            // We just need to make sure the container shows when ads are available
        }

        // For TSA Algérie custom SwG paywall
        if (viewpayVars.paywallType === 'tsa') {
            debugLog('Mode TSA Algérie détecté');
            // The TSA integration handles button injection via PHP
            // Uses ACF field 'article_premium_google' and filter 'tsa_user_has_access'
        }

        // Initialize ViewPay elements
        initViewPayElements();

        // Handle click on unlock button (works for all integrations including SwG, RRM and TSA)
        $(document).on('click', '#viewpay-button, .viewpay-swg-button, .viewpay-rrm-button, .viewpay-tsa-button', function(e) {
            e.preventDefault();
            debugLog('Bouton cliqué');
            VPloadAds();
        });
    });

    // Expose VPloadAds globally for SwG integration
    window.VPloadAds = VPloadAds;
    window.viewpayLoadAd = VPloadAds;

})(jQuery);
