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
     * Configure l'intégration avec le plugin de paywall actif
     */
    private function setup_paywall_integration() {
        // Integration files directory
        $integration_dir = VIEWPAY_WORDPRESS_PLUGIN_DIR . 'includes/integrations/';
        
        // Paid Memberships Pro
        if (function_exists('pmpro_has_membership_access')) {
            require_once $integration_dir . 'class-viewpay-pmpro-integration.php';
            $this->integrations['pmpro'] = new ViewPay_PMPro_Integration($this);
        }
        
        // Simple Membership (SWPM)
        if (class_exists('SwpmMembershipLevel')) {
            add_filter('swpm_restricted_content_msg', array($this, 'add_viewpay_button_simple'), 10, 1);
            add_filter('swpm_access_control_before_filtering', array($this, 'check_viewpay_access_simple'), 10, 2);
        }
        
        // WP-Members
        if (function_exists('wpmem_is_blocked')) {
            add_filter('wpmem_restricted_msg', array($this, 'add_viewpay_button_simple'), 10, 1);
            add_filter('wpmem_access_filter', array($this, 'check_viewpay_access_wpmem'), 10, 2);
        }
        
        // Restrict User Access (RUA)
        if (class_exists('RUA_App')) {
            add_filter('rua/access-conditions/satisfy', array($this, 'check_viewpay_access_rua'), 10, 3);
            add_filter('rua/frontend/content-block', array($this, 'add_viewpay_button_simple'), 10, 1);
        }
        
        // Ultimate Member (UM)
        if (class_exists('UM')) {
            add_filter('um_access_restrict_non_member_message', array($this, 'add_viewpay_button_simple'), 10, 1);
            add_filter('um_access_skip_restriction_check', array($this, 'check_viewpay_access_um'), 10, 3);
        }
        
        // Restrict Content Pro (RCP)
        if (class_exists('RCP_Member') || class_exists('RCP_Requirements_Check')) {
            require_once $integration_dir . 'class-viewpay-rcp-integration.php';
            $this->integrations['rcp'] = new ViewPay_RCP_Integration($this);
        }
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
     * Vérifie l'accès pour Simple Membership
     */
    public function check_viewpay_access_simple($content, $post_id) {
        // Si le contenu est déverrouillé via ViewPay, on retourne le contenu non filtré
        if ($this->is_post_unlocked($post_id)) {
            return $content;
        }
        
        // Sinon, on laisse Simple Membership gérer l'accès
        return null;
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
    
    /**
     * Ajoute les scripts et styles nécessaires
     */
    public function enqueue_scripts() {
        // Charger les styles et scripts sur toutes les pages
        wp_enqueue_style('viewpay-styles', VIEWPAY_WORDPRESS_PLUGIN_URL . 'assets/css/viewpay-wordpress.css', array(), VIEWPAY_WORDPRESS_VERSION);
        wp_enqueue_script('viewpay-script', VIEWPAY_WORDPRESS_PLUGIN_URL . 'assets/js/viewpay-wordpress.js', array('jquery'), VIEWPAY_WORDPRESS_VERSION, true);
        
        // Passer des variables au script
        wp_localize_script('viewpay-script', 'viewpayVars', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'homeurl' => home_url(),
            'siteId' => $this->options['site_id'],
            'nonce' => wp_create_nonce('viewpay_nonce'),
            'adLoadingText' => __('Chargement de la publicité...', 'viewpay-wordpress'),
            'adWatchingText' => __('Regardez la publicité pour débloquer le contenu...', 'viewpay-wordpress'),
            'adCompleteText' => __('Publicité terminée! Débloquage du contenu...', 'viewpay-wordpress'),
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
        
        // Set cookie with more robust parameters
        $cookie_value = json_encode($cookie_posts);
        $secure = is_ssl();
        $http_only = true; // Prevent JavaScript access
        
        // Log cookie setting
        error_log('ViewPay Debug: Setting cookie with value: ' . $cookie_value);
        
        setcookie('viewpay_unlocked_posts', $cookie_value, time() + 86400, '/', '', $secure, $http_only);
        $_COOKIE['viewpay_unlocked_posts'] = $cookie_value; // Update $_COOKIE variable immediately
        
        // Send success response with force reload parameter
        wp_send_json_success(array(
            'message' => __('Contenu déverrouillé avec succès!', 'viewpay-wordpress'),
            'redirect' => get_permalink($post_id) . '?viewpay_unlocked=' . time(), // Force cache bypass
            'cookie_set' => true,
            'post_id' => $post_id
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
