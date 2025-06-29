/*
 * @sw-package inventory
 */

import './acl';
import defaultSearchConfiguration from './default-search-configuration';

const { Module } = Shopware;

/* eslint-disable max-len, sw-deprecation-rules/private-feature-declarations */
Shopware.Component.register('sw-product-stream-list', () => import('./page/sw-product-stream-list'));
Shopware.Component.register('sw-product-stream-detail', () => import('./page/sw-product-stream-detail'));
Shopware.Component.register('sw-product-stream-field-select', () => import('./component/sw-product-stream-field-select'));
Shopware.Component.register('sw-product-stream-value', () => import('./component/sw-product-stream-value'));
Shopware.Component.register('sw-product-stream-modal-preview', () => import('./component/sw-product-stream-modal-preview'));
Shopware.Component.extend(
    'sw-product-stream-filter',
    'sw-condition-base',
    () => import('./component/sw-product-stream-filter'),
);
/* eslint-enable max-len, sw-deprecation-rules/private-feature-declarations */

/**
 * @private
 */
Module.register('sw-product-stream', {
    type: 'core',
    name: 'product-stream',
    title: 'sw-product-stream.general.mainMenuItemGeneral',
    description: 'sw-product-stream.general.descriptionTextModule',
    version: '1.0.0',
    targetVersion: '1.0.0',
    color: '#57D9A3',
    icon: 'regular-products',
    favicon: 'icon-module-products.png',
    entity: 'product_stream',

    routes: {
        index: {
            components: {
                default: 'sw-product-stream-list',
            },
            path: 'index',
            meta: {
                privilege: 'product_stream.viewer',
            },
        },
        create: {
            component: 'sw-product-stream-detail',
            path: 'create',
            meta: {
                parentPath: 'sw.product.stream.index',
                privilege: 'product_stream.viewer',
            },
        },
        detail: {
            component: 'sw-product-stream-detail',
            path: 'detail/:id',
            meta: {
                parentPath: 'sw.product.stream.index',
                privilege: 'product_stream.viewer',
            },
            props: {
                default(route) {
                    return { productStreamId: route.params.id.toLowerCase() };
                },
            },
        },
    },

    navigation: [
        {
            path: 'sw.product.stream.index',
            label: 'sw-product-stream.general.mainMenuItemGeneral',
            id: 'sw-product-stream',
            privilege: 'product_stream.viewer',
            parent: 'sw-catalogue',
            color: '#57D9A3',
            position: 30,
        },
    ],

    defaultSearchConfiguration,
});
