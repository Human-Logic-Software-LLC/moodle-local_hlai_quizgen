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
 * Chart utilities and configurations for HLAI QuizGen
 *
 * This module provides reusable chart configurations and helper functions
 * for ApexCharts integration throughout the plugin.
 *
 * @module     local_hlai_quizgen/charts
 * @copyright  2025 Human Logic Software LLC
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/* global ApexCharts */
define(['jquery'], function($) {
    'use strict';

    // Color palette matching Bulma overrides
    var colors = {
        primary: '#4F46E5',
        primaryLight: '#818CF8',
        primaryDark: '#3730A3',
        success: '#10B981',
        successLight: '#D1FAE5',
        warning: '#F59E0B',
        warningLight: '#FEF3C7',
        danger: '#EF4444',
        dangerLight: '#FEE2E2',
        info: '#3B82F6',
        infoLight: '#DBEAFE',
        gray100: '#F3F4F6',
        gray200: '#E5E7EB',
        gray300: '#D1D5DB',
        gray400: '#9CA3AF',
        gray500: '#6B7280',
        gray600: '#4B5563',
        gray700: '#374151',
        gray800: '#1F2937'
    };

    // Bloom's taxonomy colors
    var bloomsColors = {
        remember: '#DC2626',
        understand: '#EA580C',
        apply: '#F59E0B',
        analyze: '#84CC16',
        evaluate: '#10B981',
        create: '#06B6D4'
    };

    // Difficulty colors
    var difficultyColors = {
        easy: '#10B981',
        medium: '#F59E0B',
        hard: '#EF4444'
    };

    // Question type colors
    var questionTypeColors = {
        multichoice: '#4F46E5',
        truefalse: '#6B7280',
        shortanswer: '#3B82F6',
        essay: '#F59E0B',
        matching: '#10B981'
    };

    // Base chart options
    var baseOptions = {
        chart: {
            fontFamily: 'Inter, -apple-system, BlinkMacSystemFont, sans-serif',
            toolbar: {
                show: false
            },
            animations: {
                enabled: true,
                easing: 'easeinout',
                speed: 500
            }
        },
        grid: {
            borderColor: colors.gray200,
            strokeDashArray: 4
        },
        colors: [colors.primary, colors.success, colors.warning, colors.danger, colors.info],
        legend: {
            position: 'bottom',
            horizontalAlign: 'center',
            fontSize: '13px',
            fontWeight: 500,
            markers: {
                width: 10,
                height: 10,
                radius: 4
            }
        },
        tooltip: {
            theme: 'light',
            style: {
                fontSize: '13px'
            }
        }
    };

    /**
     * Create a donut chart
     * @param {string} selector - CSS selector for the chart container
     * @param {Object} options - Chart configuration
     * @returns {ApexCharts} Chart instance
     */
    function createDonutChart(selector, options) {
        var chartOptions = $.extend(true, {}, baseOptions, {
            chart: {
                type: 'donut',
                height: options.height || 300
            },
            series: options.series || [],
            labels: options.labels || [],
            colors: options.colors || [colors.primary, colors.success, colors.warning, colors.danger],
            plotOptions: {
                pie: {
                    donut: {
                        size: '70%',
                        labels: {
                            show: true,
                            name: {
                                show: true,
                                fontSize: '14px',
                                fontWeight: 600,
                                color: colors.gray600
                            },
                            value: {
                                show: true,
                                fontSize: '24px',
                                fontWeight: 'bold',
                                color: colors.gray800,
                                formatter: function(val) {
                                    return val;
                                }
                            },
                            total: {
                                show: options.showTotal !== false,
                                label: options.totalLabel || 'Total',
                                fontSize: '14px',
                                fontWeight: 600,
                                color: colors.gray600,
                                formatter: function(w) {
                                    return w.globals.seriesTotals.reduce(function(a, b) {
                                        return a + b;
                                    }, 0);
                                }
                            }
                        }
                    }
                }
            },
            dataLabels: {
                enabled: false
            },
            legend: {
                position: 'bottom'
            },
            title: options.title ? {
                text: options.title,
                align: 'left',
                style: {
                    fontSize: '16px',
                    fontWeight: 600,
                    color: colors.gray800
                }
            } : undefined
        });

        var chart = new ApexCharts(document.querySelector(selector), chartOptions);
        chart.render();
        return chart;
    }

    /**
     * Create a bar chart
     * @param {string} selector - CSS selector for the chart container
     * @param {Object} options - Chart configuration
     * @returns {ApexCharts} Chart instance
     */
    function createBarChart(selector, options) {
        var chartOptions = $.extend(true, {}, baseOptions, {
            chart: {
                type: 'bar',
                height: options.height || 350,
                stacked: options.stacked || false
            },
            series: options.series || [],
            xaxis: {
                categories: options.categories || [],
                labels: {
                    style: {
                        fontSize: '12px',
                        colors: colors.gray600
                    }
                }
            },
            yaxis: {
                title: {
                    text: options.yAxisTitle || '',
                    style: {
                        fontSize: '12px',
                        color: colors.gray600
                    }
                },
                labels: {
                    style: {
                        fontSize: '12px',
                        colors: colors.gray600
                    }
                }
            },
            plotOptions: {
                bar: {
                    horizontal: options.horizontal || false,
                    borderRadius: 4,
                    columnWidth: options.columnWidth || '60%',
                    distributed: options.distributed || false
                }
            },
            dataLabels: {
                enabled: options.dataLabels !== false,
                style: {
                    fontSize: '12px',
                    fontWeight: 600
                },
                formatter: function(val) {
                    if (options.dataLabelSuffix) {
                        return val + options.dataLabelSuffix;
                    }
                    return val;
                }
            },
            colors: options.colors || [colors.primary],
            title: options.title ? {
                text: options.title,
                align: 'left',
                style: {
                    fontSize: '16px',
                    fontWeight: 600,
                    color: colors.gray800
                }
            } : undefined
        });

        // Explicitly override legend if showLegend is false
        if (options.showLegend === false) {
            chartOptions.legend = { show: false };
        }

        var chart = new ApexCharts(document.querySelector(selector), chartOptions);
        chart.render();
        return chart;
    }

    /**
     * Create a line chart
     * @param {string} selector - CSS selector for the chart container
     * @param {Object} options - Chart configuration
     * @returns {ApexCharts} Chart instance
     */
    function createLineChart(selector, options) {
        var chartOptions = $.extend(true, {}, baseOptions, {
            chart: {
                type: 'line',
                height: options.height || 350,
                zoom: {
                    enabled: false
                }
            },
            series: options.series || [],
            xaxis: {
                categories: options.categories || [],
                labels: {
                    style: {
                        fontSize: '12px',
                        colors: colors.gray600
                    }
                },
                title: {
                    text: options.xAxisTitle || '',
                    style: {
                        fontSize: '12px',
                        color: colors.gray600
                    }
                }
            },
            yaxis: {
                min: options.yAxisMin,
                max: options.yAxisMax,
                title: {
                    text: options.yAxisTitle || '',
                    style: {
                        fontSize: '12px',
                        color: colors.gray600
                    }
                },
                labels: {
                    style: {
                        fontSize: '12px',
                        colors: colors.gray600
                    },
                    formatter: options.yAxisFormatter || function(val) {
                        return val;
                    }
                }
            },
            stroke: {
                curve: 'smooth',
                width: options.strokeWidth || 3
            },
            markers: {
                size: options.markerSize || 5,
                hover: {
                    size: 7
                }
            },
            colors: options.colors || [colors.primary, colors.success, colors.info],
            title: options.title ? {
                text: options.title,
                align: 'left',
                style: {
                    fontSize: '16px',
                    fontWeight: 600,
                    color: colors.gray800
                }
            } : undefined,
            annotations: options.annotations || {}
        });

        var chart = new ApexCharts(document.querySelector(selector), chartOptions);
        chart.render();
        return chart;
    }

    /**
     * Create a radial bar (gauge) chart
     * @param {string} selector - CSS selector for the chart container
     * @param {Object} options - Chart configuration
     * @returns {ApexCharts} Chart instance
     */
    function createRadialChart(selector, options) {
        // Support both options.value and options.series[0] for flexibility
        var value = options.value !== undefined ? options.value :
                    (options.series && options.series.length > 0 ? options.series[0] : 0);

        // Use provided color or determine based on thresholds
        var color = options.colors && options.colors.length > 0 ? options.colors[0] : colors.primary;

        // Color based on thresholds (if no explicit color and thresholds provided)
        if (!options.colors && options.thresholds) {
            if (value >= options.thresholds.excellent) {
                color = colors.success;
            } else if (value >= options.thresholds.good) {
                color = colors.warning;
            } else if (value >= options.thresholds.fair) {
                color = '#F97316'; // Orange
            } else {
                color = colors.danger;
            }
        }

        var chartOptions = {
            chart: {
                type: 'radialBar',
                height: options.height || 280,
                offsetY: -10,
                fontFamily: 'Inter, -apple-system, BlinkMacSystemFont, sans-serif'
            },
            series: [value],
            plotOptions: {
                radialBar: {
                    startAngle: -135,
                    endAngle: 135,
                    hollow: {
                        margin: 15,
                        size: '65%',
                        background: 'transparent'
                    },
                    track: {
                        background: colors.gray100,
                        strokeWidth: '100%'
                    },
                    dataLabels: {
                        name: {
                            fontSize: '14px',
                            color: colors.gray500,
                            offsetY: 80
                        },
                        value: {
                            offsetY: 5,
                            fontSize: '40px',
                            fontWeight: 'bold',
                            color: colors.gray800,
                            formatter: function(val) {
                                return val + (options.suffix || '%');
                            }
                        }
                    }
                }
            },
            fill: {
                type: 'gradient',
                gradient: {
                    shade: 'dark',
                    type: 'horizontal',
                    shadeIntensity: 0.5,
                    gradientToColors: [color],
                    inverseColors: false,
                    opacityFrom: 1,
                    opacityTo: 1,
                    stops: [0, 100]
                }
            },
            stroke: {
                lineCap: 'round'
            },
            labels: [options.label || 'Progress'],
            colors: [color]
        };

        var chart = new ApexCharts(document.querySelector(selector), chartOptions);
        chart.render();
        return chart;
    }

    /**
     * Create a radar chart
     * @param {string} selector - CSS selector for the chart container
     * @param {Object} options - Chart configuration
     * @returns {ApexCharts} Chart instance
     */
    function createRadarChart(selector, options) {
        var chartOptions = $.extend(true, {}, baseOptions, {
            chart: {
                type: 'radar',
                height: options.height || 350
            },
            series: options.series || [],
            xaxis: {
                categories: options.categories || [],
                labels: {
                    style: {
                        fontSize: '13px',
                        fontWeight: 500,
                        colors: colors.gray700
                    }
                }
            },
            yaxis: {
                show: false,
                stepSize: options.stepSize || 1,
                tickAmount: 0,
                labels: {
                    show: false  // Remove numbers from radar chart
                }
            },
            fill: {
                opacity: 0.2
            },
            stroke: {
                width: 2
            },
            markers: {
                size: 4
            },
            colors: options.colors || [colors.primary, colors.info],
            title: options.title ? {
                text: options.title,
                align: 'left',
                style: {
                    fontSize: '16px',
                    fontWeight: 600,
                    color: colors.gray800
                }
            } : undefined
        });

        // Explicitly override legend if showLegend is false
        if (options.showLegend === false) {
            chartOptions.legend = { show: false };
        }

        var chart = new ApexCharts(document.querySelector(selector), chartOptions);
        chart.render();
        return chart;
    }

    /**
     * Create a heatmap chart
     * @param {string} selector - CSS selector for the chart container
     * @param {Object} options - Chart configuration
     * @returns {ApexCharts} Chart instance
     */
    function createHeatmapChart(selector, options) {
        var chartOptions = $.extend(true, {}, baseOptions, {
            chart: {
                type: 'heatmap',
                height: options.height || 350
            },
            series: options.series || [],
            xaxis: {
                categories: options.xCategories || []
            },
            dataLabels: {
                enabled: true,
                style: {
                    colors: ['#fff']
                },
                formatter: function(val) {
                    return val + (options.valueSuffix || '%');
                }
            },
            colors: [colors.success],
            plotOptions: {
                heatmap: {
                    shadeIntensity: 0.5,
                    radius: 4,
                    colorScale: {
                        ranges: [
                            { from: 0, to: 50, color: colors.danger, name: 'Poor' },
                            { from: 51, to: 70, color: colors.warning, name: 'Fair' },
                            { from: 71, to: 100, color: colors.success, name: 'Good' }
                        ]
                    }
                }
            },
            title: options.title ? {
                text: options.title,
                align: 'left',
                style: {
                    fontSize: '16px',
                    fontWeight: 600,
                    color: colors.gray800
                }
            } : undefined
        });

        var chart = new ApexCharts(document.querySelector(selector), chartOptions);
        chart.render();
        return chart;
    }

    /**
     * Create a stacked horizontal bar chart for distributions
     * @param {string} selector - CSS selector
     * @param {Object} options - Chart options
     * @returns {ApexCharts} Chart instance
     */
    function createStackedDistributionChart(selector, options) {
        var chartOptions = {
            chart: {
                type: 'bar',
                height: options.height || 100,
                stacked: true,
                stackType: '100%',
                toolbar: { show: false },
                fontFamily: 'Inter, -apple-system, BlinkMacSystemFont, sans-serif'
            },
            series: options.series || [],
            plotOptions: {
                bar: {
                    horizontal: true,
                    borderRadius: 8
                }
            },
            colors: options.colors || [colors.success, colors.warning, colors.danger],
            xaxis: {
                labels: { show: false },
                axisBorder: { show: false },
                axisTicks: { show: false }
            },
            yaxis: { show: false },
            grid: { show: false },
            dataLabels: {
                enabled: true,
                formatter: function(val) {
                    return Math.round(val) + '%';
                },
                style: {
                    fontSize: '13px',
                    fontWeight: 'bold',
                    colors: ['#fff']
                }
            },
            legend: {
                position: 'top',
                horizontalAlign: 'left'
            },
            tooltip: {
                y: {
                    formatter: function(val) {
                        return Math.round(val) + '%';
                    }
                }
            }
        };

        var chart = new ApexCharts(document.querySelector(selector), chartOptions);
        chart.render();
        return chart;
    }

    /**
     * Create a sparkline mini chart
     * @param {string} selector - CSS selector
     * @param {Object} options - Chart options
     * @returns {ApexCharts} Chart instance
     */
    function createSparkline(selector, options) {
        var chartOptions = {
            chart: {
                type: options.type || 'line',
                height: options.height || 50,
                sparkline: {
                    enabled: true
                },
                fontFamily: 'Inter, -apple-system, BlinkMacSystemFont, sans-serif'
            },
            series: [{
                name: options.name || 'Value',
                data: options.data || []
            }],
            stroke: {
                curve: 'smooth',
                width: 2
            },
            colors: [options.color || colors.primary],
            tooltip: {
                fixed: {
                    enabled: false
                },
                x: {
                    show: false
                },
                y: {
                    title: {
                        formatter: function() {
                            return '';
                        }
                    }
                },
                marker: {
                    show: false
                }
            }
        };

        var chart = new ApexCharts(document.querySelector(selector), chartOptions);
        chart.render();
        return chart;
    }

    // Public API
    return {
        colors: colors,
        bloomsColors: bloomsColors,
        difficultyColors: difficultyColors,
        questionTypeColors: questionTypeColors,

        createDonutChart: createDonutChart,
        createBarChart: createBarChart,
        createLineChart: createLineChart,
        createRadialChart: createRadialChart,
        createRadarChart: createRadarChart,
        createHeatmapChart: createHeatmapChart,
        createStackedDistributionChart: createStackedDistributionChart,
        createSparkline: createSparkline,

        /**
         * Destroy a chart instance
         * @param {ApexCharts} chart - Chart instance to destroy
         */
        destroy: function(chart) {
            if (chart && typeof chart.destroy === 'function') {
                chart.destroy();
            }
        },

        /**
         * Update chart data
         * @param {ApexCharts} chart - Chart instance
         * @param {Array} series - New series data
         */
        updateSeries: function(chart, series) {
            if (chart && typeof chart.updateSeries === 'function') {
                chart.updateSeries(series);
            }
        },

        /**
         * Update chart options
         * @param {ApexCharts} chart - Chart instance
         * @param {Object} options - New options
         */
        updateOptions: function(chart, options) {
            if (chart && typeof chart.updateOptions === 'function') {
                chart.updateOptions(options);
            }
        },

        /**
         * Get color for difficulty level
         * @param {string} difficulty - easy, medium, hard
         * @returns {string} Color hex code
         */
        getDifficultyColor: function(difficulty) {
            return difficultyColors[difficulty.toLowerCase()] || colors.gray500;
        },

        /**
         * Get color for Bloom's level
         * @param {string} level - Bloom's taxonomy level
         * @returns {string} Color hex code
         */
        getBloomsColor: function(level) {
            return bloomsColors[level.toLowerCase()] || colors.gray500;
        },

        /**
         * Get color for question type
         * @param {string} type - Question type
         * @returns {string} Color hex code
         */
        getQuestionTypeColor: function(type) {
            return questionTypeColors[type.toLowerCase()] || colors.gray500;
        }
    };
});
