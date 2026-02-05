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
        // Si déjà accès (utilisateur connecté), ne pas modifier
        if ($has_access) {
            return $has_access;
        }

        global $post;

        if (!$post) {
            return $has_access;
        }

        // Vérifier si débloqué via ViewPay
        if ($this->main->is_post_unlocked($post->ID)) {
            if ($this->is_debug_enabled()) {
                error_log('ViewPay TSA: Access granted via ViewPay for post ' . $post->ID);
            }
            return true;
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
                                    console.log('ViewPay: SwG product ID changed to open access');
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

        $button_text = $this->main->get_option('button_text') ?: __('Regarder une pub pour accéder', 'viewpay-wordpress');
        $nonce = wp_create_nonce('viewpay_nonce');

        if ($this->is_debug_enabled()) {
            error_log('ViewPay TSA: Injecting SwG modal script for post ' . $post->ID);
        }
        ?>
        <script type="text/javascript">
        (function() {
            'use strict';

            var debugEnabled = <?php echo $this->is_debug_enabled() ? 'true' : 'false'; ?>;
            var postId = <?php echo (int) $post->ID; ?>;
            var nonce = '<?php echo esc_js($nonce); ?>';
            var buttonText = '<?php echo esc_js($button_text); ?>';
            var viewpayAttached = false;

            function log(msg) {
                if (debugEnabled) {
                    console.log('ViewPay TSA: ' + msg);
                }
            }

            function createViewPayButton() {
                var container = document.createElement('div');
                container.id = 'viewpay-swg-attachment';
                container.innerHTML =
                    '<button id="viewpay-button" class="viewpay-button viewpay-tsa-button" ' +
                    'data-post-id="' + postId + '" data-nonce="' + nonce + '" ' +
                    'style="width:208px;height:40px;font-size:14px;font-weight:500;border-radius:20px;border:1px solid #dadce0;background:#fff;color:#1a73e8;cursor:pointer">' +
                    buttonText +
                    '</button>';
                return container;
            }

            function attachToSwgModal(swgDialog) {
                if (viewpayAttached) {
                    log('Already attached, skipping');
                    return;
                }

                log('SwG dialog found, attaching ViewPay button');

                var rect = swgDialog.getBoundingClientRect();
                var swgZIndex = parseInt(window.getComputedStyle(swgDialog).zIndex) || 2147483647;

                // Détecter mobile : modal collé en bas de l'écran
                var isMobile = rect.top + rect.height > window.innerHeight - 50;

                var container = createViewPayButton();

                // Positionnement : desktop = top avec offset, mobile = bottom fixe
                var posStyle = isMobile
                    ? 'bottom: 60px;'
                    : 'top: ' + (rect.top + rect.height - 109) + 'px;';

                container.style.cssText =
                    'position: fixed;' +
                    'left: ' + (rect.left + rect.width / 2 - 104) + 'px;' +
                    posStyle +
                    'z-index: ' + swgZIndex + ';';

                document.body.appendChild(container);
                viewpayAttached = true;

                log('ViewPay button attached, isMobile: ' + isMobile);

                function updatePosition() {
                    if (!document.body.contains(swgDialog)) {
                        if (document.body.contains(container)) {
                            document.body.removeChild(container);
                            viewpayAttached = false;
                            log('SwG dialog removed, detaching ViewPay');
                        }
                        return;
                    }
                    var newRect = swgDialog.getBoundingClientRect();
                    var newIsMobile = newRect.top + newRect.height > window.innerHeight - 50;

                    container.style.left = (newRect.left + newRect.width / 2 - 104) + 'px';
                    if (newIsMobile) {
                        container.style.top = '';
                        container.style.bottom = '60px';
                    } else {
                        container.style.bottom = '';
                        container.style.top = (newRect.top + newRect.height - 109) + 'px';
                    }
                }

                window.addEventListener('resize', updatePosition);

                var checkInterval = setInterval(function() {
                    if (!document.body.contains(swgDialog)) {
                        clearInterval(checkInterval);
                        if (document.body.contains(container)) {
                            document.body.removeChild(container);
                            viewpayAttached = false;
                            log('SwG dialog removed (interval check), detaching ViewPay');
                        }
                    }
                }, 500);
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
            background: #f8f9fa !important;
            border-color: #1a73e8 !important;
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
