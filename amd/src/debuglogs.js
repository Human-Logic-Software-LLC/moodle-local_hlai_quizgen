// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

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
                    // eslint-disable-next-line no-alert
                    if (!confirm(message)) {
                        e.preventDefault();
                    }
                });
            });
        }
    };
});
