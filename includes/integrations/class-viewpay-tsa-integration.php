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

        // Hook pour ajouter le bouton ViewPay au contenu tronqué
        add_filter('the_content', array($this, 'add_viewpay_button'), 100);

        // Hook pour désactiver le script SwG si débloqué via ViewPay
        add_action('wp_head', array($this, 'maybe_disable_swg_script'), 1);

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
     * Add ViewPay button to truncated content
     *
     * @param string $content The post content
     * @return string Modified content with ViewPay button
     */
    public function add_viewpay_button($content) {
        global $post;

        if (!$post || !is_single()) {
            return $content;
        }

        // Ne pas ajouter si admin
        if (current_user_can('manage_options')) {
            return $content;
        }

        // Ne pas ajouter si déjà débloqué via ViewPay
        if ($this->main->is_post_unlocked($post->ID)) {
            return $content;
        }

        // Ne pas ajouter si utilisateur connecté (a déjà accès)
        if (is_user_logged_in()) {
            return $content;
        }

        // Vérifier si c'est un article premium
        if (!$this->is_premium_article($post->ID)) {
            return $content;
        }

        // Vérifier si le bouton ViewPay existe déjà
        if (strpos($content, 'viewpay-button') !== false) {
            return $content;
        }

        if ($this->is_debug_enabled()) {
            error_log('ViewPay TSA: Adding ViewPay button to premium article ' . $post->ID);
        }

        // Générer le HTML du bouton ViewPay
        $nonce = wp_create_nonce('viewpay_nonce');
        $button_html = $this->create_viewpay_button_html($post->ID, $nonce);

        // Ajouter le bouton après le contenu tronqué
        $content .= $button_html;

        return $content;
    }

    /**
     * Create the ViewPay button HTML
     *
     * @param int $post_id Post ID
     * @param string $nonce Security nonce
     * @return string Button HTML
     */
    private function create_viewpay_button_html($post_id, $nonce) {
        $button_text = $this->main->get_option('button_text') ?: __('Regarder une pub pour accéder', 'viewpay-wordpress');
        $or_text = $this->main->get_or_text();

        $html = '<div class="viewpay-container viewpay-tsa-container">';

        // Message d'introduction
        $html .= '<div class="viewpay-tsa-message">';
        $html .= '<p>' . esc_html__('Cet article est réservé aux abonnés.', 'viewpay-wordpress') . '</p>';
        $html .= '</div>';

        // Séparateur "OU"
        $html .= '<span class="viewpay-separator">' . esc_html($or_text) . '</span>';

        // Bouton ViewPay
        $html .= '<button id="viewpay-button" class="viewpay-button viewpay-tsa-button" ';
        $html .= 'data-post-id="' . esc_attr($post_id) . '" ';
        $html .= 'data-nonce="' . esc_attr($nonce) . '">';
        $html .= '<span class="viewpay-icon"></span>';
        $html .= esc_html($button_text);
        $html .= '</button>';

        $html .= '</div>';

        return $html;
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
        .viewpay-tsa-container {
            margin: 30px 0;
            padding: 25px;
            text-align: center;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-radius: 12px;
            border: 1px solid #dee2e6;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            position: relative;
            z-index: 999999;
        }

        .viewpay-tsa-message {
            margin-bottom: 15px;
            color: #495057;
            font-size: 16px;
        }

        .viewpay-tsa-message p {
            margin: 0;
        }

        .viewpay-tsa-container .viewpay-separator {
            display: block;
            margin: 15px 0;
            font-weight: bold;
            color: #6c757d;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 2px;
        }

        .viewpay-tsa-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 14px 28px;
            font-size: 16px;
            font-weight: 600;
            border-radius: 30px;
            cursor: pointer;
            transition: all 0.3s ease;
            min-width: 280px;
            border: none;
            background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
            color: white;
            text-transform: none;
        }

        .viewpay-tsa-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 123, 255, 0.4);
            background: linear-gradient(135deg, #0056b3 0%, #003d80 100%);
        }

        .viewpay-tsa-button:active {
            transform: translateY(0);
        }

        .viewpay-tsa-button .viewpay-icon {
            width: 20px;
            height: 20px;
        }

        /* Cacher le conteneur ViewPay quand débloqué */
        .viewpay-unlocked .viewpay-tsa-container,
        .tsa-viewpay-unlocked .viewpay-tsa-container {
            display: none !important;
        }

        /* Cacher les éléments SwG quand débloqué */
        .viewpay-unlocked .swg-dialog,
        .viewpay-unlocked [class*="swg-"],
        .tsa-viewpay-unlocked .swg-dialog,
        .tsa-viewpay-unlocked [class*="swg-"] {
            display: none !important;
        }

        @media (max-width: 768px) {
            .viewpay-tsa-container {
                padding: 20px 15px;
                margin: 20px 0;
            }

            .viewpay-tsa-button {
                width: 100%;
                min-width: auto;
                padding: 16px 24px;
                font-size: 15px;
            }
        }
        ';

        wp_add_inline_style('viewpay-styles', $css);
    }
}
