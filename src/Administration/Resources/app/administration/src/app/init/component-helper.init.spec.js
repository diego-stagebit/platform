/**
 * @sw-package framework
 */
import initComponentHelper from 'src/app/init/component-helper.init';

describe('src/app/init/component-helper.init.ts', () => {
    it('should init component-helper', () => {
        const componentHelper = initComponentHelper();

        expect(componentHelper.hasOwnProperty('mapState')).toBe(true);
        expect(componentHelper.hasOwnProperty('mapActions')).toBe(true);
        expect(componentHelper.hasOwnProperty('mapVuexState')).toBe(true);
        expect(componentHelper.hasOwnProperty('mapVuexGetters')).toBe(true);
        expect(componentHelper.hasOwnProperty('mapVuexMutations')).toBe(true);
        expect(componentHelper.hasOwnProperty('mapVuexActions')).toBe(true);
        expect(componentHelper.hasOwnProperty('mapCollectionPropertyErrors')).toBe(true);
        expect(componentHelper.hasOwnProperty('mapPageErrors')).toBe(true);
        expect(componentHelper.hasOwnProperty('mapPropertyErrors')).toBe(true);
        expect(componentHelper.hasOwnProperty('mapSystemConfigErrors')).toBe(true);
        expect(componentHelper.hasOwnProperty('createTextEditorDataMappingButton')).toBe(true);
    });
});
