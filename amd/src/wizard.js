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
 * AI Quiz Generator - Wizard Module
 *
 * Handles all wizard step interactions, form validation, and UI behaviour
 * across the 5-step quiz generation wizard. Replaces inline JavaScript
 * previously embedded in wizard.php.
 *
 * Initialized via:
 *   $PAGE->requires->js_call_amd('local_hlai_quizgen/wizard', 'init', [$jsconfig]);
 *
 * @module     local_hlai_quizgen/wizard
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['jquery'], function($) {
    'use strict';

    /**
     * Wizard controller object.
     * Manages per-step initialization and all DOM event binding.
     *
     * @namespace
     */
    var Wizard = {

        /** @type {Object} Configuration passed from PHP. */
        config: {},

        // -----------------------------------------------------------------
        // Core
        // -----------------------------------------------------------------

        /**
         * Initialize the wizard for the current step.
         *
         * @param {Object} config - Configuration from PHP.
         * @param {number} config.courseid - Course ID.
         * @param {number} config.requestid - Request ID.
         * @param {string} config.step - Current step ('1','2','3','3.5','4','5').
         * @param {string} [config.refreshUrl] - Auto-refresh URL for step 3.5.
         * @param {Object} [config.strings] - Translation strings.
         */
        init: function(config) {
            this.config = config;
            var step = String(config.step);

            switch (step) {
                case '1':
                    this.initStep1();
                    break;
                case '3':
                    this.initStep3();
                    break;
                case '3.5':
                    this.initProgressStep();
                    break;
                case '4':
                    this.initStep4();
                    break;
                case '5':
                    this.initStep5();
                    break;
                // Steps 2 and others may not require client-side JS.
            }
        },

        // =================================================================
        // Step 1 - Content Upload
        // =================================================================

        /**
         * Initialize Step 1 handlers: form validation, content-source
         * toggles, file-input feedback, and activity selection helpers.
         */
        initStep1: function() {
            this._bindStep1FormSubmit();
            this._bindContentSourceToggles();
            this._bindFileInputChange();
            this._bindActivitySelectionControls();
        },

        // -----------------------------------------------------------------
        // Step 1 - Form submit validation
        // -----------------------------------------------------------------

        /**
         * Attach a submit handler to the content-upload form that validates
         * all selected content sources before allowing the POST.
         *
         * @private
         */
        _bindStep1FormSubmit: function() {
            var self = this;
            var form = document.getElementById('content-upload-form');
            if (!form) {
                // Fallback: try to locate the multipart form on the page.
                form = document.querySelector('form[enctype="multipart/form-data"]');
            }
            if (form) {
                form.addEventListener('submit', function(e) {
                    if (!self._validateStep1Form()) {
                        e.preventDefault();
                    }
                });
            }
        },

        /**
         * Validate the Step 1 content-upload form.
         * Ensures at least one source is selected and each selected source
         * has the required data filled in.
         *
         * @private
         * @return {boolean} True if the form is valid.
         */
        _validateStep1Form: function() {
            var states = this._getSourceStates();

            if (!this._validateSourceSelected(states)) {
                return false;
            }
            if (states.manualChecked && !this._validateManualSource()) {
                return false;
            }
            if (states.uploadChecked && !this._validateUploadSource()) {
                return false;
            }
            if (states.urlChecked && !this._validateUrlSource()) {
                return false;
            }
            if (states.activitiesChecked && !this._validateActivitySource()) {
                return false;
            }

            return true;
        },

        /**
         * Gather the checked state of all content-source checkboxes.
         *
         * @private
         * @return {Object} Map of source checkbox states.
         */
        _getSourceStates: function() {
            var get = function(id) {
                var el = document.getElementById(id);
                return el ? el.checked : false;
            };
            return {
                manualChecked: get('source_manual'),
                uploadChecked: get('source_upload'),
                urlChecked: get('source_url'),
                activitiesChecked: get('source_activities'),
                scanCourseChecked: get('source_scan_course'),
                scanResourcesChecked: get('source_scan_resources'),
                scanActivitiesChecked: get('source_scan_activities')
            };
        },

        /**
         * Validate that at least one content source is selected.
         *
         * @private
         * @param {Object} states - Source checkbox states from _getSourceStates.
         * @return {boolean} True if at least one source is selected.
         */
        _validateSourceSelected: function(states) {
            if (!states.manualChecked && !states.uploadChecked && !states.urlChecked &&
                !states.activitiesChecked && !states.scanCourseChecked &&
                !states.scanResourcesChecked && !states.scanActivitiesChecked) {
                // eslint-disable-next-line no-alert
                alert('Please select at least one content source!');
                return false;
            }
            return true;
        },

        /**
         * Validate that manual text has been entered.
         *
         * @private
         * @return {boolean} True if manual text is valid.
         */
        _validateManualSource: function() {
            var manualTextEl = document.getElementById('manual_text');
            var manualText = manualTextEl ? manualTextEl.value.trim() : '';
            if (manualText === '') {
                // eslint-disable-next-line no-alert
                alert('Please enter some manual text or uncheck the Manual Entry option.');
                return false;
            }
            return true;
        },

        /**
         * Validate that files have been selected and none exceed the size limit.
         *
         * @private
         * @return {boolean} True if the upload is valid.
         */
        _validateUploadSource: function() {
            var fileInput = document.getElementById('content-files');
            var files = fileInput ? fileInput.files : [];
            if (files.length === 0) {
                // eslint-disable-next-line no-alert
                alert('Please select files to upload or uncheck the Upload Files option.');
                return false;
            }
            var maxFileSizeMB = 50;
            var maxFileSizeBytes = maxFileSizeMB * 1024 * 1024;
            var oversizedFiles = [];
            Array.from(files).forEach(function(file) {
                if (file.size > maxFileSizeBytes) {
                    oversizedFiles.push(file.name + ' (' + (file.size / 1024 / 1024).toFixed(2) + ' MB)');
                }
            });
            if (oversizedFiles.length > 0) {
                // eslint-disable-next-line no-alert
                alert('The following files exceed the maximum size of ' + maxFileSizeMB + ' MB:\n\n' +
                    oversizedFiles.join('\n') + '\n\nPlease remove these files and try again.');
                return false;
            }
            return true;
        },

        /**
         * Validate that at least one URL has been entered.
         *
         * @private
         * @return {boolean} True if the URL input is valid.
         */
        _validateUrlSource: function() {
            var urlListEl = document.getElementById('url_list');
            var urlList = urlListEl ? urlListEl.value.trim() : '';
            if (urlList === '') {
                // eslint-disable-next-line no-alert
                alert('Please enter at least one URL or uncheck the URL option.');
                return false;
            }
            return true;
        },

        /**
         * Validate that at least one activity is selected.
         *
         * @private
         * @return {boolean} True if the activity selection is valid.
         */
        _validateActivitySource: function() {
            var selectedActivities = document.querySelectorAll('.hlai-activity-checkbox:checked');
            if (selectedActivities.length === 0) {
                // eslint-disable-next-line no-alert
                alert('Please select at least one activity or uncheck the Course Activities option.');
                return false;
            }
            return true;
        },

        // -----------------------------------------------------------------
        // Step 1 - Content source toggles
        // -----------------------------------------------------------------

        /**
         * Bind change handlers on content-source checkboxes so that the
         * corresponding detail section is shown/hidden and the
         * "selected sources" summary strip is updated.
         *
         * @private
         */
        _bindContentSourceToggles: function() {
            var self = this;
            document.querySelectorAll('.content-source-checkbox[data-source]').forEach(function(cb) {
                cb.addEventListener('change', function() {
                    self._toggleContentSection(this.dataset.source);
                });
            });
        },

        /**
         * Show or hide the detail section for a given content source and
         * refresh the summary strip.
         *
         * @private
         * @param {string} source - Source key (e.g. 'manual', 'upload').
         */
        _toggleContentSection: function(source) {
            var checkbox = document.getElementById('source_' + source);
            var section = document.getElementById('section-' + source);
            if (section && checkbox) {
                section.style.display = checkbox.checked ? 'block' : 'none';
            }
            this._updateSelectedSources();
        },

        /**
         * Refresh the "selected sources" summary strip based on currently
         * checked content-source checkboxes.
         *
         * @private
         */
        _updateSelectedSources: function() {
            var checkboxes = document.querySelectorAll('.content-source-checkbox:checked');
            var display = document.getElementById('selected-sources-display');
            var list = document.getElementById('selected-sources-list');

            if (checkboxes.length > 0) {
                var sources = [];
                checkboxes.forEach(function(cb) {
                    var label = document.querySelector('label[for="' + cb.id + '"] strong');
                    if (label) {
                        sources.push(label.textContent);
                    }
                });
                if (list) {
                    list.textContent = sources.join(' + ');
                }
                if (display) {
                    display.style.display = 'block';
                }
            } else {
                if (display) {
                    display.style.display = 'none';
                }
            }
        },

        // -----------------------------------------------------------------
        // Step 1 - File input feedback
        // -----------------------------------------------------------------

        /**
         * Bind a change handler on the file input to display selected
         * file names and flag oversized files.
         *
         * @private
         */
        _bindFileInputChange: function() {
            var self = this;
            var fileInput = document.getElementById('content-files');
            if (!fileInput) {
                return;
            }

            fileInput.addEventListener('change', function(e) {
                var fileCount = e.target.files.length;
                var label = e.target.nextElementSibling;
                var fileList = document.getElementById('uploaded-files-list');
                var maxFileSizeMB = 50;
                var maxFileSizeBytes = maxFileSizeMB * 1024 * 1024;
                var hasOversizedFiles = false;
                var oversizedFileNames = [];

                if (fileCount > 0) {
                    if (label) {
                        label.innerText = fileCount + ' file(s) selected';
                    }
                    var fileNames = '<div class="mt-2"><strong>Selected files:</strong><ul class="mb-0">';
                    Array.from(e.target.files).forEach(function(file) {
                        // Safely escape the file name via textContent.
                        var safeEl = document.createElement('div');
                        safeEl.textContent = file.name;
                        var safeFileName = safeEl.innerHTML;
                        var fileSizeMB = (file.size / 1024 / 1024).toFixed(2);
                        var sizeClass = file.size > maxFileSizeBytes ? 'text-danger' : 'text-muted';

                        if (file.size > maxFileSizeBytes) {
                            hasOversizedFiles = true;
                            oversizedFileNames.push(file.name + ' (' + fileSizeMB + ' MB)');
                            fileNames += '<li class="text-danger">' + safeFileName +
                                ' <span class="' + sizeClass + '">(' + fileSizeMB + ' MB) - TOO LARGE!</span></li>';
                        } else {
                            fileNames += '<li>' + safeFileName +
                                ' <span class="' + sizeClass + '">(' + fileSizeMB + ' MB)</span></li>';
                        }
                    });
                    fileNames += '</ul></div>';

                    if (hasOversizedFiles) {
                        fileNames += '<div class="notification is-danger is-light mt-2"><strong>Error:</strong> ' +
                            'The following files exceed the maximum size of ' + maxFileSizeMB + ' MB:<ul>';
                        oversizedFileNames.forEach(function(fname) {
                            fileNames += '<li>' + fname + '</li>';
                        });
                        fileNames += '</ul>Please remove these files before submitting.</div>';
                    }

                    if (fileList) {
                        fileList.innerHTML = fileNames;
                    }
                } else {
                    var chooseFilesText = (self.config.strings && self.config.strings.choose_files)
                        ? self.config.strings.choose_files
                        : 'Choose files...';
                    if (label) {
                        label.innerText = chooseFilesText;
                    }
                    if (fileList) {
                        fileList.innerHTML = '';
                    }
                }
            });
        },

        // -----------------------------------------------------------------
        // Step 1 - Activity selection helpers
        // -----------------------------------------------------------------

        /**
         * Bind the select-all / deselect-all buttons and per-checkbox
         * change events for the activity picker, then run an initial
         * count update.
         *
         * @private
         */
        _bindActivitySelectionControls: function() {
            var self = this;

            var selectAllBtn = document.getElementById('select-all-activities');
            if (selectAllBtn) {
                selectAllBtn.addEventListener('click', function() {
                    document.querySelectorAll('.hlai-activity-checkbox').forEach(function(cb) {
                        cb.checked = true;
                    });
                    self._updateActivityCount();
                });
            }

            var deselectAllBtn = document.getElementById('deselect-all-activities');
            if (deselectAllBtn) {
                deselectAllBtn.addEventListener('click', function() {
                    document.querySelectorAll('.hlai-activity-checkbox').forEach(function(cb) {
                        cb.checked = false;
                    });
                    self._updateActivityCount();
                });
            }

            document.querySelectorAll('.hlai-activity-checkbox').forEach(function(cb) {
                cb.addEventListener('change', function() {
                    self._updateActivityCount();
                });
            });

            // Initial count.
            this._updateActivityCount();
        },

        /**
         * Refresh the activity count display and toggle the visual
         * "selected" state on each activity card.
         *
         * @private
         */
        _updateActivityCount: function() {
            var checked = document.querySelectorAll('.hlai-activity-checkbox:checked').length;
            var countDisplay = document.getElementById('selected-activities-count');
            var selectedBar = document.querySelector('.hlai-activities-selected-bar');

            if (countDisplay) {
                var text = checked === 1
                    ? '1 activity selected'
                    : checked + ' activities selected';
                countDisplay.textContent = text;
            }

            if (selectedBar) {
                if (checked > 0) {
                    selectedBar.classList.add('has-selection');
                } else {
                    selectedBar.classList.remove('has-selection');
                }
            }

            document.querySelectorAll('.hlai-activity-checkbox').forEach(function(cb) {
                var card = cb.closest('.hlai-activity-card');
                if (card) {
                    if (cb.checked) {
                        card.classList.add('is-selected');
                    } else {
                        card.classList.remove('is-selected');
                    }
                }
            });
        },

        // =================================================================
        // Step 3 - Question Configuration
        // =================================================================

        /**
         * Initialize Step 3 handlers: difficulty buttons, Bloom's sliders,
         * question-type distribution inputs, and the generate form submit.
         */
        initStep3: function() {
            this._bindDifficultyButtons();
            this._bindBloomsSliders();
            this._bindQuestionTypeDistribution();
            this._bindStep3FormSubmit();
        },

        // -----------------------------------------------------------------
        // Step 3 - Difficulty radio buttons
        // -----------------------------------------------------------------

        /**
         * Bind change handlers on the difficulty radio buttons so that the
         * active visual state follows the currently selected option.
         *
         * @private
         */
        _bindDifficultyButtons: function() {
            document.querySelectorAll('.hlai-diff-btn input[type="radio"]').forEach(function(radio) {
                radio.addEventListener('change', function() {
                    document.querySelectorAll('.hlai-diff-btn').forEach(function(btn) {
                        btn.classList.remove('is-active');
                    });
                    if (this.parentElement) {
                        this.parentElement.classList.add('is-active');
                    }
                });
            });
        },

        // -----------------------------------------------------------------
        // Step 3 - Bloom's taxonomy sliders
        // -----------------------------------------------------------------

        /**
         * Bind input handlers on the Bloom's taxonomy range sliders so
         * that the percentage labels and track fills update live, and the
         * running total is recalculated on each change.
         *
         * @private
         */
        _bindBloomsSliders: function() {
            var self = this;
            document.querySelectorAll('input[type="range"][id^="blooms_"]').forEach(function(slider) {
                // Apply initial styling.
                self._updateSliderColor(slider);
                slider.addEventListener('input', function() {
                    self._updateSliderColor(this);
                    self._updateBloomsTotal();
                });
            });
            // Set the initial total.
            this._updateBloomsTotal();
        },

        /**
         * Update the track fill colour and value label for a single
         * Bloom's slider.
         *
         * @private
         * @param {HTMLInputElement} slider - The range input element.
         */
        _updateSliderColor: function(slider) {
            var value = slider.value;
            var id = slider.id;
            var color = slider.dataset.color || '#6366f1';
            var valueEl = document.getElementById(id + '_value');
            if (valueEl) {
                valueEl.textContent = value + '%';
            }
            slider.style.background = 'linear-gradient(to right, ' + color + ' 0%, ' + color +
                ' ' + value + '%, #e2e8f0 ' + value + '%, #e2e8f0 100%)';
        },

        /**
         * Recalculate and display the Bloom's taxonomy total percentage
         * with appropriate colour coding.
         *
         * @private
         */
        _updateBloomsTotal: function() {
            var levels = ['remember', 'understand', 'apply', 'analyze', 'evaluate', 'create'];
            var total = 0;
            levels.forEach(function(level) {
                var slider = document.getElementById('blooms_' + level);
                if (slider) {
                    total += parseInt(slider.value, 10);
                }
            });
            var totalElem = document.getElementById('blooms_total');
            if (totalElem) {
                totalElem.textContent = total + '%';
                if (total === 100) {
                    totalElem.style.color = '#10b981';
                } else if (total > 80 && total < 120) {
                    totalElem.style.color = '#f59e0b';
                } else {
                    totalElem.style.color = '#ef4444';
                }
            }
        },

        // -----------------------------------------------------------------
        // Step 3 - Question type distribution
        // -----------------------------------------------------------------

        /**
         * Bind input handlers on the question-type number inputs and the
         * total-questions field so the running total stays in sync.
         *
         * @private
         */
        _bindQuestionTypeDistribution: function() {
            var self = this;
            var totalInput = document.getElementById('total-questions');

            if (totalInput) {
                totalInput.addEventListener('input', function() {
                    self._updateQuestionTypeDistribution();
                });
            }

            document.querySelectorAll('.hlai-qtype-input').forEach(function(input) {
                input.addEventListener('input', function() {
                    self._updateQuestionTypeTotal();
                });
            });

            // Initial calculation.
            this._updateQuestionTypeTotal();
        },

        /**
         * When the total-questions value changes, update the denominator
         * in the distribution display and adjust the max on each
         * question-type input.
         *
         * @private
         */
        _updateQuestionTypeDistribution: function() {
            var totalInput = document.getElementById('total-questions');
            var total = totalInput ? (parseInt(totalInput.value, 10) || 10) : 10;
            var displayEl = document.getElementById('qtype-total-display');

            if (displayEl) {
                var parts = displayEl.textContent.split('/');
                displayEl.textContent = (parts[0] ? parts[0].trim() : '0') + ' / ' + total;
            }

            document.querySelectorAll('.hlai-qtype-input').forEach(function(input) {
                input.max = total;
            });

            this._updateQuestionTypeTotal();
        },

        /**
         * Recalculate the sum of all question-type inputs and update the
         * visual indicator (valid / warning / error).
         *
         * @private
         */
        _updateQuestionTypeTotal: function() {
            var totalInput = document.getElementById('total-questions');
            var totalRequired = totalInput ? (parseInt(totalInput.value, 10) || 10) : 10;
            var total = 0;

            document.querySelectorAll('.hlai-qtype-input').forEach(function(input) {
                var val = parseInt(input.value, 10) || 0;
                total += val;
                if (val > 0) {
                    input.classList.add('has-value');
                } else {
                    input.classList.remove('has-value');
                }
            });

            var displayEl = document.getElementById('qtype-total-display');
            if (displayEl) {
                displayEl.textContent = total + ' / ' + totalRequired;
                displayEl.classList.remove('is-valid', 'is-warning', 'is-error');
                if (total === totalRequired) {
                    displayEl.classList.add('is-valid');
                } else if (total > totalRequired) {
                    displayEl.classList.add('is-error');
                } else {
                    displayEl.classList.add('is-warning');
                }
            }
        },

        // -----------------------------------------------------------------
        // Step 3 - Generate form submit
        // -----------------------------------------------------------------

        /**
         * Bind the submit handler on the question-config form to validate
         * totals and show a loading overlay while the request is in flight.
         *
         * @private
         */
        _bindStep3FormSubmit: function() {
            var configForm = document.getElementById('question-config-form');
            var generateBtn = document.getElementById('generate-btn');
            var loadingOverlay = document.getElementById('loading-overlay');
            var totalInput = document.getElementById('total-questions');
            var isSubmitting = false;

            if (configForm) {
                configForm.addEventListener('submit', function(e) {
                    var totalRequired = totalInput ? (parseInt(totalInput.value, 10) || 10) : 10;
                    var total = 0;

                    document.querySelectorAll('.hlai-qtype-input').forEach(function(input) {
                        total += parseInt(input.value, 10) || 0;
                    });

                    if (total === 0) {
                        e.preventDefault();
                        // eslint-disable-next-line no-alert
                        alert('Please specify at least one question type with a quantity greater than 0.');
                        return;
                    }

                    if (total !== totalRequired) {
                        e.preventDefault();
                        // eslint-disable-next-line no-alert
                        alert('Question type total (' + total + ') must equal total questions (' + totalRequired + ').');
                        return;
                    }

                    if (isSubmitting) {
                        e.preventDefault();
                        return;
                    }

                    isSubmitting = true;

                    if (loadingOverlay) {
                        loadingOverlay.style.display = 'flex';
                    }
                    if (generateBtn) {
                        generateBtn.disabled = true;
                        generateBtn.textContent = 'Generating...';
                    }
                });
            }
        },

        // =================================================================
        // Step 3.5 - Generation Progress (auto-refresh)
        // =================================================================

        /**
         * Initialize Step 3.5: automatically redirect to the refresh URL
         * after a short delay so the user sees the progress page before
         * polling continues.
         */
        initProgressStep: function() {
            var refreshUrl = this.config.refreshUrl;
            if (refreshUrl) {
                setTimeout(function() {
                    window.location.href = refreshUrl;
                }, 2000);
            }
        },

        // =================================================================
        // Step 4 - Review & Bulk Actions
        // =================================================================

        /**
         * Initialize Step 4 handlers: select-all checkbox, bulk-action
         * button, and filter dropdowns.
         */
        initStep4: function() {
            this._bindSelectAllQuestions();
            this._bindBulkAction();
            this._bindQuestionFilters();
        },

        /**
         * Bind the "select all" checkbox to toggle every visible question
         * checkbox.
         *
         * @private
         */
        _bindSelectAllQuestions: function() {
            var selectAll = document.getElementById('select-all-questions');
            if (selectAll) {
                selectAll.addEventListener('change', function() {
                    var checked = this.checked;
                    document.querySelectorAll('.question-checkbox').forEach(function(checkbox) {
                        var card = checkbox.closest('.question-card');
                        if (card && card.style.display !== 'none') {
                            checkbox.checked = checked;
                        }
                    });
                });
            }
        },

        /**
         * Bind the bulk-action button so that it collects selected
         * question IDs and submits them via a dynamically created form.
         *
         * @private
         */
        _bindBulkAction: function() {
            var bulkBtn = document.getElementById('bulk-action-btn');
            if (!bulkBtn) {
                return;
            }

            bulkBtn.addEventListener('click', function() {
                var selectEl = document.getElementById('bulk-action-select');
                var action = selectEl ? selectEl.value : '';

                if (!action) {
                    // eslint-disable-next-line no-alert
                    alert('Please select an action');
                    return;
                }

                var selectedQuestions = [];
                document.querySelectorAll('.question-checkbox:checked').forEach(function(checkbox) {
                    selectedQuestions.push(checkbox.dataset.questionId);
                });

                if (selectedQuestions.length === 0) {
                    // eslint-disable-next-line no-alert
                    alert('Please select at least one question');
                    return;
                }

                var confirmMsg = 'Are you sure you want to ' + action + ' ' +
                    selectedQuestions.length + ' question(s)?';
                // eslint-disable-next-line no-alert
                if (!confirm(confirmMsg)) {
                    return;
                }

                // Build and submit a hidden form.
                var form = document.createElement('form');
                form.method = 'POST';
                form.action = window.location.href;

                var sesskeyInput = document.createElement('input');
                sesskeyInput.type = 'hidden';
                sesskeyInput.name = 'sesskey';
                sesskeyInput.value = M.cfg.sesskey;
                form.appendChild(sesskeyInput);

                var actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'bulk_action';
                actionInput.value = action;
                form.appendChild(actionInput);

                selectedQuestions.forEach(function(qid) {
                    var qInput = document.createElement('input');
                    qInput.type = 'hidden';
                    qInput.name = 'question_ids[]';
                    qInput.value = qid;
                    form.appendChild(qInput);
                });

                document.body.appendChild(form);
                form.submit();
            });
        },

        /**
         * Bind change handlers on the status, type, and difficulty filter
         * dropdowns to show/hide question cards accordingly.
         *
         * @private
         */
        _bindQuestionFilters: function() {
            var filterIds = ['filter-status', 'filter-type', 'filter-difficulty'];
            var applyFilters = function() {
                var statusEl = document.getElementById('filter-status');
                var typeEl = document.getElementById('filter-type');
                var difficultyEl = document.getElementById('filter-difficulty');

                var statusFilter = statusEl ? statusEl.value : 'all';
                var typeFilter = typeEl ? typeEl.value : 'all';
                var difficultyFilter = difficultyEl ? difficultyEl.value : 'all';

                document.querySelectorAll('.question-card').forEach(function(card) {
                    var show = true;
                    if (statusFilter !== 'all' && card.dataset.status !== statusFilter) {
                        show = false;
                    }
                    if (typeFilter !== 'all' && card.dataset.type !== typeFilter) {
                        show = false;
                    }
                    if (difficultyFilter !== 'all' && card.dataset.difficulty !== difficultyFilter) {
                        show = false;
                    }
                    card.style.display = show ? '' : 'none';
                });

                // Reset the select-all checkbox when filters change.
                var selectAll = document.getElementById('select-all-questions');
                if (selectAll) {
                    selectAll.checked = false;
                }
            };

            filterIds.forEach(function(id) {
                var el = document.getElementById(id);
                if (el) {
                    el.addEventListener('change', applyFilters);
                }
            });
        },

        // =================================================================
        // Step 5 - Deployment
        // =================================================================

        /**
         * Initialize Step 5 handlers: deployment-type radio toggles and
         * the double-submit guard on the deployment form.
         */
        initStep5: function() {
            this._bindDeploymentTypeRadios();
            this._bindDeploymentFormSubmit();
        },

        /**
         * Bind change handlers on deployment-type radio buttons to toggle
         * visibility of the corresponding option panels.
         *
         * @private
         */
        _bindDeploymentTypeRadios: function() {
            document.querySelectorAll('.deploy-type-radio').forEach(function(radio) {
                radio.addEventListener('change', function() {
                    var newQuizOptions = document.getElementById('new_quiz_options');
                    var qbankOptions = document.getElementById('qbank_options');
                    var newQuizRadio = document.getElementById('deploy_new_quiz');
                    var qbankRadio = document.getElementById('deploy_qbank');

                    if (newQuizOptions) {
                        newQuizOptions.style.display = (newQuizRadio && newQuizRadio.checked) ? 'block' : 'none';
                    }
                    if (qbankOptions) {
                        qbankOptions.style.display = (qbankRadio && qbankRadio.checked) ? 'block' : 'none';
                    }
                });
            });
        },

        /**
         * Bind the submit handler on the deployment form to prevent
         * double-submission and provide visual feedback.
         *
         * @private
         */
        _bindDeploymentFormSubmit: function() {
            var deploymentForm = document.getElementById('deployment-form');
            var isSubmitting = false;

            if (deploymentForm) {
                deploymentForm.addEventListener('submit', function(e) {
                    if (isSubmitting) {
                        e.preventDefault();
                        return;
                    }
                    isSubmitting = true;
                    var submitBtn = deploymentForm.querySelector('button[type="submit"]');
                    if (submitBtn) {
                        submitBtn.disabled = true;
                        submitBtn.textContent = 'Deploying...';
                    }
                });
            }
        }
    };

    // =====================================================================
    // Public API
    // =====================================================================

    return /** @alias module:local_hlai_quizgen/wizard */ {

        /**
         * Entry point called by Moodle's AMD loader.
         *
         * @param {Object} config - Configuration object from PHP.
         */
        init: function(config) {
            $(document).ready(function() {
                Wizard.init(config);
            });
        }
    };
});
