<?php
/**
 * ViewPay Integration with TSA Algérie (Custom SwG Implementation)
 *
 * Cette intégration est spécifique au site TSA Algérie qui utilise une
 * implémentation custom de Subscribe with Google.
 *
 * Architecture TSA :
 * - Hook wp_head (priorité 30) pour injecter swg-basic.js
 * - ACF field 'article_premium_google' pour marquer les articles premium
 * - Troncature server-side dans single.php via wp_trim_words()
 * - Filtre 'tsa_user_has_access' pour contrôler l'accès
 *
 * Stratégie ViewPay :
 * - Le bouton ViewPay est injecté DANS le modal SwG (en dessous)
 * - Utilise un MutationObserver pour détecter l'apparition du modal
 * - Position fixed avec z-index maximum pour rester visible
 *
 * @package ViewPay_WordPress
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class for integrating ViewPay with TSA Algérie's custom SwG implementation
 */
class ViewPay_TSA_Integration {

    /**
     * Reference to the main plugin class
     */
    private $main;

    /**
     * Constructor
     *
     * @param ViewPay_WordPress $main_instance Main plugin instance
     */
    public function __construct($main_instance) {
        $this->main = $main_instance;
        $this->init();
    }

    /**
     * Check if debug mode is enabled
     *
     * @return bool True if debug logs are enabled
     */
    private function is_debug_enabled() {
        return $this->main->get_option('enable_debug_logs') === 'yes';
    }

    /**
     * Initialize the integration
     */
    public function init() {
        // Hook principal : intercepter le filtre TSA pour contrôler l'accès
        add_filter('tsa_user_has_access', array($this, 'check_viewpay_access'), 10, 1);

        // Hook pour désactiver le script SwG si débloqué via ViewPay
        add_action('wp_head', array($this, 'maybe_disable_swg_script'), 1);

        // Hook pour injecter le JavaScript qui attache le bouton au modal SwG
        add_action('wp_footer', array($this, 'inject_swg_modal_script'), 100);

        // Ajouter les classes body
        add_filter('body_class', array($this, 'add_body_classes'));

        // Ajouter les styles CSS spécifiques TSA
        add_action('wp_enqueue_scripts', array($this, 'enqueue_tsa_styles'));

        if ($this->is_debug_enabled()) {
            error_log('ViewPay TSA: Integration initialized');
        }
    }

    /**
     * Check if user has access via ViewPay
     * This hooks into the 'tsa_user_has_access' filter
     *
     * @param bool $has_access Current access status (usually is_user_logged_in())
     * @return bool Modified access status
     */
    public function check_viewpay_access($has_access) {
        if ($this->is_debug_enabled()) {
            error_log('ViewPay TSA: check_viewpay_access called with has_access=' . ($has_access ? 'true' : 'false'));
        }

        // Si déjà accès (utilisateur connecté), ne pas modifier
        if ($has_access) {
            return $has_access;
        }

        // Récupérer le post ID de plusieurs façons pour être robuste
        $post_id = null;

        // Méthode 1: global $post
        global $post;
        if ($post && isset($post->ID)) {
            $post_id = $post->ID;
        }

        // Méthode 2: get_queried_object() - plus fiable dans certains contextes
        if (!$post_id) {
            $queried = get_queried_object();
            if ($queried && isset($queried->ID)) {
                $post_id = $queried->ID;
            }
        }

        // Méthode 3: get_the_ID()
        if (!$post_id) {
            $post_id = get_the_ID();
        }

        if ($this->is_debug_enabled()) {
            error_log('ViewPay TSA: Post ID resolved to: ' . ($post_id ?: 'null'));
        }

        if (!$post_id) {
            if ($this->is_debug_enabled()) {
                error_log('ViewPay TSA: No post ID found, returning original has_access');
            }
            return $has_access;
        }

        // Vérifier si débloqué via ViewPay
        if ($this->main->is_post_unlocked($post_id)) {
            if ($this->is_debug_enabled()) {
                error_log('ViewPay TSA: Access GRANTED via ViewPay for post ' . $post_id);
            }
            return true;
        }

        if ($this->is_debug_enabled()) {
            error_log('ViewPay TSA: Access NOT granted for post ' . $post_id . ', returning original has_access');
        }

        return $has_access;
    }

    /**
     * Check if current post is a premium article (using ACF field)
     *
     * @param int $post_id Post ID
     * @return bool True if premium
     */
    private function is_premium_article($post_id) {
        if (!function_exists('get_field')) {
            return false;
        }

        return (bool) get_field('article_premium_google', $post_id);
    }

    /**
     * Maybe disable SwG script output if content is unlocked via ViewPay
     * Runs at priority 1 on wp_head, before TSA's hook at priority 30
     */
    public function maybe_disable_swg_script() {
        if (!is_single()) {
            return;
        }

        global $post;

        if (!$post) {
            return;
        }

        // Si débloqué via ViewPay, ajouter un script pour neutraliser SwG
        if ($this->main->is_post_unlocked($post->ID)) {
            if ($this->is_debug_enabled()) {
                error_log('ViewPay TSA: Disabling SwG prompts for unlocked post ' . $post->ID);
            }

            // Injecter un script qui empêche SwG de montrer le paywall
            ?>
            <script type="text/javascript">
            // ViewPay: Disable SwG paywall for unlocked content
            (function() {
                var swgHidden = false; // Flag pour éviter les logs répétés

                // Fonction pour cacher les éléments SwG (SwG utilise !important inline, on doit forcer via JS)
                function hideSwgElements() {
                    var swgDialog = document.querySelector('iframe.swg-dialog');
                    var swgPopup = document.querySelector('swg-popup-background');
                    var swgContainer = document.querySelector('.swg-container');
                    var didHide = false;

                    if (swgDialog) {
                        swgDialog.style.setProperty('display', 'none', 'important');
                        swgDialog.style.setProperty('visibility', 'hidden', 'important');
                        didHide = true;
                    }
                    if (swgPopup) {
                        swgPopup.style.setProperty('display', 'none', 'important');
                        swgPopup.style.setProperty('visibility', 'hidden', 'important');
                        didHide = true;
                    }
                    if (swgContainer) {
                        swgContainer.style.setProperty('display', 'none', 'important');
                        didHide = true;
                    }

                    // Réactiver le scroll (SwG le désactive via overflow:hidden)
                    if (didHide) {
                        document.body.style.setProperty('overflow', 'auto', 'important');
                        document.documentElement.style.setProperty('overflow', 'auto', 'important');

                        // Log une seule fois
                        if (!swgHidden) {
                            swgHidden = true;
                            console.log('ViewPay TSA: SwG elements hidden, content unlocked');
                        }
                    }
                }

                // Cacher immédiatement si déjà présents
                hideSwgElements();

                // Observer pour cacher dès qu'ils apparaissent
                var observer = new MutationObserver(function(mutations) {
                    hideSwgElements();
                });

                // Observer le body pour les nouveaux éléments
                if (document.body) {
                    observer.observe(document.body, { childList: true, subtree: true });
                } else {
                    document.addEventListener('DOMContentLoaded', function() {
                        observer.observe(document.body, { childList: true, subtree: true });
                        hideSwgElements();
                    });
                }

                // Fallback: vérifier périodiquement pendant les 5 premières secondes
                var checkCount = 0;
                var checkInterval = setInterval(function() {
                    hideSwgElements();
                    checkCount++;
                    if (checkCount >= 10) {
                        clearInterval(checkInterval);
                    }
                }, 500);

                // Override SWG_BASIC to prevent paywall display
                window.SWG_BASIC = window.SWG_BASIC || [];
                var originalPush = window.SWG_BASIC.push;
                window.SWG_BASIC.push = function(callback) {
                    // Wrap the callback to intercept and modify behavior
                    return originalPush.call(this, function(basicSubscriptions) {
                        // Override init to use open access
                        var originalInit = basicSubscriptions.init;
                        basicSubscriptions.init = function(config) {
                            // Force open access product ID
                            if (config && config.isPartOfProductId) {
                                // Change to open access if it contains premium
                                if (config.isPartOfProductId.indexOf('premium') !== -1) {
                                    config.isPartOfProductId = config.isPartOfProductId.replace(/premium.*$/, 'openaccess');
                                }
                            }
                            return originalInit.call(this, config);
                        };
                        callback(basicSubscriptions);
                    });
                };
            })();
            </script>
            <?php
        }
    }

    /**
     * Inject JavaScript to attach ViewPay button to SwG modal
     */
    public function inject_swg_modal_script() {
        if (!is_single()) {
            return;
        }

        global $post;

        if (!$post) {
            return;
        }

        // Ne pas injecter si admin
        if (current_user_can('manage_options')) {
            return;
        }

        // Ne pas injecter si déjà débloqué via ViewPay
        if ($this->main->is_post_unlocked($post->ID)) {
            return;
        }

        // Ne pas injecter si utilisateur connecté (a déjà accès)
        if (is_user_logged_in()) {
            return;
        }

        // Vérifier si c'est un article premium
        if (!$this->is_premium_article($post->ID)) {
            return;
        }

        $button_text = __('Accès gratuit avec une pub', 'viewpay-wordpress');
        $nonce = wp_create_nonce('viewpay_nonce');

        // Récupérer les options de positionnement configurables
        $desktop_offset = (int) $this->main->get_option('tsa_desktop_offset');
        $mobile_bottom = (int) $this->main->get_option('tsa_mobile_bottom');

        // Valeurs par défaut si non définies
        if ($desktop_offset === 0 && $this->main->get_option('tsa_desktop_offset') === null) {
            $desktop_offset = -86;
        }
        if ($mobile_bottom === 0 && $this->main->get_option('tsa_mobile_bottom') === null) {
            $mobile_bottom = 60;
        }

        if ($this->is_debug_enabled()) {
            error_log('ViewPay TSA: Injecting SwG modal script for post ' . $post->ID);
            error_log('ViewPay TSA: Desktop offset: ' . $desktop_offset . ', Mobile bottom: ' . $mobile_bottom);
        }
        ?>
        <script type="text/javascript">
        (function() {
            'use strict';

            var debugEnabled = <?php echo $this->is_debug_enabled() ? 'true' : 'false'; ?>;
            var postId = <?php echo (int) $post->ID; ?>;
            var nonce = '<?php echo esc_js($nonce); ?>';
            var buttonText = '<?php echo esc_js($button_text); ?>';
            var desktopOffset = <?php echo (int) $desktop_offset; ?>;
            var mobileBottom = <?php echo (int) $mobile_bottom; ?>;
            var viewpayAttached = false;

            function log(msg) {
                if (debugEnabled) {
                    console.log('ViewPay TSA: ' + msg);
                }
            }

            function hideSwgAndLoadAd() {
                // Cacher le modal SwG
                var swgDialog = document.querySelector('iframe.swg-dialog');
                if (swgDialog) swgDialog.style.display = 'none';

                // Cacher le background SwG
                var swgBg = document.querySelector('swg-popup-background');
                if (swgBg) swgBg.style.display = 'none';

                // Cacher notre bouton
                var vpBtn = document.getElementById('viewpay-swg-attachment');
                if (vpBtn) vpBtn.style.display = 'none';

                // Ouvrir le modal ViewPay
                if (typeof window.VPloadAds === 'function') {
                    window.VPloadAds();
                }
            }

            function createViewPayButton(isMobile, modalWidth) {
                var container = document.createElement('div');
                container.id = 'viewpay-swg-attachment';
                var btn = document.createElement('button');
                btn.id = 'viewpay-button';
                btn.className = 'viewpay-button viewpay-tsa-button';
                btn.setAttribute('data-post-id', postId);
                btn.setAttribute('data-nonce', nonce);

                // Largeur adaptée : mobile = même largeur que bouton S'abonner, desktop = 208px
                // Le modal SwG contient une carte blanche avec margin ~16px et padding ~16px de chaque côté
                // Donc le bouton S'abonner fait environ modalWidth - 64px
                var btnWidth = isMobile ? Math.min(modalWidth - 64, 320) : 208;

                btn.style.cssText = 'width:' + btnWidth + 'px !important;height:40px !important;border-radius:20px !important;border:none !important;background:#0b57d0 !important;color:#fff !important;cursor:pointer !important;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif !important;line-height:40px !important;padding:0 16px !important;box-sizing:border-box !important;text-align:center !important;display:inline-block !important;';
                btn.textContent = buttonText;
                btn.onclick = hideSwgAndLoadAd;
                container.appendChild(btn);
                container.btnWidth = btnWidth; // Stocker pour le positionnement
                return container;
            }

            function attachToSwgModal(swgDialog) {
                if (viewpayAttached) {
                    log('Already attached, skipping');
                    return;
                }

                log('SwG dialog found, waiting for animation to complete...');
                viewpayAttached = true; // Marquer immédiatement pour éviter les doubles appels

                // Attendre que l'animation SwG soit terminée (300ms typique)
                setTimeout(function() {
                    if (!document.body.contains(swgDialog)) {
                        log('SwG dialog disappeared during animation wait');
                        viewpayAttached = false;
                        return;
                    }

                    var rect = swgDialog.getBoundingClientRect();
                    var swgZIndex = parseInt(window.getComputedStyle(swgDialog).zIndex) || 2147483647;

                    // Détecter mobile : modal collé en bas de l'écran
                    var isMobile = rect.top + rect.height > window.innerHeight - 50;

                    var container = createViewPayButton(isMobile, rect.width);
                    var btnHalfWidth = container.btnWidth / 2;

                    // Positionnement : desktop = top avec offset, mobile = bottom fixe
                    var posStyle = isMobile
                        ? 'bottom: ' + mobileBottom + 'px;'
                        : 'top: ' + (rect.top + rect.height + desktopOffset) + 'px;';

                    container.style.cssText =
                        'position: fixed;' +
                        'left: ' + (rect.left + rect.width / 2 - btnHalfWidth) + 'px;' +
                        posStyle +
                        'z-index: ' + swgZIndex + ';';

                    document.body.appendChild(container);

                    log('ViewPay button attached, isMobile: ' + isMobile + ', btnWidth: ' + container.btnWidth);

                    // Stocker la référence du dialog actuel pour détecter les changements
                    var currentSwgDialog = swgDialog;
                    var currentBtnHalfWidth = btnHalfWidth;

                    function updatePosition() {
                        if (!document.body.contains(currentSwgDialog)) {
                            if (document.body.contains(container)) {
                                document.body.removeChild(container);
                                viewpayAttached = false;
                                log('SwG dialog removed, detaching ViewPay');
                            }
                            return;
                        }
                        var newRect = currentSwgDialog.getBoundingClientRect();
                        var newIsMobile = newRect.top + newRect.height > window.innerHeight - 50;

                        container.style.left = (newRect.left + newRect.width / 2 - currentBtnHalfWidth) + 'px';
                        if (newIsMobile) {
                            container.style.top = '';
                            container.style.bottom = mobileBottom + 'px';
                        } else {
                            container.style.bottom = '';
                            container.style.top = (newRect.top + newRect.height + desktopOffset) + 'px';
                        }
                    }

                    window.addEventListener('resize', updatePosition);

                    // Vérifier périodiquement si le dialog SwG est toujours présent
                    // et aussi détecter si un nouveau dialog a remplacé l'ancien
                    var checkInterval = setInterval(function() {
                        var currentDialog = document.querySelector('iframe.swg-dialog');

                        // Si le dialog original a disparu
                        if (!document.body.contains(currentSwgDialog)) {
                            clearInterval(checkInterval);
                            if (document.body.contains(container)) {
                                document.body.removeChild(container);
                            }
                            viewpayAttached = false;
                            log('SwG dialog removed (interval check), detaching ViewPay');

                            // Si un nouveau dialog existe, réattacher
                            if (currentDialog) {
                                log('New SwG dialog detected, reattaching...');
                                setTimeout(function() { checkForSwgDialog(); }, 300);
                            }
                            return;
                        }

                        // Si un nouveau dialog différent est apparu (SwG a changé de message)
                        if (currentDialog && currentDialog !== currentSwgDialog) {
                            clearInterval(checkInterval);
                            if (document.body.contains(container)) {
                                document.body.removeChild(container);
                            }
                            viewpayAttached = false;
                            log('SwG dialog changed, reattaching to new dialog...');
                            setTimeout(function() { checkForSwgDialog(); }, 300);
                        }
                    }, 500);
                }, 800); // Attendre 800ms pour être sûr que l'animation SwG soit terminée
            }

            function checkForSwgDialog() {
                var swgDialog = document.querySelector('iframe.swg-dialog');
                if (swgDialog && !viewpayAttached) {
                    attachToSwgModal(swgDialog);
                }
            }

            var observer = new MutationObserver(function(mutations) {
                mutations.forEach(function(mutation) {
                    if (mutation.addedNodes.length) {
                        checkForSwgDialog();
                    }
                });
            });

            observer.observe(document.body, {
                childList: true,
                subtree: true
            });

            document.addEventListener('DOMContentLoaded', checkForSwgDialog);
            if (document.readyState !== 'loading') {
                checkForSwgDialog();
            }

            setTimeout(checkForSwgDialog, 1000);
            setTimeout(checkForSwgDialog, 2000);
            setTimeout(checkForSwgDialog, 3000);

            log('SwG modal observer initialized');
        })();
        </script>
        <?php
    }

    /**
     * Add body classes for TSA/ViewPay state
     *
     * @param array $classes Body classes
     * @return array Modified classes
     */
    public function add_body_classes($classes) {
        global $post;

        if ($post && $this->main->is_post_unlocked($post->ID)) {
            $classes[] = 'viewpay-unlocked';
            $classes[] = 'tsa-viewpay-unlocked';
        }

        if ($post && $this->is_premium_article($post->ID)) {
            $classes[] = 'tsa-premium-article';
        }

        return $classes;
    }

    /**
     * Enqueue TSA-specific styles
     */
    public function enqueue_tsa_styles() {
        $css = '
        /* ViewPay TSA Integration Styles */
        #viewpay-swg-attachment .viewpay-tsa-button:hover {
            background: #0842a0 !important;
        }

        /* Cacher les éléments SwG quand débloqué */
        .viewpay-unlocked .swg-dialog,
        .viewpay-unlocked [class*="swg-"],
        .viewpay-unlocked #viewpay-swg-attachment,
        .tsa-viewpay-unlocked .swg-dialog,
        .tsa-viewpay-unlocked [class*="swg-"],
        .tsa-viewpay-unlocked #viewpay-swg-attachment {
            display: none !important;
        }
        ';

        wp_add_inline_style('viewpay-styles', $css);
    }
}
