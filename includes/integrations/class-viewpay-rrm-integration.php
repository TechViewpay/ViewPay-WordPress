<?php
/**
 * ViewPay Integration with Google Reader Revenue Manager (via Site Kit)
 *
 * Cette intégration fonctionne avec Google Site Kit et Reader Revenue Manager.
 * Elle permet aux utilisateurs de débloquer le contenu premium en regardant une
 * publicité comme alternative à l'abonnement Google Subscribe with Google (SwG).
 *
 * Reader Revenue Manager utilise :
 * - Attributs HTML : subscriptions-section="content" et subscriptions-section="content-not-granted"
 * - Script : swg.js ou swg-basic.js
 * - Structured data JSON-LD avec isAccessibleForFree: false
 *
 * @package ViewPay_WordPress
 * @see https://sitekit.withgoogle.com/documentation/supported-services/reader-revenue-manager/
 * @see https://developers.google.com/news/subscribe/integration-requirements
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class for integrating ViewPay with Google Reader Revenue Manager
 */
class ViewPay_RRM_Integration {

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
        // Hook très tôt pour vérifier le statut ViewPay AVANT que RRM/SwG tronque le contenu
        add_filter('the_content', array($this, 'early_content_access'), 1);

        // Hook après RRM pour ajouter le bouton ViewPay au message de restriction
        add_filter('the_content', array($this, 'add_viewpay_button'), 100);

        // Forcer l'affichage du contenu si toujours bloqué (dernier recours)
        add_filter('the_content', array($this, 'force_content_display'), 999);

        // Modifier le structured data pour indiquer l'accès libre si débloqué
        add_filter('the_content', array($this, 'modify_structured_data'), 5);

        // Ajouter les classes body
        add_filter('body_class', array($this, 'add_body_classes'));

        // Ajouter les styles CSS
        add_action('wp_enqueue_scripts', array($this, 'enqueue_rrm_styles'));

        // Hook dans Site Kit si disponible
        add_filter('googlesitekit_reader_revenue_manager_snippet_output', array($this, 'maybe_disable_rrm_snippet'), 10, 2);
    }

    /**
     * Early content access filter - runs BEFORE RRM/SwG processes content
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
                error_log('ViewPay RRM: Post ' . $post->ID . ' unlocked, attempting to bypass RRM/SwG');
            }

            // Retirer les filtres Site Kit RRM si présents
            $this->remove_rrm_filters();
        }

        return $content;
    }

    /**
     * Remove RRM/SwG related filters
     */
    private function remove_rrm_filters() {
        global $wp_filter;

        if (!isset($wp_filter['the_content'])) {
            return;
        }

        foreach ($wp_filter['the_content']->callbacks as $priority => $callbacks) {
            foreach ($callbacks as $key => $callback) {
                // Chercher les callbacks liés à Site Kit ou SwG
                $callback_name = '';

                if (is_string($callback['function'])) {
                    $callback_name = $callback['function'];
                } elseif (is_array($callback['function']) && isset($callback['function'][0])) {
                    if (is_object($callback['function'][0])) {
                        $callback_name = get_class($callback['function'][0]);
                    } elseif (is_string($callback['function'][0])) {
                        $callback_name = $callback['function'][0];
                    }
                }

                // Patterns à détecter pour RRM/SwG
                $patterns = array(
                    'reader_revenue',
                    'Reader_Revenue',
                    'swg',
                    'SwG',
                    'subscribe_with_google',
                    'subscribewithgoogle',
                    'Google\\Site_Kit\\Modules\\Reader_Revenue_Manager',
                );

                foreach ($patterns as $pattern) {
                    if (stripos($callback_name, $pattern) !== false || stripos($key, $pattern) !== false) {
                        remove_filter('the_content', $callback['function'], $priority);
                        if ($this->is_debug_enabled()) {
                            error_log('ViewPay RRM: Removed filter ' . $key . ' at priority ' . $priority);
                        }
                        break;
                    }
                }
            }
        }
    }

    /**
     * Maybe disable RRM snippet output when unlocked via ViewPay
     *
     * @param string $output The RRM snippet output
     * @param int $post_id The post ID
     * @return string Modified output
     */
    public function maybe_disable_rrm_snippet($output, $post_id = 0) {
        global $post;

        if (!$post_id && $post) {
            $post_id = $post->ID;
        }

        if ($post_id && $this->main->is_post_unlocked($post_id)) {
            if ($this->is_debug_enabled()) {
                error_log('ViewPay RRM: Disabling RRM snippet for unlocked post ' . $post_id);
            }
            return ''; // Disable RRM snippet
        }

        return $output;
    }

    /**
     * Modify structured data to indicate free access when unlocked
     *
     * @param string $content The post content
     * @return string Modified content
     */
    public function modify_structured_data($content) {
        global $post;

        if (!$post || !$this->main->is_post_unlocked($post->ID)) {
            return $content;
        }

        // Ajouter un script inline pour modifier le structured data
        // Cette approche évite de parser et modifier le JSON-LD existant
        $script = '<script type="application/javascript">
        (function() {
            var scripts = document.querySelectorAll(\'script[type="application/ld+json"]\');
            scripts.forEach(function(script) {
                try {
                    var data = JSON.parse(script.textContent);
                    if (data.isAccessibleForFree === false) {
                        data.isAccessibleForFree = true;
                        script.textContent = JSON.stringify(data);
                    }
                } catch(e) {}
            });
        })();
        </script>';

        return $content . $script;
    }

    /**
     * Add ViewPay button to RRM/SwG restriction message
     *
     * @param string $content The post content
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

        // Détecter si RRM/SwG a restreint le contenu
        $rrm_indicators = array(
            'subscriptions-section="content-not-granted"',
            "subscriptions-section='content-not-granted'",
            'swg-subscription-button',
            'swg-paywall',
            'swg-dialog',
            'class="swg-',
            'SWG_BASIC',
            'swg.js',
            'swg-basic.js',
            'goog-contribute-button',
            'goog-subscribe-button',
        );

        $is_rrm_restricted = false;
        foreach ($rrm_indicators as $indicator) {
            if (strpos($content, $indicator) !== false) {
                $is_rrm_restricted = true;
                break;
            }
        }

        // Aussi vérifier les meta tags JSON-LD
        if (!$is_rrm_restricted) {
            // Chercher dans le head le JSON-LD avec isAccessibleForFree: false
            if (preg_match('/"isAccessibleForFree"\s*:\s*false/i', $content)) {
                $is_rrm_restricted = true;
            }
        }

        if (!$is_rrm_restricted) {
            // Pas de paywall RRM détecté, ne rien faire
            return $content;
        }

        if ($this->is_debug_enabled()) {
            error_log('ViewPay RRM: RRM/SwG paywall detected, adding ViewPay button');
        }

        // Générer le HTML du bouton ViewPay
        $nonce = wp_create_nonce('viewpay_nonce');
        $button_html = $this->create_viewpay_button_html($post->ID, $nonce);

        // Injecter le bouton après le message de restriction RRM/SwG
        // Pattern: <... subscriptions-section="content-not-granted">...</...>
        $pattern = '/(<[^>]*subscriptions-section=["\']content-not-granted["\'][^>]*>.*?<\/[^>]+>)/is';

        if (preg_match($pattern, $content)) {
            $content = preg_replace($pattern, '$1' . $button_html, $content, 1);
        } else {
            // Fallback: chercher des boutons SwG/Google
            $button_patterns = array(
                '/(<button[^>]*class="[^"]*goog-[^"]*"[^>]*>.*?<\/button>)/is',
                '/(<div[^>]*class="[^"]*swg-[^"]*"[^>]*>.*?<\/div>)/is',
            );

            $inserted = false;
            foreach ($button_patterns as $btn_pattern) {
                if (preg_match($btn_pattern, $content)) {
                    $content = preg_replace($btn_pattern, '$1' . $button_html, $content, 1);
                    $inserted = true;
                    break;
                }
            }

            if (!$inserted) {
                // Dernier recours: ajouter à la fin du contenu
                $content .= $button_html;
            }
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

        $html = '<div class="viewpay-container viewpay-rrm-container">';

        // Séparateur "OU"
        $html .= '<span class="viewpay-separator">' . esc_html($or_text) . '</span>';

        // Bouton ViewPay
        $html .= '<button id="viewpay-button" class="viewpay-button viewpay-rrm-button" ';
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

        // Vérifier si le contenu semble toujours être restreint
        $restriction_indicators = array(
            'subscriptions-section="content-not-granted"',
            "subscriptions-section='content-not-granted'",
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
                error_log('ViewPay RRM: Content still restricted, forcing display for post ' . $post->ID);
            }

            // Récupérer le contenu brut du post
            $raw_content = get_post_field('post_content', $post->ID);

            // Retirer les marqueurs RRM/SwG du contenu brut si présents
            $raw_content = preg_replace('/<!-- wp:rrm\/[^>]*-->.*?<!-- \/wp:rrm\/[^>]*-->/s', '', $raw_content);
            $raw_content = preg_replace('/<!-- wp:swg\/[^>]*-->.*?<!-- \/wp:swg\/[^>]*-->/s', '', $raw_content);

            // Appliquer les filtres standard sauf RRM
            remove_filter('the_content', array($this, 'force_content_display'), 999);
            remove_filter('the_content', array($this, 'add_viewpay_button'), 100);
            $this->remove_rrm_filters();

            $filtered_content = apply_filters('the_content', $raw_content);

            add_filter('the_content', array($this, 'add_viewpay_button'), 100);
            add_filter('the_content', array($this, 'force_content_display'), 999);

            return $filtered_content;
        }

        return $content;
    }

    /**
     * Add body classes for RRM/ViewPay state
     *
     * @param array $classes Body classes
     * @return array Modified classes
     */
    public function add_body_classes($classes) {
        global $post;

        if ($post && $this->main->is_post_unlocked($post->ID)) {
            $classes[] = 'viewpay-unlocked';
            $classes[] = 'rrm-viewpay-unlocked';
            $classes[] = 'swg-viewpay-unlocked';
        }

        return $classes;
    }

    /**
     * Enqueue RRM-specific styles
     */
    public function enqueue_rrm_styles() {
        $css = '
        /* ViewPay RRM Integration Styles */
        .viewpay-rrm-container {
            margin: 20px 0;
            padding: 15px;
            text-align: center;
            background: #f9f9f9;
            border-radius: 8px;
            display: none; /* Hidden by default, shown when ads available */
        }

        .viewpay-rrm-container .viewpay-separator {
            display: block;
            margin-bottom: 15px;
            font-weight: bold;
            color: #666;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .viewpay-rrm-button {
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
            background: #1a73e8;
            color: white;
        }

        .viewpay-rrm-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(26, 115, 232, 0.4);
            background: #1557b0;
        }

        /* Cacher le paywall RRM/SwG quand débloqué via ViewPay */
        .viewpay-unlocked [subscriptions-section="content-not-granted"],
        .rrm-viewpay-unlocked [subscriptions-section="content-not-granted"],
        .swg-viewpay-unlocked [subscriptions-section="content-not-granted"] {
            display: none !important;
        }

        /* Afficher le contenu complet quand débloqué */
        .viewpay-unlocked [subscriptions-section="content"],
        .rrm-viewpay-unlocked [subscriptions-section="content"],
        .swg-viewpay-unlocked [subscriptions-section="content"] {
            display: block !important;
            max-height: none !important;
            overflow: visible !important;
        }

        /* Cacher le conteneur ViewPay quand débloqué */
        .viewpay-unlocked .viewpay-rrm-container,
        .rrm-viewpay-unlocked .viewpay-rrm-container,
        .swg-viewpay-unlocked .viewpay-rrm-container {
            display: none !important;
        }

        /* Cacher les dialogs/boutons SwG quand débloqué */
        .viewpay-unlocked .swg-dialog,
        .viewpay-unlocked [class*="swg-dialog"],
        .viewpay-unlocked .goog-subscribe-button,
        .viewpay-unlocked .goog-contribute-button,
        .rrm-viewpay-unlocked .swg-dialog,
        .rrm-viewpay-unlocked [class*="swg-dialog"],
        .rrm-viewpay-unlocked .goog-subscribe-button,
        .rrm-viewpay-unlocked .goog-contribute-button,
        .swg-viewpay-unlocked .swg-dialog,
        .swg-viewpay-unlocked [class*="swg-dialog"],
        .swg-viewpay-unlocked .goog-subscribe-button,
        .swg-viewpay-unlocked .goog-contribute-button {
            display: none !important;
        }

        @media (max-width: 768px) {
            .viewpay-rrm-container {
                padding: 12px;
            }

            .viewpay-rrm-button {
                width: 100%;
                padding: 15px 20px;
                font-size: 14px;
            }
        }
        ';

        wp_add_inline_style('viewpay-styles', $css);
    }
}
