import type CriteriaType from 'src/core/data/criteria.data';
import type RepositoryType from 'src/core/data/repository.data';
import template from './sw-text-editor-link-menu.html.twig';
import './sw-text-editor-link-menu.scss';

const { Criteria, EntityCollection } = Shopware.Data;

type ButtonVariant = 'primary' | 'primary-sm' | 'secondary' | 'secondary-sm';
type LinkCategories = 'link' | 'detail' | 'navigation' | 'media' | 'email' | 'phone';
interface TextEditorLinkMenuConfig {
    title: string;
    icon: string;
    expanded: boolean;
    newTab: boolean;
    displayAsButton: boolean;
    buttonVariant: ButtonVariant;
    value: string;
    type: string;
    tag: 'a';
    active: false;
}

/**
 * @sw-package framework
 * @deprecated tag:v6.8.0 - Will be removed, use mt-text-editor instead.
 *
 * @private
 */
export default Shopware.Component.wrapComponentConfig({
    template,

    inject: [
        'repositoryFactory',
    ],

    props: {
        buttonConfig: {
            type: Object as () => TextEditorLinkMenuConfig,
            required: true,
        },
    },

    data(): {
        linkTitle: string;
        linkTarget: string;
        isHTTPs: boolean;
        opensNewTab: boolean;
        displayAsButton: boolean;
        buttonVariant: ButtonVariant;
        linkCategory: LinkCategories;
        categoryCollection?: EntityCollection<'category'>;
        buttonVariantList: Array<{ id: ButtonVariant; name: string }>;
    } {
        return {
            linkTitle: '',
            linkTarget: '',
            isHTTPs: false,
            opensNewTab: false,
            displayAsButton: false,
            buttonVariant: 'primary',
            linkCategory: 'link',
            categoryCollection: undefined,
            buttonVariantList: [
                {
                    id: 'primary',
                    name: this.$tc('sw-text-editor-toolbar.link.buttonVariantPrimary'),
                },
                {
                    id: 'secondary',
                    name: this.$tc('sw-text-editor-toolbar.link.buttonVariantSecondary'),
                },
                {
                    id: 'primary-sm',
                    name: this.$tc('sw-text-editor-toolbar.link.buttonVariantPrimarySmall'),
                },
                {
                    id: 'secondary-sm',
                    name: this.$tc('sw-text-editor-toolbar.link.buttonVariantSecondarySmall'),
                },
            ],
        };
    },

    computed: {
        seoUrlReplacePrefix(): string {
            return '124c71d524604ccbad6042edce3ac799';
        },

        entityFilter(): CriteriaType {
            const criteria = new Criteria(1, 25);

            criteria.addAssociation('options.group');

            criteria.addFilter(
                Criteria.multi('OR', [
                    Criteria.equals('product.childCount', 0),
                    Criteria.equals('product.childCount', null),
                ]),
            );

            return criteria;
        },

        categoryRepository(): RepositoryType<'category'> {
            return this.repositoryFactory.create('category');
        },

        linkCategoryOptions() {
            return [
                {
                    id: 1,
                    value: 'link',
                    label: this.$tc('sw-text-editor-toolbar.link.labelUrl'),
                },
                {
                    id: 2,
                    value: 'detail',
                    label: this.$tc('sw-text-editor-toolbar.link.labelProduct'),
                },
                {
                    id: 3,
                    value: 'navigation',
                    label: this.$tc('sw-text-editor-toolbar.link.labelCategory'),
                },
                {
                    id: 4,
                    value: 'media',
                    label: this.$tc('sw-text-editor-toolbar.link.labelMedia'),
                },
                {
                    id: 5,
                    value: 'email',
                    label: this.$tc('sw-text-editor-toolbar.link.labelEmail'),
                },
                {
                    id: 6,
                    value: 'phone',
                    label: this.$tc('sw-text-editor-toolbar.link.labelPhoneNumber'),
                },
            ];
        },
    },

    watch: {
        buttonConfig: {
            async handler(buttonConfig): Promise<void> {
                const { title, newTab, displayAsButton, buttonVariant, value, type } =
                    buttonConfig as TextEditorLinkMenuConfig;
                this.linkTitle = title;
                this.opensNewTab = newTab;
                this.displayAsButton = displayAsButton;
                this.buttonVariant = buttonVariant;

                const parsedResult = await this.parseLink(value, type);
                this.linkCategory = parsedResult.type;
                this.linkTarget = parsedResult.target;
            },
            immediate: true,
        },
    },

    created(): void {
        this.createdComponent();
    },

    mounted(): void {
        this.mountedComponent();
    },

    methods: {
        createdComponent(): void {
            this.categoryCollection = this.getEmptyCategoryCollection();
        },

        mountedComponent(): void {
            this.$emit('mounted');
        },

        getCategoryCollection(categoryId: string): Promise<EntityCollection<'category'>> {
            const categoryCriteria = new Criteria(1, 25).addFilter(Criteria.equals('id', categoryId));
            return this.categoryRepository.search(categoryCriteria);
        },

        getEmptyCategoryCollection(): EntityCollection<'category'> {
            return new EntityCollection(
                this.categoryRepository.route,
                this.categoryRepository.entityName,
                Shopware.Context.api,
            );
        },

        async parseLink(link: string, detectedLinkType: string): Promise<{ type: LinkCategories; target: string }> {
            const slicedLink = link.slice(0, -1).split('/');

            if (
                link.startsWith(this.seoUrlReplacePrefix) &&
                [
                    'navigation',
                    'detail',
                    'mediaId',
                ].includes(slicedLink[1])
            ) {
                if (slicedLink[1] === 'navigation') {
                    this.categoryCollection = await this.getCategoryCollection(slicedLink[2]);
                } else if (slicedLink[1] === 'mediaId') {
                    slicedLink[1] = 'media';
                }
                return {
                    type: slicedLink[1] as LinkCategories,
                    target: slicedLink[2],
                };
            }

            if (link.startsWith('mailto:')) {
                return {
                    type: 'email',
                    target: link.replace('mailto:', ''),
                };
            }

            if (link.startsWith('tel:')) {
                return {
                    type: 'phone',
                    target: link.replace('tel:', ''),
                };
            }

            return {
                type: (detectedLinkType ?? 'link') as LinkCategories,
                target: link,
            };
        },

        replaceCategorySelection(category: { id: string }): void {
            this.linkTarget = category.id;
        },

        removeCategorySelection(): void {
            this.linkTarget = '';
        },

        prepareLink(): string {
            switch (this.linkCategory) {
                case 'detail':
                    return `${this.seoUrlReplacePrefix}/detail/${this.linkTarget}#`;
                case 'navigation':
                    return `${this.seoUrlReplacePrefix}/navigation/${this.linkTarget}#`;
                case 'media':
                    return `${this.seoUrlReplacePrefix}/mediaId/${this.linkTarget}#`;
                case 'email':
                    return `mailto:${this.linkTarget}`;
                case 'phone':
                    return `tel:${this.linkTarget.replace(/\//, '')}`;
                default:
                    return this.addProtocolToLink(this.linkTarget);
            }
        },

        addProtocolToLink(link: string): string {
            if (/(^(\w+):\/\/)|(mailto:)|(fax:)|(tel:)/.test(link)) {
                return link;
            }

            const isInternal = /^\/[^\/\s]/.test(link);
            const isAnchor = link.substring(0, 1) === '#';
            const isProtocolRelative = /^\/\/[^\/\s]/.test(link);

            if (!isInternal && !isAnchor && !isProtocolRelative) {
                link = `http://${link}`;
            }

            return link;
        },

        setLink(): void {
            this.$emit('button-click', {
                type: 'link',
                value: this.prepareLink(),
                displayAsButton: this.displayAsButton,
                buttonVariant: this.buttonVariant,
                newTab: this.opensNewTab,
            });
        },

        removeLink(): void {
            this.$emit('button-click', {
                type: 'linkRemove',
            });
        },

        onSelectFieldChange(category: LinkCategories): void {
            this.linkCategory = category;
            this.linkTarget = '';
        },
    },
});
