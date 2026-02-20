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
 * AI Quiz Generator - Dashboard Module
 *
 * Handles dashboard initialization, AJAX data fetching,
 * and chart rendering for the teacher dashboard.
 *
 * @module     local_hlai_quizgen/dashboard
 * @package
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/* global ApexCharts */
define(['jquery', 'core/ajax', 'local_hlai_quizgen/charts'], function($, Ajax, Charts) {
    'use strict';

    /**
     * Dashboard controller
     */
    var Dashboard = {
        courseid: 0,
        refreshInterval: null,
        charts: {},

        /**
         * Initialize the dashboard
         * @param {object} config - Configuration object
         */
        init: function(config) {
            this.courseid = config.courseid;
            this.initData = config;

            // Wait for ApexCharts to load
            this.waitForApexCharts().then(function() {
                Dashboard.initializeCharts();
                Dashboard.loadChartData();
                Dashboard.setupEventListeners();
                return undefined;
            }).catch(function() {
                // ApexCharts loading failed silently.
            });
        },

        /**
         * Wait for ApexCharts library to be available
         * @returns {Promise}
         */
        waitForApexCharts: function() {
            return new Promise(function(resolve) {
                if (typeof ApexCharts !== 'undefined') {
                    resolve();
                    return;
                }

                var attempts = 0;
                var maxAttempts = 50;
                var checkInterval = setInterval(function() {
                    attempts++;
                    if (typeof ApexCharts !== 'undefined') {
                        clearInterval(checkInterval);
                        resolve();
                    } else if (attempts >= maxAttempts) {
                        clearInterval(checkInterval);
                        // ApexCharts did not load in time.
                        resolve();
                    }
                }, 100);
            });
        },

        /**
         * Initialize all dashboard charts with loading states
         */
        initializeCharts: function() {
            var stats = this.initData.stats || {};

            // FTAR Gauge Chart
            if ($('#ftar-gauge-chart').length && typeof ApexCharts !== 'undefined') {
                this.charts.ftarGauge = Charts.createRadialChart('#ftar-gauge-chart', {
                    series: [stats.ftar || 0],
                    labels: ['First-Time Acceptance'],
                    colors: [this.getFtarColor(stats.ftar || 0)],
                    height: 280,
                    plotOptions: {
                        radialBar: {
                            startAngle: -135,
                            endAngle: 135,
                            hollow: {
                                margin: 0,
                                size: '70%',
                                background: 'transparent'
                            },
                            track: {
                                background: '#f0f0f0',
                                strokeWidth: '100%'
                            },
                            dataLabels: {
                                name: {
                                    offsetY: -10,
                                    color: '#666',
                                    fontSize: '14px'
                                },
                                value: {
                                    color: '#333',
                                    fontSize: '36px',
                                    fontWeight: 'bold',
                                    formatter: function(val) {
                                        return val.toFixed(1) + '%';
                                    }
                                }
                            }
                        }
                    }
                });
            }

            // Initialize type distribution from PHP data
            var typeData = this.initData.typeDistribution || [];
            if ($('#question-type-chart').length && typeData.length > 0 && typeof ApexCharts !== 'undefined') {
                var labels = typeData.map(function(item) {
                    return Dashboard.formatQuestionType(item.questiontype);
                });
                var series = typeData.map(function(item) {
                    return parseInt(item.count, 10);
                });
                var colors = typeData.map(function(item) {
                    return Charts.getQuestionTypeColor(item.questiontype);
                });

                this.charts.questionType = Charts.createDonutChart('#question-type-chart', {
                    series: series,
                    labels: labels,
                    colors: colors,
                    height: 280
                });
            }
        },

        /**
         * Load chart data via AJAX
         */
        loadChartData: function() {
            var self = this;

            // Load acceptance trend
            this.fetchData('acceptancetrend').then(function(data) {
                self.renderAcceptanceTrendChart(data);
                return undefined;
            }).catch(function() {
                // Data fetch handled by fetchData.
            });

            // Load difficulty distribution
            this.fetchData('difficultydist').then(function(data) {
                self.renderDifficultyChart(data);
                return undefined;
            }).catch(function() {
                // Data fetch handled by fetchData.
            });

            // Load Bloom's distribution
            this.fetchData('bloomsdist').then(function(data) {
                self.renderBloomsCharts(data);
                return undefined;
            }).catch(function() {
                // Data fetch handled by fetchData.
            });

            // Load regeneration by type
            this.fetchData('regenbytype').then(function(data) {
                self.renderRegenByTypeChart(data);
                return undefined;
            }).catch(function() {
                // Data fetch handled by fetchData.
            });
        },

        /**
         * Fetch data from Moodle External Services.
         * @param {string} action - The action identifier to perform.
         * @returns {Promise}
         */
        fetchData: function(action) {
            var cid = this.courseid || 0;
            var methodMap = {
                'acceptancetrend': {methodname: 'local_hlai_quizgen_get_acceptance_trend', args: {courseid: cid}},
                'difficultydist': {methodname: 'local_hlai_quizgen_get_difficulty_distribution', args: {courseid: cid}},
                'bloomsdist': {methodname: 'local_hlai_quizgen_get_blooms_distribution', args: {courseid: cid}},
                'regenbytype': {methodname: 'local_hlai_quizgen_get_regeneration_by_type', args: {courseid: cid}}
            };

            var call = methodMap[action];
            if (!call) {
                return Promise.reject('Unknown action: ' + action);
            }

            return Ajax.call([call])[0].then(function(response) {
                // The regeneration-by-type endpoint returns a JSON string in the 'data' key.
                if (action === 'regenbytype') {
                    return JSON.parse(response.data);
                }
                return response;
            }).catch(function(error) {
                // eslint-disable-next-line no-console
                console.error('External service call failed for ' + action + ':', error);
                return null;
            });
        },

        /**
         * Render acceptance trend line chart
         * @param {object} data - Chart data
         */
        renderAcceptanceTrendChart: function(data) {
            if (!data || !$('#acceptance-trend-chart').length || typeof ApexCharts === 'undefined') {
                return;
            }

            // Clear loading skeleton
            $('#acceptance-trend-chart').empty();

            var categories = data.labels || [];
            var acceptanceRates = data.acceptance_rates || [];
            var ftarRates = data.ftar_rates || [];

            if (categories.length === 0) {
                $('#acceptance-trend-chart').html(
                    '<div class="has-text-centered has-text-grey p-5">' +
                    '<p>Not enough data to show trends yet.</p>' +
                    '<p class="mt-2" style="font-size: 0.85rem;">Generate more quizzes to see patterns.</p>' +
                    '</div>'
                );
                return;
            }

            this.charts.acceptanceTrend = Charts.createLineChart('#acceptance-trend-chart', {
                series: [
                    {
                        name: 'Acceptance Rate',
                        data: acceptanceRates
                    },
                    {
                        name: 'First-Time Acceptance',
                        data: ftarRates
                    }
                ],
                categories: categories,
                colors: ['#4F46E5', '#10B981'],
                height: 300,
                stroke: {
                    curve: 'smooth',
                    width: 3
                },
                markers: {
                    size: 5
                },
                yaxis: {
                    min: 0,
                    max: 100,
                    labels: {
                        formatter: function(val) {
                            return val.toFixed(0) + '%';
                        }
                    }
                }
            });
        },

        /**
         * Render difficulty distribution chart
         * @param {object} data - Chart data
         */
        renderDifficultyChart: function(data) {
            if (!data || !$('#difficulty-chart').length || typeof ApexCharts === 'undefined') {
                return;
            }

            var difficulties = ['easy', 'medium', 'hard'];
            var series = [];
            var labels = [];
            var colors = [];

            difficulties.forEach(function(diff) {
                var count = data[diff] || 0;
                if (count > 0) {
                    series.push(count);
                    labels.push(diff.charAt(0).toUpperCase() + diff.slice(1));
                    colors.push(Charts.getDifficultyColor(diff));
                }
            });

            if (series.length === 0) {
                $('#difficulty-chart').html(
                    '<div class="has-text-centered has-text-grey p-5">' +
                    '<p>No difficulty data available</p>' +
                    '</div>'
                );
                return;
            }

            this.charts.difficulty = Charts.createDonutChart('#difficulty-chart', {
                series: series,
                labels: labels,
                colors: colors,
                height: 280
            });
        },

        /**
         * Render Bloom's taxonomy charts
         * @param {object} data - Chart data
         */
        renderBloomsCharts: function(data) {
            if (!data || typeof ApexCharts === 'undefined') {
                return;
            }

            var bloomsLevels = ['remember', 'understand', 'apply', 'analyze', 'evaluate', 'create'];
            var bloomsLabels = ['Remember', 'Understand', 'Apply', 'Analyze', 'Evaluate', 'Create'];
            var series = [];
            var barColors = [];

            bloomsLevels.forEach(function(level) {
                series.push(data[level] || 0);
                barColors.push(Charts.getBloomsColor(level));
            });

            // Enhanced Radar chart - ABSOLUTELY NO NUMBERS
            if ($('#blooms-radar-chart').length) {
                var radarOptions = {
                    chart: {
                        type: 'radar',
                        height: 400,
                        fontFamily: 'Inter, -apple-system, BlinkMacSystemFont, sans-serif',
                        toolbar: {show: false},
                        dropShadow: {
                            enabled: true,
                            blur: 4,
                            left: 0,
                            top: 0,
                            opacity: 0.1
                        }
                    },
                    series: [{
                        name: 'Questions',
                        data: series
                    }],
                    dataLabels: {
                        enabled: false
                    },
                    xaxis: {
                        categories: bloomsLabels,
                        labels: {
                            show: true,
                            style: {
                                colors: barColors,
                                fontSize: '13px',
                                fontWeight: 600
                            }
                        }
                    },
                    yaxis: {
                        show: false,
                        labels: {
                            show: false
                        },
                        tickAmount: 0
                    },
                    grid: {
                        show: false
                    },
                    fill: {
                        opacity: 0.2,
                        type: 'solid'
                    },
                    stroke: {
                        width: 2,
                        colors: ['#4F46E5']
                    },
                    markers: {
                        size: 5,
                        colors: ['#4F46E5'],
                        strokeColors: '#fff',
                        strokeWidth: 2,
                        hover: {
                            size: 7
                        }
                    },
                    colors: ['#4F46E5'],
                    legend: {
                        show: false
                    },
                    tooltip: {
                        enabled: true,
                        y: {
                            formatter: function(val) {
                                return val + ' questions';
                            }
                        }
                    },
                    plotOptions: {
                        radar: {
                            size: 140,
                            polygons: {
                                strokeColors: '#E5E7EB',
                                strokeWidth: 1,
                                connectorColors: '#E5E7EB',
                                fill: {
                                    colors: ['#F9FAFB', '#F3F4F6']
                                }
                            }
                        }
                    }
                };
                this.charts.bloomsRadar = new ApexCharts(document.querySelector('#blooms-radar-chart'), radarOptions);
                this.charts.bloomsRadar.render();
            }

            // Bar chart - NO LEGEND
            if ($('#blooms-bar-chart').length) {
                var barOptions = {
                    chart: {
                        type: 'bar',
                        height: 400,
                        fontFamily: 'Inter, -apple-system, BlinkMacSystemFont, sans-serif',
                        toolbar: {show: false}
                    },
                    series: [{
                        name: 'Questions',
                        data: series
                    }],
                    plotOptions: {
                        bar: {
                            horizontal: true,
                            borderRadius: 6,
                            distributed: true,
                            barHeight: '65%'
                        }
                    },
                    dataLabels: {
                        enabled: true,
                        style: {
                            fontSize: '13px',
                            fontWeight: 'bold',
                            colors: ['#fff']
                        }
                    },
                    xaxis: {
                        categories: bloomsLabels,
                        labels: {
                            style: {
                                fontSize: '12px',
                                colors: '#6B7280'
                            }
                        }
                    },
                    yaxis: {
                        labels: {
                            style: {
                                fontSize: '13px',
                                fontWeight: 500,
                                colors: barColors
                            }
                        }
                    },
                    colors: barColors,
                    legend: {
                        show: false
                    },
                    tooltip: {
                        y: {
                            formatter: function(val) {
                                return val + ' questions';
                            }
                        }
                    }
                };
                this.charts.bloomsBar = new ApexCharts(document.querySelector('#blooms-bar-chart'), barOptions);
                this.charts.bloomsBar.render();
            }
        },

        /**
         * Render regeneration by type chart
         * @param {object} data - Chart data
         */
        renderRegenByTypeChart: function(data) {
            if (!data || !$('#regen-by-type-chart').length || typeof ApexCharts === 'undefined') {
                return;
            }

            var types = Object.keys(data);
            var series = [];
            var labels = [];
            var colors = [];

            types.forEach(function(type) {
                var avgRegen = data[type].avg_regenerations || 0;
                series.push(parseFloat(avgRegen.toFixed(2)));
                labels.push(Dashboard.formatQuestionType(type));
                colors.push(Charts.getQuestionTypeColor(type));
            });

            if (series.length === 0) {
                $('#regen-by-type-chart').html(
                    '<div class="has-text-centered has-text-grey p-4">' +
                    '<p>No regeneration data yet</p>' +
                    '</div>'
                );
                return;
            }

            this.charts.regenByType = Charts.createBarChart('#regen-by-type-chart', {
                series: [{
                    name: 'Avg Regenerations',
                    data: series
                }],
                categories: labels,
                colors: colors,
                horizontal: true,
                height: 250,
                distributed: true,
                dataLabels: {
                    enabled: true,
                    formatter: function(val) {
                        return val.toFixed(1);
                    }
                }
            });
        },

        /**
         * Setup event listeners
         */
        setupEventListeners: function() {
            var self = this;

            // Refresh data button (if exists)
            $(document).on('click', '.js-refresh-dashboard', function(e) {
                e.preventDefault();
                self.refreshDashboard();
            });

            // Delete notification button
            $(document).on('click', '.hlai-dismiss-notification, .hlai-notification .delete', function() {
                $(this).closest('.notification, .hlai-notification').fadeOut(300, function() {
                    $(this).remove();
                });
            });
        },

        /**
         * Refresh all dashboard data
         */
        refreshDashboard: function() {
            // Destroy existing charts
            Object.keys(this.charts).forEach(function(key) {
                if (Dashboard.charts[key] && typeof Dashboard.charts[key].destroy === 'function') {
                    Dashboard.charts[key].destroy();
                }
            });
            this.charts = {};

            // Reload data
            this.loadChartData();
        },

        /**
         * Get color based on FTAR percentage
         * @param {number} ftar - First-time acceptance rate
         * @returns {string} Color hex code
         */
        getFtarColor: function(ftar) {
            if (ftar >= 75) {
                return '#10B981'; // Success green
            } else if (ftar >= 60) {
                return '#F59E0B'; // Warning yellow
            } else if (ftar >= 45) {
                return '#F97316'; // Orange
            } else {
                return '#EF4444'; // Danger red
            }
        },

        /**
         * Format question type for display
         * @param {string} type - Raw question type
         * @returns {string} Formatted type name
         */
        formatQuestionType: function(type) {
            var typeMap = {
                'multichoice': 'Multiple Choice',
                'truefalse': 'True/False',
                'shortanswer': 'Short Answer',
                'essay': 'Essay',
                'numerical': 'Numerical',
                'matching': 'Matching',
                'ordering': 'Ordering',
                'cloze': 'Cloze',
                'description': 'Description'
            };
            return typeMap[type] || type.charAt(0).toUpperCase() + type.slice(1);
        }
    };

    return {
        /**
         * Module initialization entry point
         * @param {Object} config - Configuration object with courseid, sesskey, stats, typeDistribution
         */
        init: function(config) {
            $(document).ready(function() {
                Dashboard.init(config);
            });
        }
    };
});
