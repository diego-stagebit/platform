/**
 * @sw-package admin
 */

const path = require('path');

const baseRules = {
    // Disabled because it hides some warnings
    'file-progress/activate': 0,
    // Match the max line length with the phpstorm default settings
    'max-len': ['error', 125, { ignoreRegExpLiterals: true }],
    // Warn about useless path segment in import statements
    'import/no-useless-path-segments': 0,
    // don't require .vue and .js extensions
    'import/extensions': ['error', 'always', {
        js: 'never',
        ts: 'never',
        vue: 'never',
    }],
    'no-console': ['error', { allow: ['warn', 'error'] }],
    'no-warning-comments': ['error', { location: 'anywhere' }],
    'inclusive-language/use-inclusive-words': 'error',
    'comma-dangle': ['error', 'always-multiline'],
    'sw-core-rules/require-position-identifier': ['error', {
        components: [
            'sw-button',
            'sw-card',
            'sw-tabs',
            'sw-extension-component-section',
        ],
    }],
    'sw-core-rules/require-package-annotation': ['error'],
    'sw-deprecation-rules/private-feature-declarations': 'error',
    'no-restricted-exports': 'off',
    'filename-rules/match': [2, /^.*(?:\.js|\.ts|\.html|\.html\.twig)$/],
    'vue/multi-word-component-names': ['error', {
        ignores: ['index.html'],
    }],
    'func-names': 'off',
};

module.exports = {
    root: true,
    extends: [
        '@shopware-ag/eslint-config-base',
    ],
    env: {
        browser: true,
        'jest/globals': true,
    },

    globals: {
        Shopware: true,
        VueJS: true,
        Cypress: true,
        cy: true,
        autoStub: true,
        flushPromises: true,
        wrapTestComponent: true,
        resetFilters: true,
    },

    plugins: [
        'jest',
        'twig-vue',
        'inclusive-language',
        'vuejs-accessibility',
        'file-progress',
        'sw-core-rules',
        'sw-deprecation-rules',
        'sw-test-rules',
        'filename-rules',
    ],

    settings: {
        'import/resolver': {
            node: {},

            // This plugin supports to load the actual vite config
            // But the import resolver is not able to resolve the alias find regex
            // It only works with strings, so we need to manually add the aliases
            // @see: https://github.com/pzmosquito/eslint-import-resolver-vite/blob/main/index.js#L13
            vite: {
                viteConfig: {
                    resolve: {
                        extensions: ['.js', '.ts', '.vue', '.json', '.less', '.twig'],

                        alias: [
                            {
                                find: 'vue',
                                replacement: '@vue/compat/dist/vue.esm-bundler.js',
                            },
                            {
                                find: 'src',
                                replacement: path.join(__dirname, 'src'),
                            },
                            {
                                find: 'test',
                                replacement: path.join(__dirname, 'test'),
                            },
                        ],
                    },
                },
            },
        },
    },

    rules: {
        ...baseRules,
    },

    overrides: [
        {
            extends: [
                'plugin:vue/vue3-recommended',
                '@shopware-ag/eslint-config-base',
                'prettier',
            ],
            files: ['**/*.js'],
            excludedFiles: ['*.spec.js'],
            rules: {
                ...baseRules,
                'sw-core-rules/require-explicit-emits': 'error',
                'sw-core-rules/enforce-async-component-registers': 'error',
                'vue/require-prop-types': 'error',
                'vue/require-default-prop': 'error',
                'vue/no-mutating-props': 'error',
                'vue/component-definition-name-casing': ['error', 'kebab-case'],
                'vue/no-boolean-default': ['error', 'default-false'],
                'vue/order-in-components': ['error', {
                    order: [
                        'el',
                        'name',
                        'parent',
                        'functional',
                        ['template', 'render'],
                        'inheritAttrs',
                        ['provide', 'inject'],
                        'emits',
                        'extends',
                        'mixins',
                        'model',
                        ['components', 'directives', 'filters'],
                        ['props', 'propsData'],
                        'data',
                        'metaInfo',
                        'computed',
                        'watch',
                        'LIFECYCLE_HOOKS',
                        'methods',
                        ['delimiters', 'comments'],
                        'renderError',
                    ],
                }],
                'vue/no-deprecated-destroyed-lifecycle': 'error',
                'vue/no-deprecated-events-api': 'error',
                'vue/require-slots-as-functions': 'error',
                'vue/no-deprecated-props-default-this': 'error',
                'sw-deprecation-rules/no-compat-conditions': ['error'],
                'sw-deprecation-rules/no-empty-listeners': ['error', 'enableFix'],
                'sw-deprecation-rules/no-vue-options-api': 'off',
            },
        }, {
            extends: [
                'plugin:vue/vue3-recommended',
                'plugin:vue/essential',
                'plugin:vue/recommended',
                'eslint:recommended',
                'plugin:vuejs-accessibility/recommended',
            ],
            processor: 'twig-vue/twig-vue',
            files: [
                'src/**/*.html.twig',
                'test/eslint/**/*.html.twig',
            ],
            rules: {
                'no-warning-comments': ['error', { location: 'anywhere' }],
                'vue/component-name-in-template-casing': ['error', 'kebab-case', {
                    registeredComponentsOnly: true,
                    ignores: [],
                }],
                'vue/html-indent': ['error', 4, {
                    baseIndent: 0,
                }],
                'no-multiple-empty-lines': ['error', { max: 1 }],
                'vue/attribute-hyphenation': 'error',
                'vue/multiline-html-element-content-newline': 'off', // allow more spacy templates
                'vue/html-self-closing': ['error', {
                    html: {
                        void: 'never',
                        normal: 'never',
                        component: 'always',
                    },
                    svg: 'always',
                    math: 'always',
                }],
                'vue/no-parsing-error': ['error', {
                    'nested-comment': false,
                }],
                'vue/valid-v-slot': ['error', {
                    allowModifiers: true,
                }],
                'vue/v-slot-style': 'error',
                'vue/attributes-order': 'error',
                'vue/no-deprecated-slot-attribute': ['error'],
                'vue/no-deprecated-slot-scope-attribute': ['error'],
                // @deprecated v.6.7.0.0 - will be error in v.6.7
                'sw-deprecation-rules/no-deprecated-components': ['error', {
                    fix: true,
                    activatedComponents: [
                        'sw-button',
                        'sw-colorpicker',
                        'sw-alert',
                        'sw-progress-bar',
                        'sw-button',
                        'sw-text-field',
                        'sw-email-field',
                        'sw-card',
                        'sw-switch-field',
                        'sw-textarea-field',
                        'sw-icon',
                        'sw-url-field',
                        'sw-datepicker',
                        'sw-select-field',
                        'sw-checkbox-field',
                        'sw-number-field',
                        'sw-password-field',
                    ],
                }],
                // @deprecated v.6.7.0.0 - will be error in v.6.7
                'sw-deprecation-rules/no-deprecated-component-usage': ['error', 'enableFix'],
                'vue/no-useless-template-attributes': 'error',
                'vue/no-lone-template': 'error',

                // Disabled rules
                'eol-last': 'off', // no newline required at the end of file
                'max-len': 'off',
                'vue/no-multiple-template-root': 'off',
                'vue/no-unused-vars': 'off',
                'vue/no-template-shadow': 'off',
                'vue/no-v-html': 'off',
                'vue/valid-template-root': 'off',
                'vue/no-v-model-argument': 'off',
                'vue/no-v-for-template-key': 'off',
                'vue/html-closing-bracket-newline': 'error',
                'vue/no-v-for-template-key-on-child': 'error',
                'vue/no-deprecated-filter': 'error',
                'vue/no-deprecated-dollar-listeners-api': 'error',
                'vue/no-deprecated-dollar-scopedslots-api': 'error',
                'vue/no-deprecated-v-on-native-modifier': 'error',
                'vuejs-accessibility/media-has-caption': 'off',
            },
        }, {
            files: ['**/*.spec.js', '**/*.spec.ts', '**/fixtures/*.js', 'test/**/*.js', 'test/**/*.ts'],
            rules: {
                'sw-test-rules/await-async-functions': 'error',
                'max-len': 0,
                'sw-deprecation-rules/private-feature-declarations': 0,
                'jest/expect-expect': 'error',
                'jest/no-duplicate-hooks': 'error',
                'jest/no-test-return-statement': 'error',
                'jest/prefer-hooks-in-order': 'error',
                'jest/prefer-hooks-on-top': 'error',
                'jest/prefer-to-be': 'error',
                'jest/require-top-level-describe': 'error',
                'jest/prefer-to-contain': 'error',
                'jest/prefer-to-have-length': 'error',
                'jest/consistent-test-it': ['error', { fn: 'it', withinDescribe: 'it' }],
                'jest/valid-expect': [
                    'error',
                    {
                        maxArgs: 2,
                    },
                ],
                'jest/no-disabled-tests': 'error',
                'func-names': 'off',
            },
            extends: [
                'plugin:jest/recommended',
                'prettier',
            ],
        }, {
            files: ['**/snippet/*.json'],
            rules: {
                'inclusive-language/use-inclusive-words': 'error',
            },
        }, {
            files: ['**/*.ts', '**/*.tsx'],
            extends: [
                '@shopware-ag/eslint-config-base',
                'plugin:@typescript-eslint/eslint-recommended',
                'plugin:@typescript-eslint/recommended',
                'plugin:@typescript-eslint/recommended-requiring-type-checking',
                'prettier',
            ],
            parser: '@typescript-eslint/parser',
            parserOptions: {
                tsconfigRootDir: __dirname,
                project: ['./tsconfig.json'],
            },
            plugins: ['@typescript-eslint'],
            rules: {
                ...baseRules,
                '@typescript-eslint/ban-ts-comment': 0,
                '@typescript-eslint/no-unsafe-member-access': 'error',
                '@typescript-eslint/no-unsafe-call': 'error',
                '@typescript-eslint/no-unsafe-assignment': 'error',
                '@typescript-eslint/no-unsafe-return': 'error',
                '@typescript-eslint/explicit-module-boundary-types': 0,
                '@typescript-eslint/prefer-ts-expect-error': 'error',
                'no-shadow': 'off',
                '@typescript-eslint/no-shadow': ['error'],
                '@typescript-eslint/consistent-type-imports': ['error'],
                'import/extensions': [
                    'error',
                    'ignorePackages',
                    {
                        js: 'never',
                        jsx: 'never',
                        ts: 'never',
                        tsx: 'never',
                    },
                ],
                'no-void': 'off',
                // Disable the base rule as it can report incorrect errors
                'no-unused-vars': 'off',
                '@typescript-eslint/no-unused-vars': [
                    'error',
                    { caughtErrors: 'none' },
                ],
                '@typescript-eslint/prefer-promise-reject-errors': 'warn',
                'sw-deprecation-rules/no-compat-conditions': ['error'],
                'sw-core-rules/enforce-async-component-registers': 'error',
                'sw-deprecation-rules/no-empty-listeners': ['error', 'enableFix'],
                'sw-deprecation-rules/no-vue-options-api': 'off',
            },
        },
    ],
};
