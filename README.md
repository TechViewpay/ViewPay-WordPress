# ViewPay WordPress

Ce plugin intègre la solution ViewPay dans les principaux plugins de paywall WordPress, permettant aux visiteurs de débloquer du contenu premium en regardant une publicité.

## Description

ViewPay WordPress est un plugin qui s'intègre avec les principaux systèmes de paywall WordPress pour offrir une alternative aux abonnements payants. Lorsqu'un visiteur rencontre du contenu verrouillé, il peut choisir de regarder une publicité complète pour débloquer l'accès à ce contenu spécifique.

### Plugins de paywall compatibles

- Paid Memberships Pro
- Simple Membership (SWPM)
- WP-Members
- Restrict User Access (RUA)
- Ultimate Member (UM)

## Installation

1. Téléchargez le plugin dans le répertoire `/wp-content/plugins/`
2. Activez le plugin via le menu 'Extensions' dans WordPress
3. Accédez à Réglages > ViewPay WordPress pour configurer le plugin

## Configuration

1. **ID de site ViewPay** : Entrez votre identifiant de site fourni par ViewPay. Par défaut, un ID de démonstration est utilisé.
2. **Texte du bouton** : Personnalisez le texte du bouton qui permettra aux utilisateurs de débloquer le contenu.
3. **Couleur du bouton** : Choisissez une couleur qui s'intègre bien à votre thème.

## Fonctionnement

1. Le plugin détecte automatiquement quel système de paywall est utilisé sur votre site.
2. Lorsqu'un visiteur rencontre du contenu restreint, un bouton "Débloquer en regardant une publicité" (ou votre texte personnalisé) s'affiche.
3. En cliquant sur ce bouton, une publicité ViewPay s'affiche dans une fenêtre modale.
4. Après avoir regardé la publicité complète, le contenu est débloqué pour l'utilisateur.
5. L'accès au contenu débloqué persiste pendant 24 heures grâce à un cookie côté client.

## Personnalisation avancée

Vous pouvez personnaliser davantage l'apparence du bouton en modifiant les fichiers CSS :
- `/assets/css/viewpay-wordpress.css` pour les styles principaux
- Ou utiliser votre propre CSS dans votre thème pour surcharger les styles par défaut

## FAQ

### La publicité ne s'affiche pas, que faire ?

Vérifiez que vous avez bien entré l'ID de site ViewPay correct dans les paramètres du plugin. Par défaut, un ID de démonstration est utilisé.

### Comment puis-je modifier l'apparence du bouton ?

Vous pouvez modifier la couleur du bouton dans les paramètres du plugin. Pour des personnalisations plus avancées, vous pouvez ajouter votre propre CSS dans votre thème.

### Est-ce que le contenu reste débloqué indéfiniment ?

Non, l'accès au contenu débloqué est temporaire et dure 24 heures. Après cette période, l'utilisateur devra regarder une nouvelle publicité pour accéder au contenu à nouveau.

## Support

Pour tout problème ou question concernant ce plugin, veuillez contacter votre interlocuteur ViewPay habituel ou nous écrire à support@viewpay.tv.
