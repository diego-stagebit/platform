# 6.6.10.1

## Fix `ServiceNotFoundException` during platform update

Updating shopware to 6.6.10.0 with the commercial plugin activated lead to `ServiceNotFoundException` being thrown until the commercial plugin was updated as well. 
To fix the error an alias to the old service was added to ensure the commercial plugin does not error during the update process.

## Fix `MessengerMiddlewareCompilerPass` middleware assertion
The `MessengerMiddlewareCompilerPass` now handles cases when middlewares are not defined yet. This change ensures that the middleware is correctly registered in the application.

# 6.6.10.0

## Deprecated EntityExtension::getDefinitionClass
Since (app) custom entities and entities defined via PHP attributes do not have a definition class, the method `EntityExtension::getDefinitionClass` has been deprecated. 
It will be replaced by `EntityExtension::getEntityName`, which needs to return the entity name. This can already be implemented now.

Before:

```php
<?php

namespace Examples\Extension;

use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityExtension;

class MyEntityExtension extends EntityExtension
{
    public function getDefinitionClass(): string
    { 
        return ProductDefinition::class;
    }
}
```

After:

```php
<?php

namespace Examples\Extension;

use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityExtension;

class MyEntityExtension extends EntityExtension
{
    public function getEntityName() : string
    {
        return ProductDefinition::ENTITY_NAME;
    }
}
```

## Feature: Bulk entity extension
The new `BulkEntityExtension` allows to define fields for different entities within one class. It removes the overhead of creating multiple classes for each entity and allows to define the fields in one place.

```php
<?php

namespace Examples\Extension;

use Shopware\Core\Content\Category\CategoryDefinition;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\BulkEntityExtension;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;

class MyEntityExtension extends BulkEntityExtension
{
    public function collect(): \Generator
    {
        yield ProductDefinition::ENTITY_NAME => [
            new FkField('follow_up_id', 'followUp', ProductDefinition::class),
            new ManyToOneAssociationField('followUp', 'follow_up_id', ProductDefinition::class, 'id')
        ];

        yield CategoryDefinition::ENTITY_NAME => [
            new FkField('linked_category_id', 'linkedCategoryId', CategoryDefinition::class),
            new ManyToOneAssociationField('category', 'linked_category_id', CategoryDefinition::class, 'id')
        ];
    }
}
```

```xml
<service id="Examples\Extension\MyEntityExtension">
    <tag name="shopware.bulk.entity.extension"/>
</service>
```

## setTwig in Storefront Controller deprecated
The method `Shopware\Storefront\Controller\StorefrontController::setTwig` is deprecated and will be removed in 6.7.0, you can remove the `setTwig` call from your DI config, no further change is required.

## Using external URL for media's path
You can now store media paths as external URLs using the admin API. This allows for more flexible media management without the need to store physical files on the server.

**Example Request:**

```http
POST http://sw.test/api/media
Content-Type: application/json

{
    "id": "01934e0015bd7174b35838bbb30dc927",
    "mediaFolderId": "01934ebfc0da735d841f38e8e54fda09",
    "path": "https://test.com/photo/2024/11/30/sunflowers.jpg",
    "fileName": "sunflower",
    "mimeType": "image/jpeg"
}
```
## Deprecated `messenger.bus.shopware` service
Change your usages of `messenger.bus.shopware` to `messenger.default_bus`. As long as you typed the interface `\Symfony\Component\Messenger\MessageBusInterface`, your code will work as expected.

## Deprecated old address editor
The `address-editor.plugin.js` is deprecated and will be removed in 6.7.0, extend `address-manager.plugin.js` instead.
The `address-editor-modal.html.twig` is deprecated and will be removed in 6.7.0, extend `address-manager-modal.html.twig` instead.
The `address-editor-modal-list.html.twig` is deprecated and will be removed in 6.7.0, extend `address-manager-modal-list.html.twig` instead.
The `address-editor-modal-create-address.html.twig` is deprecated and will be removed in 6.7.0, extend `address-manager-modal-create-address.html.twig` instead.

## Added new address search plugin

Added `address-search.plugin.js` to search customer addresses in the new modal and address account page.
## Deprecation of ReverseProxyCacheClearer

The `\Shopware\Core\Framework\Adapter\Cache\ReverseProxy\ReverseProxyCacheClearer` will be removed with the next major version.

If you relied on the `cache:clear` command to clear your HTTP cache, you should use the `cache:clear:http` command additionally.
However, unless you enable the `v6.7.0.0` feature flag, HTTP cache will still be cleared on `cache:clear`

## Addition of MySQLInvalidatorStorage
We added a new MySQL cache invalidator storage so you can take advantage of delayed cache invalidation without needing Redis (Redis is still preferred).

```yaml
shopware:
    cache:
        invalidation:
            delay: 1
            delay_options:
                storage: mysql
```

## Mail settings
If you are using OAuth2 authentication for mail sending, you need to update your mail settings in the administration:
1. Go to Setting > System > Mailer
2. Select the new option "SMTP server with OAuth2"
3. Fill in the required fields
4. Save the settings
5. Test the connection
6. Check Settings > System > Event logs for any errors

## Internalisation of StorefrontPluginRegistry & Removal of StorefrontPluginRegistryInterface

The class `Shopware\Storefront\Theme\StorefrontPluginRegistry` will become internal and will no longer implement `Shopware\Storefront\Theme\StorefrontPluginRegistryInterface`.

The interface `Shopware\Storefront\Theme\StorefrontPluginRegistryInterface` will be removed.

Please refactor your code to not use this class & interface.
The new field type allows to use PHP's `BackedEnum` types to be used as Entity fields. Together with RDBMS `ENUM` types, this allows to store and query enum values in a type-safe way with restricted values for a field.

## Example

```php
<?php
// Declare your Enum
enum PaymentProvider: string {
    case PAYPAL = 'paypal';
    case CREDIT_CARD = 'credit_card';
}

// Assign the Enum to a field
class Entity {
    private PaymentProvider $paymentProvider;
…
}

// Define the field in the EntityDefinition
class EntityDefinition extends EntityDefinition {
…
    public function getFields(): FieldCollection {
        return new FieldCollection([
            new EnumField('paymentProvider', 'payment_provider', PaymentProvider::CREDIT_CARD),
        ]);
    }
}
```

```mysql
CREATE TABLE `entity`
(
    `id`               INTEGER                        NOT NULL,
    `payment_provider` ENUM ('paypal', 'credit_card') NOT NULL
)
```

## New `.encode` event for store api routes
This new event allows you to extend the response data of store api routes on a event based approach. The event is triggered after the data has been fetched and before it is returned to the client.

```php
<?php

#[\Symfony\Component\EventDispatcher\Attribute\AsEventListener(
    event: 'store-api.product.listing.encode', 
    priority: 0, 
    method: 'onListing'
)]
class MyListener
{
    public function onListing(\Symfony\Component\HttpKernel\Event\ResponseEvent $event): void
    {
        $response = $event->getResponse();
           
        assert($response instanceof \Shopware\Core\System\SalesChannel\StoreApiResponse);
    }
}

```

## Storefront accessibility: Unify focus outline:
To improve the keyboard accessibility we will unify all focus outlines to have the same appearance.
Currently, the focus outlines are dependent on the color of the interactive element (e.g. light-green outline for green buttons).
This can be an accessibility issue because some focus outlines don't have sufficient contrast and also look inconsistent which makes keyboard navigation harder.

## Storefront accessibility: Deprecated current structure of `filter-active` span elements in favor of a button:
Currently, the label that displays an activate listing filter is using a span element with custom styling. 
Instead, we will use a Bootstrap button `.btn` which also functions as the "Remove filter" button directly.
This improves the focus outline visibility of active filters and also increases the click-surface for removal.
The `getLabelTemplate` of the `ListingPlugin` will return the updated HTML structure.

Current HTML structure:
```html
<span class="filter-active">
    <span aria-hidden="true">Example manufacturer</span>
    <button class="filter-active-remove" data-id="1234" aria-label="Remove filter: Example manufacturer">
        &times;
    </button>
</span>
```

New HTML structure:
```html
<button class="filter-active btn" data-id="1234" aria-label="Remove filter: Example manufacturer">
    Example manufacturer
    <span aria-hidden="true" class="ms-1 fs-4">&times;</span>
</button>
```
## Improved local form handling in the Storefront
To make forms more accessible, we overhauled the form handling in the Storefront, which includes local form validation and best-practices for user feedback.

### Implemented best-practices
Sadly, the native browser validation methods are not accessible by default. Therefore, we decided to create custom form handling and disable the native validation of the browser. In the following you can learn more about specific best-practices we implemented for accessible form handling and optimization for screen readers.

#### Form
* The form has the `novalidate` attribute to not use native validation by the browser.
* The `checkValidity()` method of the form is replaced by a custom implementation.

#### Required fields
* The asterisk to mark required fields has now a highlight color for better contrast.
* The asterisk got the `aria-hidden` attribute, because it is irritating to screen readers. The required state is already read out by screen readers if the form field has the necessary attributes.
* Required fields are not marked with a `required` attribute, but with a `aria-required` attribute. This marks the field as required from a semantic standpoint and will be read out by screen readers, but will not trigger native validation.

#### Input validation
* Fields are validated by a local form validation on direct change, but only if changed. The `input` event is used instead of the `change` or `blur` event because these fire everytime and not on immediate change. It is a common pattern that keyboard users tab through a form to get a sense of available fields without filling them out. Therefore, the fields should only be validated on change and then with immediate feedback. If the feedback happens only on `blur` event, it can be irritating because the user has already moved on to the next field.
* Field validation can be configured via the `data-validation` attribute. You can pass a comma separated list of validation rules the field should be checked against. You can define the priority of these validators by their order. The first validator has the highest priority. This is important to always give the user the right validation feedback. Only the validation message with the highest applying validator is shown to the user and read out by screen readers.
* By default, the validators `required`, `email`, `confirmation`, and `minLength` are available for local form validation. You can extend these and add your own if needed.

#### Input feedback
* Every field has a feedback area beneath it that is referenced with the `aria-describedby` attribute. It is used to show a validation message to users, which is also read out by screen readers.
* Optionally every input field can have a description area beneath it to give more context to the user. If present, the description is also referenced via the `aria-describedby` attribute and read out by screen readers.
* Besides the normal color feedback, invalid fields now also display an error icon on the right side of the input. This is an important visual feedback for users which find it difficult to identify colors.
* Placeholder labels were removed from most form fields, as they are irritating and don't add value, especially if they just mirror the content of the label.

#### Form validation
* Besides the immediate field validation feedback, all fields will be validated when submitting the form. 
* If there are still invalid fields, they will be highlighted with the necessary visual feedback.
* In addition, the page will focus the first invalid field. The browser will automatically scroll the page to that field if it is not in view.

### Implementation

#### New validation service and form handler plugin
There is a new central form validation class that is also available as a default instance under `window.formValidation`. This is used by a new form handler plugin that will automatically implement the necessary events and handling on a form element. It can be activated with the `data-form-handler="true"` attribute on a form element.

**Example:**  
```HTML
<form action="/newsletter" method="post" data-form-handler="true">
    <label for="email">Email</label>
    <input type="email" id="email" name="email" data-validation="required,email">
    

    <button type="submit">Submit</button>
</form>
```
The form validation works with an associated `data-validation` attribute on the form fields. You can pass a comma separated list of validator keys. Their priority is defined by their order. Only the validation message of the highest applying validator is shown to the user for relevant feedback.

These validators are available by default:  

| Key            | Description                                                                                                                                                                                                                                                                                                                                                                                                                                  |
|----------------|----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `required`     | Checks if the field is not empty.                                                                                                                                                                                                                                                                                                                                                                                                            |
| `email`        | Checks the value of the field to be a valid email address.                                                                                                                                                                                                                                                                                                                                                                                   |
| `conformation` | Checks if the value of a confirmation field matches the value of the original field. Make sure to use the right ID naming for the validator to work. As an example, the orginal field has the ID `email` and the confirmation field has the ID `emailConfirmation`. The `confirmation` validator should be added to the confirmation field. Note that unnecessary inputs are seen as not accessible and should be avoided wherever possible. |
| `minLength`    | Checks the value of the field for a minimum length. If available the validator will use the `minlength` attribute of the field to validate against. Otherwise, it will use the default configuration of eight characters.                                                                                                                                                                                                                    | 

You can add your own custom validators via the global `formValidation` class.

```JavaScript
window.formValidation.addValidator('custom', (value, field) => {
    // You custom validation. Should return a boolean.
}, 'Your custom validation message.');
```

You can take a look at the reference documentation of the service and the plugin for further information.

#### New form field components in Twig
To make it easier to implement all best practices without recreating a lot of boilerplate code for every form field, we created new templates for different field types which can be used for easy form field rendering. You can find them in `views/storefront/components/form/`. These components work in association with the described local form handling but also the additional server-side validation.

**Example usage:**  
```TWIG
<form action="/newsletter" method="post" data-form-handler="true">

    {% sw_include '@Storefront/storefront/component/form/form-input.html.twig' with {
        type: 'email',
        label: 'account.personalMailLabel'|trans|sw_sanitize,
        id: 'personalMail',
        name: 'email',
        value: data.get('email'),
        autocomplete: 'section-personal email',
        violationPath: '/email',
        validationRules: 'required,email',
        additionalClass: 'col-sm-6',
    } %}

    <button type="submit">Submit</button>
</form>
```

### Updated Shopware standard forms
The existing forms in the Shopware Storefront are already reworked to use the described practices and tools. The changes are part of our accessibility initiative and are still behind a feature flag. They will become the default with the Shopware 6.7.0.0 major version. If you already want to get these changes among other accessibility improvements you can activate the flag `ACCESSIBILITY_TWEAKS`.

**Forms that are updated:**
* Login
* Guest Login
* Registration
* Custom Registration
* Customer profile
* Change email
* Change password
* Recover password
* Reset password
* Address creation
* Address editing
* Product reviews
* Newsletter registration (CMS)
* Contact form (CMS)

## Adjust duplicate async JS file names

We have made changes to have more consistent JavaScript filenames in the storefront. If we have duplicate filenames, we will append the chunk id (numeric value, length of 5) to the filename.

### Examples

Filenames **before** this change in different modes  
Hot-Reloading: `http://localhost:9999/storefront/plugin_scroll-up_scroll-up_plugin_js.js`  
Development: `http://localhost:8000/theme/fa1abe71af50c0c1fd964660ee680e66/js/storefront/scroll-up.plugin.0ce767.js`  
Production: `http://localhost:8000/theme/fa1abe71af50c0c1fd964660ee680e66/js/storefront/scroll-up.plugin.0ce767.js`  
Duplicate Filename: `http://localhost:8000/theme/fa1abe71af50c0c1fd964660ee680e66/js/storefront/plugin_scroll-up_scroll-up_plugin_js.0ce767.js`

Filenames **after** this change in different modes  
Hot-Reloading: `http://localhost:9999/storefront/hot-reloading.scroll-up.plugin.js`  
Development: `http://localhost:8000/theme/fa1abe71af50c0c1fd964660ee680e66/js/storefront/storefront.scroll-up.plugin.2e9f58.js`  
Production: `http://localhost:8000/theme/fa1abe71af50c0c1fd964660ee680e66/js/storefront/storefront.scroll-up.plugin.2e9f58.js`  
Duplicate Filename: `http://localhost:8000/theme/fa1abe71af50c0c1fd964660ee680e66/js/storefront/storefront.scroll-up.plugin.45231.2e9f58.js`

## Deprecation of Twig variable
The global `showStagingBanner` Twig variable has been deprecated. Use `shopware.showStagingBanner` instead.

## New constructor parameter in FooterPagelet
The new optional parameter `serviceMenu` of type `\Shopware\Core\Content\Category\CategoryCollection` has been added to `\Shopware\Storefront\Pagelet\Footer\FooterPagelet`.
You can already add it to your implementation to prevent breaking changes, as it will be required in the next major version.

## Deprecated CSS declarations
* Deprecated custom CSS declarations for selectors `.header-cart-total`, `.header-logo-col`, `.header-search`, `.header-logo-main-link`, `.header-logo-main` and `.header-logo-picture`  and replaced them by Bootstrap helper classes in the corresponding templates.

## App System
Use `sw_macro_function` instead of usual `macro` in app scripts if you return values (`sw_macro_function` will be the new default in Shopware Version 6.8.0)

## Introduction of ESI for header and footer
With the next major version the header and footer will be loaded via ESI.
Due to this change many things were deprecated and will be removed with the next major version, as they are not needed anymore.
See the following chapter for a detailed list of deprecations.

### Deprecations
* The properties `header` and `footer` and their getter and setter Methods in `\Shopware\Storefront\Framework\Twig\ErrorTemplateStruct` are deprecated and will be removed with the next major version.
* The loading of header, footer, payment methods and shipping methods in `\Shopware\Storefront\Page\GenericPageLoader` is deprecated and will be removed with the next major version.
  Extend `\Shopware\Storefront\Pagelet\Header\HeaderPageletLoader` or `\Shopware\Storefront\Pagelet\Footer\FooterPageletLoader` instead.
* The properties `header`, `footer`, `salesChannelShippingMethods` and `salesChannelPaymentMethods` and their getter and setter Methods in `\Shopware\Storefront\Page\Page` are deprecated and will be removed with the next major version.
  Extend `\Shopware\Storefront\Pagelet\Header\HeaderPagelet` or `\Shopware\Storefront\Pagelet\Footer\FooterPagelet` instead.
  Use the following alternatives in templates instead:
    * `context.currency` instead of `page.header.activeCurrency`
    * `shopware.navigation.id` instead of `page.header.navigation.active.id`
    * `shopware.navigation.pathIdList` instead of `page.header.navigation.active.path`
    * `context.languageInfo` instead of `page.header.activeLanguage`
* The property `serviceMenu` and its getter and setter Methods in `\Shopware\Storefront\Pagelet\Header\HeaderPagelet` are deprecated and will be removed with the next major version.
  Extend it via the `\Shopware\Storefront\Pagelet\Footer\FooterPagelet` instead.
* The `navigationId` request parameter in `\Shopware\Storefront\Pagelet\Header\HeaderPageletLoader::load` is deprecated and will be removed with the next major version as it is not needed anymore.
* The `setNavigation` method in `\Shopware\Storefront\Pagelet\Menu\Offcanvas\MenuOffcanvasPagelet` is deprecated and will be removed with the next major version as it is unused.
* The option `tiggerEvent` in `OffcanvasMenuPlugin` JavaScript plugin is deprecated and will be removed with the next major version. Use `triggerEvent` instead.
* The following blocks will be moved from `src/Storefront/Resources/views/storefront/base.html.twig` to `src/Storefront/Resources/views/storefront/layout/header.html.twig` in the next major version.
  * `base_header`
  * `base_header_inner`
  * `base_navigation`
  * `base_navigation_inner`
  * `base_offcanvas_navigation`
  * `base_offcanvas_navigation_inner`
* The following blocks will be moved from `src/Storefront/Resources/views/storefront/base.html.twig` to `src/Storefront/Resources/views/storefront/layout/footer.html.twig` in the next major version.
  * `base_footer`
  * `base_footer_inner`
* The template variable `page` in following templates is deprecated and will be removed in the next major version. Provide `header` or `footer` directly.
  * `src/Storefront/Resources/views/storefront/layout/footer/footer.html.twig`
  * `src/Storefront/Resources/views/storefront/layout/header/actions/currency-widget.html.twig`
  * `src/Storefront/Resources/views/storefront/layout/header/actions/language-widget.html.twig`
  * `src/Storefront/Resources/views/storefront/layout/header/top-bar.html.twig`
  * `src/Storefront/Resources/views/storefront/layout/navbar/navbar.html.twig`
* The template variables `activeId` and `activePath` in `src/Storefront/Resources/views/storefront/layout/navbar/categories.html.twig` are deprecated and will be removed in the next major version.
* The template variable `activePath` in `src/Storefront/Resources/views/storefront/layout/navbar/navbar.html.twig` is deprecated and will be removed in the next major version.
* The parameter `activeResult` of `src/Storefront/Resources/views/storefront/layout/sidebar/category-navigation.html.twig` is deprecated and will be removed in the next major version.

## Rule classes becoming internal
* Existing rule classes will be marked as internal, limiting direct usage by third parties.
* If you currently extend any of the existing rule classes, consider migrating to a custom rule class.
* Existing rule behavior remains unchanged, but internal implementations may evolve.

## Added `addTrailingSlash` option to the `sw-url-field` component
This option allows you to add a trailing slash to the URL and adds it to the value if it is missing.
The option is disabled by default.

### Example
```html
<sw-url-field v-model:value="currentValue" addTrailingSlash />
```
## Empty theme config values
We changed the way empty theme config fields are handled. Previous the fields were not added as variables to the SCSS if they were empty, which could lead to unwanted compiler crashes. But empty values could be a reasonable setting, for example to use it for optional styling or the usage of default variables in the SCSS code. Therefore, we decided to always add theme config fields to the SCSS, even if they are empty. In that case the value of the variable is set to "null". This is a valid value in SCSS and works along default variables or conditional styling.

### Example: Default Variables
```SCSS
$test-color: #fff !default;

body {
    background: $test-color;
}
```
If the variable is left empty in the config, the default value will be used.

### Example: Conditions
```SCSS
@if ($test-color != null) {
    body {
        background: darken($test-color, 20);
    }
}
```
You can use a condition to do optional styling. It should also be used in case of color variables and the usage of color functions. Those color functions would break with a null value if you don't use a proper default value.
## SalesChannelId is available in SystemConfigChangedHook
The SalesChannelId is now available in the SystemConfigChangedHook (`app.config.changed`). The request formats now looks like this:*
```diff
{
  "changes": [...],
+  "salesChannelId": "00000"
}
```

## E-Invoice
### New document config fields
The current field “Company address” cannot be used for e-invoices, as it is a single field with values separated by " - ". Therefore, new atomic fields will be added and the old one will be marked as “deprecated”.
To create a valid e-invoice document, these new fields must be filled in. However, the normal PDF invoice document still uses the old field, which will be replaced by the new ones in the next major version.

### AbstractDocumentRenderer render workflow
#### PDF renderer
With the next major version, the PDF rendering will be moved from the `\Shopware\Core\Checkout\Document\Service\DocumentGenerator` to each renderer with a PDF document.
Each implementation of the `\Shopware\Core\Checkout\Document\Renderer\AbstractDocumentRenderer` class needs to set the fully rendered file with `\Shopware\Core\Checkout\Document\Renderer\RenderedDocument::setContent()`.
With this change, the `\Shopware\Core\Checkout\Document\Renderer\RenderedDocument::html` property is not needed anymore and will be removed.

The content of a PDF document must be rendered within the renderer for the next major version.
```php
$doc = new RenderedDocument(
    $html, // @deprecated html property will be removed 
    $number,
    $config->buildName(),
    $operation->getFileType(),
    $config->jsonSerialize(),
);
if (Feature::isActive('v6.7.0.0')) {
    $doc->setContent($this->pdfRenderer->render($doc, $html));
}
```

#### New document filetypes
The new `\Shopware\Core\Checkout\Document\Service\DocumentFileRendererRegistry` allows creating other types of files besides `PDF`'s.
As example, the `\Shopware\Core\Checkout\Document\Service\XmlRenderer` will be called, if the file type is "xml".

New renderer need to extend `\Shopware\Core\Checkout\Document\Service\AbstractDocumentTypeRenderer` and the service need to have the file type as a service tag
```xml
<service id="Shopware\Core\Checkout\Document\Service\XmlRenderer">
    <tag name="document_type.renderer" key="xml"/>
</service>
```

# 6.6.9.0

## SCSS Values will be validated and sanitized
From now on, every scss value added by a theme will be validated when changed in the administration interface.
The values will be sanitized when they are invalid to a standard value when they are not valid when changed before or via api.

## Parameter names of some `\Shopware\Core\Framework\Migration\MigrationStep` methods will change
This will only have an effect if you are using the named parameter feature of PHP with those methods.
If you want to be forward compatible, call the methods without using named parameters.
* Parameter name `column` of `\Shopware\Core\Framework\Migration\MigrationStep::dropColumnIfExists` will change to `columnName`
* Parameter name `column` of `\Shopware\Core\Framework\Migration\MigrationStep::dropForeignKeyIfExists` will change to `foreignKeyName`
* Parameter name `index` of `\Shopware\Core\Framework\Migration\MigrationStep::dropIndexIfExists` will change to `indexName`

## Environment Configuration

The web installer now supports configurable command timeouts through the environment variable `SHOPWARE_INSTALLER_TIMEOUT`. This value should be provided in seconds.

### Default Behavior
If the environment variable is not set, the installer will use the default timeout of 900 seconds (15 minutes).

### Configuration Examples
```bash
# Set timeout to 30 minutes
export SHOPWARE_INSTALLER_TIMEOUT=1800

# Set timeout to 1 hour
export SHOPWARE_INSTALLER_TIMEOUT=3600
```

Or in the projects' `.env.installer` file:

```bash
SHOPWARE_INSTALLER_TIMEOUT=1800
```

### Validation
The provided timeout value must be:
- A numeric value
- Non-negative

If these conditions are not met, the installer will fall back to the default timeout of 900 seconds.

## Product review loading moved to core
The logic responsible for loading product reviews was unified and moved to the core.
* The service `\Shopware\Storefront\Page\Product\Review\ProductReviewLoader` is deprecated. Use `\Shopware\Core\Content\Product\SalesChannel\Review\AbstractProductReviewLoader` instead.
* The event `\Shopware\Storefront\Page\Product\Review\ProductReviewsLoadedEvent` is deprecated. Use `\Shopware\Core\Content\Product\SalesChannel\Review\Event\ProductReviewsLoadedEvent` instead.
* The hook `\Shopware\Storefront\Page\Product\Review\ProductReviewsWidgetLoadedHook` is deprecated. Use `\Shopware\Core\Content\Product\SalesChannel\Review\ProductReviewsWidgetLoadedHook` instead.
* The struct `\Shopware\Storefront\Page\Product\Review\ReviewLoaderResult` is deprecated. Use `\Shopware\Core\Content\Product\SalesChannel\Review\ProductReviewResult` instead.

## Native types for PHP class properties
A "deprecation" message was added to every PHP class property without a native type.
The native types will be added with Shopware 6.7.0.0.
If you extend classes with such properties, you will also need to add the type accordingly during the major update.

## New skip to content links
The "Skip to content" link for accessibility inside `@Storefront/storefront/base.html.twig` is now inside a separate include template `@Storefront/storefront/component/skip-to-content.html.twig`.
The new template also has additional links to skip directly to the search field and main navigation. The links can be enabled or disabled by passing boolean variables. By default, only "Skip to main content" is shown:

```twig
{% sw_include '@Storefront/storefront/component/skip-to-content.html.twig' with {
    skipToContent: true,
    skipToSearch: true,
    skipToMainNav: true
} %}
```

## Storefront product box accessibility: Replace duplicate links around the product image with stretched link in product name
**Affected template: `Resources/views/storefront/component/product/card/box-standard.html.twig`**

Currently, the link to the product detail page is always duplicated in the default product box because the image is wrapped with the same link.
This is not ideal for accessibility because the link is read twice when using a screen reader. Therefore, we want to remove the link around the product image that also points to the detail page.
To make the image still click-able the Bootstrap helper class `stretched-link` will be used on the product name link.

When the `ACESSIBILITY_TWEAKS` flag is active, the product card will no longer contain a link around the product image:
```diff
<div class="card product-box box-standard">
    <div class="card-body">
        <div class="product-image-wrapper">
-            <a href="https://shopware.local/Example-Product/SW-01931a101dcc725aa3affc0ff408ee31">
                <img src="https://shopware.local/media/a3/22/75/1731309077/Example-Product_%283%29.webp?ts=1731309077" alt="Example-Product">
-            </a>
        </div>

        <div class="product-info">
            <a href="https://shopware.local/Example-Product/SW-01931a101dcc725aa3affc0ff408ee31"
+               class="product-name stretched-link"> {# <------ stretched-link is used instead #}
                Example-Product
            </a>
        </div>
    </div>
</div>
```
## Required foreign key in mapping definition for many-to-many associations
For many-to-many associations it is necessary that the mapping definition contains the foreign key fields.
Until now there was a silent error triggered, which is now changed to a proper deprecation message. An exception will be thrown in the next major version.

## App servers: New In-App Purchases feature
In-App Purchases are a way to lock certain features behind a paywall within the same extension. 
This is useful for developers who want to offer a free version of their extension with limited features and a paid version with more features.

We've modified the requests to app servers to include a JWT token for all active In-App Purchases.
With GET requests, the `in-app-purchases` parameter was added.
With POST requests, the In-App-Purchases are part of the `source` object in the request body.

If you use the official Shopware [Symfony App bundle](https://github.com/shopware/app-bundle-symfony) or the [App PHP SDK](https://github.com/shopware/app-php-sdk), you're app server is already set to go.
If you use a custom signing mechanism, you need to adjust your app server to handle the new requests.
See an example implementation in PHP [here](https://github.com/shopware/app-php-sdk/blob/main/examples/index.php).

See the documentation for In-App Purchases [here](https://developer.shopware.com/docs/guides/plugins/apps/in-app-purchase/).

# 6.6.8.0
## Search server now provides OpenSearch/Elasticsearch shards and replicas

Previously we had an default configuration of three shards and three replicas. With 6.7 we removed this default configuration and now the search server is responsible for providing the correct configuration.
This allows that the indices automatically scale based on your nodes available in the cluster.

You can revert to the old behavior by setting the following configuration in your `config/packages/shopware.yml`:

```yaml
elasticsearch:
    index_settings:
        number_of_shards: 3
        number_of_replicas: 3
```
## Redis configuration

Now you can define multiple redis connections in the `config/packages/shopware.yaml` file under the `shopware` section:
```yaml
shopware:
    # ...
    redis:
        connections:
            connection_1:
                dsn: 'redis://host:port/database_index'
            connection_2:
                dsn: 'redis://host:port/database_index'
```
Connection names should reflect the actual connection purpose/type and be unique, for example `ephemeral`, `persistent`. Also they are used as a part of service names in the container, so they should follow the service naming conventions. After defining connections, you can reference them by name in configuration of different subsystems.

### Cache invalidation

Replace `shopware.cache.invalidation.delay_options.dsn` with `shopware.cache.invalidation.delay_options.connection` in the configuration files:

```yaml
shopware:
    # ...
    cache:
        invalidation:
            delay: 1
            delay_options:
                storage: redis
                # dsn: 'redis://host:port/database_index' # deprecated
                connection: 'connection_1' # new way
```

### Increment storage

Replace `shopware.increment.<increment_name>.config.url` with `shopware.increment.<increment_name>.config.connection` in the configuration files:

```yaml
shopware:
    # ...
    increment:
        increment_name:
            type: 'redis'
            config:
                # url: 'redis://host:port/database_index' # deprecated
                connection: 'connection_2' # new way
```

### Number ranges

Replace `shopware.number_range.config.dsn` with `shopware.number_range.config.connection` in the configuration files:

```yaml
shopware:
    # ...
    number_range:
        increment_storage: "redis"
        config:
            # dsn: 'redis://host:port/dbindex' # deprecated
            connection: 'connection_2' # new way
```

### Cart storage

Replace `cart.storage.config.dsn` with `cart.storage.config.connection` in the configuration files:

```yaml
shopware:
    # ...
    cart:
        storage:
            type: 'redis'
            config:
                #dsn: 'redis://host:port/dbindex' # deprecated
                connection: 'connection_2' # new way
```

### Custom services

If you have custom services that use redis connection, you have next options for the upgrade:

1. Inject `Shopware\Core\Framework\Adapter\Redis\RedisConnectionProvider` and use it to get the connection by name:

    ```xml
    <service id="MyCustomService">
        <argument type="service" id="Shopware\Core\Framework\Adapter\Redis\RedisConnectionProvider" />
        <argument>%myservice.redis_connection_name%</argument>
    </service>
    ```

    ```php
    class MyCustomService
    { 
        public function __construct (
            private RedisConnectionProvider $redisConnectionProvider,
            string $connectionName,
        ) { }

        public function doSomething()
        {
            if ($this->redisConnectionProvider->hasConnection($this->connectionName)) {
                $connection = $this->redisConnectionProvider->getConnection($this->connectionName);
                // use connection
            }
        }
    }
    ```

2. Use `Shopware\Core\Framework\Adapter\Redis\RedisConnectionProvider` as factory to define custom services:

    ```xml
    <service id="my.custom.redis_connection" class="Redis">
        <factory service="Shopware\Core\Framework\Adapter\Redis\RedisConnectionProvider" method="getConnection" />
        <argument>%myservice.redis_connection_name%</argument>
    </service>

    <service id="MyCustomService">
        <argument type="service" id="my.custom.redis_connection" />
    </service>
    ```

    ```php
    class MyCustomService
    { 
        public function __construct (
            private Redis $redisConnection,
        ) { }

        public function doSomething()
        {
            // use connection
        }
    }
    ```
    This approach is especially useful if you need multiple services to share the same connection.

3. Inject connection by name directly:
    ```xml
    <service id="MyCustomService">
        <argument type="service" id="shopware.redis.connection.connection_name" />
    </service>
    ```
   Be cautious with this approach—if you change the Redis connection names in your configuration, it will cause container build errors.

Please beware that redis connections with the **same DSNs** are shared over the system, so closing the connection in one service will affect all other services that use the same connection.
## "adminMenu" Vuex store moved to Pinia

The `adminMenu` store has been migrated from Vuex to Pinia. The store is now available as a Pinia store and can be accessed via `Shopware.Store.get('adminMenu')`.

### Before:
```js
Shopware.State.get('adminMenu');
```

### After:
```js
Shopware.Store.get('adminMenu');
```
If you use a TLS proxy in your setup, you can now start the hot reloading with https without setting certificate files.

**_Example .env file for a DDEV setup:_**
```
IPV4FIRST=1
APP_ENV=dev
ESLINT_DISABLE=true
HOST=0.0.0.0
STOREFRONT_ASSETS_PORT=9999
STOREFRONT_PROXY_PORT=9998
APP_URL=https://shopware-ddev-new.ddev.site/
PROXY_URL=https://shopware-ddev-new.ddev.site:9998/
```
## Deprecation of obsolete method in DefinitionValidator
The method `\Shopware\Core\Framework\DataAbstractionLayer\DefinitionValidator::getNotices` is deprecated and will be removed without replacement.
It always returns an empty array, so it has no real purpose.

# 6.6.7.0
## Shortened filenames with hashes for async JS built files
When building the Storefront JS-files for production using `composer run build:js:storefront`, the async bundle filenames no longer contain the filepath.
Instead, only the filename is used with a chunkhash / dynamic version number. This also helps to identify which files have changed after build. Similar to the main entry file like e.g. `cms-extensions.js?1720776107`.

**JS Filename before change in dist:**
```
└── custom/apps/
    └── ExampleCmsExtensions/src/Resources/app/storefront/dist/storefront/js/
        └── cms-extensions/           
            ├── cms-extensions.js <-- The main entry pint JS-bundle
            └── custom_plugins_CmsExtensions_src_Resources_app_storefront_src_cms-extensions-quickview.js  <-- Complete path in filename
```

**JS Filename after change in dist:**
```
└── custom/apps/
    └── ExampleCmsExtensions/src/Resources/app/storefront/dist/storefront/js/
        └── cms-extensions/           
            ├── cms-extensions.js <-- The main entry pint JS-bundle
            └── cms-extensions-quickview.plugin.423fc1.js <-- Filename and chunkhash
```
## Persistent mode for `focusHandler`
The `window.focusHandler` now supports a persistent mode that can be used in case the current focus is lost after a page reload.
When using methods `saveFocusStatePersistent` and `resumeFocusStatePersistent` the focus element will be saved inside the `sessionStorage` instead of the window object / memory.

The persistent mode requires a key name for the `sessionStorage` as well as a unique selector as string. It is not possible to save element references into the `sessionStorage`.
The unique selector will be used to find the DOM element during `resumeFocusStatePersistent` and re-focus it.
```js
// Save the current focus state
window.focusHandler.saveFocusStatePersistent('special-form', '#unique-id-on-this-page');

// Something happens and the page reloads
window.location.reload();

// Resume the focus state for the key `special-form`. The unique selector will be retrieved from the `sessionStorage` 
window.focusHandler.resumeFocusStatePersistent('special-form');
```

By default, the storage keys are prefixed with `sw-last-focus`. The above example will save the following to the `sessionStorage`:

| key                          | value                     |
|------------------------------|---------------------------|
| `sw-last-focus-special-form` | `#unique-id-on-this-page` |

## Automatic focus for `FormAutoSubmitPlugin`
The `FormAutoSubmitPlugin` can now try to re-focus elements after AJAX submits or full page reloads using the `window.focusHandler`.
This works automatically for all form input elements inside an auto submit form that have a `[data-focus-id]` attribute that is unique.

The automatic focus is activated by default and be modified by the new JS-plugin options:

```js
export default class FormAutoSubmitPlugin extends Plugin {
    static options = {
        autoFocus: true,
        focusHandlerKey: 'form-auto-submit'
    }
}
```

```diff
<form action="/example/action" data-form-auto-submit="true">
    <!-- FormAutoSubmitPlugin will try to restore previous focus on all elements with da focus-id -->
    <input 
        class="form-control"
+        data-focus-id="unique-id"
    >
</form>
```
## Improved formating behaviour of the text editor
The text editor in the administration was changed to produce paragraph `<p>` elements for new lines instead of `<div>` elements. This leads to a more consistent text formatting. You can still create `<div>` elements on purpose via using the code editor.

In addition, loose text nodes will be wrapped in a paragraph `<p>` element on initializing a new line via the enter key. In the past it could happen that when starting to write in an empty text editor, that text is not wrapped in a proper section element. Now this is automatically fixed when you add a first new line to your text. From then on everything is wrapped in paragraph elements and every new line will also create a new paragraph instead of `<div>` elements.
## Change Storefront language and currency dropdown items to buttons
The "top-bar" dropdown items inside `views/storefront/layout/header/top-bar.html.twig` will use `<button>` elements instead of hidden `<input type="radio">` when the `ACCESSIBILITY_TWEAKS` flag is `1`.
This will improve the keyboard navigation because the user can navigate through all options first before submitting the form.

Currently, every radio input change results in a form submit and thus in a page reload. Using button elements is also more aligned with Bootstraps dropdown HTML structure: [Bootstrap dropdown documentation](https://getbootstrap.com/docs/5.3/components/dropdowns/#menu-items)
## Change Storefront order items and cart line-items from `<div>` to `<ul>` and `<li>`:
* We want to change several list views that are currently using generic `<div>` elements to proper `<ul>` and `<li>`. This will not only improve the semantics but also the screen reader accessibility. 
* To avoid breaking changes in the HTML and the styling, the change to `<ul>` and `<li>` is done behind the `ACCESSIBILITY_TWEAKS` feature flag.
* With the next major version the `<ul>` and `<li>` will become the default. In the meantime, the `<div>` elements get `role="list"` and `role="listitem"`.
* All `<ul>` will get a Bootstrap `list-unstyled` class to avoid the list bullet points and have the same appearance as `<div>`.
* The general HTML structure and Twig blocks remain the same.

### Affected templates:
* Account order overview
    * `src/Storefront/Resources/views/storefront/page/account/order-history/index.html.twig`
    * `src/Storefront/Resources/views/storefront/page/account/order-history/order-detail-document-item.html.twig`
    * `src/Storefront/Resources/views/storefront/page/account/order-history/order-detail-document.html.twig`
* Cart table header (Root element changed to `<li>`)
    * `src/Storefront/Resources/views/storefront/component/checkout/cart-header.html.twig`
* Line-items wrapper (List wrapper element changed to `<ul>`)
    * `src/Storefront/Resources/views/storefront/page/checkout/cart/index.html.twig`
    * `src/Storefront/Resources/views/storefront/page/checkout/confirm/index.html.twig`
    * `src/Storefront/Resources/views/storefront/page/checkout/finish/index.html.twig`
    * `src/Storefront/Resources/views/storefront/page/checkout/address/index.html.twig`
    * `src/Storefront/Resources/views/storefront/page/account/order-history/order-detail-list.html.twig`
    * `src/Storefront/Resources/views/storefront/component/checkout/offcanvas-cart.html.twig`
* Line-items (Root element changed to `<li>`)
    * `src/Storefront/Resources/views/storefront/component/line-item/type/product.html.twig`
    * `src/Storefront/Resources/views/storefront/component/line-item/type/discount.html.twig`
    * `src/Storefront/Resources/views/storefront/component/line-item/type/generic.html.twig`
    * `src/Storefront/Resources/views/storefront/component/line-item/type/container.html.twig`
## Correct order of app-cms blocks via xml files
The order of app CMS blocks is now correctly applied when using XML files to define the blocks. This is achieved by using a position attribute in the JSON generated from the XML file, which reflects the order of the CMS slots within the file. Since it's not possible to determine the correct order of CMS blocks that have already been loaded into the database, this change will only affect newly loaded blocks.

To ensure the correct order is applied, you should consider to reinstall apps that provide app CMS blocks.

# 6.6.6.0
## Rework Storefront pagination to use anchor links and improve accessibility
We want to change the Storefront pagination component (`Resources/views/storefront/component/pagination.html.twig`) to use anchor links `<a href="#"></a>` instead of radio inputs with styled labels.
This will improve the accessibility and keyboard operation, as well as the HTML semantics. The pagination with anchor links will also be more aligned with the Bootstrap pagination semantics.

To avoid breaking changes, the updated pagination can only be activated by setting the `ACCESSIBILITY_TWEAKS` feature flag to `1`. With the next major version `v6.7.0` the updated pagination will become the default.
The pagination will also be more simple because the hidden radio input and the label are no longer there. We only use a single anchor link element instead.

### Pagination item markup before:
```html
<li class="page-item">
  <input type="radio" name="p" id="p2" value="2" class="d-none" title="pagination">
  <label class="page-link" for="p2">2</label>
</li>
```

### Pagination item markup after:
```html
<li class="page-item">
    <a href="?p=2" class="page-link" data-page="2" data-focus-id="2">2</a>
</li>
```

## New `ariaHidden` option for `sw_icon`
When rendering an icon using the `{% sw_icon %}` function, it is now possible to pass an `ariaHidden` option to hide the icon from the screen reader.
This can be helpful if the icon is only decorative or the purpose is already explained in a parent elements aria-label or title.

```diff
{# Twig implementation #}
<a href="#" aria-label="Go to first page">
-    {% sw_icon 'arrow-medium-double-left' style { pack: 'solid' } %}
+    {% sw_icon 'arrow-medium-double-left' style { pack: 'solid', ariaHidden: true } %}
</a>

<!-- HTML result -->
<a href="#" aria-label="Go to first page">
-    <span class="icon icon-arrow-medium-double-left icon-fluid"><svg></svg></span>
+    <span aria-hidden="true" class="icon icon-arrow-medium-double-left icon-fluid"><svg></svg></span>
</a>
```
## Accessibility improvements for listing filters
### Additional aria-label and alt texts for screen readers to explain listing filter components
The listing filter components (dropdown buttons, e.g. "Manufacturer") now support additional `ariaLabel` parameters.
Those will render an `aria-label` attribute for screen readers that explain the filter buttons purpose without altering the appearance of the filter toggle button.

For example: The `filter-multi-select.html.twig` template now supports an additional `ariaLabel` parameter.
```diff
{% sw_include '@Storefront/storefront/component/listing/filter/filter-multi-select.html.twig' with {
    elements: manufacturersSorted,
    sidebar: sidebar,
    name: 'manufacturer',
    displayName: 'Manufacturer',
+    ariaLabel: 'Filter by manufacturer'
} %}
```

This will render the `aria-label` on the filter toggle button. When focusing the button, the screen reader will read "Filter by manufacturer" instead of just "Manufacturer".
```diff
<button 
    class="filter-panel-item-toggle btn"
+    aria-label="Filter by manufacturer"
    aria-expanded="false"
    data-bs-toggle="dropdown"
    data-boundary="viewport"
    aria-haspopup="true">
    Manufacturer
</button>    
```

### Aria-live updates for listing filters
When a listing filter is applied and the products results are updated, the screen reader should announce that the product results have been changed.
To achieve this, the filter panel template `Resources/views/storefront/component/listing/filter-panel.html.twig` now has an `aria-live` region. 
The live region will be updated when a listing filter is applied or removed. Whenever a live region is updated, it will be announced by the screen reader.

The live region is not visible for the user and can be switched on or off via the include parameter `ariaLiveUpdates` of `Resources/views/storefront/component/listing/filter-panel.html.twig`.

```diff
{% sw_include '@Storefront/storefront/component/listing/filter-panel.html.twig' with {
    listing: listing,
    sidebar: sidebar,
+    ariaLiveUpdates: true
} %}
```

```diff
<div class="filter-panel">
    <div class="filter-panel-items-container" role="list" aria-label="Filter">
        <!-- Available filters are shown here. -->
    </div>

    <div class="filter-panel-active-container">
        <!-- Active filters are shown here. -->
    </div>

+    <!-- Aria live region to tell the screen reader how many product results are shown after a filter was selected or deselected. -->
+    <div class="filter-panel-aria-live visually-hidden" aria-live="polite" aria-atomic="true">
+        <!-- The live region content is generated by the `ListingPlugin` -->
+        <!-- For example: "Showing 6 products" -->
+    </div>
</div>
```
## Storefront focus handler helper
To improve accessibility while navigating via keyboard you can use the `window.focusHandler` to save and resume focus states. This is helpful if an element opens new content in a modal or offcanvas menu. While the modal is open the users should navigate through the content of the modal. If the modal closes the focus state should resume to the element which opened the modal, so users can continue at the position where they left. The default Shopware plugins `ajax-modal`, `offcanvas` and `address-editor` will use this behaviour by default. If you want to implement this behaviour in your own plugin, you can use the `saveFocusState` and `resumeFocusState` methods. Have a look at the class `Resources/app/storefront/src/helper/focus-handler.helper.js` to see additional options.
## New `DomAccessHelper` methods to find focusable elements

The `DomAccessHelper` now supports new methods to find DOM elements that can have keyboard focus.
Optionally, an element can be provided as a parameter to only search within this given element. By default, the document body will be used.

```js
import DomAccess from 'src/helper/dom-access.helper';

// Find all focusable elements
DomAccess.getFocusableElements();

// Return the first focusable element
DomAccess.getFirstFocusableElement();

// Return the last focusable element
DomAccess.getLastFocusableElement();

// Only search for focus-able elements inside the given DOM node
const element = document.querySelector('.special-modal-container');
DomAccess.getFirstFocusableElement(element);
```
## Native typehints of properties
The properties of the following classes will be typed natively in v6.7.0.0.
If you have extended from those classes and overwritten the properties, you can already set the correct type.
* `\Shopware\Core\Content\Media\Aggregate\MediaThumbnailSize\MediaThumbnailSizeEntity`
* `\Shopware\Core\Framework\DataAbstractionLayer\Entity`
* `\Shopware\Core\Framework\DataAbstractionLayer\Field\FkField`
* `\Shopware\Core\Framework\DataAbstractionLayer\Field\ReferenceVersionField`
## Deprecated exceptions
The following exceptions were deprecated and will be removed in v6.7.0.0.
You can already catch the replacement exceptions additionally to the deprecated ones.
* `\Shopware\Core\Framework\Api\Exception\UnsupportedEncoderInputException`. Also catch `\Shopware\Core\Framework\Api\ApiException`.
* `\Shopware\Core\Framework\DataAbstractionLayer\Exception\CanNotFindParentStorageFieldException`. Also catch `\Shopware\Core\Framework\DataAbstractionLayer\DataAbstractionLayerException`.
* `\Shopware\Core\Framework\DataAbstractionLayer\Exception\InternalFieldAccessNotAllowedException`. Also catch `\Shopware\Core\Framework\DataAbstractionLayer\DataAbstractionLayerException`.
* `\Shopware\Core\Framework\DataAbstractionLayer\Exception\InvalidParentAssociationException`. Also catch `\Shopware\Core\Framework\DataAbstractionLayer\DataAbstractionLayerException`.
* `\Shopware\Core\Framework\DataAbstractionLayer\Exception\ParentFieldNotFoundException`. Also catch `\Shopware\Core\Framework\DataAbstractionLayer\DataAbstractionLayerException`.
* `\Shopware\Core\Framework\DataAbstractionLayer\Exception\PrimaryKeyNotProvidedException`. Also catch `\Shopware\Core\Framework\DataAbstractionLayer\DataAbstractionLayerException`.
## Deprecated methods
The following methods of the `\Shopware\Core\Framework\DataAbstractionLayer\Entity` class were deprecated and will throw different exceptions in v6.7.0.0.
You can already catch the replacement exceptions additionally to the deprecated ones.
* `\Shopware\Core\Framework\DataAbstractionLayer\Entity::__get`. Also catch `\Shopware\Core\Framework\DataAbstractionLayer\DataAbstractionLayerException` in addition to `\Shopware\Core\Framework\DataAbstractionLayer\Exception\InternalFieldAccessNotAllowedException`.
* `\Shopware\Core\Framework\DataAbstractionLayer\Entity::get`. Also catch `\Shopware\Core\Framework\DataAbstractionLayer\DataAbstractionLayerException` in addition to `\Shopware\Core\Framework\DataAbstractionLayer\Exception\InternalFieldAccessNotAllowedException`.
* `\Shopware\Core\Framework\DataAbstractionLayer\Entity::checkIfPropertyAccessIsAllowed`. Also catch `\Shopware\Core\Framework\DataAbstractionLayer\DataAbstractionLayerException` in addition to `\Shopware\Core\Framework\DataAbstractionLayer\Exception\InternalFieldAccessNotAllowedException`.
* `\Shopware\Core\Framework\DataAbstractionLayer\Entity::get`. Also catch `\Shopware\Core\Framework\DataAbstractionLayer\Exception\PropertyNotFoundException` in addition to `\InvalidArgumentException`.

# 6.6.5.0
## Elasticsearch with special chars
* To apply searching by Elasticsearch with special chars, you would need to update your ES index mapping by running: `es:index`

## New parameter `shopware.search.preserved_chars` when tokenizing
* By default, the parameter `shopware.search.preserved_chars` is set to `['-', '_', '+', '.', '@']`. You can add or remove special characters to this parameter by override it in `shopware.yaml` to allow them when tokenizing string.
## Add skip to content link to improve a11y
The `base.html.twig` template now has a new block `base_body_skip_to_content` directly after the opening `<body>` tag.
The new block holds a link that allows to skip the focus directly to the `<main>` content element.
This improves a11y because a keyboard or screen-reader user does not have to "skip" through all elements of the page (header, top-bar) and can jump straight to the main content if wanted.
The "skip to main content" link will not be visible, unless it has focus.

```html
<body>
    <div class="skip-to-content bg-primary-subtle text-primary-emphasis visually-hidden-focusable overflow-hidden">
        <div class="container d-flex justify-content-center">
            <a href="#content-main" class="skip-to-content-link d-inline-flex text-decoration-underline m-1 p-2 fw-bold gap-2">
                Skip to main content
            </a>
        </div>
    </div>

    <main id="content-main">
        <!-- Main content... -->
    </main>
```

# 6.6.4.0
Thumbnail handling performance can now be improved by using remote thumbnails.

## Remote Thumbnail Configuration

To use remote thumbnails, you need to adjust the following parameters in your `shopware.yaml`:

1. `shopware.media.remote_thumbnails.enable`: Set this parameter to `true` to enable the use of remote thumbnails.

2. `shopware.media.remote_thumbnails.pattern`: This parameter defines the URL pattern for your remote thumbnails. Replace it with your actual URL pattern.
   
This pattern supports the following variables:
   *  `mediaUrl`: The base URL of the media file.
   *  `mediaPath`: The path of the media file relative to the mediaUrl.
   *  `width`: The width of the thumbnail.
   *  `height`: The height of the thumbnail.

For example, consider a scenario where you want to generate a thumbnail with a width of 80px.
With the pattern set as `{mediaUrl}/{mediaPath}?width={width}`, the resulting URL would be `https://yourshop.example/abc/123/456.jpg?width=80`.
## Added new `ariaLive` option to Storefront sliders
By default, all Storefront sliders/carousels (`GallerySliderPlugin`, `BaseSliderPlugin`, `ProductSliderPlugin`) are adding an `aria-live` region to announce slider updates to a screen reader.

In some cases this can worsen the accessibility, for example when a slider uses "auto slide" functionality. With automatic slide the slider updates can disturb the reading of other contents on the page.

You can now deactivate the `aria-live` region on the slider plugins with the new option `ariaLive` (default: `true`).

Example for `GallerySliderPlugin` (Also works for `BaseSliderPlugin` and `ProductSliderPlugin`)
```diff
{% set gallerySliderOptions = {
    slider: {
+        ariaLive: false,
        autoHeight: false,
    },
    thumbnailSlider: {
+        ariaLive: false,
        controls: true,
        responsive: {}
    }
} %}

<div data-gallery-slider-options='{{ gallerySliderOptions|json_encode }}'>
```

When `ariaLive` is `false` it will omit the `aria-live` region in the generated `tiny-slider` HTML code:
```diff
<div class="tns-outer" id="tns3-ow">
-    <div class="tns-liveregion tns-visually-hidden" aria-live="polite" aria-atomic="true">
-        slide <span class="current">2</span> of 6
-    </div>
    <div id="tns3-mw" class="tns-ovh">
        <!-- Slider contents -->
    </div>
</div>
```
## Rating widget alternative text for improved accessibility
The twig template that renders the rating stars (`Resources/views/storefront/component/review/rating.html.twig`) now supports an alternative text for screen readers:
```diff
{% sw_include '@Storefront/storefront/component/review/rating.html.twig' with {
    points: points,
+    altText: 'translation.key.example'|trans({ '%points%': points, '%maxPoints%': maxPoints })|sw_sanitize,
} %}
```

Instead of reading the rating star icons as "graphic", the screen reader will read the alternative text, e.g. `Average rating of 3 out of 5 stars`.
By default, the `rating.html.twig` template will always use the alternative text with translation `detail.reviewAvgRatingAltText`, unless it is overwritten by the `altText` include parameter.

The `rating.html.twig` template will now render the alternative text as shown below:
```diff
<div class="product-review-rating">               
    <!-- Review star SVGs are now hidden for the screen reader, alt text is read instead. -->
    <div class="product-review-point" aria-hidden="true"></div>
    <div class="product-review-point" aria-hidden="true"></div>
    <div class="product-review-point" aria-hidden="true"></div>
    <div class="product-review-point" aria-hidden="true"></div>
    <div class="product-review-point" aria-hidden="true"></div>
+    <p class="product-review-rating-alt-text visually-hidden">
+        Average rating of 4 out of 5 stars
+    </p>
</div>
```
## Messenger routing overwrite

The overwriting logic for the messenger routing has been moved from `framework.messenger.routing` to `shopware.messenger.routing_overwrite`. The old config key is still supported but deprecated and will be removed in the next major release.
The new config key considers also the `instanceof` logic of the default symfony behavior.

We have made these changes for various reasons:
1) In the old logic, the `instanceof` logic of Symfony was not taken into account. This means that only exact matches of the class were overwritten.
2) It is not possible to simply backport this in the same config, as not only the project overwrites are in the old config, but also the standard Shopware routing configs.

```yaml

#before
framework:
    messenger:
        routing:
            Shopware\Core\Framework\DataAbstractionLayer\Indexing\EntityIndexingMessage: entity_indexing

#after
shopware:
    messenger:
        routing_overwrite:
            Shopware\Core\Framework\DataAbstractionLayer\Indexing\EntityIndexingMessage: entity_indexing

```
## Separate plugin generation scaffolding commands

Instead of always generating a complete plugin scaffold with `bin/console plugin:create`, you can now generate specific parts of the plugin scaffold e.g. `bin/console make:plugin:config` to generate only the symfony config part of the plugin scaffold.
## Transition Vuex states into Pinia Stores
1. In Pinia, there are no `mutations`. Place every mutation under `actions`.
2. `state` needs to be an arrow function returning an object: `state: () => ({})`.
3. `actions` and `getters` no longer need to use the `state` as an argument. They can access everything with correct type support via `this`.
4. Use `Shopware.Store.register` instead of `Shopware.State.registerModule`.
5. Use `Shopware.Store.unregister` instead of `Shopware.State.unregisterModule`.
6. Use `Shopware.Store.list` instead of `Shopware.State.list`.
7. Use `Shopware.Store.get` instead of `Shopware.State.get`.

# 6.6.3.0
## Configure Redis for cart storage
When you are using Redis for cart storage, you should add the following config inside `shopware.yaml`:
```yaml
    cart:
        compress: false
        expire_days: 120
        storage:
            type: "redis"
            config:
                dsn: 'redis://localhost'
```
## Configure Redis for number range storage
When you are using Redis for number range storage, you should add the following config inside `shopware.yaml`:
```yaml
    number_range:
        increment_storage: "redis"
        config:
            dsn: 'redis://localhost'
```

# 6.6.1.0
## Accessibility: No empty nav element in top-bar
There will be no empty `<nav>` tag anymore on single language and single currency shops so accessibility tools will not be confused by it.

On shops with only one language and one currency the blocks `layout_header_top_bar_language` or `layout_header_top_bar_currency` will not be rendered anymore.

If you still need to add content to the `<div class="top-bar d-none d-lg-block">` you should extend the new block `layout_header_top_bar_inner`.

If you add `<nav>` tags always ensure they are only rendered if they contain navigation links.
## EntityIndexingMessage::isFullIndexing

We added a new `isFullIndexing` flag to the `EntityIndexingMessage` class. 
When entities will be updated, the flag is marked with `false`. It will be marked with `true` via `bin/console dal:refresh:index` or other APIs which triggers a full re-index.
This enhancement allows developers to specify whether a full re-indexing is required or just a single entity was updated inside the stack

```
<?php

class Indexer extends ...
{
    public function index(EntityIndexingMessage $message) 
    { 
        $message->isFullIndexing()
    }
}
```

We also added a new optional (hidden) parameter `bool $recursive` to `TreeUpdater::batchUpdate`. This parameter will be introduced in the next major version. 
If you extend the `TreeUpdater` class, you should properly handle the new parameter in your custom implementation.
Within the 6.6 release, the parameter is optional and defaults to `true`. It will be changed to `false` in the next major version.
```php
<?php

class CustomTreeUpdater extends TreeUpdater
{
    public function batchUpdate(array $updateIds, string $entity, Context $context/*, bool $recursive = false*/): void
    {
        $recursive = func_get_arg(3) ?? true;
        
        parent::batchUpdate($updateIds, $entity, $context, $recursive);
    }
}
```
## HMAC JWT keys

Usage of normal RSA JWT keys is deprecated. And will be removed with Shopware 6.7.0.0. Please use the new HMAC JWT keys instead using configuration:

```yaml
shopware:
    api:
        jwt_key:
              use_app_secret: true
```

Also make sure that the `APP_SECRET` environment variable is at least 32 characters long. You can use the `bin/console system:generate-app-secret` command to generate an valid secret.

Changing this will invalidate all existing tokens and require a re-login for all users and all integrations.
## Local app manifest

In app's development, it's usually necessary to have a different configuration or urls in the manifest file. For e.g, on the production app, the manifest file should have the production endpoints and the setup's secret should not be set, in development, we can set a secret and use local environment endpoints.

This change allows you to create a local manifest file that overriding the real's manifest.

All you have to do is create a `manifest.local.xml` and place it in the root of the app's directory. 

_Hint: The local manifest file should be ignored on the actual app's repository_
## Configure Fastly as media proxy
When you are using Fastly as a media proxy, you should configure this inside shopware, to make sure that the media urls are purged correctly.
Enabling Fastly as a media proxy can be done by setting the `shopware.cdn.fastly` configuration (for example with an env variable):

```yaml
shopware:
    fastly:
        api_key: '%env(FASTLY_API_KEY)%'
```
## Sync option for CLI theme commands

The `theme:compile` and `theme:change ` command now accept `--sync` option to compile themes synchronously. The `--sync` option is useful for CI/CD pipelines, when at runtime themes should be compiled async, but during the build process you want sync generation.

# 6.6.0.0

## Configure Fastly as media proxy
When you are using Fastly as a media proxy, you should configure this inside shopware, to make sure that the media urls are purged correctly.
Enabling Fastly as a media proxy can be done by setting the `shopware.cdn.fastly` configuration (for example with an env variable):

```yaml
shopware:
    fastly:
        api_key: '%env(FASTLY_API_KEY)%'
```

# New System Requirements and Configuration Changes
## New System requirements
We upgraded some system requirements according to this [proposal](https://github.com/shopware/shopware/discussions/3359).
### Min PHP 8.2
We upgraded the minimum PHP version to 8.2.
### Min MariaDB 10.11
We upgraded the minimum MariaDB version to 10.11, the minimum MySQL version is still 8.0.
### Min Redis 7.0
We upgraded the minimum Redis version to 7.0.
### Min Elasticsearch 7.10
We upgraded the minimum Elasticsearch version to 7.10, there are no changes for OpenSearch compatibility, so there still all versions are supported.
## Node.js version change

To build the javascript for the administration or storefront it's now mandatory that your node version is the current LTS version `20` (`Iron`).
If you use `devenv` or `nvm`, you need to update your session as our configuration files are configured to use the correct version.
Otherwise, you need to update your node installation manually.

## Configure queue workers to consume low_priority queue
Explicitly configure your workers to additionally consume messages from the `low_priority` queue.
Up to 6.6 the `low_priority` queue is automatically added to the workers, even if not specified explicitly.

Before:
```bash
php bin/console messenger:consume async
```

After:
```bash
php bin/console messenger:consume async low_priority
```
**Note:** This is not required if you use the [`admin_worker`](https://developer.shopware.com/docs/guides/plugins/plugins/framework/message-queue/add-message-handler.html#the-admin-worker), however the admin worker should only be used in local dev or test environments, and never be used in production or production-like environments.

## Configure another transport for the "low priority" queue
The transport defaults to use Doctrine. You can use the `MESSENGER_TRANSPORT_LOW_PRIORITY_DSN` environment variable to change it.

Before:
```dotenv
MESSENGER_TRANSPORT_DSN="doctrine://default?auto_setup=false"
```

After:
```dotenv
MESSENGER_TRANSPORT_DSN="doctrine://default?auto_setup=false"
MESSENGER_TRANSPORT_LOW_PRIORITY_DSN="doctrine://default?auto_setup=false&queue_name=low_priority"
```

For further details on transports with different priorities, please refer to the Symfony Docs: https://symfony.com/doc/current/messenger.html#prioritized-transports

## Removed dependencies to storage adapters
Removed composer packages `league/flysystem-async-aws-s3` and `league/flysystem-google-cloud-storage`. If your installation uses the AWS S3 or Google Cloud storage adapters, you need to install the corresponding packages separately.

Run the following commands to install the packages:
```bash
composer require league/flysystem-async-aws-s3
composer require league/flysystem-google-cloud-storage
```

## Removal of CacheInvalidatorStorage

The delayed cache invalidation storage was configured to use the default cache implementation until 6.6.
As this is not ideal for multi-server usage, we deprecated it in 6.5 and removed it now.
Delaying of cache invalidations now requires a Redis instance to be configured.

```yaml
shopware:
    cache:
        invalidation:
            delay_options:
                storage: redis
                dsn: 'redis://localhost'
```

Since 6.6.10.0 we also have a MySQL implementation available: `\Shopware\Core\Framework\Adapter\Cache\InvalidatorStorage\MySQLInvalidatorStorage`. Use it via `mysql`

# General Core Breaking Changes

## Symfony 7 upgrade
We upgraded to symfony 7, for details check out symfony's [upgrade guide](https://github.com/symfony/symfony/blob/7.0/UPGRADE-7.0.md)

## Cache rework preparation
With 6.6 we are marking a lot of HTTP Cache and Reverse Proxy classes as @internal and move them to the core.
We are preparing a bigger cache rework in the next releases. The cache rework will be done within the v6.6 version lane and and will be released with 6.7.0 major version.
The cache rework will be a breaking change and will be announced in the changelog of 6.7.0. We will provide a migration guide for the cache rework, so that you can prepare your project for the cache rework.

You can find more details about the cache rework in the [shopware/shopware discussions](https://github.com/shopware/shopware/discussions/3299)

Since the cache is a critical component for systems, we have taken the liberty of marking almost all classes as @internal for the time being. However, we have left the important events and interfaces public so that you can prepare your systems for the changes now.
Even though there were a lot of deprecations in this release, 99% of them involved moving the classes to the core domain.

But there is one big change that affects each project and nearly all repositories outside which are using PHPStan.

### Kernel bootstrapping
We had to refactor the Kernel bootstrapping and the Kernel itself.
When you forked our production template, or you boot the kernel somewhere by your own, you have to change the bootstrapping as follows:

```php

#### Before #####

$kernel = new Kernel(
    environment: $appEnv, 
    debug: $debug, 
    pluginLoader: $pluginLoader
);

#### After #####

$kernel = KernelFactory::create(
    environment: $appEnv,
    debug: $debug,
    classLoader: $classLoader,
    pluginLoader: $pluginLoader
);


### In case of static code analysis

KernelFactory::$kernelClass = StaticAnalyzeKernel::class;

/** @var StaticAnalyzeKernel $kernel */
$kernel = KernelFactory::create(
    environment: 'phpstan',
    debug: true,
    classLoader: $classLoader,
    pluginLoader: $pluginLoader
);

```

### Session access in PHPUnit tests
The way how you can access the session in unit test has changed.
The session is no more accessible via the request/response.
You have to use the `session.factory` service to access it or use the `SessionTestBehaviour` for a shortcut

```php
##### Before

$this->request(....);

$session = $this->getBrowser()->getRequest()->getSession();

##### After

use Shopware\Core\Framework\Test\TestCaseBase\SessionTestBehaviour;

$this->request(....);

// shortcut via trait 
$this->getSession();

// code behind the shortcut
$this->getContainer()->get('session.factory')->getSession();

```

### Manipulate the HTTP cache
Since we are moving the cache to the core, you have to change the way you can manipulate the HTTP cache.

1) In case you decorated or replaced the `src/Storefront/Framework/Cache/HttpCacheKeyGenerator.php` class, this will not be possible anymore in the upcoming release. You should use the HTTP cache events.
2) You used one of the HTTP cache events --> They will be moved to the core, so you have to adapt the namespace+name of the event class. The signature is also not 100% the same, so please check the new event classes (public properties, etc.)

```php

#### Before

<?php

namespace Foo;

use Shopware\Storefront\Framework\Cache\Event\HttpCacheGenerateKeyEvent;
use Shopware\Storefront\Framework\Cache\Event\HttpCacheHitEvent;
use Shopware\Storefront\Framework\Cache\Event\HttpCacheItemWrittenEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class Subscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents()
    {
        return [
            HttpCacheHitEvent::class => 'onHit',
            HttpCacheGenerateKeyEvent::class => 'onKey',
            HttpCacheItemWrittenEvent::class => 'onWrite',
        ];
    }
}

#### After
<?php

namespace Foo;

use Shopware\Core\Framework\Adapter\Cache\Event\HttpCacheHitEvent;
use Shopware\Core\Framework\Adapter\Cache\Event\HttpCacheKeyEvent;
use Shopware\Core\Framework\Adapter\Cache\Event\HttpCacheStoreEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class Subscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents()
    {
        return [
            HttpCacheHitEvent::class => 'onHit',
            HttpCacheKeyEvent::class => 'onKey',
            HttpCacheStoreEvent::class => 'onWrite',
        ];
    }
}



```

### Own reverse proxy gateway
If you implement an own reverse proxy gateway, you have to change the namespace of the gateway and the event.

```php
#### Before

class RedisReverseProxyGateway extends \Shopware\Storefront\Framework\Cache\ReverseProxy\AbstractReverseProxyGateway
{
    // ...
}


#### After

class RedisReverseProxyGateway extends \Shopware\Core\Framework\Adapter\Cache\ReverseProxy\AbstractReverseProxyGateway
{
    // ...
}
```

### HTTP cache warmer

We deprecated all HTTP cache warmer, because they will not be usable with the new HTTP kernel anymore.
They are also not suitable for the new cache rework or for systems which have a reverse proxy or a load balancer in front of the Shopware system.
Therefore, we marked them as deprecated and will remove them in the next major version.
You should use a real website crawler to warm up your desired sites instead, which is much more suitable and realistic for your system.

## New stock handling implementation is now the default

The `product.stock` field is now the primary source for real time product stock values. However, `product.availableStock` is a direct mirror of `product.stock` and is updated whenever `product.stock` is updated via the DAL.

A database migration `\Shopware\Core\Migration\V6_6\Migration1691662140MigrateAvailableStock` takes care of copying the `available_stock` field to the `stock` field.

### New configuration values

* `stock.enable_stock_management` - Default `true`. This can be used to completely disable Shopware's stock handling. If disabled, product stock will be not be updated as orders are created and transitioned through the various states.

### Removed `\Shopware\Core\Content\Product\DataAbstractionLayer\StockUpdater`

The listener was replaced with a new listener `\Shopware\Core\Content\Product\Stock\OrderStockSubscriber` which handles subscribing to the various order events and interfaces with the stock storage `\Shopware\Core\Content\Product\Stock\AbstractStockStorage` to write the stock alterations.

### Removed `\Shopware\Core\Content\Product\SalesChannel\Detail\AbstractAvailableCombinationLoader::load()` && `\Shopware\Core\Content\Product\SalesChannel\Detail\AvailableCombinationLoader::load()`

These methods are removed and superseded by `loadCombinations` which has a different method signature.

From:

```php
public function load(string $productId, Context $context, string $salesChannelId)
```

To:

```php
public function loadCombinations(string $productId, SalesChannelContext $salesChannelContext): AvailableCombinationResult
```

The `loadCombinations` method has been made abstract so it must be implemented. The `SalesChannelContext` instance, contains the data which was previously in the defined on the `load` method.

`$salesChannelId` can be replaced with `$salesChannelContext->getSalesChannelId()`.

`$context` can be replaced with `$salesChannelContext->getContext()`.

### Writing to `product.availableStock` field is now not possible

The field is write protected. Use the `product.stock` to write new stock levels.

### Reading product stock

The `product.stock` should be used to read the current stock level. When building new extensions which need to query the stock of a product, use this field. Not the `product.availableStock` field.

### Removed `\Shopware\Core\Framework\DataAbstractionLayer\Event\BeforeDeleteEvent`

It is replaced by `\Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWriteEvent` with the same API.

You should use `\Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWriteEvent` instead, only the class name changed.

## Removal of `MessageSubscriberInterface` for `ScheduledTaskHandler`
The method `getHandledMessages()` in abstract class `\Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler` was removed, please use the `#[AsMessageHandler]` attribute instead.

Before:
```php
class MyScheduledTaskHandler extends ScheduledTaskHandler
{
    public static function getHandledMessages(): iterable
    {
        return [MyMessage::class];
    }
    
    public function run(): void
    {
        // ...
    }
}
```

After:
```php
#[AsMessageHandler(handles: MyMessage::class)]
class MyScheduledTaskHandler extends ScheduledTaskHandler
{
    public function run(): void
    {
        // ...
    }
}
```

**Note:** Please make sure that your MessageHandlers are already tagged with `messenger.message_handler` in the services.xml file.

# General Administration Breaking Changes

## Vue 3 upgrade
We upgraded to Vue 3, for details check out our [upgrade guide](https://developer.shopware.com/docs/resources/references/upgrades/administration/vue3.html#vue-3-upgrade).

## Webpack 5 upgrade
If your plugin uses a custom webpack configuration, you need to update the configuration to the new Webpack 5 API.
Please refer to the [Webpack 5 migration guide](https://webpack.js.org/migrate/5/) for more information.

Cross-compatibility with older shopware versions is not possible because we upgraded the build system, e.g. admin extensions built for shopware 6.6 with webpack 5 will not work with shopware 6.5 (and webpack 4) or lower.
When you want to have a single version of your admin extension, you should consider switching to the [`meteor-admin-sdk`](https://shopware.github.io/meteor-admin-sdk/) as that lets you control your extensions runtime environment.

## Removal of vue-meta:
* `vue-meta` will be removed. We use our own implementation which only supports the `title` inside `metaInfo`.
* If you use other properties than title they will no longer work.
* If your `metaInfo` option is a object, rewrite it to a function returning an object.

## Admin event name changes
Some generic `@change` or `@input` event names from admin components were changed to be more specific.
See the complete list of changes below:
* Change `sw-text-field` event listeners from `@input="onInput"` to `@update:value="onInput"`
* Change `sw-boolean-radio-groups` event listeners from `@change="onChange"` to `@update:value="onChange"`
* Change `sw-bulk-edit-change-type` event listeners from `@change="onChange"` to `@update:value="onChange"`
* Change `sw-custom-entity-input-field` event listeners from `@change="onChange"` to `@update:value="onChange"`
* Change `sw-entity-many-to-many-select` event listeners from `@change="onChange"` to `@update:entityCollection="onChange"`
* Change `sw-entity-multi-id-select` event listeners from `@change="onChange"` to `@update:ids="onChange"`
* Change `sw-extension-rating-stars` event listeners from `@rating-changed="onChange"` to `@update:rating="onChange"`
* Change `sw-extension-select-rating` event listeners from `@change="onChange"` to `@update:value="onChange"`
* Change `sw-file-input` event listeners from `@change="onChange"` to `@update:value="onChange"`
* Change `sw-gtc-checkbox` event listeners from `@change="onChange"` to `@update:value="onChange"`
* Change `sw-many-to-many-assignment-card` event listeners from `@change="onChange"` to `@update:entityCollection="onChange"`
* Change `sw-meteor-single-select` event listeners from `@change="onChange"` to `@update:value="onChange"`
* Change `sw-multi-select` event listeners from `@change="onChange"` to `@update:value="onChange"`
* Change `sw-multi-tag-select` event listeners from `@change="onChange"` to `@update:value="onChange"`
* Change `sw-price-field` event listeners from `@change="onChange"` to `@update:price="onChange"`
* Change `sw-radio-panel` event listeners from `@input="onInput"` to `@update:value="onInput"`
* Change `sw-select-field` event listeners from `@change="onChange"` to `@update:value="onChange"`
* Change `sw-select-number-field` event listeners from `@change="onChange"` to `@update:value="onChange"`
* Change `sw-single-select` event listeners from `@change="onChange"` to `@update:value="onChange"`
* Change `sw-tagged-field` event listeners from `@change="onChange"` to `@update:value="onChange"`
* Change `sw-textarea-field` event listeners from `@input="onInput"` to `@update:value="onInput"`
* Change `sw-url-field` event listeners from `@input="onIput"` to `@update:value="onInput"`
* Change `sw-button-process` event listeners from `@process-finish="onFinish"` to `@update:processSuccess="onFinish"`
* Change `sw-import-export-entity-path-select` event listeners from `@change="onChange"` to `@update:value="onChange"`
* Change `sw-inherit-wrapper` event listeners from `@input="onInput"` to `@update:value="onInput"`
* Change `sw-media-breadcrumbs` event listeners from `@media-folder-change="onChange"` to `@update:currentFolderId="onChange"`
* Change `sw-media-library` event listeners from `@media-selection-change="onChange"` to `@update:selection="onChange"`
* Change `sw-multi-snippet-drag-and-drop` event listeners from `@change="onChange"` to `@update:value="onChange"`
* Change `sw-order-customer-address-select` event listeners from `@change="onChange"` to `@update:value="onChange"`
* Change `sw-order-select-document-type-modal` event listeners from `@change="onChange"` to `@update:value="onChange"`
* Change `sw-password-field` event listeners from `@input="onInput"` to `@update:value="onInput"`
* Change `sw-promotion-v2-rule-select` event listeners from `@change="onChange"` to `@update:collection="onChange"`
* Change `sw-radio-field` event listeners from `@change="onChange"` to `@update:value="onChange"`

# General Storefront Breaking Changes

## Storefront async JavaScript and all.js removal

With the upcoming major version v6.6.0 we want to get rid of the `all.js` in the Storefront and also allow async JavaScript with dynamic imports.
Our current webpack compiling for JavaScript alongside the `all.js` does not consider asynchronous imports.

### New distribution of App/Plugin "dist" JavaScript

The merging of your App/Plugin JavaScript into an `all.js` will no longer take place. Each App/Plugin will get its own JavaScript served by a separate `<script>` tag instead.
Essentially, all JavaScript inside your "dist" folder (`ExampleApp/src/Resources/app/storefront/dist/storefront/js`) will be distributed into the `public/theme` directory as it is.
Each App/Plugin will get a separate subdirectory which matches the App/Plugin technical name as snake-case, for example `public/theme/<theme-hash>/js/example-app/`.

This subdirectory will be added automatically during `composer build:js:storefront`. Please remove outdated generated JS files from the old location from your "dist" folder.
Please also include all additional JS files which might have been generated due to dynamic imports in your release:

Before:
```
└── custom/apps/
    └── ExampleApp/src/Resources/app/storefront/dist/storefront/js/
        └── example-app.js
```

After:
```
└── custom/apps/
    └── ExampleApp/src/Resources/app/storefront/dist/storefront/js/
        ├── example-app.js         <-- OLD: Please remove
        └── example-app/           <-- NEW: Please include everything in this folder in the release
            ├── example-app.js     
            ├── async-example-1.js 
            └── async-example-2.js 
```

The distributed version in `/public/theme/<theme-hash>/js/` will look like below.

**Just to illustrate, you don't need to change anything manually here!**

Before:
```
└── public/theme/
    └── 6c7abe8363a0dfdd16929ca76c02aa35/
        ├── css/
        │   └── all.css
        └── js/
            └── all.js  
```

After:
```
└── public/theme/
    └── 6c7abe8363a0dfdd16929ca76c02aa35/
        ├── css/
        │   └── all.css
        └── js/
            ├── storefront/
            │   ├── storefront.js (main bundle of "storefront", generates <script>)
            │   ├── cross-selling_plugin.js
            │   └── listing_plugin.js
            └── example-app/
                ├── example-app (main bundle of "my-listing", generates <script>)
                ├── async-example-1.js
                └── async-example-2.js
```

### Re-compile your JavaScript

Because of the changes in the JavaScript compiling process and dynamic imports, it is not possible to have pre-compiled JavaScript (`ExampleApp/src/Resources/app/storefront/dist/storefront/js`)
to be cross-compatible with the current major lane v6.5.0 and v6.6.0 at the same time.

Therefore, we recommend to release a new App/Plugin version which is compatible with v6.6.0 onwards.
The JavaScript for the Storefront can be compiled as usual using the script `bin/build-storefront.sh`.

**The App/Plugin entry point for JS `main.js` and the general way to compile the JS remains the same!**

Re-compiling your App/Plugin is a good starting point to ensure compatibility.
If your App/Plugin mainly adds new JS-Plugins and does not override existing JS-Plugins, chances are that this is all you need to do in order to be compatible.

### Registering async JS-plugins (optional)

To prevent all JS-plugins from being present on every page, we will offer the possibility to fetch the JS-plugins on-demand.
This is done by the `PluginManager` which determines if the selector from `register()` is present in the current document. Only if this is the case the JS-plugin will be fetched.

The majority of the platform Storefront JS-plugin will be changed to async.

**The general API to register JS-plugin remains the same!**

If you pass an arrow function with a dynamic import instead of a normal import,
your JS-plugin will be async and also generate an additional `.js` file in your `/dist` folder.

Before:
```js
import ExamplePlugin from './plugins/example.plugin';

window.PluginManager.register('Example', ExamplePlugin, '[data-example]');
```
After:
```js
window.PluginManager.register('Example', () => import('./plugins/example.plugin'), '[data-example]');
```

The "After" example above will generate:
```
└── custom/apps/
    └── ExampleApp/src/Resources/app/storefront/dist/storefront/js/
        └── example-app/           
            ├── example-app.js                 <-- The main app JS-bundle
            └── src_plugins_example_plugin.js  <-- Auto generated by the dynamic import
```

### Override async JS-plugins

If a platform Storefront plugin is async, the override class needs to be async as well.

Before:
```js
import MyListingExtensionPlugin from './plugin-extensions/listing/my-listing-extension.plugin';

window.PluginManager.override(
    'Listing', 
    MyListingExtensionPlugin, 
    '[data-listing]'
);
```
After:
```js
window.PluginManager.override(
    'Listing', 
    () => import('./plugin-extensions/listing/my-listing-extension.plugin'),
    '[data-listing]',
);
```

### Async plugin initialization with `PluginManager.initializePlugins()`

The method `PluginManager.initializePlugins()` is now async and will return a Promise because it also downloads all async JS-plugins before their initialization.
If you need access to newly created JS-Plugin instances (for example after a dynamic DOM-update with new JS-Plugin selectors), you need to wait for the Promise to resolve.

Before:
```js
/**
 * Example scenario:
 * 1. A dynamic DOM update via JavaScript (e.g. Ajax) adds selector "[data-form-ajax-submit]"
 * 2. PluginManager.initializePlugins() intializes Plugin "FormAjaxSubmit" because a new selector is present.
 * 3. You need access to the Plugin instance of "FormAjaxSubmit" directly after PluginManager.initializePlugins().
 */
window.PluginManager.initializePlugins();

const FormAjaxSubmitInstance = window.PluginManager.getPluginInstanceFromElement(someElement, 'FormAjaxSubmit');
// ... does something with "FormAjaxSubmitInstance"
```

After:
```js
/**
 * Example scenario:
 * 1. A dynamic DOM update via JavaScript (e.g. Ajax) adds selector "[data-form-ajax-submit]"
 * 2. PluginManager.initializePlugins() intializes Plugin "FormAjaxSubmit" because a new selector is present.
 * 3. You need access to the Plugin instance of "FormAjaxSubmit" directly after PluginManager.initializePlugins().
 */
window.PluginManager.initializePlugins().then(() => {
    const FormAjaxSubmitInstance = window.PluginManager.getPluginInstanceFromElement(someElement, 'FormAjaxSubmit');
    // ... does something with "FormAjaxSubmitInstance"
});
```

If you don't need direct access to newly created JS-plugin instances via `getPluginInstanceFromElement()`, and you only want to "re-init" all JS-plugins,
you do not need to wait for the Promise of `initializePlugins()` because `initializePlugins()` already downloads and initializes the JS-plugins.

### Avoid import from PluginManager

Because the PluginManager is a singleton class which also assigns itself to the `window` object,
it should be avoided to import the PluginManager. It can lead to unintended side effects.

Use the existing `window.PluginManager` instead.
**Note:** This already works for older shopware versions and is considered best practice.

Before:
```js
import PluginManager from 'src/plugin-system/plugin.manager';

PluginManager.getPluginInstances('SomePluginName');
```
After:
```js
window.PluginManager.getPluginInstances('SomePluginName');
```

### Avoid import from Plugin base class

The import of the `Plugin` class can lead to code-duplication of the Plugin class in every App/Plugin.

Use `window.PluginBaseClass` instead.
**Note:** This already works for older shopware versions and is considered best practice.

Before:
```js
import Plugin from 'src/plugin-system/plugin.class';

export default class MyPlugin extends Plugin {
    // Plugin code...
};
```
After:
```js
export default class MyPlugin extends window.PluginBaseClass {
    // Plugin code...
};
```

# App System Breaking Changes

## Removal of `flow-action-1.0.xsd`
We removed `Shopware\Core\Framework\App\FlowAction\Schema\flow-action-1.0.xsd`, use `Shopware\Core\Framework\App\Flow\Schema\flow-1.0.xsd` instead.
Also use the `Resources/flow.xml` file path instead of `Resources/flow-action.xml` for your apps flow configuration.

# Code Level Breaking Changes

## Old Elasticsearch data mapping structure is deprecated, introduce new data mapping structure:

* For the full reference, please read the [adr](/adr/2023-04-11-new-language-inheritance-mechanism-for-opensearch.md)
* If you've defined your own Elasticsearch definitions, please prepare for the new structure by update your definition's `getMapping` and `fetch` methods:

```php
<?php

use Shopware\Elasticsearch\Framework\AbstractElasticsearchDefinition;
use Shopware\Core\Framework\Context;
use Shopware\Elasticsearch\Framework\ElasticsearchFieldBuilder;
use Shopware\Elasticsearch\Framework\ElasticsearchFieldMapper;
use Shopware\Elasticsearch\Framework\ElasticsearchIndexingUtils;

class YourElasticsearchDefinition extends AbstractElasticsearchDefinition
{
    public function getMapping(Context $context): array
    {
        // use ElasticsearchFieldBuilder::translated to build translated fields mapping
        $languageFields = $this->fieldBuilder->translated(self::getTextFieldConfig());

        $mapping = [
            // Non-translated fields are updated as current
            'productNumber' => [
                'type' => 'keyword',
                'normalizer' => 'sw_lowercase_normalizer',
                'fields' => [
                    'search' => [
                        'type' => 'text',
                    ],
                    'ngram' => [
                        'type' => 'text',
                        'analyzer' => 'sw_ngram_analyzer',
                    ],
                ],
            ],
            // Translated text fields mapping need to be updated with the new structure
            'name' => $languageFields,
            // use ElasticsearchFieldBuilder::customFields to build translated custom fields mapping
            'customFields' => $this->fieldBuilder->customFields($this->getEntityDefinition()->getEntityName(), $context),
            // nested translated fields needs to be updated too using ElasticsearchFieldBuilder::nested
            'manufacturer' => ElasticsearchFieldBuilder::nested([
                'name' => $languageFields,
            ]),
        ];


        return $mapping;
    }

    public function fetch(array $ids, Context $context): array
    {
        // We need to fetch all available content of translated fields in all languages
        ...;

        return [
            '466f4eadf13a4486b851e747f5d99a4f' => [
                'name' => [
                    '2fbb5fe2e29a4d70aa5854ce7ce3e20b' => 'English foo',
                    '46986b26eadf4bb3929e9fc91821e294' => 'German foo',
                ],
                'manufacturer' => [
                    'id' => '5bf0d9be43cb41ccbb5781cec3052d91',
                    '_count' => 1,
                    'name' => [
                        '2fbb5fe2e29a4d70aa5854ce7ce3e20b' => 'English baz',
                        '46986b26eadf4bb3929e9fc91821e294' => 'German baz',
                    ],
                ],
                'productNumber' => 'PRODUCT_NUM',
            ],
        ];
    }
}
```

* The new structure will be applied since next major, however you can try it out by enabling the flag `ES_MULTILINGUAL_INDEX=1`

### Update your live shops

* To migrate the existing data to the new indexes following the  new structure, you must run `bin/console es:index`, then the new index mapping will be ready to use after the es indexing process is finished
* **optional:** The old indexes is then obsolete and can be removed by running `bin/console es:index:cleanup`

## SalesChannel Analytics association is not autoloaded anymore
If you are relying on the `sales_channel.analytics` association, please associate the definition directly with the criteria because we will remove autoload from version 6.6.0.0.

## Shopware\Core\Checkout\Customer\SalesChannel\AccountService::login is removed

The `Shopware\Core\Checkout\Customer\SalesChannel\AccountService::login` method will be removed in the next major version. Use `AccountService::loginByCredentials` or `AccountService::loginById` instead.

## Deprecation of methods floatMatch and arrayMatch in CustomFieldRule
### Before

```php
CustomFieldRule::floatMatch($operator, $floatA, $floatB)
CustomFieldRule::arrayMatch($operator, $arrayA, $arrayB)
```
### After
We introduced new `compare` method in `FloatComparator` and `ArrayComparator` classes.
```php
FloatComparator::compare($floatA, $floatB, $operator)
ArrayComparator::compare($arrayA, $arrayB, $operator)
```

## sw-entity-multi-id-select
* Change model `ids` to `value`.
* Change event `update:ids` to `update:value`

## sw-price-field
* Change model `price` to `value`
* Change event `update:price` to `update:value`

## New `HttpException::is` function

The new `HttpException::is` function can be used to check if an exception is of a specific error code.

```php
try {
    // do something
} catch (HttpException $exception) {
    if ($exception->is(CategoryException::FOOTER_CATEGORY_NOT_FOUND)) {
        // handle empty footer or service navigation
    }
} 

```

## 204 response for empty footer/service navigation

The response code for empty footer or service navigation has been changed from 400 to 204. This is to prevent unnecessary error logging in the browser console and to be more consistent with the response code for different kind of sales channel navigations.

```javascript

// show example how to handle in javascript a 404 response for footer navigation
this.client.get('/store-api/navigation/footer-navigation/footer-navigation', {
    headers: this.basicHeaders
}).then((response) => {
    if (response.status === 400) {
        // handle empty footer
    }
});


// after
this.client.get('/store-api/navigation/footer-navigation/footer-navigation', {
    headers: this.basicHeaders
}).then((response) => {
    if (response.status === 204) {
        // handle empty footer
    }
});
```

## Paging processor now accepts preset limit
The `PagingListingProcessor` now also considers the preset `limit` value when processing the request. This means that the `limit` value from the request will be used if it is set, otherwise the preset `limit` value, of the provided criteria, will be used.
If the criteria does not have a preset `limit` value, the default `limit` from the system configuration will be used.

```php
$criteria = new Criteria();
$criteria->setLimit(10);

$request = new Request();
$request->query->set('limit', 5);

$processor = new PagingListingProcessor();

$processor->process($criteria, $request);

// $criteria->getLimit() === 5
// $criteria->getLimit() === 10 (if no limit is set in the request)
```

## Introduced in 6.6.0.0

### Main categories are now available in seo url templates
We added the `mainCategories` association in the `\Shopware\Storefront\Framework\Seo\SeoUrlRoute\ProductPageSeoUrlRoute::prepareCriteria` method.
This association is filtered by the current sales channel id. You can now use the main categories in your seo url templates for product detail pages.

```
{{ product.mainCategories.first.category.translated.name }}
```

## Introduced in 6.5.8.0

## Storefront async JavaScript and all.js removal

With the upcoming major version v6.6.0 we want to get rid of the `all.js` in the Storefront and also allow async JavaScript with dynamic imports.
Our current webpack compiling for JavaScript alongside the `all.js` does not consider asynchronous imports.

### New distribution of App/Plugin "dist" JavaScript

The merging of your App/Plugin JavaScript into an `all.js` will no longer take place. Each App/Plugin will get its own JavaScript served by a separate `<script>` tag instead.
Essentially, all JavaScript inside your "dist" folder (`ExampleApp/src/Resources/app/storefront/dist/storefront/js`) will be distributed into the `public/theme` directory as it is.
Each App/Plugin will get a separate subdirectory which matches the App/Plugin technical name as snake-case, for example `public/theme/<theme-hash>/js/example-app/`.

This subdirectory will be added automatically during `composer build:js:storefront`. Please remove outdated generated JS files from the old location from your "dist" folder.
Please also include all additional JS files which might have been generated due to dynamic imports in your release:

Before:
```
└── custom/apps/
    └── ExampleApp/src/Resources/app/storefront/dist/storefront/js/
        └── example-app.js
```

After:
```
└── custom/apps/
    └── ExampleApp/src/Resources/app/storefront/dist/storefront/js/
        ├── example-app.js         <-- OLD: Will be ignored (but should be removed for theme:compile)
        └── example-app/           <-- NEW: Please include everything in this folder in the release
            ├── example-app.js     
            ├── async-example-1.js 
            └── async-example-2.js 
```

The distributed version in `/public/theme/<theme-hash>/js/` will look like below.

**Just to illustrate, you don't need to change anything manually here!**

Before:
```
└── public/theme/
    └── 6c7abe8363a0dfdd16929ca76c02aa35/
        ├── css/
        │   └── all.css
        └── js/
            └── all.js  
```

After:
```
└── public/theme/
    └── 6c7abe8363a0dfdd16929ca76c02aa35/
        ├── css/
        │   └── all.css
        └── js/
            ├── storefront/
            │   ├── storefront.js (main bundle of "storefront", generates <script>)
            │   ├── cross-selling_plugin.js
            │   └── listing_plugin.js
            └── example-app/
                ├── example-app (main bundle of "my-listing", generates <script>)
                ├── async-example-1.js
                └── async-example-2.js
```

### File path pattern for scripts in theme.json file
If the script file does not match the new file path pattern, it will be **ignored** (during getThemeScripts in Storefront, not during theme:compile).

Example for a Theme called MyOldTheme (theme.json)
```json
...
"script": [
  "@Storefront",
  "@AnotherTheme",
  "app/storefront/dist/storefront/js/my-old-theme.js", // This file will be ignored (structure before 6.6)
  "app/storefront/dist/storefront/js/my-old-theme/my-old-theme.js", // This file will be used (new structure)
],
...
```
We need to ignore the old files for multiple reasons. The main reason is that the old files are not compatible with the new async JavaScript and dynamic imports. Second it would throw an error for all themes that do not update their theme.json file.

### Re-compile your JavaScript

Because of the changes in the JavaScript compiling process and dynamic imports, it is not possible to have pre-compiled JavaScript (`ExampleApp/src/Resources/app/storefront/dist/storefront/js`)
to be cross-compatible with the current major lane v6.5.0 and v6.6.0 at the same time.

Therefore, we recommend to release a new App/Plugin version which is compatible with v6.6.0 onwards.
The JavaScript for the Storefront can be compiled as usual using the composer script `composer build:js:storefront`.

**The App/Plugin entry point for JS `main.js` and the general way to compile the JS remains the same!**

Re-compiling your App/Plugin is a good starting point to ensure compatibility.
If your App/Plugin mainly adds new JS-Plugins and does not override existing JS-Plugins, chances are that this is all you need to do in order to be compatible.

### JavaScript build separation of apps/plugin with webpack MultiCompiler

With 6.6 we use webpack [MultiCompiler](https://webpack.js.org/api/node/#multicompiler) to build the default storefront as well as apps and plugins.
Each app/plugin will generate its own webpack config in the background and will be built in a separate build process to enhance JS-bundle stability.

You can still extend the webpack config of the default storefront with your own config like in 6.5, for example to add a new alias.
Due to the build process separation, your modified webpack config will only take effect in your current app/plugin but will no longer effect other apps/plugins.

Let's imagine two apps "App1" and "App2". "App1" is now extending the webpack config with a custom alias. 
In the example below, your will have access to all "alias" from the default storefront, as well as the additional alias "ExampleAlias" in "App1":

```js
// App 1 webpack config
// custom/apps/App1/Resources/app/storefront/build/webpack.config.js
module.exports = function (params) {
    return {
        resolve: {
            alias: {
                // The alias "ExampleAlias" can only be used within App1
                ExampleAlias: `${params.basePath}/Resources/app/storefront/src/example-dir`,
            }
        }
    };
};
```

Now the alias can be used within "App1":
```js
// custom/apps/App1/Resources/app/storefront/src/main.js
import MyComponent from 'ExampleAlias/example-module'; // <-- ✅ Can be resolved
```

If the alias is used within "App2", you will get an error because the import cannot be resolved:
```js
// custom/apps/App2/Resources/app/storefront/src/main.js
import MyComponent from 'ExampleAlias/example-module'; // <-- ❌ Cannot be resolved
```

If you need the alias `ExampleAlias` or another config from "App1", you need to explicitly add the alias to "App2".
Apps/plugins should no longer be able to influence each other during the build process for stability reasons.
Your App/plugins webpack config only inherits the core webpack config but no other webpack configs.

### Registering async JS-plugins (optional)

To prevent all JS-plugins from being present on every page, we will offer the possibility to fetch the JS-plugins on-demand.
This is done by the `PluginManager` which determines if the selector from `register()` is present in the current document. Only if this is the case the JS-plugin will be fetched.

The majority of the platform Storefront JS-plugin will be changed to async.

**The general API to register JS-plugin remains the same!**

If you pass an arrow function with a dynamic import instead of a normal import,
your JS-plugin will be async and also generate an additional `.js` file in your `/dist` folder.

Before:
```js
import ExamplePlugin from './plugins/example.plugin';

window.PluginManager.register('Example', ExamplePlugin, '[data-example]');
```
After:
```js
window.PluginManager.register('Example', () => import('./plugins/example.plugin'), '[data-example]');
```

The "After" example above will generate:
```
└── custom/apps/
    └── ExampleApp/src/Resources/app/storefront/dist/storefront/js/
        └── example-app/           
            ├── example-app.js                 <-- The main app JS-bundle
            └── src_plugins_example_plugin.js  <-- Auto generated by the dynamic import
```

### Override async JS-plugins

If a platform Storefront plugin is async, the override class needs to be async as well.

Before:
```js
import MyListingExtensionPlugin from './plugin-extensions/listing/my-listing-extension.plugin';

window.PluginManager.override(
    'Listing', 
    MyListingExtensionPlugin, 
    '[data-listing]'
);
```
After:
```js
window.PluginManager.override(
    'Listing', 
    () => import('./plugin-extensions/listing/my-listing-extension.plugin'),
    '[data-listing]',
);
```

### Async plugin initialization with `PluginManager.initializePlugins()` and `PluginManager.initializePlugin()`

* The method `PluginManager.initializePlugins()` is now async and will return a Promise because it also downloads all async JS-plugins before their initialization.
* The method `PluginManager.initializePlugin()` to initialize a single JS-plugin is now async as well and will download the single plugin if was not downloaded beforehand.

If you need access to newly created JS-Plugin instances (for example after a dynamic DOM-update with new JS-Plugin selectors), you need to wait for the Promise to resolve.

Before:
```js
/**
 * Example scenario:
 * 1. A dynamic DOM update via JavaScript (e.g. Ajax) adds selector "[data-form-ajax-submit]"
 * 2. PluginManager.initializePlugins() intializes Plugin "FormAjaxSubmit" because a new selector is present.
 * 3. You need access to the Plugin instance of "FormAjaxSubmit" directly after PluginManager.initializePlugins().
 */
window.PluginManager.initializePlugins();

const FormAjaxSubmitInstance = window.PluginManager.getPluginInstanceFromElement(someElement, 'FormAjaxSubmit');
// ... does something with "FormAjaxSubmitInstance"
```

After:
```js
/**
 * Example scenario:
 * 1. A dynamic DOM update via JavaScript (e.g. Ajax) adds selector "[data-form-ajax-submit]"
 * 2. PluginManager.initializePlugins() intializes Plugin "FormAjaxSubmit" because a new selector is present.
 * 3. You need access to the Plugin instance of "FormAjaxSubmit" directly after PluginManager.initializePlugins().
 */
window.PluginManager.initializePlugins().then(() => {
    const FormAjaxSubmitInstance = window.PluginManager.getPluginInstanceFromElement(someElement, 'FormAjaxSubmit');
    // ... does something with "FormAjaxSubmitInstance"
});
```

If you don't need direct access to newly created JS-plugin instances via `getPluginInstanceFromElement()`, and you only want to "re-init" all JS-plugins,
you do not need to wait for the Promise of `initializePlugins()` or `initializePlugin()` because `initializePlugins()` and `initializePlugin()` already download and initialize the JS-plugins.

### Avoid import from PluginManager

Because the PluginManager is a singleton class which also assigns itself to the `window` object,
it should be avoided to import the PluginManager. It can lead to unintended side effects.

Use the existing `window.PluginManager` instead.

Before:
```js
import PluginManager from 'src/plugin-system/plugin.manager';

PluginManager.getPluginInstances('SomePluginName');
```
After:
```js
window.PluginManager.getPluginInstances('SomePluginName');
```

### Avoid import from Plugin base class

The import of the `Plugin` class can lead to code-duplication of the Plugin class in every App/Plugin.

Use `window.PluginBaseClass` instead.

Before:
```js
import Plugin from 'src/plugin-system/plugin.class';

export default class MyPlugin extends Plugin {
    // Plugin code...
};
```
After:
```js
export default class MyPlugin extends window.PluginBaseClass {
    // Plugin code...
};
```

## Removal of static product detail page templates

The deprecated template `src/Storefront/Resources/views/storefront/page/product-detail/index.html.twig` was removed and replaced by configurable product detail CMS pages.
Please use the template `src/Storefront/Resources/views/storefront/page/content/product-detail.html.twig` instead.

This also applies to the sub-templates of the product detail page. From now on, CMS components are used instead.
The old templates from `/page/product-detail` will no longer be used when a product detail page is rendered. 
The default product detail page CMS layout will be used, if no other layout is configured in the administration.

| Old                                                                           | New                                                                                  |
|-------------------------------------------------------------------------------|--------------------------------------------------------------------------------------|
| Resources/views/storefront/page/product-detail/tabs.html.twig                 | Resources/views/storefront/element/cms-element-product-description-reviews.html.twig |
| Resources/views/storefront/page/product-detail/description.html.twig          | Resources/views/storefront/component/product/description.html.twig                   |
| Resources/views/storefront/page/product-detail/properties.html.twig           | Resources/views/storefront/component/product/properties.html.twig                    |
| Resources/views/storefront/page/product-detail/headline.html.twig             | Resources/views/storefront/element/cms-element-product-name.html.twig                |
| Resources/views/storefront/page/product-detail/configurator.html.twig         | Resources/views/storefront/component/buy-widget/configurator.html.twig               |
| Resources/views/storefront/page/product-detail/buy-widget.html.twig           | Resources/views/storefront/component/buy-widget/buy-widget.html.twig                 |
| Resources/views/storefront/page/product-detail/buy-widget-price.html.twig     | Resources/views/storefront/component/buy-widget/buy-widget-price.html.twig           |
| Resources/views/storefront/page/product-detail/buy-widget-form.html.twig      | Resources/views/storefront/component/buy-widget/buy-widget-form.html.twig            |
| Resources/views/storefront/page/product-detail/review/review.html.twig        | Resources/views/storefront/component/review/review.html.twig                         |
| Resources/views/storefront/page/product-detail/review/review-form.html.twig   | Resources/views/storefront/component/review/review-form.html.twig                    |
| Resources/views/storefront/page/product-detail/review/review-item.html.twig   | Resources/views/storefront/component/review/review-item.html.twig                    |
| Resources/views/storefront/page/product-detail/review/review-login.html.twig  | Resources/views/storefront/component/review/review-login.html.twig                   |
| Resources/views/storefront/page/product-detail/review/review-widget.html.twig | Resources/views/storefront/component/review/review-widget.html.twig                  |
| Resources/views/storefront/page/product-detail/cross-selling/tabs.html.twig   | Resources/views/storefront/element/cms-element-cross-selling.html.twig               |

## Introduced in 6.5.7.0
## New media url generator and path strategy
* Removed deprecated `UrlGeneratorInterface` interface, use `AbstractMediaUrlGenerator` instead to generate the urls for media entities
* Removed deprecated `AbstractPathNameStrategy` abstract class, use `AbstractMediaPathStrategy` instead to implement own strategies

```php
<?php 

namespace Examples;

use Shopware\Core\Content\Media\Core\Application\AbstractMediaUrlGenerator;use Shopware\Core\Content\Media\Core\Params\UrlParams;use Shopware\Core\Content\Media\MediaCollection;use Shopware\Core\Content\Media\MediaEntity;use Shopware\Core\Content\Media\Pathname\UrlGeneratorInterface;

class BeforeChange
{
    private UrlGeneratorInterface $urlGenerator;
    
    public function foo(MediaEntity $media) 
    {
        $relative = $this->urlGenerator->getRelativeMediaUrl($media);
        
        $absolute = $this->urlGenerator->getAbsoluteMediaUrl($media);
    }
    
    public function bar(MediaThumbnailEntity $thumbnail) 
    {
        $relative = $this->urlGenerator->getRelativeThumbnailUrl($thumbnail);
        
        $absolute = $this->urlGenerator->getAbsoluteThumbnailUrl($thumbnail);
    }
}

class AfterChange
{
    private AbstractMediaUrlGenerator $generator;
    
    public function foo(MediaEntity $media) 
    {
        $relative = $media->getPath();

        $urls = $this->generator->generate([UrlParams::fromMedia($media)]);
        
        $absolute = $urls[0];
    }
    
    public function bar(MediaThumbnailEntity $thumbnail) 
    {
        // relative is directly stored at the entity
        $relative = $thumbnail->getPath();
        
        // path generation is no more entity related, you could also use partial entity loading and you can also call it in batch, see below
        $urls = $this->generator->generate([UrlParams::fromMedia($media)]);
        
        $absolute = $urls[0];
    }
    
    public function batch(MediaCollection $collection) 
    {
        $params = [];
        
        foreach ($collection as $media) {
            $params[$media->getId()] = UrlParams::fromMedia();
            
            foreach ($media->getThumbnails() as $thumbnail) {
                $params[$thumbnail->getId()] = UrlParams::fromThumbnail($thumbnail);
            }
        }
        
        $urls = $this->generator->generate($paths);

        // urls is a flat list with {id} => {url} for media and also for thumbnails        
    }
}
```

## New custom fields mapping event

* Previously the event `ElasticsearchProductCustomFieldsMappingEvent` is dispatched when create new ES index so you can add your own custom fields mapping.
* We replaced the event with a new event `Shopware\Elasticsearch\Event\ElasticsearchCustomFieldsMappingEvent`, this provides a better generic way to add custom fields mapping

```php
class ExampleCustomFieldsMappingEventSubscriber implements EventSubscriberInterface {

    public static function getSubscribedEvents(): array
    {
        return [
            ElasticsearchCustomFieldsMappingEvent::class => 'addCustomFieldsMapping',
        ];
    }

    public function addCustomFieldsMapping(ElasticsearchCustomFieldsMappingEvent $event): void 
    {
        if ($event->getEntity() === 'product') {
            $event->setMapping('productCfFoo', CustomFieldTypes::TEXT);
        }

        if ($event->getEntity() === 'category') {
            $event->setMapping('categoryCfFoo', CustomFieldTypes::TEXT);
        }
        // ...
    }
}
```

## Adding syntax sugar for ES Definition

We added new utility classes to make creating custom ES definition look simpler

In this example, assuming you have a custom ES definition with `name` & `description` fields are translatable fields:

```php
<?php declare(strict_types=1);

namespace Shopware\Commercial\AdvancedSearch\Domain\Indexing\ElasticsearchDefinition\Manufacturer;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\SqlHelper;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Elasticsearch\Framework\AbstractElasticsearchDefinition;
use Shopware\Elasticsearch\Framework\ElasticsearchFieldBuilder;
use Shopware\Elasticsearch\Framework\ElasticsearchFieldMapper;
use Shopware\Elasticsearch\Framework\ElasticsearchIndexingUtils;

class YourElasticsearchDefinition extends AbstractElasticsearchDefinition
{
    /**
     * @internal
     */
    public function __construct(
        private readonly EntityDefinition $definition,
        private readonly CompletionDefinitionEnrichment $completionDefinitionEnrichment,
        private readonly ElasticsearchFieldBuilder $fieldBuilder
    ) {
    }

    public function getMapping(Context $context): array
    {
        $languageFields = $this->fieldBuilder->translated(self::getTextFieldConfig());

        $properties = [
            'id' => self::KEYWORD_FIELD,
            'name' => $languageFields,
            'description' => $languageFields,
        ];

        return [
            '_source' => ['includes' => ['id']],
            'properties' => $properties,
        ];
    }

    public function fetch(array $ids, Context $context): array
    {
        $data = $this->fetchData($ids, $context);

        $documents = [];

        foreach ($data as $id => $item) {
            $translations = ElasticsearchIndexingUtils::parseJson($item, 'translation');

            $documents[$id] = [
                'id' => $id,
                'name' => ElasticsearchFieldMapper::translated('name', $translations),
                'description' => ElasticsearchFieldMapper::translated('description', $translations),
            ];
        }

        return $documents;
    }
}
```

## \Shopware\Core\Framework\Log\LoggerFactory:
`\Shopware\Core\Framework\Log\LoggerFactory` will be removed. You can use monolog configuration to achieve the same results. See https://symfony.com/doc/current/logging/channels_handlers.html.

## Removal of separate Elasticsearch exception classes
Removed the following exception classes:
* `\Shopware\Elasticsearch\Exception\ElasticsearchIndexingException`
* `\Shopware\Elasticsearch\Exception\NoIndexedDocumentsException`
* `\Shopware\Elasticsearch\Exception\ServerNotAvailableException`
* `\Shopware\Elasticsearch\Exception\UnsupportedElasticsearchDefinitionException`
* `\Shopware\Elasticsearch\Exception\ElasticsearchIndexingException`
Use the exception factory class `\Shopware\Elasticsearch\ElasticsearchException` instead.

## `availabilityRuleId` in `\Shopware\Core\Checkout\Shipping\ShippingMethodEntity`:
* Type changed from `string` to be also nullable and will be natively typed to enforce strict data type checking.

## `getAvailabilityRuleId` in `\Shopware\Core\Checkout\Shipping\ShippingMethodEntity`:
* Return type is nullable.

## `getAvailabilityRuleUuid` in `\Shopware\Core\Framework\App\Lifecycle\Persister\ShippingMethodPersister`:
* Has been removed without replacement.

## `Required` flag for `availability_rule_id` in `\Shopware\Core\Checkout\Shipping\ShippingMethodDefinition`:
* Has been removed.

## ES Definition's buildTermQuery could return BuilderInterface:
* In 6.5 we only allow return `BoolQuery` from `AbstractElasticsearchDefinition::buildTermQuery` method which is not always the case. From next major version, we will allow return `BuilderInterface` from this method.

## Removal of Product Export exception
* Removed `\Shopware\Core\Content\ProductExport\Exception\EmptyExportException` use `\Shopware\Core\Content\ProductExport\ProductExportException::productExportNotFound` instead

## Introduced in 6.5.6.0
## Removal of CacheInvalidatorStorage

The delayed cache invalidation storage was until 6.6 the cache implementation.
As this is not ideal for multi-server usage, we deprecated it in 6.5 and removed it now.
Delaying of cache invalidations now requires a Redis instance to be configured.

```yaml
shopware:
    cache:
        invalidation:
            delay_options:
                storage: redis
                dsn: 'redis://localhost'
```

Since 6.6.10.0 we also have a MySQL implementation available: `\Shopware\Core\Framework\Adapter\Cache\InvalidatorStorage\MySQLInvalidatorStorage`. Use it via `mysql`

## Introduced in 6.5.5.0
## New stock handling implementation is now the default

The `product.stock` field is now the primary source for real time product stock values. However, `product.availableStock` is a direct mirror of `product.stock` and is updated whenever `product.stock` is updated via the DAL.

A database migration `\Shopware\Core\Migration\V6_6\Migration1691662140MigrateAvailableStock` takes care of copying the `available_stock` field to the `stock` field.

## New configuration values

* `stock.enable_stock_management` - Default `true`. This can be used to completely disable Shopware's stock handling. If disabled, product stock will be not be updated as orders are created and transitioned through the various states.

## Removed `\Shopware\Core\Content\Product\DataAbstractionLayer\StockUpdater`

The listener was replaced with a new listener `\Shopware\Core\Content\Product\Stock\OrderStockSubscriber` which handles subscribing to the various order events and interfaces with the stock storage `\Shopware\Core\Content\Product\Stock\AbstractStockStorage` to write the stock alterations.

## Removed `\Shopware\Core\Content\Product\SalesChannel\Detail\AbstractAvailableCombinationLoader::load()` && `\Shopware\Core\Content\Product\SalesChannel\Detail\AvailableCombinationLoader::load()`

These methods are removed and superseded by `loadCombinations` which has a different method signature.

From:

```php
public function load(string $productId, Context $context, string $salesChannelId)
```

To:

```php
public function loadCombinations(string $productId, SalesChannelContext $salesChannelContext): AvailableCombinationResult
```

The `loadCombinations` method has been made abstract so it must be implemented. The `SalesChannelContext` instance, contains the data which was previously in the defined on the `load` method. 

`$salesChannelId` can be replaced with `$salesChannelContext->getSalesChannelId()`.

`$context` can be replaced with `$salesChannelContext->getContext()`.

## Writing to `product.availableStock` field is now not possible

The field is write protected. Use the `product.stock` to write new stock levels. 

## Reading product stock

The `product.stock` should be used to read the current stock level. When building new extensions which need to query the stock of a product, use this field. Not the `product.availableStock` field.

## Removed `\Shopware\Core\Framework\DataAbstractionLayer\Event\BeforeDeleteEvent`

It is replaced by `\Shopware\Core\Framework\DataAbstractionLayer\Event\EntityDeleteEvent` with the same API.

You should use `\Shopware\Core\Framework\DataAbstractionLayer\Event\EntityDeleteEvent` instead, only the class name changed.

## sw-field deprecation:
* Instead of `<sw-field type="url"` use `<sw-url-field`. You can see the component mapping in the `sw-field/index.js`

## Removal of `ProductLineItemFactory`
Removed `\Shopware\Core\Content\Product\Cart\ProductLineItemFactory`, use `\Shopware\Core\Checkout\Cart\LineItemFactoryHandler\ProductLineItemFactory` instead.

## Removal of `Shopware\Core\Framework\App\FlowAction` and `Shopware\Core\Framework\App\FlowAction\Xml`
We moved all class from namespaces `Shopware\Core\Framework\App\FlowAction` to `Shopware\Core\Framework\App\Flow\Action` and `Shopware\Core\Framework\App\FlowAction\Xml` to `Shopware\Core\Framework\App\Flow\Action\Xml`.
Please use new namespaces.
* Removed `\Shopware\Core\Content\Product\SalesChannel\Listing\ProductListingFeaturesSubscriber`, use `CompositeProcessor` instead

## Removal of API-Conversion mechanism

The API-Conversion mechanism was not used anymore, therefore, the following classes were removed:
* `\Shopware\Core\Framework\Api\Converter\ApiVersionConverter`
* `\Shopware\Core\Framework\Api\Converter\ConverterRegistry`
* `\Shopware\Core\Framework\Api\Converter\Exceptions\ApiConversionException`

## Removal of separate Product Export exception classes
Removed the following exception classes:
* `\Shopware\Core\Content\ProductExport\Exception\RenderFooterException`
* `\Shopware\Core\Content\ProductExport\Exception\RenderHeaderException`
* `\Shopware\Core\Content\ProductExport\Exception\RenderProductException`

## `writeAccess` field removed in `integrations`

The `writeAccess` field was removed from the `integration` entity without replacement as it was unused.

## `defaultRunInterval` field is required for `ScheduledTask` entities

The `defaultRunInterval` field is now required for `ScheduledTask` entities. So you now have to provide the following required fields to create a new Scheduled Task in the DB:
* `name`
* `scheduledTaskClass`
* `runInterval`
* `defaultRunInterval`
* `status`

## Removed `\Shopware\Core\Content\Media\DeleteNotUsedMediaService`
All usages of `\Shopware\Core\Content\Media\DeleteNotUsedMediaService` should be replaced with `\Shopware\Core\Content\Media\UnusedMediaPurger`. There is no replacement for the `countNotUsedMedia` method because counting the number of unused media on a system with a lot of media is time intensive.
The `deleteNotUsedMedia` method exists on the new service but has a different signature. `Context` is no longer required. To delete only entities of a certain type it was previously necessary to add an extension to the `Context` object. Instead, pass the entity name to the third parameter of `deleteNotUsedMedia`.
The first two parameters allow to process a slice of media, passing null to those parameters instructs the method to check all media, in batches.
* Changed the following classes to be internal:
  - `\Shopware\Core\Framework\Webhook\Hookable\HookableBusinessEvent`
  - `\Shopware\Core\Framework\Webhook\Hookable\HookableEntityWrittenEvent`
  - `\Shopware\Core\Framework\Webhook\Hookable\HookableEventFactory`
  - `\Shopware\Core\Framework\Webhook\Hookable\WriteResultMerger`
  - `\Shopware\Core\Framework\Webhook\Message\WebhookEventMessage`
  - `\Shopware\Core\Framework\Webhook\ScheduledTask\CleanupWebhookEventLogTask`
  - `\Shopware\Core\Framework\Webhook\BusinessEventEncoder`
  - `\Shopware\Core\Framework\Webhook\WebhookDispatcher`

## FlowEventAware interface change 
With v6.6 we change the class hierarchy of the following flow event interfaces:
* `CustomerRecoveryAware`
* `MessageAware`
* `NewsletterRecipientAware`
* `OrderTransactionAware`
* `CustomerAware`
* `CustomerGroupAware`
* `MailAware`
* `OrderAware`
* `ProductAware`
* `SalesChannelAware`
* `UserAware`
* `LogAware`

When you have implemented one of these interfaces in one of your own event classes, you should now also implement the `FlowEventAware` interface by yourself.
This is necessary to ensure that your event class is compatible with the new flow event system.

**Before:**
```php
<?php declare(strict_types=1);

namespace App\Event;

use Shopware\Core\Framework\Log\LogAware;

class MyEvent implements LogAware
{
    // ...
}
```

**After:**

```php
<?php declare(strict_types=1);

namespace App\Event;

use Shopware\Core\Framework\Event\FlowEventAware;

class MyEvent implements FlowEventAware, LogAware
{
    // ...
}
```

## Indexer Offset Changes

The methods `setNextLanguage()` and `setNextDefinition()` in `\Shopware\Elasticsearch\Framework\Indexing\IndexerOffset` are removed, use `selectNextLanguage()` or `selectNextDefinition()` instead.
Before:
```php 
$offset->setNextLanguage($languageId);
$offset->setNextDefinition($definition);
```

After:
```php
$offset->selectNextLanguage($languageId);
$offset->selectNextDefinition($definition);
```

## Changes to data-attribute selector names

We want to change several data-attribute selector names to be more aligned with the JavaScript plugin name which is initialized on the data-attribute selector.
When you use one of the selectors listed below inside HTML/Twig, JavaScript or CSS, please change the selector to the new selector.

## HTML/Twig example

### Before

```twig
<div 
    data-offcanvas-menu="true" {# <<< Did not match options attr #}
    data-off-canvas-menu-options='{ ... }'
>
</div>
```

### After

```twig
<div 
    data-off-canvas-menu="true" {# <<< Now matches options attr #}
    data-off-canvas-menu-options='{ ... }'
>
</div>
```

_The options attribute is automatically generated using the camelCase JavaScript plugin name._

## Full list of selectors

| old                             | new                              |
|:--------------------------------|:---------------------------------|
| `data-search-form`              | `data-search-widget`             |
| `data-offcanvas-cart`           | `data-off-canvas-cart`           |
| `data-collapse-footer`          | `data-collapse-footer-columns`   |
| `data-offcanvas-menu`           | `data-off-canvas-menu`           |
| `data-offcanvas-account-menu`   | `data-account-menu`              |
| `data-offcanvas-tabs`           | `data-off-canvas-tabs`           |
| `data-offcanvas-filter`         | `data-off-canvas-filter`         |
| `data-offcanvas-filter-content` | `data-off-canvas-filter-content` |

## Introduced in 6.5.0.0
## Removed `SyncOperationResult`
The `\Shopware\Core\Framework\Api\Sync\SyncOperationResult` class was removed without replacement, as it was unused.

## Deprecated component `sw-dashboard-external-link` has been removed
* Use component `sw-external-link` instead of `sw-dashboard-external-link`

## Selector to open an ajax modal
The selector to initialize the `AjaxModal` plugin will be changed to not interfere with Bootstrap defaults data-attribute API.

### Before
```html
<a data-bs-toggle="modal" data-url="/my/route" href="/my/route">Open Ajax Modal</a>
```

### After
```html
<a data-ajax-modal="true" data-url="/my/route" href="/my/route">Open Ajax Modal</a>
```

## `IsNewCustomerRule` to be removed with major release v6.6.0
* Use `DaysSinceFirstLoginRule` instead with operator `=` and `daysPassed` of `0` to achieve identical behavior

## Seeding mechanism for `AbstractThemePathBuilder`

The `generateNewPath()` and `saveSeed()` methods  in `\Shopware\Storefront\Theme\AbstractThemePathBuilder` are now abstract, this means you should implement those methods to allow atomic theme compilations.

For more details refer to the corresponding [ADR](/adr/2023-01-10-atomic-theme-compilation.md).

## Removal of `blacklistIds` and `whitelistIds` in  `\Shopware\Core\Content\Product\ProductEntity`
Two properties `blacklistIds` and `whitelistIds` were removed without replacement

## Replace `@shopware-ag/admin-extension-sdk` with `@shopware-ag/meteor-admin-sdk`

### Before
```json
{
    "dependencies": {
        "@shopware-ag/admin-extension-sdk": "^3.0.14"
    }
}
```

### After
```json
{
    "dependencies": {
        "@shopware-ag/meteor-admin-sdk": "^3.0.16"
    }
}
```

## Update `@shopware-ag/meteor-admin-sdk` to `^4.0.0`

### Before
```json
{
    "dependencies": {
        "@shopware-ag/meteor-admin-sdk": "^3.0.17"
    }
}
```

### After
```json
{
    "dependencies": {
        "@shopware-ag/meteor-admin-sdk": "^4.0.0"
    }
}
```

## Administration tooltips no longer support components/ html
Due to a Vue 3 limitation the `v-tooltip` directive no longer supports components or html.

### Before
```html
<div
    v-tooltip="{
        message: 'For more information click <a href=\"https://shopware.com\">here</a>.',
    }"
</div>
```

### After
```html
<div
    v-tooltip="{
        message: 'For more information visit shopware.com',
    }"
</div>
```
