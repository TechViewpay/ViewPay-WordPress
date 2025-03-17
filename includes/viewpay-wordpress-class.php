<?php
/**
 * Classe principale pour ViewPay WordPress
 */
class ViewPay_WordPress {
    
    /**
     * Initialise le plugin
     */
    public function init() {
        // Hook principal pour ajouter notre bouton dans le message de restriction
        add_filter('pmpro_non_member_text_filter', array($this, 'add_viewpay_button'), 10, 2);
        
        // Hook secondaire pour s'assurer que ça fonctionne avec diverses configurations
        add_filter('pmpro_not_logged_in_text_filter', array($this, 'add_viewpay_button'), 10, 2);
        
        // Hook pour vérifier l'accès
        add_filter('pmpro_has_membership_access_filter', array($this, 'check_viewpay_access'), 10, 4);
        
        // Intercepter les requêtes avec le paramètre de déverrouillage
        // add_action('template_redirect', array($this, 'maybe_force_unlock'));
        
        // Ajouter les scripts et styles
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        
        // Ajouter des styles inline en secours
        add_action('wp_head', array($this, 'add_inline_styles'));
        
        // Ajouter l'endpoint AJAX pour traiter le déverrouillage
        add_action('wp_ajax_viewpay_content', array($this, 'process_viewpay'));
        add_action('wp_ajax_nopriv_viewpay_content', array($this, 'process_viewpay'));
    }
    
    /**
     * Force le déverrouillage si le paramètre est présent dans l'URL
     * (Méthode désactivée - utilisation des cookies uniquement)
     */
    /*
    public function maybe_force_unlock() {
        if (isset($_GET['viewpay_unlocked']) && !empty($_GET['viewpay_unlocked'])) {
            $post_id = intval($_GET['viewpay_unlocked']);
            error_log('ViewPay: Tentative de déverrouillage forcé pour post ID ' . $post_id);
            
            if (is_singular() && get_the_ID() == $post_id) {
                // Vérifier si le cookie existe déjà
                $cookie_posts = array();
                if (isset($_COOKIE['viewpay_unlocked_posts']) && !empty($_COOKIE['viewpay_unlocked_posts'])) {
                    $cookie_data = stripslashes($_COOKIE['viewpay_unlocked_posts']);
                    $cookie_posts = json_decode($cookie_data, true);
                    
                    if (!is_array($cookie_posts)) {
                        $cookie_posts = array();
                    }
                }
                
                // S'assurer que ce post est dans la liste
                if (!in_array($post_id, $cookie_posts)) {
                    $cookie_posts[] = $post_id;
                    
                    // Définir le cookie
                    $cookie_value = json_encode($cookie_posts);
                    setcookie('viewpay_unlocked_posts', $cookie_value, time() + 86400, '/');
                    $_COOKIE['viewpay_unlocked_posts'] = $cookie_value;
                    
                    error_log('ViewPay: Cookie renforcé avec succès pour post ID ' . $post_id);
                }
                
                // Pour les utilisateurs connectés, mettre à jour les métadonnées
                $user_id = get_current_user_id();
                if ($user_id > 0) {
                    $unlocked_posts = get_user_meta($user_id, 'viewpay_unlocked_posts', true);
                    if (!is_array($unlocked_posts)) {
                        $unlocked_posts = array();
                    }
                    
                    if (!in_array($post_id, $unlocked_posts)) {
                        $unlocked_posts[] = $post_id;
                        update_user_meta($user_id, 'viewpay_unlocked_posts', $unlocked_posts);
                        error_log('ViewPay: Métadonnées utilisateur forcées pour user ID ' . $user_id);
                    }
                }
            }
        }
    }
    */
    
    /**
     * Ajoute le bouton "Débloquer en regardant une publicité" au message de restriction
     */
    public function add_viewpay_button($text, $level_id = 0) {
        global $post;
        
        if (!$post) {
            return $text;
        }
        
        // Générer un nonce pour la sécurité
        $nonce = wp_create_nonce('viewpay_nonce');
        
        // Créer le bouton avec la classe pmpro_btn pour adopter le style de PMPro
        $button = '<div class="viewpay-container">';
        $button .= '<button id="viewpay-button" class="viewpay-button pmpro_btn" data-post-id="' . esc_attr($post->ID) . '" data-nonce="' . esc_attr($nonce) . '">';
        $button .= __('Débloquer en regardant une publicité', 'viewpay-wordpress');
        $button .= '</button>';
        $button .= '</div>';
        
        // Ajouter le bouton après le message de restriction
        return $text . $button;
    }
    
    /**
     * Vérifie si l'utilisateur a déverrouillé le contenu via une publicité
     */
    public function check_viewpay_access($hasaccess, $mypost, $myuser, $post_membership_levels) {
        // Si l'utilisateur a déjà accès, on ne fait rien
        if ($hasaccess) {
            return $hasaccess;
        }
        
        // Vérifier si l'utilisateur a déjà regardé une publicité pour ce contenu
        $post_id = $mypost->ID;
        
        // Log de débogage
        error_log('ViewPay: Vérification d\'accès pour post ID ' . $post_id);
        
        // Vérifier uniquement via les cookies
        if (isset($_COOKIE['viewpay_unlocked_posts']) && !empty($_COOKIE['viewpay_unlocked_posts'])) {
            $cookie_value = stripslashes($_COOKIE['viewpay_unlocked_posts']);
            error_log('ViewPay: Cookie trouvé avec valeur: ' . $cookie_value);
            
            $unlocked_posts = json_decode($cookie_value, true);
            
            if (is_array($unlocked_posts) && in_array($post_id, $unlocked_posts)) {
                error_log('ViewPay: Accès accordé via cookie');
                return true;
            }
        } else {
            error_log('ViewPay: Aucun cookie viewpay_unlocked_posts trouvé');
        }
        
        // Utilisation des user_meta désactivée
        /*
        $user_id = $myuser->ID;
        // Pour les utilisateurs connectés, on vérifie dans les métadonnées utilisateur
        if ($user_id > 0) {
            $unlocked_posts = get_user_meta($user_id, 'viewpay_unlocked_posts', true);
            if (is_array($unlocked_posts) && in_array($post_id, $unlocked_posts)) {
                error_log('ViewPay: Accès accordé via user meta');
                return true;
            }
        }
        */
        
        error_log('ViewPay: Accès refusé, contenu toujours verrouillé');
        return $hasaccess;
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
            'adTime' => 30, // Durée de la publicité en secondes
            'nonce' => wp_create_nonce('viewpay_nonce'),
            'adLoadingText' => __('Chargement de la publicité...', 'viewpay-wordpress'),
            'adWatchingText' => __('Regardez la publicité pour débloquer le contenu...', 'viewpay-wordpress'),
            'adCompleteText' => __('Publicité terminée! Débloquage du contenu...', 'viewpay-wordpress'),
            'adErrorText' => __('Erreur lors du chargement de la publicité. Veuillez réessayer.', 'viewpay-wordpress')
        ));
    }
    
    /**
     * Ajoute des styles CSS inline en secours
     */
    public function add_inline_styles() {
        ?>
        <style type="text/css">
            .viewpay-container {
                margin: 20px 0;
                text-align: center;
            }
            .viewpay-button {
                display: inline-block;
                background-color: #0073aa;
                color: #fff !important;
                padding: 12px 24px;
                border: none;
                border-radius: 4px;
                font-size: 16px;
                font-weight: 600;
                cursor: pointer;
                transition: all 0.3s ease;
                text-decoration: none;
            }
            .viewpay-button:hover {
                background-color: #005a87;
            }
            .viewpay-modal {
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background-color: rgba(0, 0, 0, 0.8);
                display: flex;
                justify-content: center;
                align-items: center;
                z-index: 9999;
            }
            .viewpay-modal-content {
                background-color: #fff;
                padding: 30px;
                border-radius: 8px;
                max-width: 600px;
                width: 90%;
                text-align: center;
                box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
            }
        </style>
        <?php
    }
    
    /**
     * Traite la demande de déverrouillage après visionnage de la publicité
     */
    public function process_viewpay() {
        error_log('ViewPay: Traitement de la demande de déverrouillage');
        
        // Vérifier l'ID du post
        if (!isset($_POST['post_id']) || empty($_POST['post_id'])) {
            wp_send_json_error(array('message' => __('ID du post manquant.', 'viewpay-wordpress')));
            return;
        }
        
        $post_id = intval($_POST['post_id']);
        error_log('ViewPay: Déverrouillage demandé pour post ID ' . $post_id);
        
        // Créer/mettre à jour le cookie (méthode unique)
        $cookie_posts = array();
        if (isset($_COOKIE['viewpay_unlocked_posts']) && !empty($_COOKIE['viewpay_unlocked_posts'])) {
            $cookie_data = stripslashes($_COOKIE['viewpay_unlocked_posts']);
            
            // Tentative de décodage JSON avec gestion d'erreur
            try {
                $decoded = json_decode($cookie_data, true);
                if (is_array($decoded)) {
                    $cookie_posts = $decoded;
                } else {
                    error_log('ViewPay: Décodage JSON a échoué, mais pas d\'erreur: ' . $cookie_data);
                }
            } catch (Exception $e) {
                error_log('ViewPay: Erreur lors du décodage JSON: ' . $e->getMessage());
            }
        }
        
        if (!in_array($post_id, $cookie_posts)) {
            $cookie_posts[] = $post_id;
        }
        
        // Définir un cookie avec un temps d'expiration de 24 heures
        $cookie_value = json_encode($cookie_posts);
        error_log('ViewPay: Définition du cookie avec valeur: ' . $cookie_value);
        
        setcookie('viewpay_unlocked_posts', $cookie_value, time() + 86400, '/');
        $_COOKIE['viewpay_unlocked_posts'] = $cookie_value; // Mettre à jour la variable $_COOKIE immédiatement
        
        // Partie user_meta désactivée
        /*
        // Pour les utilisateurs connectés, enregistrer également dans les métadonnées utilisateur
        $user_id = get_current_user_id();
        if ($user_id > 0) {
            $unlocked_posts = get_user_meta($user_id, 'viewpay_unlocked_posts', true);
            if (!is_array($unlocked_posts)) {
                $unlocked_posts = array();
            }
            
            if (!in_array($post_id, $unlocked_posts)) {
                $unlocked_posts[] = $post_id;
                update_user_meta($user_id, 'viewpay_unlocked_posts', $unlocked_posts);
                error_log('ViewPay: Métadonnées utilisateur mises à jour');
            }
        }
        */
        
        // Envoyer une réponse de succès
        wp_send_json_success(array(
            'message' => __('Contenu déverrouillé avec succès!', 'viewpay-wordpress'),
            'redirect' => get_permalink($post_id),
            'cookie_set' => true,
            'post_id' => $post_id
        ));
    }
}
