import template from './sw-multi-tag-ip-select.html.twig';

const { string } = Shopware.Utils;

/**
 * @sw-package framework
 * @private
 * @status ready
 * @description Renders a multi select field for ip addresses specifically. The corresponding validation method
 * is active by default.
 * @example-type static
 * @component-example
 * <sw-multi-tag-ip-select
 *     :value="['127.0.0.1', '10.0.0.1', '::']"
 * ></sw-multi-tag-ip-select>
 */
export default {
    template,

    props: {
        validate: {
            type: Function,
            required: false,
            default: (searchTerm) => string.isValidIp(searchTerm),
        },

        knownIps: {
            type: Array,
            required: false,
            default() {
                return [];
            },
        },

        errorCode: {
            type: String,
            required: false,
            default() {
                return 'SHOPWARE_INVALID_IP';
            },
        },
    },

    computed: {
        errorObject() {
            const err = !this.inputIsValid && this.searchTerm.length > 0;

            return err ? { code: this.errorCode } : null;
        },

        validKnownIps() {
            return this.knownIps.filter((ip) => this.validate(ip.value));
        },

        validUnselectedKnownIps() {
            return this.validKnownIps.filter((ip) => this.value.indexOf(ip.value) === -1);
        },
    },

    methods: {
        addSpecific(value) {
            this.searchTerm = value;
            this.addItem();
        },

        getKnownIp(ip) {
            const index = this.validKnownIps.findIndex((knownIp) => knownIp.value === ip.value);

            if (index === -1) {
                return null;
            }

            return this.validKnownIps[index];
        },
    },
};
