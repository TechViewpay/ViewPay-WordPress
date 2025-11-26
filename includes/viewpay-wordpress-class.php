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
     * Integration instances
     */
    private $integrations = array();
    
    /**
     * Initialise le plugin
     */
    public function init() {
        // Charger les options
        $this->options = get_option('viewpay_wordpress_options', viewpay_wordpress_default_options());
        
        // Déterminer quel plugin de paywall est actif et configurer les hooks appropriés
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
     * Fonction de diagnostic pour vérifier la configuration des cookies
     * À appeler lors du debug ou en cas de problème
     */
    public function diagnostic_cookie_settings() {
        if (!$this->is_debug_enabled()) {
            return;
        }
        
        $options = get_option('viewpay_wordpress_options', array());
        $cookie_duration = isset($options['cookie_duration']) ? $options['cookie_duration'] : 'NON DÉFINI';
        
        $wp_timezone = wp_timezone();
        $current_time = new DateTime('now', $wp_timezone);
        
        $diagnostic_info = array(
            'Options ViewPay' => $options,
            'Durée cookie configurée' => $cookie_duration . ' minutes',
            'Fuseau horaire WordPress' => $wp_timezone->getName(),
            'Heure actuelle locale' => $current_time->format('Y-m-d H:i:s T'),
            'Heure actuelle UTC' => gmdate('Y-m-d H:i:s T'),
            'Cookies existants' => $_COOKIE
        );
        
        error_log('ViewPay Diagnostic: ' . print_r($diagnostic_info, true));
        
        // Ajouter aussi dans la console du navigateur si en mode debug
        add_action('wp_footer', function() use ($diagnostic_info) {
            echo '<script>console.log("ViewPay Diagnostic:", ' . json_encode($diagnostic_info) . ');</script>';
        });
    }
 
    /**
     * Configure l'intégration avec le plugin de paywall actif
     */
    private function setup_paywall_integration() {
        $this->debug_log("setup_paywall_integration called");
	    
	// Integration files directory
        $integration_dir = VIEWPAY_WORDPRESS_PLUGIN_DIR . 'includes/integrations/';
        
        // Paid Memberships Pro
        if (function_exists('pmpro_has_membership_access')) {
            require_once $integration_dir . 'class-viewpay-pmpro-integration.php';
            $this->integrations['pmpro'] = new ViewPay_PMPro_Integration($this);
            $this->debug_log("PMPro integration loaded");
        }
        
        // Simple Membership (SWPM)
        if (class_exists('SwpmMembershipLevel') || class_exists('SwpmProtectContent')) {
            require_once $integration_dir . 'class-viewpay-swpm-integration.php';
            $this->integrations['swpm'] = new ViewPay_SWPM_Integration($this);
            $this->debug_log("SWPM integration loaded");
        }
        
        // WP-Members
        if (function_exists('wpmem_is_blocked')) {
            add_filter('wpmem_restricted_msg', array($this, 'add_viewpay_button_simple'), 10, 1);
            add_filter('wpmem_access_filter', array($this, 'check_viewpay_access_wpmem'), 10, 2);
            $this->debug_log("WP-Members integration loaded");
        }
        
        // Restrict User Access (RUA)
        if (class_exists('RUA_App')) {
            add_filter('rua/access-conditions/satisfy', array($this, 'check_viewpay_access_rua'), 10, 3);
            add_filter('rua/frontend/content-block', array($this, 'add_viewpay_button_simple'), 10, 1);
            $this->debug_log("RUA integration loaded");
        }
        
        // Ultimate Member (UM)
        if (class_exists('UM')) {
            add_filter('um_access_restrict_non_member_message', array($this, 'add_viewpay_button_simple'), 10, 1);
            add_filter('um_access_skip_restriction_check', array($this, 'check_viewpay_access_um'), 10, 3);
            $this->debug_log("UM integration loaded");
        }
        
        // Restrict Content Pro (RCP)
        if (class_exists('RCP_Member') || class_exists('RCP_Requirements_Check')) {
            require_once $integration_dir . 'class-viewpay-rcp-integration.php';
            $this->integrations['rcp'] = new ViewPay_RCP_Integration($this);
            $this->debug_log("RCP integration loaded");
	}

        // Pyrenees Magazine Custom Paywall
        // Check if we're on the Pyrenees Magazine site or if custom paywall is detected
	if ($this->is_pymag_site()) {
            $this->debug_log("is PyMag site");
	}
	if ($this->has_custom_paywall()) {
            $this->debug_log("has custom Paywall");
	}
	
	if ($this->is_pymag_site() || $this->has_custom_paywall()) {
            $this->debug_log("PyMag site detected, loading integration");
            if (file_exists($integration_dir . 'class-viewpay-pymag-integration.php')) {
                require_once $integration_dir . 'class-viewpay-pymag-integration.php';
                $this->integrations['pymag'] = new ViewPay_PyMag_Integration($this);
                $this->debug_log("PyMag integration loaded successfully");
            } else {
                $this->debug_log("ERROR: PyMag integration file not found");
            }
	} else {
            $this->debug_log("PyMag site NOT detected");
        }
        
        $this->debug_log("setup_paywall_integration completed");
    }
    
    /**
     * Check if we're on the Pyrenees Magazine site
     *
     * @return bool True if on PyMag site
     */
    private function is_pymag_site() {
        $site_url = get_site_url();
        $site_name = get_bloginfo('name');
        
        // Check for Pyrenees Magazine indicators
        return (
		strpos(strtolower($site_url), 'testwp.viewpay.tv') !== false ||
		strpos(strtolower($site_url), 'pyrenees.ouzom.fr') !== false ||	
	    strpos(strtolower($site_url), 'pyreneesmagazine.com') !== false /* ||
            strpos(strtolower($site_name), 'pyrenees') !== false ||
	    strpos(strtolower($site_name), 'pyrénées') !== false  */
        );
    }

    /**
     * Check if site has custom paywall with premium-content-cta class
     *
     * @return bool True if custom paywall detected
     */
    private function has_custom_paywall() {
        // This is a simple check - in a real implementation, you might want to
        // check for specific CSS classes or HTML structures in the content
        // For now, we'll rely on the is_pymag_site() check
        return false;
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
     * Ajoute le bouton ViewPay pour les autres plugins
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
        // Si le contenu est déverrouillé via ViewPay, on le débloque
        if ($this->is_post_unlocked($post_id)) {
            return false; // Non bloqué
        }
        
        // Sinon, on laisse WP-Members gérer l'accès
        return $is_blocked;
    }
    
    /**
     * Vérifie l'accès pour Restrict User Access
     */
    public function check_viewpay_access_rua($satisfy, $conditions, $post_id) {
        // Si le contenu est déverrouillé via ViewPay, on le débloque
        if ($this->is_post_unlocked($post_id)) {
            return true; // Conditions satisfaites
        }
        
        // Sinon, on laisse RUA gérer l'accès
        return $satisfy;
    }
    
    /**
     * Vérifie l'accès pour Ultimate Member
     */
    public function check_viewpay_access_um($skip, $is_restricted, $post_id) {
        // Si le contenu est déverrouillé via ViewPay, on le débloque
        if ($this->is_post_unlocked($post_id)) {
            return true; // Sauter la vérification de restriction
        }
        
        // Sinon, on laisse UM gérer l'accès
        return $skip;
    }
    
    /**
     * Vérifie si un article a été déverrouillé via ViewPay
     */
    public function is_post_unlocked($post_id) {
        // Convert to integer for consistent comparison
        $post_id = (int)$post_id;
        
        // Check URL parameter for direct unlocking (useful for testing)
        if (isset($_GET['viewpay_unlocked'])) {
            error_log('ViewPay: Post ' . $post_id . ' unlocked via URL parameter');
            return true;
        }
        
        // Check via cookies
        if (isset($_COOKIE['viewpay_unlocked_posts']) && !empty($_COOKIE['viewpay_unlocked_posts'])) {
            $cookie_value = stripslashes($_COOKIE['viewpay_unlocked_posts']);
            error_log('ViewPay: Checking cookie value: ' . $cookie_value . ' for post ' . $post_id);
            
            try {
                $unlocked_posts = json_decode($cookie_value, true);
                
                if (is_array($unlocked_posts) && in_array($post_id, $unlocked_posts)) {
                    error_log('ViewPay: Content unlocked for post ' . $post_id);
                    return true;
                }
            } catch (Exception $e) {
                error_log('ViewPay: Error decoding cookie: ' . $e->getMessage());
            }
        }
        
        return false;
    }
    
    /**
     * Obtient la traduction de "OU" en fonction de la langue du site
     */
    public function get_or_text() {
        // Liste des traductions de "OU" dans différentes langues
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
        
        // Obtenir la locale actuelle de WordPress
        $locale = get_locale();
        
        // Retourner la traduction correspondante ou "OR" par défaut
        return isset($translations[$locale]) ? $translations[$locale] : 'OR';
    }
    
public function enqueue_scripts() {
    // Charger les styles et scripts sur toutes les pages
    wp_enqueue_style('viewpay-styles', VIEWPAY_WORDPRESS_PLUGIN_URL . 'assets/css/viewpay-wordpress.css', array(), VIEWPAY_WORDPRESS_VERSION);
    wp_enqueue_script('viewpay-script', VIEWPAY_WORDPRESS_PLUGIN_URL . 'assets/js/viewpay-wordpress.js', array('jquery'), VIEWPAY_WORDPRESS_VERSION, true);
    
    // Vérifier si la personnalisation de couleur est activée
    $use_custom_color = isset($this->options['use_custom_color']) && $this->options['use_custom_color'] === 'yes';
    
    // Appliquer la couleur personnalisée uniquement si l'option est activée
    if ($use_custom_color && isset($this->options['button_color']) && !empty($this->options['button_color'])) {
        $button_color = $this->options['button_color'];
        $hover_color = $this->adjust_brightness($button_color, -20); // Assombrir pour l'effet hover
        
        // CSS personnalisé pour la couleur du bouton
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
        
        // Ajouter le CSS personnalisé
        wp_add_inline_style('viewpay-styles', $custom_css);
    }
    
    // Récupérer la durée du cookie configurée
    $cookie_duration = isset($this->options['cookie_duration']) ? (int) $this->options['cookie_duration'] : 30;
    
    // Passer des variables au script
    wp_localize_script('viewpay-script', 'viewpayVars', array(
        'ajaxurl' => admin_url('admin-ajax.php'),
        'homeurl' => home_url(),
        'siteId' => $this->options['site_id'],
        'cookieDuration' => $cookie_duration, // Durée en minutes
        'useCustomColor' => $use_custom_color,
        'buttonColor' => $use_custom_color && isset($this->options['button_color']) ? $this->options['button_color'] : '',
        'nonce' => wp_create_nonce('viewpay_nonce'),
        'debugEnabled' => $this->is_debug_enabled(), // Ajouter l'état du debug
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
        // Vérifier l'ID du post
        if (!isset($_POST['post_id']) || empty($_POST['post_id'])) {
            wp_send_json_error(array('message' => __('ID du post manquant.', 'viewpay-wordpress')));
            return;
        }
        
        $post_id = intval($_POST['post_id']);
        
        // Récupérer la durée du cookie depuis les options (en minutes)
        $cookie_duration_minutes = $this->get_option('cookie_duration');
        if (!$cookie_duration_minutes || !is_numeric($cookie_duration_minutes)) {
            $cookie_duration_minutes = 30; // Valeur par défaut si non définie
        }
        
        // Force la récupération des options fraîches pour s'assurer qu'on a la bonne valeur
        $fresh_options = get_option('viewpay_wordpress_options', array());
        if (isset($fresh_options['cookie_duration']) && is_numeric($fresh_options['cookie_duration'])) {
            $cookie_duration_minutes = (int) $fresh_options['cookie_duration'];
        }
        
        // Convertir en secondes pour setcookie()
        $cookie_duration_seconds = $cookie_duration_minutes * 60;
        
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
                error_log('ViewPay: Error decoding JSON: ' . $e->getMessage());
            }
        }
        
        if (!in_array($post_id, $cookie_posts)) {
            $cookie_posts[] = $post_id;
        }
        
        // Set cookie with configured duration
        $cookie_value = json_encode($cookie_posts);
        $secure = is_ssl();
        $http_only = true; // Prevent JavaScript access
        
        // Calculer l'expiration en tenant compte du fuseau horaire WordPress
        $wp_timezone = wp_timezone();
        $current_time = new DateTime('now', $wp_timezone);
        $expiry_time = clone $current_time;
        $expiry_time->add(new DateInterval('PT' . $cookie_duration_minutes . 'M'));
        
        // Convertir en timestamp UTC pour setcookie()
        $expiry_timestamp = $expiry_time->getTimestamp();
        
        // Log détaillé pour debug
        error_log('ViewPay Debug: Configuration durée cookie: ' . $cookie_duration_minutes . ' minutes');
        error_log('ViewPay Debug: Heure actuelle (' . $wp_timezone->getName() . '): ' . $current_time->format('Y-m-d H:i:s T'));
        error_log('ViewPay Debug: Expiration (' . $wp_timezone->getName() . '): ' . $expiry_time->format('Y-m-d H:i:s T'));
        error_log('ViewPay Debug: Timestamp expiration UTC: ' . $expiry_timestamp . ' (' . date('Y-m-d H:i:s T', $expiry_timestamp) . ')');
        error_log('ViewPay Debug: Cookie value: ' . $cookie_value);
        
        setcookie('viewpay_unlocked_posts', $cookie_value, $expiry_timestamp, '/', '', $secure, $http_only);
        $_COOKIE['viewpay_unlocked_posts'] = $cookie_value; // Update $_COOKIE variable immediately
        
        // Send success response with force reload parameter
        wp_send_json_success(array(
            'message' => sprintf(__('Contenu déverrouillé avec succès pour %d minutes!', 'viewpay-wordpress'), $cookie_duration_minutes),
            'redirect' => get_permalink($post_id) . '?viewpay_unlocked=' . time(), // Force cache bypass
            'cookie_set' => true,
            'post_id' => $post_id,
            'duration_minutes' => $cookie_duration_minutes,
            'expiry_local' => $expiry_time->format('Y-m-d H:i:s T'),
            'expiry_timestamp' => $expiry_timestamp
        ));
    } 
    
    /**
     * Ajuste la luminosité d'une couleur hexadécimale
     * @param string $hex Couleur hexadécimale
     * @param int $steps Nombre de pas (positif pour éclaircir, négatif pour assombrir)
     * @return string Couleur hexadécimale ajustée
     */
    private function adjust_brightness($hex, $steps) {
        // Supprimer le # si présent
        $hex = ltrim($hex, '#');
        
        // Convertir en RGB
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        
        // Ajuster la luminosité
        $r = max(0, min(255, $r + $steps));
        $g = max(0, min(255, $g + $steps));
        $b = max(0, min(255, $b + $steps));
        
        // Convertir en hexadécimal
        return '#' . sprintf('%02x%02x%02x', $r, $g, $b);
    }
}
