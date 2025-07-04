import type CriteriaType from 'src/core/data/criteria.data';
import type RepositoryType from 'src/core/data/repository.data';
import template from './sw-dynamic-url-field.html.twig';
import './sw-dynamic-url-field.scss';

const { Criteria, EntityCollection } = Shopware.Data;

type LinkCategories = 'link' | 'detail' | 'navigation' | 'media' | 'email' | 'phone';

/**
 * @sw-package framework
 *
 * @private
 */
export default Shopware.Component.wrapComponentConfig({
    template,

    inject: [
        'repositoryFactory',
    ],

    props: {
        value: {
            type: String,
            required: false,
            default: '',
        },
    },

    data(): {
        lastEmittedLink: string;
        linkTarget: string;
        isHTTPs: boolean;
        displayAsButton: boolean;
        linkCategory: LinkCategories;
        categoryCollection?: EntityCollection<'category'>;
    } {
        return {
            lastEmittedLink: '',
            linkTarget: '',
            isHTTPs: false,
            displayAsButton: false,
            linkCategory: 'link',
            categoryCollection: undefined,
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
                { value: 'link', label: this.$tc('sw-text-editor-toolbar.link.labelUrl') },
                { value: 'detail', label: this.$tc('sw-text-editor-toolbar.link.labelProduct') },
                { value: 'navigation', label: this.$tc('sw-text-editor-toolbar.link.labelCategory') },
                { value: 'media', label: this.$tc('sw-text-editor-toolbar.link.labelMedia') },
                { value: 'email', label: this.$tc('sw-text-editor-toolbar.link.labelEmail') },
                { value: 'phone', label: this.$tc('sw-text-editor-toolbar.link.labelPhoneNumber') },
            ];
        },
    },

    watch: {
        value: {
            async handler(value): Promise<void> {
                if (value === this.lastEmittedLink || typeof value !== 'string') {
                    return;
                }

                const parsedResult = await this.parseLink(value);
                this.linkCategory = parsedResult.type;
                this.linkTarget = parsedResult.target;
            },
            immediate: true,
        },
        linkTarget: {
            handler(): void {
                const preparedLink = this.prepareLink();

                if (preparedLink === this.value) {
                    return;
                }

                this.lastEmittedLink = preparedLink;

                this.$emit('update:value', preparedLink);
            },
        },
    },

    created(): void {
        this.createdComponent();
    },

    methods: {
        createdComponent(): void {
            this.categoryCollection = this.getEmptyCategoryCollection();
        },

        getEmptyCategoryCollection(): EntityCollection<'category'> {
            return new EntityCollection(
                this.categoryRepository.route,
                this.categoryRepository.entityName,
                Shopware.Context.api,
            );
        },

        getCategoryCollection(categoryId: string): Promise<EntityCollection<'category'>> {
            const categoryCriteria = new Criteria(1, 25).addFilter(Criteria.equals('id', categoryId));
            return this.categoryRepository.search(categoryCriteria);
        },

        async parseLink(link: string): Promise<{ type: LinkCategories; target: string }> {
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
                type: 'link',
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
            if (!this.linkTarget) {
                return '';
            }

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
                    return this.linkTarget;
            }
        },

        removeLink(): void {
            this.linkTarget = '';
        },

        onSelectFieldChange(selectedLinkCategory: string): void {
            this.linkTarget = '';
            this.linkCategory = selectedLinkCategory as LinkCategories;
        },
    },
});
