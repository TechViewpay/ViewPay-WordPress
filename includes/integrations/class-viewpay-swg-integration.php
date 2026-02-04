<?php
/**
 * ViewPay Integration with Subscribe with Google (SwG)
 *
 * Cette intégration fonctionne avec le système de paywall Subscribe with Google.
 * Elle permet aux utilisateurs de débloquer le contenu premium en regardant une
 * publicité comme alternative à l'abonnement Google.
 *
 * Le plugin SwG officiel utilise le filtre the_content à priorité 10 avec
 * le marqueur <!--more--> pour tronquer le contenu.
 *
 * @package ViewPay_WordPress
 * @see https://github.com/subscriptions-project/swg-wordpress-plugin
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class for integrating ViewPay with Subscribe with Google
 */
class ViewPay_SwG_Integration {

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
        // Hook très tôt pour vérifier le statut ViewPay AVANT que SwG tronque le contenu
        // SwG utilise priorité 10, on utilise priorité 1
        add_filter('the_content', array($this, 'early_content_access'), 1);

        // Hook après SwG pour ajouter le bouton ViewPay au message de restriction
        // SwG utilise priorité 10, on utilise priorité 100
        add_filter('the_content', array($this, 'add_viewpay_button'), 100);

        // Forcer l'affichage du contenu si toujours bloqué (dernier recours)
        add_filter('the_content', array($this, 'force_content_display'), 999);

        // Ajouter les classes body
        add_filter('body_class', array($this, 'add_body_classes'));

        // Ajouter les styles CSS
        add_action('wp_enqueue_scripts', array($this, 'enqueue_swg_styles'));
    }

    /**
     * Early content access filter - runs BEFORE SwG truncates content
     *
     * @param string $content The post content
     * @return string The content
     */
    public function early_content_access($content) {
        global $post;

        if (!$post) {
            return $content;
        }

        // Vérifier si le contenu est débloqué via ViewPay
        if ($this->main->is_post_unlocked($post->ID)) {
            if ($this->is_debug_enabled()) {
                error_log('ViewPay SwG: Post ' . $post->ID . ' unlocked, removing SwG filters');
            }

            // Retirer les filtres SwG pour afficher le contenu complet
            // Le plugin SwG officiel utilise ces noms de fonction
            remove_filter('the_content', 'subscribewithgoogle_the_content', 10);
            remove_filter('the_content', array('SubscribeWithGoogle\WordPress\Filters', 'the_content'), 10);

            // Aussi retirer les filtres potentiels d'autres implémentations SwG
            global $wp_filter;
            if (isset($wp_filter['the_content'])) {
                foreach ($wp_filter['the_content']->callbacks as $priority => $callbacks) {
                    foreach ($callbacks as $key => $callback) {
                        // Chercher les callbacks liés à SwG
                        if (is_string($key) && (
                            stripos($key, 'swg') !== false ||
                            stripos($key, 'subscribewithgoogle') !== false ||
                            stripos($key, 'subscribe_with_google') !== false
                        )) {
                            remove_filter('the_content', $callback['function'], $priority);
                            if ($this->is_debug_enabled()) {
                                error_log('ViewPay SwG: Removed filter ' . $key . ' at priority ' . $priority);
                            }
                        }
                    }
                }
            }
        }

        return $content;
    }

    /**
     * Add ViewPay button to SwG restriction message
     * Runs AFTER SwG has truncated the content
     *
     * @param string $content The post content (potentially truncated by SwG)
     * @return string Modified content with ViewPay button
     */
    public function add_viewpay_button($content) {
        global $post;

        if (!$post) {
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

        // Vérifier si le bouton ViewPay existe déjà
        if (strpos($content, 'viewpay-button') !== false) {
            return $content;
        }

        // Détecter si SwG a tronqué le contenu
        // Le plugin SwG officiel utilise subscriptions-section="content-not-granted"
        $swg_indicators = array(
            'subscriptions-section="content-not-granted"',
            'subscriptions-section=\'content-not-granted\'',
            'swg-subscription-button',
            'swg-paywall',
            'class="swg-'
        );

        $is_swg_restricted = false;
        foreach ($swg_indicators as $indicator) {
            if (strpos($content, $indicator) !== false) {
                $is_swg_restricted = true;
                break;
            }
        }

        if (!$is_swg_restricted) {
            // Pas de paywall SwG détecté, ne rien faire
            return $content;
        }

        if ($this->is_debug_enabled()) {
            error_log('ViewPay SwG: SwG paywall detected, adding ViewPay button');
        }

        // Générer le HTML du bouton ViewPay
        $nonce = wp_create_nonce('viewpay_nonce');
        $button_html = $this->create_viewpay_button_html($post->ID, $nonce);

        // Injecter le bouton après le message de restriction SwG
        // Pattern: <div ... subscriptions-section="content-not-granted">...</div>
        $pattern = '/(<div[^>]*subscriptions-section=["\']content-not-granted["\'][^>]*>.*?<\/div>)/is';

        if (preg_match($pattern, $content)) {
            $content = preg_replace($pattern, '$1' . $button_html, $content, 1);
        } else {
            // Fallback: ajouter à la fin du contenu
            $content .= $button_html;
        }

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
        $unlock_message = $this->main->get_option('unlock_message') ?: '';

        $html = '<div class="viewpay-container viewpay-swg-container">';

        // Séparateur "OU"
        $html .= '<span class="viewpay-separator">' . esc_html($or_text) . '</span> ';

        // Bouton ViewPay
        $html .= '<button id="viewpay-button" class="viewpay-button viewpay-swg-button" ';
        $html .= 'data-post-id="' . esc_attr($post_id) . '" ';
        $html .= 'data-nonce="' . esc_attr($nonce) . '">';
        $html .= '<span class="viewpay-icon"></span>';
        $html .= esc_html($button_text);
        $html .= '</button>';

        $html .= '</div>';

        return $html;
    }

    /**
     * Last-resort content display enforcer
     *
     * @param string $content The post content
     * @return string The potentially modified content
     */
    public function force_content_display($content) {
        global $post;

        if (!$post) {
            return $content;
        }

        if (!$this->main->is_post_unlocked($post->ID)) {
            return $content;
        }

        // Vérifier si le contenu semble toujours être restreint par SwG
        $restriction_indicators = array(
            'subscriptions-section="content-not-granted"',
            'subscriptions-section=\'content-not-granted\''
        );

        $is_still_restricted = false;
        foreach ($restriction_indicators as $indicator) {
            if (strpos($content, $indicator) !== false) {
                $is_still_restricted = true;
                break;
            }
        }

        if ($is_still_restricted) {
            if ($this->is_debug_enabled()) {
                error_log('ViewPay SwG: Content still restricted, forcing display for post ' . $post->ID);
            }

            // Récupérer le contenu brut du post
            $raw_content = get_post_field('post_content', $post->ID);

            // Retirer les marqueurs SwG du contenu brut si présents
            // Pattern: <!-- wp:swg/... -->...<!-- /wp:swg/... -->
            $raw_content = preg_replace('/<!-- wp:swg\/[^>]*-->.*?<!-- \/wp:swg\/[^>]*-->/s', '', $raw_content);

            // Appliquer les filtres standard sauf SwG
            remove_filter('the_content', array($this, 'force_content_display'), 999);
            remove_filter('the_content', array($this, 'add_viewpay_button'), 100);

            $filtered_content = apply_filters('the_content', $raw_content);

            add_filter('the_content', array($this, 'add_viewpay_button'), 100);
            add_filter('the_content', array($this, 'force_content_display'), 999);

            return $filtered_content;
        }

        return $content;
    }

    /**
     * Add body classes for SwG/ViewPay state
     *
     * @param array $classes Body classes
     * @return array Modified classes
     */
    public function add_body_classes($classes) {
        global $post;

        if ($post && $this->main->is_post_unlocked($post->ID)) {
            $classes[] = 'viewpay-unlocked';
            $classes[] = 'swg-viewpay-unlocked';
        }

        return $classes;
    }

    /**
     * Enqueue SwG-specific styles
     */
    public function enqueue_swg_styles() {
        $css = '
        /* ViewPay SwG Integration Styles */
        .viewpay-swg-container {
            margin: 20px 0;
            padding: 15px;
            text-align: center;
            background: #f9f9f9;
            border-radius: 8px;
        }

        .viewpay-swg-container .viewpay-separator {
            display: block;
            margin-bottom: 15px;
            font-weight: bold;
            color: #666;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .viewpay-swg-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 12px 24px;
            font-size: 16px;
            font-weight: bold;
            border-radius: 25px;
            cursor: pointer;
            transition: all 0.3s ease;
            min-width: 200px;
            border: none;
        }

        .viewpay-swg-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
        }

        /* Cacher le paywall SwG quand débloqué via ViewPay */
        .viewpay-unlocked [subscriptions-section="content-not-granted"],
        .swg-viewpay-unlocked [subscriptions-section="content-not-granted"] {
            display: none !important;
        }

        /* Afficher le contenu complet quand débloqué */
        .viewpay-unlocked [subscriptions-section="content"],
        .swg-viewpay-unlocked [subscriptions-section="content"] {
            display: block !important;
            max-height: none !important;
            overflow: visible !important;
        }

        /* Cacher le conteneur ViewPay quand débloqué */
        .viewpay-unlocked .viewpay-swg-container,
        .swg-viewpay-unlocked .viewpay-swg-container {
            display: none !important;
        }

        /* Cacher les dialogs SwG quand débloqué */
        .viewpay-unlocked .swg-dialog,
        .viewpay-unlocked [class*="swg-dialog"],
        .swg-viewpay-unlocked .swg-dialog,
        .swg-viewpay-unlocked [class*="swg-dialog"] {
            display: none !important;
        }

        @media (max-width: 768px) {
            .viewpay-swg-container {
                padding: 12px;
            }

            .viewpay-swg-button {
                width: 100%;
                padding: 15px 20px;
                font-size: 14px;
            }
        }
        ';

        wp_add_inline_style('viewpay-styles', $css);
    }
}
