(function($) {
    'use strict';
    
    $(document).ready(function() {
        // Initialiser le color picker
        $('.viewpay-color-field').wpColorPicker();
        
        // Référence aux éléments importants
        var $useCustomColor = $('#viewpay-use-custom-color');
        var $colorPickerContainer = $('.viewpay-color-field').closest('.wp-picker-container');
        
        // Fonction pour désactiver le color picker
        function disableColorPicker() {
            // Ajouter une classe personnalisée pour le style
            $colorPickerContainer.addClass('viewpay-picker-disabled');
            
            // Désactiver les clics sur le bouton du color picker
            $colorPickerContainer.find('.wp-color-result').css('pointer-events', 'none');
            
            // Ajouter un style CSS inline pour montrer visuellement qu'il est désactivé
            $colorPickerContainer.find('.wp-color-result').css('opacity', '0.5');
        }
        
        // Fonction pour activer le color picker
        function enableColorPicker() {
            // Supprimer la classe personnalisée
            $colorPickerContainer.removeClass('viewpay-picker-disabled');
            
            // Réactiver les clics sur le bouton du color picker
            $colorPickerContainer.find('.wp-color-result').css('pointer-events', 'auto');
            
            // Restaurer l'opacité normale
            $colorPickerContainer.find('.wp-color-result').css('opacity', '1');
        }
        
        // Appliquer l'état initial
        if ($useCustomColor.is(':checked')) {
            enableColorPicker();
        } else {
            disableColorPicker();
        }
        
        // Gérer le changement d'état en temps réel
        $useCustomColor.on('change', function() {
            if ($(this).is(':checked')) {
                enableColorPicker();
            } else {
                disableColorPicker();
            }
        });
    });
    
})(jQuery);
