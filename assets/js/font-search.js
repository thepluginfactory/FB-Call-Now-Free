/**
 * FB Call Now - Google Font Search
 *
 * Provides autocomplete functionality for Google Font selection
 *
 * @package FBCallNow
 * @since 4.2.0
 */

(function($) {
    'use strict';

    let googleFonts = [];
    let currentSelection = -1;

    $(document).ready(function() {
        // Only initialize if the font input exists
        if ($('#google_font_input').length === 0) {
            return;
        }

        // Load Google Fonts list
        loadGoogleFonts();

        // Font source selector change
        $('#font_family').on('change', function() {
            if ($(this).val() === 'google') {
                $('#google_font_search').slideDown();
            } else {
                $('#google_font_search').slideUp();
            }
        });

        // Google font search input
        $('#google_font_input').on('input', function() {
            const query = $(this).val().toLowerCase();
            if (query.length >= 2) {
                showSuggestions(query);
            } else {
                hideSuggestions();
            }
        });

        // Keyboard navigation
        $('#google_font_input').on('keydown', function(e) {
            const $suggestions = $('.fbcn-font-suggestion');

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                currentSelection = Math.min(currentSelection + 1, $suggestions.length - 1);
                updateSelection();
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                currentSelection = Math.max(currentSelection - 1, -1);
                updateSelection();
            } else if (e.key === 'Enter') {
                e.preventDefault();
                if (currentSelection >= 0) {
                    selectFont($suggestions.eq(currentSelection).data('font'));
                }
            } else if (e.key === 'Escape') {
                hideSuggestions();
            }
        });

        // Click outside to close suggestions
        $(document).on('click', function(e) {
            if (!$(e.target).closest('.fbcn-font-selector').length) {
                hideSuggestions();
            }
        });

        // Click on suggestion
        $(document).on('click', '.fbcn-font-suggestion', function() {
            selectFont($(this).data('font'));
        });
    });

    function loadGoogleFonts() {
        // Load the Google Fonts JSON
        $.getJSON(fbcn_font_search.plugin_url + 'assets/google-fonts.json', function(data) {
            googleFonts = data.fonts;
        }).fail(function() {
            console.error('Failed to load Google Fonts list');
            // Fallback to a basic list
            googleFonts = [
                {family: 'Open Sans', category: 'sans-serif'},
                {family: 'Roboto', category: 'sans-serif'},
                {family: 'Questrial', category: 'sans-serif'},
                {family: 'Montserrat', category: 'sans-serif'},
                {family: 'Lato', category: 'sans-serif'},
                {family: 'Poppins', category: 'sans-serif'},
                {family: 'Playfair Display', category: 'serif'},
                {family: 'Merriweather', category: 'serif'},
                {family: 'Oswald', category: 'sans-serif'},
                {family: 'Raleway', category: 'sans-serif'}
            ];
        });
    }

    function showSuggestions(query) {
        const matches = googleFonts.filter(font =>
            font.family.toLowerCase().includes(query)
        ).slice(0, 10); // Limit to 10 suggestions

        if (matches.length > 0) {
            let html = '';
            matches.forEach((font, index) => {
                html += `
                    <div class="fbcn-font-suggestion" data-font="${font.family}" data-index="${index}">
                        <span class="fbcn-font-suggestion-name">${font.family}</span>
                        <span class="fbcn-font-suggestion-category">(${font.category})</span>
                    </div>
                `;
            });
            $('#google_font_suggestions').html(html).show();
            currentSelection = -1;
        } else {
            hideSuggestions();
        }
    }

    function hideSuggestions() {
        $('#google_font_suggestions').hide();
        currentSelection = -1;
    }

    function updateSelection() {
        $('.fbcn-font-suggestion').removeClass('selected');
        if (currentSelection >= 0) {
            $('.fbcn-font-suggestion').eq(currentSelection).addClass('selected');
        }
    }

    function selectFont(fontFamily) {
        $('#google_font_input').val(fontFamily);
        hideSuggestions();

        // Load font for preview
        loadFontPreview(fontFamily);
    }

    function loadFontPreview(fontFamily) {
        // Check if font is already loaded
        const fontId = 'fbcn-preview-font-' + fontFamily.replace(/\s+/g, '-').toLowerCase();

        if ($('#' + fontId).length === 0) {
            const link = $('<link>', {
                id: fontId,
                rel: 'stylesheet',
                href: 'https://fonts.googleapis.com/css2?family=' + encodeURIComponent(fontFamily) + ':wght@400;700&display=swap'
            });
            $('head').append(link);
        }

        // Update preview button if it exists
        const $preview = $('#fbcn-live-button');
        if ($preview.length) {
            $preview.css('font-family', '"' + fontFamily + '", sans-serif');
        }
    }

})(jQuery);
