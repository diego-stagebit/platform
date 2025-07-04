import type { PropType } from 'vue';
import template from './sw-cms-el-config-location-renderer.html.twig';
import type { ElementDataProp } from '../index';

const { Component, Mixin } = Shopware;

/**
 * @private
 * @sw-package discovery
 */
export default Component.wrapComponentConfig({
    template,

    mixins: [
        Mixin.getByName('cms-element'),
    ],

    props: {
        elementData: {
            type: Object as PropType<ElementDataProp>,
            required: true,
        },
    },

    computed: {
        src(): string {
            // Add this.element.id to the url as a query param
            const url = new URL(this.elementData.appData.baseUrl);
            // eslint-disable-next-line @typescript-eslint/no-unsafe-argument
            url.searchParams.set('elementId', this.element.id);

            return url.toString();
        },

        configLocation(): string {
            return `${this.elementData.name}-config`;
        },

        publishingKey(): string {
            return `${this.elementData.name}__config-element`;
        },
    },

    watch: {
        element() {
            this.$emit('element-update', this.element);
        },
    },

    created() {
        this.createdComponent();
    },

    methods: {
        createdComponent() {
            this.initElementConfig(this.elementData.name);

            /**
             * @deprecated tag:v6.8.0 - Will be removed
             */
            Shopware.ExtensionAPI.publishData({
                id: this.publishingKey,
                path: 'element',
                scope: this,
                deprecated: true,
                deprecationMessage:
                    // eslint-disable-next-line max-len
                    'The general cms element data set is deprecated. Please use a specific cms data set instead by provoding the element id.',
                showDoubleRegistrationError: false,
            });

            Shopware.ExtensionAPI.publishData({
                id: `${this.publishingKey}__${this.element.id}`,
                path: 'element',
                scope: this,
                showDoubleRegistrationError: false,
            });
        },

        onBlur(content: unknown) {
            this.emitChanges(content);
        },

        onInput(content: unknown) {
            this.emitChanges(content);
        },

        emitChanges(content: unknown) {
            if (content === this.element.config.content.value) {
                return;
            }

            this.element.config.content.value = content as string;

            this.$emit('element-update', this.element);
        },
    },
});
