<?php
/**
 * Classe principale pour ViewPay WordPress
 */
class ViewPay_WordPress {

    /**
     * Options du plugin
     */
    private $options;

    /**
     * Integration instance
     */
    private $integration = null;

    /**
     * Initialise le plugin
     */
    public function init() {
        // Charger les options
        $this->options = get_option('viewpay_wordpress_options', viewpay_wordpress_default_options());

        // Charger l'intégration appropriée basée sur la configuration
        $this->setup_paywall_integration();

        // Ajouter les scripts et styles
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));

        // Ajouter l'endpoint AJAX pour traiter le déverrouillage
        add_action('wp_ajax_viewpay_content', array($this, 'process_viewpay'));
        add_action('wp_ajax_nopriv_viewpay_content', array($this, 'process_viewpay'));
    }

    /**
     * Helper function to add debug console logs when debug is enabled
     *
     * @param string $message Debug message
     */
    private function debug_log($message) {
        if ($this->is_debug_enabled()) {
            add_action('wp_footer', function() use ($message) {
                echo '<script>console.log("ViewPay Debug: ' . esc_js($message) . '");</script>';
            });
        }
    }

    /**
     * Check if debug logs are enabled
     *
     * @return bool True if debug logs are enabled
     */
    private function is_debug_enabled() {
        return isset($this->options['enable_debug_logs']) && $this->options['enable_debug_logs'] === 'yes';
    }

    /**
     * Configure l'intégration avec le plugin de paywall configuré
     */
    private function setup_paywall_integration() {
        $paywall_type = isset($this->options['paywall_type']) ? $this->options['paywall_type'] : 'auto';

        $this->debug_log("setup_paywall_integration called - type: " . $paywall_type);

        $integration_dir = VIEWPAY_WORDPRESS_PLUGIN_DIR . 'includes/integrations/';

        // Si mode auto, détecter automatiquement
        if ($paywall_type === 'auto') {
            $paywall_type = $this->detect_active_paywall();
            $this->debug_log("Auto-detected paywall: " . $paywall_type);
        }

        // Charger l'intégration appropriée
        switch ($paywall_type) {
            case 'pms':
                if (function_exists('pms_is_member') || class_exists('Paid_Member_Subscriptions')) {
                    require_once $integration_dir . 'class-viewpay-pms-integration.php';
                    $this->integration = new ViewPay_PMS_Integration($this);
                    $this->debug_log("PMS integration loaded");
                }
                break;

            case 'pmpro':
                if (function_exists('pmpro_has_membership_access')) {
                    require_once $integration_dir . 'class-viewpay-pmpro-integration.php';
                    $this->integration = new ViewPay_PMPro_Integration($this);
                    $this->debug_log("PMPro integration loaded");
                }
                break;

            case 'rcp':
                if (class_exists('RCP_Member') || class_exists('RCP_Requirements_Check')) {
                    require_once $integration_dir . 'class-viewpay-rcp-integration.php';
                    $this->integration = new ViewPay_RCP_Integration($this);
                    $this->debug_log("RCP integration loaded");
                }
                break;

            case 'swpm':
                if (class_exists('SwpmMembershipLevel') || class_exists('SwpmProtectContent')) {
                    require_once $integration_dir . 'class-viewpay-swpm-integration.php';
                    $this->integration = new ViewPay_SWPM_Integration($this);
                    $this->debug_log("SWPM integration loaded");
                }
                break;

            case 'wpmem':
                if (function_exists('wpmem_is_blocked')) {
                    add_filter('wpmem_restricted_msg', array($this, 'add_viewpay_button_simple'), 10, 1);
                    add_filter('wpmem_access_filter', array($this, 'check_viewpay_access_wpmem'), 10, 2);
                    $this->debug_log("WP-Members integration loaded");
                }
                break;

            case 'rua':
                if (class_exists('RUA_App')) {
                    add_filter('rua/access-conditions/satisfy', array($this, 'check_viewpay_access_rua'), 10, 3);
                    add_filter('rua/frontend/content-block', array($this, 'add_viewpay_button_simple'), 10, 1);
                    $this->debug_log("RUA integration loaded");
                }
                break;

            case 'um':
                if (class_exists('UM')) {
                    add_filter('um_access_restrict_non_member_message', array($this, 'add_viewpay_button_simple'), 10, 1);
                    add_filter('um_access_skip_restriction_check', array($this, 'check_viewpay_access_um'), 10, 3);
                    $this->debug_log("UM integration loaded");
                }
                break;

            case 'custom':
                require_once $integration_dir . 'class-viewpay-custom-integration.php';
                $this->integration = new ViewPay_Custom_Integration($this);
                $this->debug_log("Custom integration loaded");
                break;

            default:
                $this->debug_log("No integration loaded for type: " . $paywall_type);
                break;
        }

        $this->debug_log("setup_paywall_integration completed");
    }

    /**
     * Détecte automatiquement le paywall actif (mode auto)
     *
     * @return string Le type de paywall détecté
     */
    private function detect_active_paywall() {
        // Ordre de priorité pour la détection
        if (function_exists('pms_is_member') || class_exists('Paid_Member_Subscriptions')) {
            return 'pms';
        }
        if (function_exists('pmpro_has_membership_access')) {
            return 'pmpro';
        }
        if (class_exists('RCP_Member') || class_exists('RCP_Requirements_Check')) {
            return 'rcp';
        }
        if (class_exists('SwpmMembershipLevel') || class_exists('SwpmProtectContent')) {
            return 'swpm';
        }
        if (function_exists('wpmem_is_blocked')) {
            return 'wpmem';
        }
        if (class_exists('RUA_App')) {
            return 'rua';
        }
        if (class_exists('UM')) {
            return 'um';
        }

        return 'none';
    }

    /**
     * Get an option value
     */
    public function get_option($key) {
        if (isset($this->options[$key])) {
            return $this->options[$key];
        }
        return null;
    }

    /**
     * Ajoute le bouton ViewPay pour les plugins avec intégration simple (filtre)
     */
    public function add_viewpay_button_simple($text) {
        global $post;

        if (!$post) {
            return $text;
        }

        // Générer un nonce pour la sécurité
        $nonce = wp_create_nonce('viewpay_nonce');

        // Chercher les boutons existants dans le message
        $button_regex = '/<a\s+class="(.*?)"\s+href="(.*?)">(.*?)<\/a>/i';

        if (preg_match($button_regex, $text, $matches)) {
            // Créer le bouton ViewPay
            $viewpay_button = '<button id="viewpay-button" class="viewpay-button" data-post-id="' . esc_attr($post->ID) . '" data-nonce="' . esc_attr($nonce) . '">';
            $viewpay_button .= esc_html($this->options['button_text']);
            $viewpay_button .= '</button>';

            // Déterminer le texte "OU" en fonction de la langue du site
            $or_text = $this->get_or_text();

            // Insérer le bouton ViewPay après le bouton existant avec le séparateur "OU"
            $original_button = $matches[0];
            $replacement = $original_button . ' <span class="viewpay-separator">' . $or_text . '</span> ' . $viewpay_button;

            // Remplacer le bouton original par notre nouvelle structure
            $text = str_replace($original_button, $replacement, $text);

            return $text;
        } else {
            // Aucun bouton trouvé, on ajoute simplement notre bouton
            $button = '<div class="viewpay-container">';
            $button .= '<button id="viewpay-button" class="viewpay-button" data-post-id="' . esc_attr($post->ID) . '" data-nonce="' . esc_attr($nonce) . '">';
            $button .= esc_html($this->options['button_text']);
            $button .= '</button>';
            $button .= '</div>';

            // Ajouter le bouton après le message de restriction
            return $text . $button;
        }
    }

    /**
     * Vérifie l'accès pour WP-Members
     */
    public function check_viewpay_access_wpmem($is_blocked, $post_id) {
        if ($this->is_post_unlocked($post_id)) {
            return false;
        }
        return $is_blocked;
    }

    /**
     * Vérifie l'accès pour Restrict User Access
     */
    public function check_viewpay_access_rua($satisfy, $conditions, $post_id) {
        if ($this->is_post_unlocked($post_id)) {
            return true;
        }
        return $satisfy;
    }

    /**
     * Vérifie l'accès pour Ultimate Member
     */
    public function check_viewpay_access_um($skip, $is_restricted, $post_id) {
        if ($this->is_post_unlocked($post_id)) {
            return true;
        }
        return $skip;
    }

    /**
     * Vérifie si un article a été déverrouillé via ViewPay
     */
    public function is_post_unlocked($post_id) {
        $post_id = (int)$post_id;

        // Check URL parameter for direct unlocking (useful for testing)
        if (isset($_GET['viewpay_unlocked'])) {
            if ($this->is_debug_enabled()) {
                error_log('ViewPay: Post ' . $post_id . ' unlocked via URL parameter');
            }
            return true;
        }

        // Check via cookies
        if (isset($_COOKIE['viewpay_unlocked_posts']) && !empty($_COOKIE['viewpay_unlocked_posts'])) {
            $cookie_value = stripslashes($_COOKIE['viewpay_unlocked_posts']);

            try {
                $unlocked_posts = json_decode($cookie_value, true);

                if (is_array($unlocked_posts) && in_array($post_id, $unlocked_posts)) {
                    if ($this->is_debug_enabled()) {
                        error_log('ViewPay: Content unlocked for post ' . $post_id);
                    }
                    return true;
                }
            } catch (Exception $e) {
                if ($this->is_debug_enabled()) {
                    error_log('ViewPay: Error decoding cookie: ' . $e->getMessage());
                }
            }
        }

        return false;
    }

    /**
     * Obtient la traduction de "OU" en fonction de la langue du site
     */
    public function get_or_text() {
        $translations = array(
            'fr_FR' => 'OU',
            'en_US' => 'OR',
            'es_ES' => 'O',
            'de_DE' => 'ODER',
            'it_IT' => 'O',
            'pt_BR' => 'OU',
            'nl_NL' => 'OF',
            'ru_RU' => 'ИЛИ',
            'pl_PL' => 'LUB',
            'ja' => 'または',
            'zh_CN' => '或者',
            'ar' => 'أو',
        );

        $locale = get_locale();
        return isset($translations[$locale]) ? $translations[$locale] : 'OR';
    }

    /**
     * Enqueue scripts and styles
     */
    public function enqueue_scripts() {
        wp_enqueue_style('viewpay-styles', VIEWPAY_WORDPRESS_PLUGIN_URL . 'assets/css/viewpay-wordpress.css', array(), VIEWPAY_WORDPRESS_VERSION);
        wp_enqueue_script('viewpay-script', VIEWPAY_WORDPRESS_PLUGIN_URL . 'assets/js/viewpay-wordpress.js', array('jquery'), VIEWPAY_WORDPRESS_VERSION, true);

        // Custom color if enabled
        $use_custom_color = isset($this->options['use_custom_color']) && $this->options['use_custom_color'] === 'yes';

        if ($use_custom_color && isset($this->options['button_color']) && !empty($this->options['button_color'])) {
            $button_color = $this->options['button_color'];
            $hover_color = $this->adjust_brightness($button_color, -20);

            $custom_css = "
            .viewpay-button {
                background-color: {$button_color} !important;
                border-color: {$button_color} !important;
                color: #ffffff !important;
            }
            .viewpay-button:hover {
                background-color: {$hover_color} !important;
                border-color: {$hover_color} !important;
                color: #ffffff !important;
            }";

            wp_add_inline_style('viewpay-styles', $custom_css);
        }

        // Cookie duration
        $cookie_duration = isset($this->options['cookie_duration']) ? (int) $this->options['cookie_duration'] : 15;

        // Paywall type and custom settings
        $paywall_type = isset($this->options['paywall_type']) ? $this->options['paywall_type'] : 'auto';
        $custom_selector = isset($this->options['custom_paywall_selector']) ? $this->options['custom_paywall_selector'] : '';
        $custom_location = isset($this->options['custom_button_location']) ? $this->options['custom_button_location'] : 'after';

        // Pass variables to script
        wp_localize_script('viewpay-script', 'viewpayVars', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'homeurl' => home_url(),
            'siteId' => $this->options['site_id'],
            'cookieDuration' => $cookie_duration,
            'useCustomColor' => $use_custom_color,
            'buttonColor' => $use_custom_color && isset($this->options['button_color']) ? $this->options['button_color'] : '',
            'nonce' => wp_create_nonce('viewpay_nonce'),
            'debugEnabled' => $this->is_debug_enabled(),
            'paywallType' => $paywall_type,
            'customPaywallSelector' => $custom_selector,
            'customButtonLocation' => $custom_location,
            'buttonText' => $this->options['button_text'],
            'orText' => $this->get_or_text(),
            'adLoadingText' => __('Chargement de la publicité...', 'viewpay-wordpress'),
            'adWatchingText' => __('Regardez la publicité pour débloquer le contenu...', 'viewpay-wordpress'),
            'adCompleteText' => sprintf(__('Publicité terminée! Contenu débloqué pour %d minutes...', 'viewpay-wordpress'), $cookie_duration),
            'adErrorText' => __('Erreur lors du chargement de la publicité. Veuillez réessayer.', 'viewpay-wordpress')
        ));
    }

    /**
     * Traite la demande de déverrouillage après visionnage de la publicité
     */
    public function process_viewpay() {
        if (!isset($_POST['post_id']) || empty($_POST['post_id'])) {
            wp_send_json_error(array('message' => __('ID du post manquant.', 'viewpay-wordpress')));
            return;
        }

        $post_id = intval($_POST['post_id']);

        // Get cookie duration
        $cookie_duration_minutes = $this->get_option('cookie_duration');
        if (!$cookie_duration_minutes || !is_numeric($cookie_duration_minutes)) {
            $cookie_duration_minutes = 15;
        }

        // Fresh options
        $fresh_options = get_option('viewpay_wordpress_options', array());
        if (isset($fresh_options['cookie_duration']) && is_numeric($fresh_options['cookie_duration'])) {
            $cookie_duration_minutes = (int) $fresh_options['cookie_duration'];
        }

        // Create/update the cookie
        $cookie_posts = array();
        if (isset($_COOKIE['viewpay_unlocked_posts']) && !empty($_COOKIE['viewpay_unlocked_posts'])) {
            $cookie_data = stripslashes($_COOKIE['viewpay_unlocked_posts']);

            try {
                $decoded = json_decode($cookie_data, true);
                if (is_array($decoded)) {
                    $cookie_posts = $decoded;
                }
            } catch (Exception $e) {
                if ($this->is_debug_enabled()) {
                    error_log('ViewPay: Error decoding JSON: ' . $e->getMessage());
                }
            }
        }

        if (!in_array($post_id, $cookie_posts)) {
            $cookie_posts[] = $post_id;
        }

        // Set cookie
        $cookie_value = json_encode($cookie_posts);
        $secure = is_ssl();
        $http_only = true;

        $wp_timezone = wp_timezone();
        $current_time = new DateTime('now', $wp_timezone);
        $expiry_time = clone $current_time;
        $expiry_time->add(new DateInterval('PT' . $cookie_duration_minutes . 'M'));
        $expiry_timestamp = $expiry_time->getTimestamp();

        setcookie('viewpay_unlocked_posts', $cookie_value, $expiry_timestamp, '/', '', $secure, $http_only);
        $_COOKIE['viewpay_unlocked_posts'] = $cookie_value;

        wp_send_json_success(array(
            'message' => sprintf(__('Contenu déverrouillé avec succès pour %d minutes!', 'viewpay-wordpress'), $cookie_duration_minutes),
            'redirect' => get_permalink($post_id) . '?viewpay_unlocked=' . time(),
            'cookie_set' => true,
            'post_id' => $post_id,
            'duration_minutes' => $cookie_duration_minutes,
            'expiry_timestamp' => $expiry_timestamp
        ));
    }

    /**
     * Ajuste la luminosité d'une couleur hexadécimale
     */
    private function adjust_brightness($hex, $steps) {
        $hex = ltrim($hex, '#');

        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));

        $r = max(0, min(255, $r + $steps));
        $g = max(0, min(255, $g + $steps));
        $b = max(0, min(255, $b + $steps));

        return '#' . sprintf('%02x%02x%02x', $r, $g, $b);
    }
}
