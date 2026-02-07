/**
 * AI Quiz Generator - Analytics Module
 *
 * Handles chart rendering and data visualization
 * for the comprehensive analytics page.
 *
 * @module     local_hlai_quizgen/analytics
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['jquery', 'local_hlai_quizgen/charts'], function($, Charts) {
    'use strict';

    /**
     * Analytics controller
     */
    var Analytics = {
        courseid: 0,
        sesskey: '',
        timerange: '30',
        charts: {},

        /**
         * Initialize analytics page
         * @param {number} courseid - The course ID
         * @param {string} sesskey - Session key
         * @param {string} timerange - Time range filter
         */
        init: function(courseid, sesskey, timerange) {
            this.courseid = courseid;
            this.sesskey = sesskey;
            this.timerange = timerange;

            // Wait for ApexCharts to load
            this.waitForApexCharts().then(function() {
                Analytics.renderAllCharts();
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
                        console.warn('ApexCharts did not load in time');
                        resolve();
                    }
                }, 100);
            });
        },

        /**
         * Render all charts on the analytics page
         */
        renderAllCharts: function() {
            var data = window.hlaiQuizgenAnalytics || {};
            var stats = data.stats || {};

            this.renderFunnelChart(stats);
            this.renderQualityDistChart(stats);
            this.renderTypeAcceptanceChart(data.typeStats);
            this.renderDifficultyAnalysisChart(data.difficultyStats);
            this.renderBloomsCoverageChart(data.bloomsStats);
            this.renderRegenDistChart();
            this.renderRegenByDifficultyChart();
            this.renderRejectionReasonsChart(data.rejectionReasons);
            this.renderTrendsChart();
        },

        /**
         * Render question review funnel chart
         * @param {object} stats - Statistics data
         */
        renderFunnelChart: function(stats) {
            if (!$('#funnel-chart').length || typeof ApexCharts === 'undefined') {
                return;
            }

            var total = stats.totalQuestions || 0;
            var approved = stats.approved || 0;
            var rejected = stats.rejected || 0;
            var pending = stats.pending || 0;

            var options = {
                series: [{
                    name: 'Questions',
                    data: [total, total - pending, approved, Math.round(approved * 0.8)]
                }],
                chart: {
                    type: 'bar',
                    height: 350,
                    toolbar: { show: false }
                },
                plotOptions: {
                    bar: {
                        horizontal: true,
                        borderRadius: 8,
                        barHeight: '60%',
                        distributed: true,
                        dataLabels: {
                            position: 'bottom'
                        }
                    }
                },
                colors: ['#4F46E5', '#F59E0B', '#10B981', '#06B6D4'],
                dataLabels: {
                    enabled: true,
                    textAnchor: 'start',
                    formatter: function(val, opt) {
                        var labels = ['Generated', 'Reviewed', 'Approved', 'Deployed'];
                        return labels[opt.dataPointIndex] + ': ' + val;
                    },
                    offsetX: 10,
                    style: {
                        fontSize: '14px',
                        colors: ['#fff']
                    }
                },
                xaxis: {
                    categories: ['Generated', 'Reviewed', 'Approved', 'Deployed']
                },
                yaxis: {
                    labels: { show: false }
                },
                legend: { show: false }
            };

            this.charts.funnel = new ApexCharts(document.querySelector('#funnel-chart'), options);
            this.charts.funnel.render();
        },

        /**
         * Render quality score distribution chart
         * @param {object} stats - Statistics data
         */
        renderQualityDistChart: function(stats) {
            if (!$('#quality-dist-chart').length || typeof ApexCharts === 'undefined') {
                return;
            }

            // Simulated distribution - in production, this would come from AJAX
            var distribution = [5, 10, 25, 35, 25]; // 0-20, 21-40, 41-60, 61-80, 81-100

            var options = {
                series: [{
                    name: 'Questions',
                    data: distribution
                }],
                chart: {
                    type: 'bar',
                    height: 350,
                    toolbar: { show: false }
                },
                plotOptions: {
                    bar: {
                        borderRadius: 8,
                        columnWidth: '60%',
                        distributed: true
                    }
                },
                colors: ['#EF4444', '#F97316', '#F59E0B', '#10B981', '#059669'],
                dataLabels: {
                    enabled: true,
                    formatter: function(val) {
                        return val + '%';
                    }
                },
                xaxis: {
                    categories: ['0-20', '21-40', '41-60', '61-80', '81-100'],
                    title: { text: 'Quality Score Range' }
                },
                yaxis: {
                    title: { text: 'Percentage of Questions' }
                },
                legend: { show: false }
            };

            this.charts.qualityDist = new ApexCharts(document.querySelector('#quality-dist-chart'), options);
            this.charts.qualityDist.render();
        },

        /**
         * Render question type acceptance chart
         * @param {array} typeStats - Type statistics data
         */
        renderTypeAcceptanceChart: function(typeStats) {
            if (!$('#type-acceptance-chart').length || typeof ApexCharts === 'undefined' || !typeStats) {
                return;
            }

            var categories = [];
            var totalData = [];
            var approvedData = [];

            typeStats.forEach(function(item) {
                if (item.questiontype) {
                    categories.push(Analytics.formatQuestionType(item.questiontype));
                    totalData.push(parseInt(item.count) || 0);
                    approvedData.push(parseInt(item.approved) || 0);
                }
            });

            var options = {
                series: [
                    { name: 'Total', data: totalData },
                    { name: 'Approved', data: approvedData }
                ],
                chart: {
                    type: 'bar',
                    height: 350,
                    toolbar: { show: false }
                },
                plotOptions: {
                    bar: {
                        horizontal: false,
                        columnWidth: '55%',
                        borderRadius: 4
                    }
                },
                colors: ['#94A3B8', '#10B981'],
                dataLabels: { enabled: false },
                xaxis: { categories: categories },
                yaxis: { title: { text: 'Number of Questions' } },
                legend: {
                    position: 'top',
                    horizontalAlign: 'right'
                },
                fill: { opacity: 1 }
            };

            this.charts.typeAcceptance = new ApexCharts(document.querySelector('#type-acceptance-chart'), options);
            this.charts.typeAcceptance.render();
        },

        /**
         * Render difficulty analysis chart
         * @param {array} difficultyStats - Difficulty statistics
         */
        renderDifficultyAnalysisChart: function(difficultyStats) {
            if (!$('#difficulty-analysis-chart').length || typeof ApexCharts === 'undefined' || !difficultyStats) {
                return;
            }

            var series = [];
            var labels = [];

            difficultyStats.forEach(function(item) {
                if (item.difficulty) {
                    labels.push(item.difficulty.charAt(0).toUpperCase() + item.difficulty.slice(1));
                    series.push(parseInt(item.count) || 0);
                }
            });

            this.charts.difficultyAnalysis = Charts.createDonutChart('#difficulty-analysis-chart', {
                series: series,
                labels: labels,
                colors: ['#10B981', '#F59E0B', '#EF4444'],
                height: 350
            });
        },

        /**
         * Render Bloom's taxonomy coverage chart
         * @param {array} bloomsStats - Bloom's statistics
         */
        renderBloomsCoverageChart: function(bloomsStats) {
            if (!$('#blooms-coverage-chart').length || typeof ApexCharts === 'undefined' || !bloomsStats) {
                return;
            }

            var bloomsOrder = ['remember', 'understand', 'apply', 'analyze', 'evaluate', 'create'];
            var bloomsLabels = ['Remember', 'Understand', 'Apply', 'Analyze', 'Evaluate', 'Create'];
            var series = [];

            bloomsOrder.forEach(function(level) {
                var found = bloomsStats.find(function(item) {
                    return item.blooms_level && item.blooms_level.toLowerCase() === level;
                });
                series.push(found ? parseInt(found.count) || 0 : 0);
            });

            // Use custom radar chart with filled styling like dashboard
            var options = {
                series: [{ name: 'Questions', data: series }],
                chart: {
                    type: 'radar',
                    height: 350,
                    toolbar: { show: false },
                    animations: {
                        enabled: true,
                        speed: 800
                    }
                },
                xaxis: {
                    categories: bloomsLabels,
                    labels: {
                        show: true,
                        style: {
                            colors: ['#4F46E5', '#7C3AED', '#EC4899', '#F59E0B', '#10B981', '#3B82F6'],
                            fontSize: '13px',
                            fontWeight: 600
                        }
                    }
                },
                yaxis: {
                    show: false,
                    labels: { show: false },
                    tickAmount: 0
                },
                grid: { show: false },
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
                    hover: { size: 7 }
                },
                colors: ['#4F46E5'],
                legend: { show: false },
                dataLabels: { enabled: false }
            };

            this.charts.bloomsCoverage = new ApexCharts(document.querySelector('#blooms-coverage-chart'), options);
            this.charts.bloomsCoverage.render();
        },

        /**
         * Render regeneration distribution chart
         */
        renderRegenDistChart: function() {
            if (!$('#regen-dist-chart').length || typeof ApexCharts === 'undefined') {
                return;
            }

            // Simulated data - in production from AJAX
            var options = {
                series: [{
                    name: 'Questions',
                    data: [65, 20, 10, 3, 2]
                }],
                chart: {
                    type: 'bar',
                    height: 280,
                    toolbar: { show: false }
                },
                plotOptions: {
                    bar: {
                        borderRadius: 4,
                        columnWidth: '50%',
                        distributed: true
                    }
                },
                colors: ['#10B981', '#22C55E', '#F59E0B', '#F97316', '#EF4444'],
                dataLabels: {
                    enabled: true,
                    formatter: function(val) {
                        return val + '%';
                    }
                },
                xaxis: {
                    categories: ['0', '1', '2', '3', '4+'],
                    title: { text: 'Number of Regenerations' }
                },
                yaxis: {
                    title: { text: 'Percentage' }
                },
                legend: { show: false }
            };

            this.charts.regenDist = new ApexCharts(document.querySelector('#regen-dist-chart'), options);
            this.charts.regenDist.render();
        },

        /**
         * Render regeneration by difficulty chart
         */
        renderRegenByDifficultyChart: function() {
            if (!$('#regen-by-difficulty-chart').length || typeof ApexCharts === 'undefined') {
                return;
            }

            var options = {
                series: [{
                    name: 'Avg Regenerations',
                    data: [0.8, 1.2, 1.8]
                }],
                chart: {
                    type: 'bar',
                    height: 280,
                    toolbar: { show: false }
                },
                plotOptions: {
                    bar: {
                        horizontal: true,
                        borderRadius: 4,
                        barHeight: '50%',
                        distributed: true
                    }
                },
                colors: ['#10B981', '#F59E0B', '#EF4444'],
                dataLabels: { enabled: true },
                xaxis: {
                    categories: ['Easy', 'Medium', 'Hard'],
                    title: { text: 'Avg Regenerations' }
                },
                legend: { show: false }
            };

            this.charts.regenByDiff = new ApexCharts(document.querySelector('#regen-by-difficulty-chart'), options);
            this.charts.regenByDiff.render();
        },

        /**
         * Render rejection reasons chart
         * @param {array} rejectionReasons - Rejection reasons data
         */
        renderRejectionReasonsChart: function(rejectionReasons) {
            if (!$('#rejection-reasons-chart').length || typeof ApexCharts === 'undefined' || !rejectionReasons || rejectionReasons.length === 0) {
                return;
            }

            var series = [];
            var labels = [];

            rejectionReasons.forEach(function(item) {
                labels.push(item.reason || 'Not specified');
                series.push(parseInt(item.count) || 0);
            });

            this.charts.rejectionReasons = Charts.createDonutChart('#rejection-reasons-chart', {
                series: series,
                labels: labels,
                colors: ['#EF4444', '#F97316', '#F59E0B', '#6366F1', '#8B5CF6'],
                height: 300
            });
        },

        /**
         * Render trends over time chart
         */
        renderTrendsChart: function() {
            if (!$('#trends-chart').length || typeof ApexCharts === 'undefined') {
                return;
            }

            // Simulated trend data - in production from AJAX
            var categories = [];
            var now = new Date();
            for (var i = 29; i >= 0; i--) {
                var date = new Date(now);
                date.setDate(date.getDate() - i);
                categories.push(date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }));
            }

            // Simulated data
            var generated = [];
            var approved = [];
            for (var j = 0; j < 30; j++) {
                var gen = Math.floor(Math.random() * 15) + 5;
                generated.push(gen);
                approved.push(Math.floor(gen * (0.6 + Math.random() * 0.3)));
            }

            var options = {
                series: [
                    { name: 'Generated', data: generated },
                    { name: 'Approved', data: approved }
                ],
                chart: {
                    type: 'area',
                    height: 350,
                    toolbar: { show: false },
                    zoom: { enabled: false }
                },
                dataLabels: { enabled: false },
                stroke: {
                    curve: 'smooth',
                    width: 2
                },
                colors: ['#4F46E5', '#10B981'],
                fill: {
                    type: 'gradient',
                    gradient: {
                        opacityFrom: 0.4,
                        opacityTo: 0.1
                    }
                },
                xaxis: {
                    categories: categories,
                    labels: {
                        rotate: -45,
                        rotateAlways: true
                    }
                },
                yaxis: {
                    title: { text: 'Questions' }
                },
                legend: {
                    position: 'top',
                    horizontalAlign: 'right'
                },
                tooltip: {
                    x: { format: 'MMM dd' }
                }
            };

            this.charts.trends = new ApexCharts(document.querySelector('#trends-chart'), options);
            this.charts.trends.render();
        },

        /**
         * Format question type for display
         * @param {string} type - Raw question type
         * @returns {string} Formatted type name
         */
        formatQuestionType: function(type) {
            var typeMap = {
                'multichoice': 'MCQ',
                'truefalse': 'T/F',
                'shortanswer': 'Short',
                'essay': 'Essay',
                'numerical': 'Num',
                'matching': 'Match',
                'ordering': 'Order',
                'cloze': 'Cloze'
            };
            return typeMap[type] || type;
        }
    };

    return {
        /**
         * Module initialization entry point
         * @param {number} courseid - The course ID
         * @param {string} sesskey - Session key
         * @param {string} timerange - Time range filter
         */
        init: function(courseid, sesskey, timerange) {
            $(document).ready(function() {
                Analytics.init(courseid, sesskey, timerange);
            });
        }
    };
});
