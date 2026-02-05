# Notes intégration TSA Algérie

**Date:** 9 janvier 2026
**Site:** https://www.tsa-algerie.com
**Article test:** https://www.tsa-algerie.com/importations-les-entreprises-algeriennes-face-a-une-grosse-incertitude/

---

## Problème 1 : Le bouton ViewPay ne s'affiche pas

### Diagnostic
- Le plugin est configuré avec `paywallType: "pms"` (Paid Member Subscriptions)
- TSA n'utilise PAS PMS, ils ont un paywall custom
- Les hooks WordPress de PMS ne sont jamais déclenchés → pas d'injection du bouton

### Solution
Modifier la configuration dans **Réglages → ViewPay WordPress** :

| Paramètre | Valeur actuelle | Valeur correcte |
|-----------|-----------------|-----------------|
| Type de paywall | PMS | **Personnalisé (Custom)** |
| Sélecteur CSS | *(vide)* | `.article-content > p:has(a[href*="pricing"])` |
| Position du bouton | after | **inside_end** |

### Sélecteur CSS testé et validé
```css
.article-content > p:has(a[href*="pricing"])
```
- Cible précisément l'encart gris "Ce contenu est réservé aux abonnés"
- Match unique (1 seul élément)
- Compatible jQuery (utilisé par le plugin)

### Code de test console (pour vérifier)
```javascript
var selector = '.article-content > p:has(a[href*="pricing"])';
var $paywall = jQuery(selector);
if ($paywall.length) {
    var postId = document.body.className.match(/postid-(\d+)/)?.[1];
    var buttonHtml = '<div class="viewpay-custom-container" style="margin-top:15px; text-align:center;">';
    buttonHtml += '<div class="viewpay-separator" style="font-weight:bold; color:#666; margin-bottom:10px;">OU</div>';
    buttonHtml += '<button id="viewpay-button" class="viewpay-button" data-post-id="' + postId + '" data-nonce="' + viewpayVars.nonce + '" style="padding:12px 24px; background:#e74c3c; color:white; border:none; border-radius:25px; cursor:pointer; font-size:16px;">▶ Débloquer en regardant une publicité</button>';
    buttonHtml += '</div>';
    $paywall.append(buttonHtml);
    console.log('✅ Bouton injecté avec postId=' + postId);
} else {
    console.log('❌ Sélecteur non trouvé');
}
```

---

## Problème 2 : Le déblocage du contenu ne fonctionnera pas (à confirmer)

### Contexte
L'intégration "custom" du plugin fait seulement :
1. Ajoute une classe `viewpay-unlocked` au body
2. Cache le paywall via CSS : `.viewpay-unlocked [selector] { display: none !important; }`

### Limitation
**Ça cache le message du paywall, mais ça ne révèle pas le contenu !**

Ça ne fonctionne que si :
- Le contenu complet est dans le DOM mais caché en CSS
- OU le paywall vérifie lui-même le cookie ViewPay

Si le paywall de TSA **tronque le contenu côté serveur** (contenu jamais envoyé au navigateur), ViewPay ne peut rien débloquer.

### Solutions possibles
1. **TSA modifie leur paywall** pour vérifier le cookie `viewpay_unlocked_posts` (JSON array de post IDs) et afficher le contenu complet
2. **Créer une intégration spécifique TSA** dans le plugin (comme `class-viewpay-pymag-integration.php`)

---

## Problème 3 : Cookie non créé lors du test manuel

### Observation
- Le reload de page a eu lieu après visionnage de la pub
- Mais aucun cookie `viewpay_unlocked_posts` n'a été créé

### Hypothèses
- Problème de `SameSite` non spécifié dans `setcookie()` (navigateurs modernes peuvent rejeter)
- Erreur côté serveur avant `setcookie()`
- Problème de domaine (www vs non-www)

### À investiguer
Vérifier la réponse AJAX de `admin-ajax.php` :
- Response body (JSON success/error)
- Response headers (Set-Cookie présent ?)

---

## Questions pour TSA

1. **Quel système de paywall utilisez-vous ?** Plugin WordPress ou code custom ?
2. **Le contenu complet est-il dans le DOM** (caché en CSS) ou tronqué côté serveur ?
3. **Pouvez-vous modifier votre paywall** pour vérifier le cookie `viewpay_unlocked_posts` ?

---

## Structure du paywall TSA (référence)

```
<p> (fond gris rgb(248,248,248))
  ├── "Ce contenu est réservé aux abonnés."
  ├── <a href="pricing-4">Abonnez-vous dès maintenant</a>
  ├── "ou"
  ├── <a>connectez-vous</a>
  └── "pour y accéder."
</p>
```

Parents :
```
P (paywall)
└── DIV.article-content
    └── DIV.main-content
        └── DIV.article-body
            └── ARTICLE
                └── MAIN.single-article
```

---

## Fichiers pertinents du plugin

- `includes/integrations/class-viewpay-custom-integration.php` - Intégration custom (CSS-based)
- `assets/js/viewpay-wordpress.js` - Injection JS du bouton (fonction `injectCustomPaywallButton()`)
- `includes/viewpay-wordpress-class.php:379` - Fonction `process_viewpay()` qui set le cookie
