import template from './sw-select-base.html.twig';
import './sw-select-base.scss';

/**
 * @sw-package framework
 *
 * @private
 * @status ready
 * @description Base component for creating new select components. Uses sw-field base components as basic structure.
 * @example-type code-only
 */
export default {
    template,

    inheritAttrs: false,

    emits: [
        'select-expanded',
        'select-collapsed',
        'clear',
    ],

    props: {
        isLoading: {
            type: Boolean,
            required: false,
            default: false,
        },

        disabled: {
            type: Boolean,
            required: false,
            default: false,
        },

        showClearableButton: {
            type: Boolean,
            required: false,
            default: false,
        },

        size: {
            type: String,
            required: false,
            default: 'default',
        },
    },

    data() {
        return {
            expanded: false,
        };
    },

    computed: {
        swFieldClasses() {
            return { 'has--focus': this.expanded };
        },
    },

    mounted() {
        this.onMounted();
    },

    beforeUnmount() {
        this.onBeforeUnmount();
    },

    methods: {
        onMounted() {
            document.addEventListener('keydown', this.handleKeydown);
        },

        onBeforeUnmount() {
            document.removeEventListener('keydown', this.handleKeydown);
        },

        handleKeydown(event) {
            if (!this.expanded) {
                return;
            }

            // Handle escape key
            if (event.key === 'Escape' || event.key === 'Esc') {
                this.collapse();
            }
        },

        toggleExpand() {
            if (!this.expanded) {
                this.expand();
            } else {
                this.collapse();
            }
        },

        expand() {
            if (this.expanded) {
                return;
            }

            if (this.disabled) {
                return;
            }

            this.expanded = true;
            document.addEventListener('click', this.listenToClickOutside);
            this.$emit('select-expanded');
        },

        collapse(event) {
            document.removeEventListener('click', this.listenToClickOutside);
            this.expanded = false;

            // do not let clearable button trigger change event
            if (event?.target?.dataset.clearableButton === undefined) {
                this.$emit('select-collapsed');
            }

            // @see NEXT-16079 allow back tab-ing through form via SHIFT+TAB
            if (event && event?.shiftKey) {
                event.preventDefault();
                this.focusPreviousFormElement();
            }
        },

        focusPreviousFormElement() {
            const focusableSelector = 'a, button, input, textarea, select, details, [tabindex]:not([tabindex="-1"])';
            const myFocusable = this.$el.querySelector(focusableSelector);
            const keyboardFocusable = [
                ...document.querySelectorAll(focusableSelector),
            ].filter((el) => !el.hasAttribute('disabled') && el.dataset.clearableButton === undefined);

            keyboardFocusable.forEach((element, index) => {
                if (index > 0 && element === myFocusable) {
                    const kbFocusable = keyboardFocusable[index - 1];
                    kbFocusable.click();
                    kbFocusable.focus();
                }
            });
        },

        listenToClickOutside(event) {
            let path = event.path;
            if (typeof path === 'undefined') {
                path = this.computePath(event);
            }

            if (
                !path.find((element) => {
                    return element === this.$el;
                })
            ) {
                this.collapse();
            }
        },

        computePath(event) {
            const path = [];
            let target = event.target;

            while (target) {
                path.push(target);
                target = target.parentElement;
            }

            return path;
        },

        emitClear() {
            this.$emit('clear');
        },

        focusParentSelect(event) {
            if (event && event?.shiftKey) {
                this.$refs.selectWrapper.click();
                event.preventDefault();
            }
        },
    },
};
