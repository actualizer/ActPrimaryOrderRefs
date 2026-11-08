import template from './act-primary-order-refs-info.html.twig';
import './act-primary-order-refs-info.scss';

const { Component } = Shopware;

const HOW_TO_ITEM_COUNT = 5;

Component.register('act-primary-order-refs-info', {
    template,

    computed: {
        howToItems() {
            return Array.from(
                { length: HOW_TO_ITEM_COUNT },
                (_, index) => `act-primary-order-refs.settings.info.howTo.item${index + 1}`,
            );
        },
    },
});
