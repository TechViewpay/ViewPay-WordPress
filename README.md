# ViewPay WordPress Plugin

## Description

ViewPay WordPress est un plugin qui offre une alternative aux abonnements payants pour les sites utilisant des systèmes de paywalls. Cette solution permet aux visiteurs de débloquer un contenu premium en regardant une publicité, sans avoir besoin de souscrire à un abonnement.

Le plugin ajoute un bouton "Débloquer en regardant une publicité" directement dans l'interface du paywall existant, offrant ainsi une option supplémentaire aux visiteurs.

## Caractéristiques

- Intégration native avec les plugins de paywall populaires
- Processus utilisateur simple et intuitif
- Déblocage d'articles via cookies (persistance de 24h)
- Interface légère et réactive
- Compatible avec les thèmes WordPress modernes

## Plugins de Paywall Supportés

Actuellement, le plugin prend en charge l'intégration avec :

- **Paid Memberships Pro** (version gratuite) - Support complet
  - S'intègre aux messages de restriction standard
  - Compatible avec les niveaux d'adhésion multiples
  - Hooks d'intégration complètement testés

Les prochaines versions incluront le support pour :

- Simple Membership
- WP-Members
- Restrict User Access
- Ultimate Member

## Installation

1. Téléchargez le dossier `viewpay-wordpress` sur votre serveur dans le répertoire `/wp-content/plugins/`
2. Activez le plugin via le menu 'Extensions' dans WordPress
3. Assurez-vous que Paid Memberships Pro est installé et correctement configuré
4. Le bouton "Débloquer en regardant une publicité" apparaîtra automatiquement dans le message de restriction de Paid Memberships Pro

## Configuration Technique

Le plugin fonctionne en :

1. **Détectant les points d'intégration** avec le plugin de paywall via des hooks WordPress
2. **Ajoutant un bouton personnalisé** directement dans l'interface du paywall
3. **Gérant le processus publicitaire** via une modal interactive
4. **Débloquant le contenu** via un système de cookies d'une durée de 24 heures

### Détails Techniques d'Intégration pour Paid Memberships Pro

- Hooks principaux utilisés : 
  - `pmpro_non_member_text_filter`
  - `pmpro_not_logged_in_text_filter` 
  - `pmpro_has_membership_access_filter`

- Le plugin utilise uniquement les cookies pour la persistance des données afin de garder une architecture simple

## Personnalisation

### Intégration de Votre Système Publicitaire

Le plugin inclut actuellement une simulation de publicité pour démonstration. Pour intégrer votre propre solution publicitaire, modifiez la section correspondante dans le fichier `assets/js/viewpay-wordpress.js`. Recherchez le commentaire "Simuler le chargement d'une publicité" et remplacez le code par votre propre implémentation.

### Personnalisation Visuelle

Les styles du bouton et de la modal peuvent être modifiés via :

- `assets/css/viewpay-wordpress.css` pour les styles principaux
- La classe `pmpro_btn` est déjà appliquée au bouton pour correspondre au design de Paid Memberships Pro

## Dépannage

Si le bouton n'apparaît pas :
- Vérifiez que Paid Memberships Pro est bien activé
- Assurez-vous qu'un niveau d'adhésion est correctement configuré
- Vérifiez que le contenu est bien restreint via les réglages de PMPro

Si l'accès n'est pas débloqué après visionnage :
- Vérifiez que les cookies sont autorisés dans le navigateur
- Assurez-vous que la fonction `setcookie()` de PHP fonctionne correctement sur votre serveur

## Feuille de Route

### Prochaines Fonctionnalités Prévues

1. Support pour d'autres plugins de paywall
   - Simple Membership (priorité haute)
   - WP-Members (priorité haute)
   - Restrict User Access (priorité moyenne)
   - Ultimate Member (priorité moyenne)

2. Améliorations Fonctionnelles
   - Tableau de bord d'administration pour suivre l'utilisation
   - Personnalisation avancée des messages et du design
   - Paramètres de durée de déverrouillage ajustables

## Notes de Développement

Le plugin a été conçu pour être aussi léger et performant que possible. La solution actuelle se concentre sur les cookies comme mécanisme de stockage pour éviter les problèmes de base de données et de performance. Une approche modulaire a été adoptée pour faciliter l'extension à d'autres plugins de paywall.

## Licence

Ce plugin est distribué sous licence propriétaire. Tous droits réservés.

## Support

Pour toute question ou assistance, veuillez contacter notre équipe technique.
