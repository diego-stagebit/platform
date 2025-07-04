import { DiscountTypes, DiscountScopes } from 'src/module/sw-promotion-v2/helper/promotion.helper';
import template from './sw-promotion-detail-discounts.html.twig';
import './sw-promotion-detail-discounts.scss';

/**
 * @sw-package checkout
 *
 * @private
 */
export default {
    template,

    inject: [
        'repositoryFactory',
        'acl',
    ],

    data() {
        return {
            /**
             * @deprecated tag:v6.8.0 - will be removed without replacement
             */
            deleteDiscountId: null,
            /**
             * @deprecated tag:v6.8.0 - will be removed without replacement
             */
            repository: null,
        };
    },

    computed: {
        promotion() {
            return Shopware.Store.get('swPromotionDetail').promotion;
        },

        isLoading: {
            get() {
                return Shopware.Store.get('swPromotionDetail').isLoading;
            },
            set(isLoading) {
                Shopware.Store.get('swPromotionDetail').isLoading = isLoading;
            },
        },

        discounts() {
            return (
                Shopware.Store.get('swPromotionDetail').promotion &&
                Shopware.Store.get('swPromotionDetail').promotion.discounts
            );
        },
    },

    methods: {
        // This function adds a new blank discount object to our promotion.
        // It will automatically trigger a rendering of the view which
        // leads to a new card that appears within our discounts area.
        onAddDiscount() {
            const promotionDiscountRepository = this.repositoryFactory.create(this.discounts.entity, this.discounts.source);
            const newDiscount = promotionDiscountRepository.create();
            newDiscount.promotionId = this.promotion.id;
            newDiscount.scope = DiscountScopes.CART;
            newDiscount.type = DiscountTypes.PERCENTAGE;
            newDiscount.value = 0.01;
            newDiscount.considerAdvancedRules = false;
            newDiscount.sorterKey = 'PRICE_ASC';
            newDiscount.applierKey = 'ALL';
            newDiscount.usageKey = 'ALL';

            this.discounts.push(newDiscount);
        },

        deleteDiscount(discount) {
            this.discounts.remove(discount.id);
        },
    },
};
