define([], function () {
    'use strict';

    var SAFE_URL = /^(https?:\/\/|\/(?![\/\\]))/i;

    function hasText(value) {
        return value !== null && value !== undefined && value !== '';
    }

    function safeUrl(value) {
        var url = String(value);

        return SAFE_URL.test(url) ? url : '';
    }

    function optionValuesText(product) {
        var values = product.option_values;

        if (!values || typeof values !== 'object') {
            return '';
        }
        return Object.keys(values).map(function (key) {
            return values[key];
        }).join(' / ');
    }

    function needsPageChoice(product) {
        return product.needs_page_choices === true;
    }

    function hasCustomOptions(product) {
        return Array.isArray(product.custom_options) && product.custom_options.length > 0;
    }

    function isConfigurable(product) {
        var options = product.options;

        return options !== null && typeof options === 'object' && Object.keys(options).length > 0;
    }

    function customOptionLines(product) {
        var options = Array.isArray(product.custom_options) ? product.custom_options : [],
            required = options.filter(function (option) {
                return option.required === true;
            }),
            lines = required.slice(0, 3).map(function (option) {
                var values = Array.isArray(option.values) ? option.values : [],
                    shown = values.slice(0, 4).map(function (value) {
                        return value.title;
                    }),
                    more = values.length > 4 ? ' +' + (values.length - 4) + ' more' : '';

                return option.title + ': ' + shown.join(', ') + more;
            });

        if (required.length > 3) {
            lines.push('+' + (required.length - 3) + ' more options');
        }
        return lines;
    }

    function action(product, productsConfig) {
        if (productsConfig.addToCart === false) {
            return '';
        }
        if (needsPageChoice(product)) {
            return 'view';
        }
        if (hasCustomOptions(product)) {
            return 'custom_options';
        }
        if (isConfigurable(product)) {
            return 'choose_options';
        }
        return 'add';
    }

    function actionMessage(product, actionName) {
        var values = optionValuesText(product);

        if (actionName === 'custom_options') {
            return 'Which options does ' + product.title + ' have?';
        }
        if (actionName === 'choose_options') {
            return 'Which options are available for ' + product.title + '?';
        }
        if (actionName !== 'add') {
            return '';
        }
        return values
            ? 'Add ' + product.title + ' in ' + values + ' to my cart'
            : 'Add ' + product.title + ' to my cart';
    }

    function isListLayout(payload) {
        return (payload.items || []).length > 4 || payload.layout === 'list';
    }

    function tilesModifier(payload) {
        if ((payload.items || []).length === 1) {
            return 'single';
        }
        return payload.layout === 'carousel' ? 'carousel' : 'grid';
    }

    function stock(product, productsConfig) {
        if (productsConfig.stock === false) {
            return '';
        }
        if (product.in_stock === true) {
            return 'in';
        }
        return product.in_stock === false ? 'out' : '';
    }

    return {
        hasText: hasText,
        safeUrl: safeUrl,
        optionValuesText: optionValuesText,
        needsPageChoice: needsPageChoice,
        customOptionLines: customOptionLines,
        action: action,
        actionMessage: actionMessage,
        isListLayout: isListLayout,
        tilesModifier: tilesModifier,
        stock: stock
    };
});
