import './sw-pagination.scss';
import template from './sw-pagination.html.twig';

/**
 * @sw-package framework
 *
 * @private
 * @status ready
 * @example-type static
 * @component-example
 * <sw-pagination :total="500" :limit="25" :page="1"></sw-pagination>
 */
// eslint-disable-next-line sw-deprecation-rules/private-feature-declarations
export default {
    template,

    emits: ['page-change'],

    props: {
        total: {
            type: Number,
            required: true,
        },

        limit: {
            type: Number,
            required: true,
        },

        page: {
            type: Number,
            required: true,
        },

        totalVisible: {
            type: Number,
            required: false,
            default: 7,
        },

        steps: {
            type: Array,
            required: false,
            default() {
                return [
                    10,
                    25,
                    50,
                    75,
                    100,
                ];
            },
        },

        autoHide: {
            type: Boolean,
            required: false,
            // eslint-disable-next-line vue/no-boolean-default
            default: true,
        },
    },

    data() {
        return {
            currentPage: this.page,
            perPage: this.limit,
        };
    },

    computed: {
        maxPage() {
            return Math.ceil(this.total / this.perPage);
        },

        displayedPages() {
            const maxLength = this.totalVisible;
            const currentPage = this.currentPage;

            if (this.maxPage <= maxLength) {
                return this.range(1, this.maxPage);
            }

            const even = maxLength % 2 === 0 ? 1 : 0;
            const left = Math.floor(maxLength / 2);
            const right = this.maxPage - left + 1 + even;

            if (currentPage === left || (left === 1 && currentPage === left + 1)) {
                return [
                    ...this.range(1, left + 1),
                    '...',
                    ...this.range(right, this.maxPage),
                ];
            }

            if (currentPage === right || (right === this.maxPage && currentPage === this.maxPage - 1)) {
                return [
                    ...this.range(1, left),
                    '...',
                    ...this.range(right - 1, this.maxPage),
                ];
            }

            if (currentPage > left && currentPage < right) {
                const start = currentPage - left + 2;
                const end = currentPage + left - 2 - even;

                return [
                    1,
                    '...',
                    ...(start > end ? [currentPage] : this.range(start, end)),
                    '...',
                    this.maxPage,
                ];
            }

            return [
                ...this.range(1, left),
                '...',
                ...this.range(right, this.maxPage),
            ];
        },

        shouldBeVisible() {
            if (!this.autoHide) {
                return true;
            }

            return this.total > Math.min(...this.steps);
        },

        possibleSteps() {
            const total = this.total;
            const stepsSorted = [...this.steps].sort((a, b) => a - b);

            let lastStep;
            const possibleSteps = stepsSorted.filter((x) => {
                if (lastStep > total) return false;
                lastStep = x;
                return true;
            });

            return possibleSteps;
        },

        possibleStepsOptions() {
            return this.possibleSteps.map((step) => {
                return {
                    value: String(step),
                    label: String(step),
                };
            });
        },
    },

    watch: {
        page() {
            this.currentPage = this.page;
        },

        limit() {
            this.perPage = this.limit;
        },

        maxPage() {
            if (this.maxPage === 0) {
                this.currentPage = 1;
                return;
            }

            if (this.currentPage > this.maxPage) {
                this.changePageByPageNumber(this.maxPage);
            }
        },
    },

    methods: {
        range(from, to) {
            const range = [];

            from = from > 0 ? from : 1;

            for (let i = from; i <= to; i += 1) {
                range.push(i);
            }
            return range;
        },

        pageChange() {
            this.$emit('page-change', {
                page: this.currentPage,
                limit: this.perPage,
            });
        },

        onPageSizeChange(perPage) {
            this.perPage = Number(perPage);
            this.firstPage();
        },

        firstPage() {
            this.currentPage = 1;
            this.pageChange();
        },

        prevPage() {
            this.currentPage -= 1;
            this.pageChange();
        },

        nextPage() {
            this.currentPage += 1;
            this.pageChange();
        },

        lastPage() {
            this.currentPage = this.maxPage;
            this.pageChange();
        },

        changePageByPageNumber(pageNum) {
            this.currentPage = pageNum;
            this.pageChange();
        },

        refresh() {
            this.pageChange();
        },
    },
};
