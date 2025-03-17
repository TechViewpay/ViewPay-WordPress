# Guide d'Intégration pour ViewPay WordPress

Ce document fournit un guide technique détaillé pour l'intégration du plugin ViewPay WordPress avec différents systèmes de paywall et l'intégration de votre propre solution publicitaire.

## 1. Architecture Technique du Plugin

Le plugin se compose de trois parties principales :

### 1.1 Composants Principaux

- **Core Plugin (viewpay-wordpress.php)** : Point d'entrée et initialisation du plugin
- **Main Class (viewpay-wordpress-class.php)** : Logique principale et hooks WordPress
- **Frontend Assets** : JavaScript et CSS pour l'interface utilisateur

### 1.2 Flux de Données

1. Le plugin détecte le contenu protégé via des hooks spécifiques au paywall
2. Un bouton est injecté dans l'interface du paywall existant
3. Lors du clic sur le bouton, une modal s'ouvre pour afficher la publicité
4. Une fois la publicité visionnée, un appel AJAX est effectué pour enregistrer le déverrouillage
5. Le système de cookies enregistre l'accès pour une durée de 24 heures
6. La page est rechargée et l'accès est accordé via le système de vérification

## 2. Intégration avec Paid Memberships Pro

### 2.1 Points d'Intégration

Les hooks WordPress suivants sont utilisés pour l'intégration avec PMPro :

```php
// Ajouter le bouton au message de restriction
add_filter('pmpro_non_member_text_filter', array($this, 'add_viewpay_button'), 10, 2);
add_filter('pmpro_not_logged_in_text_filter', array($this, 'add_viewpay_button'), 10, 2);

// Vérifier si l'utilisateur a déverrouillé le contenu
add_filter('pmpro_has_membership_access_filter', array($this, 'check_viewpay_access'), 10, 4);
```

### 2.2 Vérification d'Accès

Le plugin intercepte la vérification d'accès standard de PMPro et vérifie si l'utilisateur a déverrouillé le contenu via publicité :

```php
public function check_viewpay_access($hasaccess, $mypost, $myuser, $post_membership_levels) {
    // Si l'utilisateur a déjà accès via PMPro, ne rien faire
    if ($hasaccess) {
        return $hasaccess;
    }
    
    // Vérifier l'accès via cookies ViewPay
    $post_id = $mypost->ID;
    if (isset($_COOKIE['viewpay_unlocked_posts']) && !empty($_COOKIE['viewpay_unlocked_posts'])) {
        $unlocked_posts = json_decode(stripslashes($_COOKIE['viewpay_unlocked_posts']), true);
        if (is_array($unlocked_posts) && in_array($post_id, $unlocked_posts)) {
            return true; // Accès accordé
        }
    }
    
    return $hasaccess; // Comportement par défaut si non déverrouillé
}
```

## 3. Intégration de Votre Système Publicitaire

### 3.1 Emplacement pour l'Intégration Publicitaire

Le code qui gère l'affichage et le traitement des publicités se trouve dans `assets/js/viewpay-wordpress.js`. Vous devrez modifier la section suivante :

```javascript
// Simuler le chargement d'une publicité
// Remplacez ce code par l'intégration de votre système publicitaire
setTimeout(function() {
    $('.viewpay-ad-container').html('<iframe src="about:blank" width="468" height="60" frameborder="0" scrolling="no" style="display: block; margin: 0 auto; border: 1px solid #ccc;"></iframe><p style="margin-top: 15px;">Ceci est une publicité simulée.</p>');
    $('.viewpay-status').text(viewpayVars.adWatchingText);
    
    var adDuration = 5; // Secondes pour la démo (normalement 30s)
    var adTimer = setInterval(function() {
        // [...] Code de la minuterie
    }, 1000);
}, 2000);
```

### 3.2 Intégration avec ViewPay SDK

Si vous utilisez le SDK ViewPay standard, vous pouvez l'intégrer comme suit :

```javascript
// Remplacer par le code d'intégration ViewPay
setTimeout(function() {
    // Initialiser le SDK ViewPay
    window.ViewPay.init({
        container: '.viewpay-ad-container',
        callback: {
            onAdComplete: function() {
                // Compléter le processus de déverrouillage
                $('.viewpay-status').text(viewpayVars.adCompleteText);
                
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
                        // [...] Code de gestion de la réponse
                    }
                });
            },
            onAdError: function(error) {
                $('.viewpay-status').html('<span style="color: red;">✗ ' + viewpayVars.adErrorText + '</span>');
                modalContent.append('<button class="viewpay-close">Fermer</button>');
            }
        }
    });
    
    // Démarrer l'affichage de la publicité
    window.ViewPay.showAd();
    
}, 1000);
```

## 4. Intégration avec d'Autres Plugins de Paywall

### 4.1 Simple Membership

Pour intégrer avec Simple Membership, vous devrez ajouter les hooks suivants au fichier `viewpay-wordpress-class.php` :

```php
// Dans la méthode init()
add_filter('swpm_non_member_message', array($this, 'add_viewpay_button_swpm'), 10, 1);
add_filter('swpm_access_check', array($this, 'check_viewpay_access_swpm'), 10, 2);

// Nouvelle méthode pour Simple Membership
public function add_viewpay_button_swpm($message) {
    global $post;
    
    if (!$post) {
        return $message;
    }
    
    // Générer un nonce pour la sécurité
    $nonce = wp_create_nonce('viewpay_nonce');
    
    // Créer le bouton avec style adapté à Simple Membership
    $button = '<div class="viewpay-container">';
    $button .= '<button id="viewpay-button" class="viewpay-button swpm-button" data-post-id="' . esc_attr($post->ID) . '" data-nonce="' . esc_attr($nonce) . '">';
    $button .= __('Débloquer en regardant une publicité', 'viewpay-wordpress');
    $button .= '</button>';
    $button .= '</div>';
    
    // Ajouter le bouton après le message de restriction
    return $message . $button;
}

// Vérification d'accès pour Simple Membership
public function check_viewpay_access_swpm($access, $post_id) {
    // Si l'accès est déjà accordé, ne rien faire
    if ($access) {
        return $access;
    }
    
    // Vérifier via cookie ViewPay
    if (isset($_COOKIE['viewpay_unlocked_posts']) && !empty($_COOKIE['viewpay_unlocked_posts'])) {
        $unlocked_posts = json_decode(stripslashes($_COOKIE['viewpay_unlocked_posts']), true);
        if (is_array($unlocked_posts) && in_array($post_id, $unlocked_posts)) {
            return true; // Accès accordé
        }
    }
    
    return $access;
}
```

### 4.2 WP-Members

L'intégration avec WP-Members suivrait un modèle similaire, en utilisant les hooks spécifiques à ce plugin.

## 5. Tests d'Intégration

Pour chaque plugin de paywall, il est recommandé de tester les scénarios suivants :

1. **Test d'affichage du bouton** : Vérifier que le bouton s'affiche correctement dans l'interface du paywall
2. **Test du processus publicitaire** : Vérifier que la modal s'ouvre et que le processus publicitaire fonctionne
3. **Test de déverrouillage** : Vérifier que l'accès est correctement accordé après visionnage de la publicité
4. **Test de persistance** : Vérifier que l'accès reste déverrouillé pendant 24 heures

## 6. Considérations de Performance

- Le plugin est conçu pour être léger et ne charger ses ressources que lorsque nécessaire
- Utilisez un CDN pour les ressources publicitaires volumineuses
- Considérez l'impact potentiel sur Core Web Vitals, en particulier pour le Largest Contentful Paint

## 7. Meilleures Pratiques pour la Production

1. Minifiez les ressources JavaScript et CSS avant la mise en production
2. Utilisez un préfixe cache-busting pour les URL de ressources (comme un numéro de version)
3. Implémentez une gestion d'erreurs robuste pour assurer une expérience utilisateur fluide même en cas d'échec du chargement publicitaire
4. Envisagez d'ajouter des métriques de suivi pour analyser l'efficacité et l'utilisation du bouton de déverrouillage

Ce guide d'intégration est un document évolutif qui sera mis à jour avec des informations supplémentaires à mesure que le plugin prendra en charge davantage de systèmes de paywall.
