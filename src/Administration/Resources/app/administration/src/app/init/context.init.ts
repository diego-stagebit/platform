/**
 * @sw-package framework
 */
import { watch } from 'vue';
/* Is covered by E2E tests */
import { publish } from '@shopware-ag/meteor-admin-sdk/es/channel';
import '../store/context.store';
import useSession from '../composables/use-session';

// eslint-disable-next-line sw-deprecation-rules/private-feature-declarations
export default function initializeContext(): void {
    // Handle incoming context requests from the ExtensionAPI
    Shopware.ExtensionAPI.handle('contextCurrency', () => {
        return {
            systemCurrencyId: Shopware.Context.app.systemCurrencyId ?? '',
            systemCurrencyISOCode: Shopware.Context.app.systemCurrencyISOCode ?? '',
        };
    });

    Shopware.ExtensionAPI.handle('contextEnvironment', () => {
        return Shopware.Context.app.environment ?? 'production';
    });

    Shopware.ExtensionAPI.handle('contextLanguage', () => {
        return {
            languageId: Shopware.Context.api.languageId ?? '',
            systemLanguageId: Shopware.Context.api.systemLanguageId ?? '',
        };
    });

    Shopware.ExtensionAPI.handle('contextLocale', () => {
        return {
            fallbackLocale: Shopware.Context.app.fallbackLocale ?? '',
            locale: Shopware.Store.get('session').currentLocale ?? '',
        };
    });

    Shopware.ExtensionAPI.handle('contextShopwareVersion', () => {
        return Shopware.Context.app.config.version ?? '';
    });

    Shopware.ExtensionAPI.handle('contextUserTimezone', () => {
        return Shopware.Store.get('session').currentUser?.timeZone ?? 'UTC';
    });

    Shopware.ExtensionAPI.handle('contextModuleInformation', (_, additionalInformation) => {
        const extension = Object.values(Shopware.Store.get('extensions').extensionsState).find((ext) =>
            ext.baseUrl.startsWith(additionalInformation._event_.origin),
        );

        if (!extension) {
            return {
                modules: [],
            };
        }

        // eslint-disable-next-line max-len,@typescript-eslint/no-unsafe-call,@typescript-eslint/no-unsafe-member-access
        const modules = Shopware.Store.get('extensionSdkModules').getRegisteredModuleInformation(
            extension.baseUrl,
        ) as Array<{
            displaySearchBar: boolean;
            heading: string;
            id: string;
            locationId: string;
        }>;

        return {
            modules,
        };
    });

    Shopware.ExtensionAPI.handle('contextUserInformation', (_, { _event_ }) => {
        const appOrigin = _event_.origin;
        const extension = Object.entries(Shopware.Store.get('extensions').extensionsState).find((ext) => {
            return ext[1].baseUrl.startsWith(appOrigin);
        });

        if (!extension) {
            return Promise.reject(new Error(`Could not find a extension with the given event origin "${_event_.origin}"`));
        }

        if (!(extension[1]?.permissions?.read as string[])?.includes('user')) {
            return Promise.reject(new Error(`Extension "${extension[0]}" does not have the permission to read users`));
        }

        const currentUser = Shopware.Store.get('session').currentUser;

        return Promise.resolve({
            aclRoles: currentUser?.aclRoles as unknown as Array<{
                name: string;
                type: string;
                id: string;
                privileges: Array<string>;
            }>,
            active: !!currentUser?.active,
            admin: !!currentUser?.admin,
            avatarId: currentUser?.avatarId ?? '',
            email: currentUser?.email ?? '',
            firstName: currentUser?.firstName ?? '',
            id: currentUser?.id ?? '',
            lastName: currentUser?.lastName ?? '',
            localeId: currentUser?.localeId ?? '',
            title: currentUser?.title ?? '',
            // @ts-expect-error - type is not defined in entity directly
            type: (currentUser?.type as unknown as string) ?? '',
            username: currentUser?.username ?? '',
        });
    });

    Shopware.ExtensionAPI.handle('contextAppInformation', (_, { _event_ }) => {
        const appOrigin = _event_.origin;
        const extension = Object.entries(Shopware.Store.get('extensions').extensionsState).find((ext) => {
            return ext[1].baseUrl.startsWith(appOrigin);
        });

        if (!extension || !extension[0] || !extension[1]) {
            const type: 'app' | 'plugin' = 'app';

            return {
                name: 'unknown',
                type: type,
                version: '0.0.0',
                inAppPurchases: null,
            };
        }

        return {
            name: extension[0],
            type: extension[1].type,
            version: extension[1].version ?? '',
            inAppPurchases: Shopware.InAppPurchase.getByExtension(extension[1].name),
        };
    });

    const contextStore = Shopware.Store.get('context');

    watch(
        () => {
            return {
                languageId: contextStore.api.languageId,
                systemLanguageId: contextStore.api.systemLanguageId,
            };
        },
        ({ languageId, systemLanguageId }, { languageId: oldLanguageId, systemLanguageId: oldSystemLanguageId }) => {
            if (languageId === oldLanguageId && systemLanguageId === oldSystemLanguageId) {
                return;
            }

            void publish('contextLanguage', {
                languageId: languageId ?? '',
                systemLanguageId: systemLanguageId ?? '',
            });
        },
    );

    watch(
        () => {
            return {
                fallbackLocale: contextStore.app.fallbackLocale,
            };
        },
        ({ fallbackLocale }, { fallbackLocale: oldFallbackLocale }) => {
            if (fallbackLocale === oldFallbackLocale) {
                return;
            }

            void publish('contextLocale', {
                locale: Shopware.Store.get('session').currentLocale ?? '',
                fallbackLocale: fallbackLocale ?? '',
            });
        },
    );

    Shopware.Vue.watch(useSession().currentLocale, (locale) => {
        void publish('contextLocale', {
            locale: locale ?? '',
            fallbackLocale: contextStore.app.fallbackLocale ?? '',
        });
    });

    Shopware.ExtensionAPI.handle('windowGetId', () => {
        if (!contextStore.app.windowId) {
            contextStore.app.windowId = Shopware.Utils.createId();
        }

        return contextStore.app.windowId;
    });

    Shopware.ExtensionAPI.handle('contextShopId', () => {
        return contextStore.app.config.shopId;
    });
}
