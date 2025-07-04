import Plugin from 'src/plugin-system/plugin.class';

export default class WishlistWidgetPlugin extends Plugin {

    static options = {
        showCounter: true,
        liveAreaSelector: '#wishlist-basket-live-area',
        liveAreaTextAttribute: 'data-wishlist-live-area-text',
    };

    init() {
        this._getWishlistStorage();

        if (!this._wishlistStorage) {
            throw new Error('No wishlist storage found');
        }

        this._renderCounter();
        this._registerEvents();

        this._wishlistStorage.load();
    }

    /**
     * @returns WishlistWidgetPlugin
     * @private
     */
    _getWishlistStorage() {
        this._wishlistStorage = window.PluginManager.getPluginInstanceFromElement(this.el, 'WishlistStorage');
    }

    /**
     * @private
     */
    _renderCounter() {
        if (!this.options.showCounter) {
            return;
        }

        this.el.innerHTML = this._wishlistStorage.getCurrentCounter() || '';

        this._updateLiveArea();
    }

    /**
     * @private
     */
    _updateLiveArea() {
        const liveArea = this._getLiveArea();

        if (!liveArea) {
            return;
        }

        const counter = this._wishlistStorage.getCurrentCounter() || 0;
        const textTemplate = liveArea.getAttribute(this.options.liveAreaTextAttribute) || '%counter%';
        liveArea.innerHTML = `<p>${ textTemplate.replace('%counter%', counter) }</p>`;
    }

    /**
     * @private
     */
    _getLiveArea() {
        return document.querySelector(this.options.liveAreaSelector);
    }

    /**
     * @private
     */
    _registerEvents() {
        this.$emitter.subscribe('Wishlist/onProductsLoaded', () => {
            this._renderCounter();

            window.PluginManager.getPluginInstances('AddToWishlist').forEach((pluginInstance) => {
                pluginInstance.initStateClasses();
            });
        });

        this.$emitter.subscribe('Wishlist/onProductRemoved', (event) => {
            this._renderCounter();

            this._reInitWishlistButton(event.detail.productId);
        });

        this.$emitter.subscribe('Wishlist/onProductAdded', (event) => {
            this._renderCounter();

            this._reInitWishlistButton(event.detail.productId);
        });

        const listingEl = document.querySelector('.cms-element-product-listing-wrapper');

        if (listingEl) {
            const listingPlugin = window.PluginManager.getPluginInstanceFromElement(listingEl, 'Listing');

            listingPlugin.$emitter.subscribe('Listing/afterRenderResponse', () => {
                window.PluginManager.getPluginInstances('AddToWishlist').forEach((pluginInstance) => {
                    pluginInstance.initStateClasses();
                });
            });
        }
    }

    /**
     * @private
     */
    _reInitWishlistButton(productId) {
        const buttonElements = document.querySelectorAll('.product-wishlist-' + productId);

        if (!buttonElements) {
            return;
        }

        buttonElements.forEach((el) => {
            const plugin = window.PluginManager.getPluginInstanceFromElement(el, 'AddToWishlist');
            plugin.initStateClasses();
        });
    }
}
