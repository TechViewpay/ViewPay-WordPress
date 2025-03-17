(function($) {
    'use strict';
    
    // Fonctions utilitaires pour gérer les cookies
    function setCookie(name, value, days) {
        var expires = "";
        if (days) {
            var date = new Date();
            date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
            expires = "; expires=" + date.toUTCString();
        }
        document.cookie = name + "=" + (value || "") + expires + "; path=/";
    }
    
    function getCookie(name) {
        var nameEQ = name + "=";
        var ca = document.cookie.split(';');
        for(var i = 0; i < ca.length; i++) {
            var c = ca[i];
            while (c.charAt(0) === ' ') c = c.substring(1, c.length);
            if (c.indexOf(nameEQ) === 0) {
                // Décoder la valeur URL-encodée avant de la retourner
                return decodeURIComponent(c.substring(nameEQ.length, c.length));
            }
        }
        return null;
    }
    
    $(document).ready(function() {
        // Initialisation du plugin ViewPay
        
        // Gérer le clic sur le bouton de déverrouillage
        $(document).on('click', '#viewpay-button', function(e) {
            e.preventDefault();
            
            var postId = $(this).data('post-id');
            var nonce = $(this).data('nonce');
            
            // Créer la modal
            var modal = $('<div class="viewpay-modal"></div>');
            var modalContent = $('<div class="viewpay-modal-content"></div>');
            
            modalContent.append('<h3>' + viewpayVars.adLoadingText + '</h3>');
            modalContent.append('<div class="viewpay-ad-container"><div class="viewpay-loading"></div></div>');
            modalContent.append('<div class="viewpay-status">' + viewpayVars.adLoadingText + '</div>');
            
            modal.append(modalContent);
            $('body').append(modal);
            
            // Simuler le chargement d'une publicité
            // Remplacez ce code par l'intégration de votre système publicitaire
            setTimeout(function() {
                $('.viewpay-ad-container').html('<iframe src="about:blank" width="468" height="60" frameborder="0" scrolling="no" style="display: block; margin: 0 auto; border: 1px solid #ccc;"></iframe><p style="margin-top: 15px;">Ceci est une publicité simulée.</p>');
                $('.viewpay-status').text(viewpayVars.adWatchingText);
                
                var adDuration = 5; // Secondes pour la démo (normalement 30s)
                var adTimer = setInterval(function() {
                    adDuration--;
                    $('.viewpay-status').text(viewpayVars.adWatchingText + ' (' + adDuration + 's)');
                    
                    if (adDuration <= 0) {
                        clearInterval(adTimer);
                        $('.viewpay-status').text(viewpayVars.adCompleteText);
                        
                        // Envoyer la requête AJAX pour débloquer le contenu
                        $.ajax({
                            url: viewpayVars.ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'viewpay_content',
                                post_id: postId,
                                nonce: viewpayVars.nonce // Utilisation du nonce global
                            },
                            success: function(response) {
                                if (response.success) {
                                    // Afficher un message de succès puis rediriger
                                    $('.viewpay-status').html('<span style="color: green;">✓ ' + response.data.message + '</span>');
                                    
                                    // Définir également un cookie local pour assurer la persistance
                                    if (response.data.post_id) {
                                        try {
                                            // Tenter de définir un cookie supplémentaire côté client
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
                                            
                                            setCookie('viewpay_unlocked_posts', JSON.stringify(cookieData), 1);
                                        } catch(e) {
                                            // Gestion silencieuse des erreurs
                                        }
                                    }
                                    
                                    // Délai pour s'assurer que les cookies sont enregistrés
                                    setTimeout(function() {
                                        // Rechargement simple de la page
                                        window.location.reload();
                                    }, 2000);
                                } else {
                                    $('.viewpay-status').html('<span style="color: red;">✗ ' + response.data.message + '</span>');
                                    modalContent.append('<button class="viewpay-close">Fermer</button>');
                                }
                            },
                            error: function(xhr, status, error) {
                                $('.viewpay-status').html('<span style="color: red;">✗ ' + viewpayVars.adErrorText + '</span>');
                                modalContent.append('<button class="viewpay-close">Fermer</button>');
                            }
                        });
                    }
                }, 1000);
            }, 2000);
        });
        
        // Gérer le clic sur le bouton de fermeture de la modal
        $(document).on('click', '.viewpay-close', function() {
            $('.viewpay-modal').remove();
        });
    });
})(jQuery);
