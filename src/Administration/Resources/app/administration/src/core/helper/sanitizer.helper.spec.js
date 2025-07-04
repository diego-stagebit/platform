/**
 * @sw-package framework
 */

import { mount } from '@vue/test-utils';

import Sanitizer from 'src/core/helper/sanitizer.helper';
import 'src/app/component/base/sw-empty-state';
import SanitizePlugin from 'src/app/plugin/sanitize.plugin';

describe('core/helper/sanitizer.helper.js', () => {
    // See for payload list: https://github.com/s0md3v/AwesomeXSS
    it('should sanitize the html', async () => {
        expect(Sanitizer.sanitize('<A/hREf="j%0aavas%09cript%0a:%09con%0afirm%0d``">z')).toBe(
            '<a href="j%0aavas%09cript%0a:%09con%0afirm%0d``">z</a>',
        );

        expect(Sanitizer.sanitize('<d3"<"/onclick="1>[confirm``]"<">z')).toBe('z');

        expect(Sanitizer.sanitize('<d3/onmouseenter=[2].find(confirm)>z')).toBe('z');

        expect(Sanitizer.sanitize('<d3/onmouseenter=[2].find(confirm)>z')).toBe('z');

        expect(Sanitizer.sanitize('<details open ontoggle=confirm()>')).toBe('<details open=""></details>');

        expect(Sanitizer.sanitize(`<script y="><">/*<script* */prompt()</script`)) // eslint-disable-line
            .toBe('');

        expect(Sanitizer.sanitize('<w="/x="y>"/ondblclick=`<`[confir\u006d``]>z')).toBe('z');

        expect(Sanitizer.sanitize('<a href=javas&#99;ript:alert(1)>click')).toBe('<a>click</a>');

        expect(Sanitizer.sanitize('<script/"<a"/src=data:=".<a,[8].some(confirm)>')).toBe('');

        expect(Sanitizer.sanitize('<svg/x=">"/onload=confirm()//')).toBe('');

        expect(Sanitizer.sanitize('<--`<img/src=` onerror=confirm``> --!>')).toBe('&lt;--`<img src="`"> --!&gt;');

        expect(Sanitizer.sanitize('<svg%0Aonload=%09((pro\u006dpt))()//')).toBe('');

        expect(Sanitizer.sanitize('<sCript x>(((confirm)))``</scRipt x>')).toBe('');

        expect(Sanitizer.sanitize('<svg </onload ="1> (_=prompt,_(1)) "">')).toBe('<svg></svg>');

        expect(Sanitizer.sanitize('<sCript x>(((confirm)))``</scRipt x>')).toBe('');

        expect(Sanitizer.sanitize('<!--><script src=//14.rs>')).toBe('');

        expect(Sanitizer.sanitize('<!--><script src=//14.rs>')).toBe('');

        expect(Sanitizer.sanitize('<embed src=//14.rs>')).toBe('');

        expect(Sanitizer.sanitize('<script x=">" src=//15.rs></script>')).toBe('');

        expect(Sanitizer.sanitize('<!\'/*"/*/\'/*/"/*--></Script><Image SrcSet=K */; OnError=confirm`1` //>')).toBe(
            '<img srcset="K">',
        );

        expect(Sanitizer.sanitize('<x oncut=alert()>x')).toBe('x');

        expect(Sanitizer.sanitize('<svg onload=write()>')).toBe('<svg></svg>');
    });

    it('should ensure a persistent configuration can be set and cleared', async () => {
        const dirtyContent = '<my-component>abc</my-component>';

        expect(Sanitizer.sanitize(dirtyContent)).toBe('abc');
        Sanitizer.setConfig({
            ADD_TAGS: ['my-component'],
        });
        expect(Sanitizer.sanitize(dirtyContent)).toBe('<my-component>abc</my-component>');
        Sanitizer.clearConfig();

        expect(Sanitizer.sanitize(dirtyContent)).toBe('abc');
    });

    it('should be able to modify the output using a middleware', async () => {
        Sanitizer.addMiddleware('afterSanitizeElements', (node) => {
            if (node.nodeType && node.nodeType === document.TEXT_NODE) {
                node.textContent = 'foo';
            }
            return node;
        });
        const content = '<div><p>Beautiful City</p><p>Beautiful country</p></div>';
        const expected = '<div><p>foo</p><p>foo</p></div>';

        expect(Sanitizer.sanitize(content)).toBe(expected);
    });

    it('should register a middleware with a valid name only', async () => {
        console.warn = jest.fn();
        expect(Sanitizer.addMiddleware('foo', () => {})).toBe(false);
        expect(Sanitizer.addMiddleware('afterSanitizeElements', () => {})).toBe(true);

        expect(Sanitizer.removeMiddleware('afterSanitizeElements')).toBe(true);
        expect(console.warn).toHaveBeenCalledTimes(1);
    });

    it('should remove a middleware with a valid name only', async () => {
        const warnSpy = jest.fn();
        jest.spyOn(global.console, 'warn').mockImplementation(warnSpy);

        expect(Sanitizer.removeMiddleware('foo')).toBe(false);
        expect(Sanitizer.removeMiddleware('afterSanitizeElements')).toBe(true);

        expect(warnSpy).toHaveBeenCalledWith('[Sanitizer]', expect.stringContaining('No middleware found for name'));
    });

    it('should sanitize untrusted HTML in a component', async () => {
        const $route = {
            meta: { $module: { icon: 'regular-storefront' } },
        };

        const unsanitized = '<x oncut=alert()>x';
        const sanitized = '<x oncut="alert()">x</x>';

        const wrapper = mount(await wrapTestComponent('sw-empty-state', { sync: true }), {
            global: {
                plugins: [SanitizePlugin],
                mocks: {
                    $route,
                },
            },
            props: {
                title: 'Foo bar',
                subline: unsanitized,
            },
        });

        expect(wrapper.html()).not.toContain(unsanitized);
        expect(wrapper.html()).toContain(sanitized);
    });
});
