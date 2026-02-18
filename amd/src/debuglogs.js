/**
 * AI Quiz Generator - Debug Logs Module
 *
 * Handles interactive elements on the debug logs page.
 *
 * @module     local_hlai_quizgen/debuglogs
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['jquery'], function($) {
    'use strict';

    return {
        /**
         * Initialize debug logs page interactions.
         */
        init: function() {
            $(document).ready(function() {
                // Attach click handlers for details toggle buttons.
                $(document).on('click', '.hlai-toggle-details', function() {
                    var id = $(this).data('id');
                    var el = document.getElementById('details-' + id);
                    if (el) {
                        el.style.display = el.style.display === 'none' ? 'block' : 'none';
                    }
                });

                // Attach click handlers for confirm-action links.
                $(document).on('click', '.hlai-confirm-action', function(e) {
                    var message = $(this).data('confirm') || 'Are you sure?';
                    if (!confirm(message)) {
                        e.preventDefault();
                    }
                });
            });
        }
    };
});
