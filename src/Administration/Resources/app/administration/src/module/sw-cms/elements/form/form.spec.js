/**
 * @sw-package discovery
 */
import { runCmsElementRegistryTest } from 'src/module/sw-cms/test-utils';

describe('src/module/sw-cms/elements/form', () => {
    runCmsElementRegistryTest({
        import: 'src/module/sw-cms/elements/form',
        name: 'form',
        component: 'sw-cms-el-form',
        config: 'sw-cms-el-config-form',
        preview: 'sw-cms-el-preview-form',
        contact: 'sw-cms-el-form-template-contact',
        newsletter: 'sw-cms-el-form-template-newsletter',
    });
});
