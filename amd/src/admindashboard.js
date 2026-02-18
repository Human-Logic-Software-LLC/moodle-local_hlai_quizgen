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
 * AI Quiz Generator - Admin Dashboard Module
 *
 * Handles chart rendering for the admin dashboard.
 * Renders usage trend, adoption donut, Bloom's taxonomy radar,
 * question type bar, and difficulty distribution charts using ApexCharts.
 *
 * @module     local_hlai_quizgen/admindashboard
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['jquery'], function($) {
    'use strict';

    /**
     * Admin Dashboard controller
     */
    var AdminDashboard = {
        charts: {},

        /**
         * Initialize the admin dashboard charts.
         * @param {object} data - Chart data passed from PHP via js_call_amd.
         */
        init: function(data) {
            var self = this;
            this.waitForApexCharts().then(function() {
                self.renderCharts(data);
            });
        },

        /**
         * Wait for ApexCharts library to be available.
         * Polls every 100ms for up to 5 seconds.
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
                        // eslint-disable-next-line no-console
                        console.warn('ApexCharts did not load in time');
                        resolve();
                    }
                }, 100);
            });
        },

        /**
         * Render all five admin dashboard charts.
         * @param {object} data - Chart data object.
         */
        renderCharts: function(data) {
            this.renderUsageTrend(data);
            this.renderAdoptionChart(data);
            this.renderBloomsChart(data);
            this.renderQuestionTypeChart(data);
            this.renderDifficultyChart(data);
        },

        /**
         * Render the Usage Trend area chart.
         * @param {object} data - Chart data object.
         */
        renderUsageTrend: function(data) {
            var el = document.querySelector('#usageTrendChart');
            if (!el || typeof ApexCharts === 'undefined') {
                return;
            }

            var usageTrendOptions = {
                series: [{
                    name: 'Questions Generated',
                    data: data.trendCounts
                }],
                chart: {
                    type: 'area',
                    height: 250,
                    toolbar: {show: false},
                    fontFamily: 'inherit'
                },
                colors: ['#3B82F6'],
                dataLabels: {enabled: false},
                stroke: {
                    curve: 'smooth',
                    width: 2
                },
                fill: {
                    type: 'gradient',
                    gradient: {
                        shadeIntensity: 1,
                        opacityFrom: 0.4,
                        opacityTo: 0.1
                    }
                },
                xaxis: {
                    categories: data.trendDates,
                    labels: {show: true}
                },
                tooltip: {
                    x: {format: 'dd MMM yyyy'}
                }
            };

            this.charts.usageTrend = new ApexCharts(el, usageTrendOptions);
            this.charts.usageTrend.render();
        },

        /**
         * Render the Adoption donut chart.
         * @param {object} data - Chart data object.
         */
        renderAdoptionChart: function(data) {
            var el = document.querySelector('#adoptionChart');
            if (!el || typeof ApexCharts === 'undefined') {
                return;
            }

            var adoptionOptions = {
                series: [data.activeTeachers, data.inactiveTeachers],
                chart: {
                    type: 'donut',
                    height: 250,
                    fontFamily: 'inherit'
                },
                labels: ['Active Teachers', 'Inactive Teachers'],
                colors: ['#10B981', '#BFDBFE'],
                legend: {
                    position: 'bottom',
                    fontSize: '13px'
                },
                dataLabels: {
                    enabled: true,
                    style: {
                        fontSize: '14px',
                        fontWeight: 600,
                        colors: ['#1E293B']
                    },
                    dropShadow: {
                        enabled: true,
                        top: 1,
                        left: 1,
                        blur: 2,
                        color: '#fff',
                        opacity: 0.8
                    }
                },
                plotOptions: {
                    pie: {
                        donut: {
                            labels: {
                                show: true,
                                total: {
                                    show: true,
                                    label: 'Total Users',
                                    fontSize: '14px',
                                    fontWeight: 600,
                                    color: '#334155'
                                },
                                value: {
                                    fontSize: '20px',
                                    fontWeight: 700,
                                    color: '#1E293B'
                                }
                            }
                        }
                    }
                },
                states: {
                    hover: {filter: {type: 'lighten', value: 0.05}},
                    active: {filter: {type: 'none'}}
                },
                tooltip: {
                    y: {
                        formatter: function(value) {
                            return value + ' teachers';
                        }
                    }
                }
            };

            this.charts.adoption = new ApexCharts(el, adoptionOptions);
            this.charts.adoption.render();
        },

        /**
         * Render the Bloom's Taxonomy radar chart.
         * @param {object} data - Chart data object.
         */
        renderBloomsChart: function(data) {
            var el = document.querySelector('#bloomsDistributionChart');
            if (!el || typeof ApexCharts === 'undefined') {
                return;
            }

            var bloomsOptions = {
                series: [{
                    name: 'Questions',
                    data: data.bloomsValues
                }],
                chart: {
                    type: 'radar',
                    height: 350,
                    fontFamily: 'inherit'
                },
                colors: ['#3B82F6'],
                fill: {opacity: 0.15},
                markers: {
                    size: 4,
                    colors: ['#3B82F6'],
                    strokeWidth: 2,
                    strokeColors: '#fff'
                },
                xaxis: {categories: data.bloomsLabels},
                yaxis: {show: false},
                grid: {show: false}
            };

            this.charts.blooms = new ApexCharts(el, bloomsOptions);
            this.charts.blooms.render();
        },

        /**
         * Render the Question Type bar chart.
         * @param {object} data - Chart data object.
         */
        renderQuestionTypeChart: function(data) {
            var el = document.querySelector('#questionTypeChart');
            if (!el || typeof ApexCharts === 'undefined') {
                return;
            }

            var questionTypeOptions = {
                series: [{
                    name: 'Questions',
                    data: data.typeValues
                }],
                chart: {
                    type: 'bar',
                    height: 350,
                    toolbar: {show: false},
                    fontFamily: 'inherit'
                },
                plotOptions: {
                    bar: {
                        horizontal: false,
                        borderRadius: 8,
                        columnWidth: '60%'
                    }
                },
                colors: ['#06B6D4'],
                dataLabels: {enabled: false},
                xaxis: {categories: data.typeLabels}
            };

            this.charts.questionType = new ApexCharts(el, questionTypeOptions);
            this.charts.questionType.render();
        },

        /**
         * Render the Difficulty Distribution horizontal bar chart.
         * @param {object} data - Chart data object.
         */
        renderDifficultyChart: function(data) {
            var el = document.querySelector('#difficultyChart');
            if (!el || typeof ApexCharts === 'undefined') {
                return;
            }

            var difficultyOptions = {
                series: [{
                    name: 'Questions',
                    data: data.difficultyValues
                }],
                chart: {
                    type: 'bar',
                    height: 300,
                    toolbar: {show: false},
                    fontFamily: 'inherit'
                },
                plotOptions: {
                    bar: {
                        horizontal: true,
                        borderRadius: 8
                    }
                },
                colors: ['#F59E0B'],
                dataLabels: {enabled: true},
                xaxis: {categories: data.difficultyLabels}
            };

            this.charts.difficulty = new ApexCharts(el, difficultyOptions);
            this.charts.difficulty.render();
        }
    };

    return {
        /**
         * Module initialization entry point.
         * @param {object} data - Chart data passed from PHP.
         */
        init: function(data) {
            $(document).ready(function() {
                AdminDashboard.init(data);
            });
        }
    };
});
