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
            date.setTime(date.getTime() + (minutes * 60 * 1000)); // Convertir minutes en millisecondes
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
                // Décoder la valeur URL-encodée avant de la retourner
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
                site_id: viewpayVars.siteId, // Récupération de l'ID du site depuis les variables localisées
                load_callback: VPexistAds,
                noads_callback: VPnoAds,
                complete_callback: VPcompleteAds,
                close_callback: VPcloseAds,
                play_callback: VPplayAds,
                cover: false,
            });
        } else {
            console.error('ViewPay: Le script JKFBASQ n\'a pas été chargé correctement');
            // Masquer le bouton en cas d'erreur
            $('#viewpay-button').hide();
            $('.viewpay-separator').hide();
        }
    }
    
    function VPexistAds(){
        // Publicité disponible, on affiche le bouton ViewPay et le séparateur
        //$('#viewpay-button').show();
        //$('.viewpay-separator').show();
        $('#viewpay-button').css('display', 'inline').attr('style', 'display: inline !important');
	$('.viewpay-separator').css('display', 'inline').attr('style', 'display: inline !important');
	debugLog('Publicité disponible, affichage du bouton et du séparateur');

	// Spécifique pour Pyrenees Magazine - afficher l'option ViewPay complète
        $('body').addClass('viewpay-ads-available');
        $('.option--viewpay').css('display', 'block').attr('style', 'display: block !important');
        $('.viewpay-separator-pymag').css('display', 'block').attr('style', 'display: block !important');

    }
    
    function VPnoAds(){
        // Aucune publicité disponible, on masque le bouton ViewPay et le séparateur
        $('#viewpay-button').hide();
        $('.viewpay-separator').hide();
        debugLog('Aucune publicité disponible, masquage du bouton et du séparateur');
	// Spécifique pour Pyrenees Magazine - masquer l'option ViewPay complète
        $('body').removeClass('viewpay-ads-available');
        $('.option--viewpay').hide();
        $('.viewpay-separator-pymag').hide();
    }
    
    function VPloadAds(){
        // Ouvrir ViewPay et charger la publicité
        // S'assurer que le modal est visible avec !important pour contourner d'éventuels conflits CSS
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
        // L'utilisateur a terminé le parcours ViewPay
        var modal = document.getElementById("VPmodal");
        if (modal) {
            modal.style.setProperty('display', 'none', 'important');
            modal.classList.remove('viewpay-visible');
        }
        
        // Récupérer l'ID du post depuis le bouton
        var postId = $('#viewpay-button').data('post-id');
        var nonce = $('#viewpay-button').data('nonce');
        
        // Envoyer la requête AJAX pour débloquer le contenu
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
                    
                    // Définir également un cookie local pour assurer la persistance avec la durée configurée
                    if (response.data.post_id && response.data.duration_minutes) {
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
                            
                            // Utiliser la durée configurée depuis la réponse du serveur
                            setCookie('viewpay_unlocked_posts', JSON.stringify(cookieData), response.data.duration_minutes);
                            debugLog('Cookie client défini avec durée: ' + response.data.duration_minutes + ' minutes');
                        } catch(e) {
                            // Gestion silencieuse des erreurs
                            console.error('ViewPay: Erreur lors de la définition du cookie', e);
                        }
                    }
                    
                    // Délai pour s'assurer que les cookies sont enregistrés
                    setTimeout(function() {
                        // Rechargement simple de la page
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
        // L'utilisateur a fermé le parcours ViewPay
        var modal = document.getElementById("VPmodal");
        if (modal) {
            modal.style.setProperty('display', 'none', 'important');
            modal.classList.remove('viewpay-visible');
        }
    }
    
    function VPplayAds(){
        // Notification lorsque l'utilisateur démarre la vidéo
        debugLog('Lecture de la publicité démarrée');
    }
    
    function initViewPayElements() {
        // Vérifier si les éléments existent déjà
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
        
        // Définir les styles inline pour s'assurer qu'ils sont appliqués
        divVPmodal.style.cssText = 'width: 100% !important; height: 100% !important; display: none; position: fixed !important; top: 0 !important; left: 0 !important; background-color: rgba(0, 0, 0, 0.85) !important; z-index: 9999999 !important;';
        divCadreJokerlyADS.style.cssText = 'margin: auto; top: 0; right: 0; left: 0; position: fixed; bottom: 0; width: 650px !important; height: 450px !important; z-index: 9999999 !important;';
        
        // Ajouter au body
        document.body.appendChild(divVPmodal);
        
        // Charger le script ViewPay s'il n'existe pas déjà
        if (!document.querySelector('script[src="https://cdn.jokerly.com/scripts/jkFbASQ.js"]')) {
            var vpScript = document.createElement('script');
            vpScript.src = 'https://cdn.jokerly.com/scripts/jkFbASQ.js';
            vpScript.defer = true;
            vpScript.addEventListener('load', VPinitVideo);
            vpScript.addEventListener('error', function() {
                console.error('ViewPay: Erreur lors du chargement du script ViewPay');
                $('#viewpay-button').hide();
                $('.viewpay-separator').hide();
            });
            document.body.appendChild(vpScript);
        } else {
            // Le script existe déjà, initialiser ViewPay directement
            VPinitVideo();
        }
    }
    
    $(document).ready(function() {
        debugLog('Initialisation du plugin');
        
        // Masquer le bouton et le séparateur par défaut jusqu'à ce qu'on sache si des publicités sont disponibles
        // Note: maintenant géré via CSS inline dans les templates HTML
        
        // Initialiser les éléments ViewPay
        initViewPayElements();
        
        // Gérer le clic sur le bouton de déverrouillage
        $(document).on('click', '#viewpay-button', function(e) {
            e.preventDefault();
            debugLog('Bouton cliqué');
            // Charger la publicité ViewPay
            VPloadAds();
        });
    });
})(jQuery);
