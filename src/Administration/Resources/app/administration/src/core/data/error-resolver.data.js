/**
 * @sw-package framework
 *
 * @private
 */
export default class ErrorResolver {
    constructor() {
        this.EntityDefinition = Shopware.EntityDefinition;
        this.ShopwareError = Shopware.Classes.ShopwareError;
        this.merge = Shopware.Utils.object.merge;
    }

    resetApiErrors() {
        return Shopware.Store.get('error').resetApiErrors();
    }

    /**
     * @param errors
     * @param changeset
     */
    handleWriteErrors(changeset, { errors } = {}) {
        if (!errors) {
            throw new Error('[error-resolver] handleWriteError was called without errors');
        }

        const writeErrors = this.reduceErrorsByWriteIndex(errors);

        this.handleErrors(writeErrors, changeset);

        this.addSystemErrors(writeErrors.system);
    }

    handleDeleteError(errors) {
        errors.forEach(({ error, entityName, id }) => {
            const shopwareError = new this.ShopwareError(error);
            Shopware.Store.get('error').addSystemError({
                error: shopwareError,
            });

            Shopware.Store.get('error').addApiError({
                expression: `${entityName}.${id}`,
                error: shopwareError,
            });
        });
    }

    reduceErrorsByWriteIndex(errors) {
        let writeErrors = {
            system: [],
        };

        errors.forEach((current) => {
            if (!current.source || !current.source.pointer) {
                writeErrors.system.push(new this.ShopwareError(current));
                return;
            }

            const segments = current.source.pointer.split('/');

            // remove first empty element in list
            if (segments[0] === '') {
                segments.shift();
            }

            const denormalized = {};
            const lastIndex = segments.length - 1;

            segments.reduce((pointer, segment, index) => {
                // skip translations
                if (segment === 'translations' || segments[index - 1] === 'translations') {
                    return pointer;
                }

                if (index === lastIndex) {
                    pointer[segment] = new this.ShopwareError(current);
                } else {
                    pointer[segment] = {};
                }

                return pointer[segment];
            }, denormalized);

            writeErrors = this.merge(writeErrors, denormalized);
        });

        return writeErrors;
    }

    /**
     * @private
     * @param {Object[]} systemErrors
     */
    addSystemErrors(systemErrors) {
        systemErrors.forEach((error) => {
            Shopware.Store.get('error').addSystemError(error);
        });
    }

    /**
     * @private
     * @param writeErrors
     * @param changeset
     */
    handleErrors(writeErrors, changeset) {
        changeset.forEach(({ entity, changes }, writeIndex) => {
            const errors = writeErrors[writeIndex];

            // entity has no errors
            if (!errors) {
                return;
            }

            const definition = this.EntityDefinition.get(entity.getEntityName());
            Object.keys(errors).forEach((fieldName) => {
                this.resolveError(fieldName, errors[fieldName], definition, entity, changes);
            });
        });
    }

    /**
     * @private
     * @param fieldName
     * @param error
     * @param definition
     * @param entity
     * @param changeset
     */
    resolveError(fieldName, error, definition, entity, changeset) {
        const field = definition.getField(fieldName);

        if (!field) {
            this.errorStore.addSystemError(error);
            return;
        }

        if (definition.isToManyAssociation(field)) {
            const associationChanges = this.buildAssociationChangeset(entity, changeset, error, fieldName);
            this.handleErrors(error, associationChanges);
            return;
        }

        if (definition.isToOneAssociation(field)) {
            this.resolveOneToOneFieldError(`${entity.getEntityName()}.${entity.id}.${fieldName}`, error);
            return;
        }

        if (definition.isJsonField(field)) {
            this.resolveJsonFieldError(`${entity.getEntityName()}.${entity.id}.${fieldName}`, error);
            return;
        }

        if (!(error instanceof this.ShopwareError)) {
            error = new this.ShopwareError(error);
        }

        Shopware.Store.get('error').addApiError({
            expression: this.getErrorPath(entity, fieldName),
            error: error,
        });
    }

    buildAssociationChangeset(entity, changeset, error, associationName) {
        if (!changeset || !Shopware.Utils.object.hasOwnProperty(changeset, associationName)) {
            Shopware.Store.get('error').addApiError({
                expression: this.getErrorPath(entity, associationName),
                error: new this.ShopwareError(error),
            });
            return [];
        }

        return changeset[associationName].map((associationChange) => {
            const field = entity[associationName] ?? entity.extensions[associationName];
            const association = field.find((a) => {
                return a.id === associationChange.id;
            });

            return { entity: association, changes: associationChange };
        });
    }

    resolveJsonFieldError(basePath, error) {
        Object.keys(error).forEach((fieldName) => {
            const path = `${basePath}.${fieldName}`;

            if (error[fieldName] instanceof this.ShopwareError) {
                Shopware.Store.get('error').addApiError({
                    expression: path,
                    error: error[fieldName],
                });
                return;
            }

            this.resolveJsonFieldError(path, error[fieldName]);
        });
    }

    resolveOneToOneFieldError(basePath, error) {
        Object.keys(error).forEach((fieldName) => {
            const path = `${basePath}.${fieldName}`;

            if (error[fieldName] instanceof this.ShopwareError) {
                Shopware.Store.get('error').addApiError({
                    expression: path,
                    error: error[fieldName],
                });
            }
        });
    }

    /**
     * @private
     */
    getErrorPath(entity, currentField) {
        return `${entity.getEntityName()}.${entity.id}.${currentField}`;
    }
}
