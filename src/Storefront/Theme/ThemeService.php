<?php declare(strict_types=1);

namespace Shopware\Storefront\Theme;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Feature;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Notification\NotificationService;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Theme\ConfigLoader\AbstractConfigLoader;
use Shopware\Storefront\Theme\ConfigLoader\StaticFileConfigLoader;
use Shopware\Storefront\Theme\Event\ThemeAssignedEvent;
use Shopware\Storefront\Theme\Event\ThemeConfigChangedEvent;
use Shopware\Storefront\Theme\Event\ThemeConfigResetEvent;
use Shopware\Storefront\Theme\Exception\InvalidThemeConfigException;
use Shopware\Storefront\Theme\Exception\ThemeException;
use Shopware\Storefront\Theme\Message\CompileThemeMessage;
use Shopware\Storefront\Theme\StorefrontPluginConfiguration\StorefrontPluginConfigurationCollection;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Service\ResetInterface;

use function Symfony\Component\String\u;

#[Package('framework')]
class ThemeService implements ResetInterface
{
    public const CONFIG_THEME_COMPILE_ASYNC = 'core.storefrontSettings.asyncThemeCompilation';
    public const STATE_NO_QUEUE = 'state-no-queue';

    private bool $notified = false;

    /**
     * @internal
     *
     * @param EntityRepository<ThemeCollection> $themeRepository
     * @param EntityRepository<EntityCollection<Entity>> $themeSalesChannelRepository
     */
    public function __construct(
        private readonly StorefrontPluginRegistry $extensionRegistry,
        private readonly EntityRepository $themeRepository,
        private readonly EntityRepository $themeSalesChannelRepository,
        private readonly ThemeCompilerInterface $themeCompiler,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly AbstractConfigLoader $configLoader,
        private readonly Connection $connection,
        private readonly SystemConfigService $configService,
        private readonly MessageBusInterface $messageBus,
        private readonly NotificationService $notificationService,
    ) {
    }

    /**
     * Only compiles a single theme/saleschannel combination.
     * Use `compileThemeById` to compile all dependend saleschannels
     */
    public function compileTheme(
        string $salesChannelId,
        string $themeId,
        Context $context,
        ?StorefrontPluginConfigurationCollection $configurationCollection = null,
        bool $withAssets = true
    ): void {
        if ($this->isAsyncCompilation($context)) {
            $this->handleAsync($salesChannelId, $themeId, $withAssets, $context);

            return;
        }
        $this->themeCompiler->compileTheme(
            $salesChannelId,
            $themeId,
            $this->configLoader->load($themeId, $context),
            $configurationCollection ?? $this->extensionRegistry->getConfigurations(),
            $withAssets,
            $context
        );
    }

    /**
     * Compiles all dependend saleschannel/Theme combinations
     *
     * @return array<int, string>
     */
    public function compileThemeById(
        string $themeId,
        Context $context,
        ?StorefrontPluginConfigurationCollection $configurationCollection = null,
        bool $withAssets = true
    ): array {
        $mappings = $this->getThemeDependencyMapping($themeId);
        $compiledThemeIds = [];
        foreach ($mappings as $mapping) {
            $this->compileTheme(
                $mapping->getSalesChannelId(),
                $mapping->getThemeId(),
                $context,
                $configurationCollection ?? $this->extensionRegistry->getConfigurations(),
                $withAssets
            );

            $compiledThemeIds[] = $mapping->getThemeId();
        }

        return $compiledThemeIds;
    }

    /**
     * @param array<string, mixed>|null $config
     */
    public function updateTheme(string $themeId, ?array $config, ?string $parentThemeId, Context $context): void
    {
        $criteria = (new Criteria([$themeId]))
            ->addAssociation('salesChannels');

        $theme = $this->themeRepository->search($criteria, $context)->getEntities()->first();
        if (!$theme) {
            throw ThemeException::couldNotFindThemeById($themeId);
        }

        $data = ['id' => $themeId];
        if ($config) {
            foreach ($config as $key => $value) {
                $data['configValues'][$key] = $value;
            }
        }

        if ($parentThemeId) {
            $data['parentThemeId'] = $parentThemeId;
        }

        if (\array_key_exists('configValues', $data)) {
            $this->dispatcher->dispatch(new ThemeConfigChangedEvent($themeId, $data['configValues']));
        }

        if (\array_key_exists('configValues', $data) && $theme->getConfigValues()) {
            $submittedChanges = $data['configValues'];
            $currentConfig = $theme->getConfigValues();
            $data['configValues'] = array_replace_recursive($currentConfig, $data['configValues']);

            foreach ($submittedChanges as $key => $changes) {
                if (isset($changes['value']) && \is_array($changes['value']) && isset($currentConfig[(string) $key]) && \is_array($currentConfig[(string) $key])) {
                    $data['configValues'][$key]['value'] = array_unique($changes['value']);
                }
            }
        }

        $this->themeRepository->update([$data], $context);

        if ($theme->getSalesChannels() === null) {
            return;
        }

        $this->compileThemeById($themeId, $context, null, false);
    }

    public function assignTheme(string $themeId, string $salesChannelId, Context $context, bool $skipCompile = false): bool
    {
        $this->connection->transactional(function () use ($themeId, $salesChannelId, $context, $skipCompile): void {
            if (!$skipCompile) {
                $this->compileTheme($salesChannelId, $themeId, $context);
            }

            $this->themeSalesChannelRepository->upsert([[
                'themeId' => $themeId,
                'salesChannelId' => $salesChannelId,
            ]], $context);
        });

        $this->dispatcher->dispatch(new ThemeAssignedEvent($themeId, $salesChannelId));

        return true;
    }

    public function resetTheme(string $themeId, Context $context): void
    {
        $theme = $this->themeRepository->search(new Criteria([$themeId]), $context)->getEntities()->first();
        if (!$theme) {
            throw ThemeException::couldNotFindThemeById($themeId);
        }

        $data = ['id' => $themeId];
        $data['configValues'] = null;

        $this->dispatcher->dispatch(new ThemeConfigResetEvent($themeId));

        $this->themeRepository->update([$data], $context);
    }

    /**
     * @throws InvalidThemeConfigException
     * @throws ThemeException
     * @throws InconsistentCriteriaIdsException
     *
     * @deprecated tag:v6.8.0 Use `getPlainThemeConfiguration` if you do not need translated labels or help texts or
     * getThemeConfigurationFieldStructure if you need structure with translations
     *
     * @return array<string, mixed>
     */
    public function getThemeConfiguration(string $themeId, bool $translate, Context $context): array
    {
        Feature::triggerDeprecationOrThrow(
            'v6.8.0.0',
            Feature::deprecatedMethodMessage(__CLASS__, __METHOD__, 'v6.8.0.0', 'getPlainThemeConfiguration')
        );

        return $this->getPlainThemeConfiguration($themeId, $context, $translate);
    }

    /**
     * @throws InvalidThemeConfigException
     * @throws ThemeException
     * @throws InconsistentCriteriaIdsException
     *
     * @return array<string, mixed>
     */
    public function getPlainThemeConfiguration(string $themeId, Context $context): array
    {
        $isLegacy = !Feature::isActive('v6.8.0.0');

        if ($isLegacy) {
            $translate = \func_num_args() === 3 ? func_get_arg(2) : false;
        }

        $criteria = (new Criteria())
            ->setTitle('theme-service::load-config');

        $themes = $this->themeRepository->search($criteria, $context)->getEntities();

        $theme = $themes->get($themeId);
        if (!$theme) {
            throw ThemeException::couldNotFindThemeById($themeId);
        }

        $baseTheme = $themes->filter(fn (ThemeEntity $themeEntry) => $themeEntry->getTechnicalName() === StorefrontPluginRegistry::BASE_THEME_NAME)->first();
        if ($baseTheme === null) {
            throw ThemeException::couldNotFindThemeByName(StorefrontPluginRegistry::BASE_THEME_NAME);
        }

        $baseThemeConfig = $this->mergeStaticConfig($baseTheme);

        $themeConfigFieldFactory = new ThemeConfigFieldFactory();
        $configFields = [];

        if ($isLegacy) {
            $labels = array_replace_recursive($baseTheme->getLabels() ?? [], $theme->getLabels() ?? []);
            $helpTexts = array_replace_recursive($baseTheme->getHelpTexts() ?? [], $theme->getHelpTexts() ?? []);
        }

        if ($theme->getParentThemeId()) {
            foreach ($this->getParentThemes($themes, $theme) as $parentTheme) {
                $configuredParentTheme = $this->mergeStaticConfig($parentTheme);
                $baseThemeConfig = array_replace_recursive($baseThemeConfig, $configuredParentTheme);

                if ($isLegacy) {
                    $labels = array_replace_recursive($labels, $parentTheme->getLabels() ?? []);
                    $helpTexts = array_replace_recursive($helpTexts, $parentTheme->getHelpTexts() ?? []);
                }
            }
        }

        $configuredTheme = $this->mergeStaticConfig($theme);
        $themeConfig = array_replace_recursive($baseThemeConfig, $configuredTheme);

        foreach ($themeConfig['fields'] ?? [] as $name => $item) {
            $configFields[$name] = $themeConfigFieldFactory->create($name, $item);
            if (
                isset($item['value'], $configuredTheme['fields'])
                && \is_array($item['value'])
                && \array_key_exists($name, $configuredTheme['fields'])
            ) {
                $configFields[$name]->setValue($configuredTheme['fields'][$name]['value']);
            }
        }

        $configFields = json_decode((string) json_encode($configFields, \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR);

        if ($isLegacy && $translate) {
            if (!empty($labels)) {
                $configFields = $this->translateLabels($configFields, $labels);
            }

            if (!empty($helpTexts)) {
                $configFields = $this->translateHelpTexts($configFields, $helpTexts);
            }
        }

        $themeConfig['themeTechnicalName'] = $theme->getTechnicalName();
        $themeConfig['fields'] = $configFields;
        $themeConfig['currentFields'] = [];
        $themeConfig['baseThemeFields'] = [];

        foreach ($themeConfig['fields'] as $field => $fieldItem) {
            $isInherited = $this->fieldIsInherited($field, $configuredTheme);
            $themeConfig['currentFields'][$field]['isInherited'] = $isInherited;

            if ($isInherited) {
                $themeConfig['currentFields'][$field]['value'] = null;
            } elseif (\array_key_exists('value', $fieldItem)) {
                $themeConfig['currentFields'][$field]['value'] = $fieldItem['value'];
            }

            $isInherited = $this->fieldIsInherited($field, $baseThemeConfig);
            $themeConfig['baseThemeFields'][$field]['isInherited'] = $isInherited;

            if ($isInherited) {
                $themeConfig['baseThemeFields'][$field]['value'] = null;
            } elseif (\array_key_exists('value', $fieldItem) && isset($baseThemeConfig['fields'][$field]['value'])) {
                $themeConfig['baseThemeFields'][$field]['value'] = $baseThemeConfig['fields'][$field]['value'];
            }
        }

        // cleaning up data that we do not want to expose in the v6.8.0.0
        if (Feature::isActive('v6.8.0.0')) {
            // labels are still stored in the database, but we don't want to expose them in the response
            if (isset($themeConfig['blocks'])) {
                foreach ($themeConfig['blocks'] as &$block) {
                    unset($block['label']);
                }
            }

            // remove next block in actual migration to v6.8.0.0, as fields will be removed
            // from ThemeConfigField and resulting array will not contain them anymore
            if (isset($themeConfig['fields'])) {
                foreach ($themeConfig['fields'] as &$field) {
                    unset($field['label']);
                    unset($field['helpText']);
                }
            }
        }

        return $themeConfig;
    }

    /**
     * @deprecated tag:v6.8.0 Use `getThemeConfigurationFieldStructure` instead
     *
     * @return array<string, mixed>
     */
    public function getThemeConfigurationStructuredFields(string $themeId, bool $translate, Context $context): array
    {
        Feature::triggerDeprecationOrThrow(
            'v6.8.0.0',
            Feature::deprecatedMethodMessage(__CLASS__, __METHOD__, 'v6.8.0.0', 'getStructuredThemeConfiguration')
        );

        return $this->getThemeConfigurationFieldStructure($themeId, $context, $translate);
    }

    /**
     * @return array<string, mixed>
     */
    public function getThemeConfigurationFieldStructure(string $themeId, Context $context): array
    {
        $isLegacy = !Feature::isActive('v6.8.0.0');
        if ($isLegacy) {
            $translate = \func_num_args() === 3 ? func_get_arg(2) : false;
            $themeConfig = Feature::silent('v6.8.0.0', function () use ($themeId, $translate, $context) {
                return $this->getThemeConfiguration($themeId, $translate, $context);
            });
        } else {
            $themeConfig = $this->getPlainThemeConfiguration($themeId, $context);
        }

        $themeTechnicalName = (string) $themeConfig['themeTechnicalName'];
        $mergedFieldConfig = $themeConfig['fields'];

        $translations = [];
        if ($isLegacy && $translate) {
            $translations = $this->getTranslations($themeId, $context);
            $mergedFieldConfig = $this->translateLabels($mergedFieldConfig, $translations);
        }

        $outputStructure = [];

        foreach ($mergedFieldConfig as $fieldName => $fieldConfig) {
            $tab = $this->getTab($fieldConfig);
            $block = $this->getBlock($fieldConfig);
            $section = $this->getSection($fieldConfig);

            $outputStructure = $this->addTranslations($outputStructure, $themeTechnicalName, $tab, $block, $section, $translations);

            $custom = $this->buildCustom($fieldConfig['custom'], $themeTechnicalName, $tab, $block, $section, $fieldName);

            $outputStructure['tabs'][$tab]['blocks'][$block]['sections'][$section]['fields'][$fieldName] =
                $this->buildField($fieldConfig, $custom, $themeTechnicalName, $tab, $block, $section, $fieldName);
        }

        return $outputStructure;
    }

    public function getThemeDependencyMapping(string $themeId): ThemeSalesChannelCollection
    {
        $mappings = new ThemeSalesChannelCollection();
        $themeData = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(theme.id)) as id, LOWER(HEX(childTheme.id)) as dependentId,
            LOWER(HEX(tsc.sales_channel_id)) as saleschannelId,
            LOWER(HEX(dtsc.sales_channel_id)) as dsaleschannelId
            FROM theme
            LEFT JOIN theme as childTheme ON childTheme.parent_theme_id = theme.id
            LEFT JOIN theme_sales_channel as tsc ON theme.id = tsc.theme_id
            LEFT JOIN theme_sales_channel as dtsc ON childTheme.id = dtsc.theme_id
            WHERE theme.id = :id',
            ['id' => Uuid::fromHexToBytes($themeId)]
        );

        foreach ($themeData as $data) {
            if (isset($data['id']) && isset($data['saleschannelId']) && $data['id'] === $themeId) {
                $mappings->add(new ThemeSalesChannel($data['id'], $data['saleschannelId']));
            }
            if (isset($data['dependentId']) && isset($data['dsaleschannelId'])) {
                $mappings->add(new ThemeSalesChannel($data['dependentId'], $data['dsaleschannelId']));
            }
        }

        return $mappings;
    }

    public function reset(): void
    {
        $this->notified = false;
    }

    /**
     * @param array<string, mixed> $fieldConfig
     * @param array<string, mixed>|null $custom
     *
     * @return array<string, mixed>
     */
    private function buildField(array $fieldConfig, ?array $custom, string $themeTechnicalName, string $tab, string $block, string $section, string $fieldName): array
    {
        $field = [
            'labelSnippetKey' => $this->buildSnippetKey(
                $themeTechnicalName,
                false,
                $tab,
                $block,
                $section,
                $fieldName,
            ),
            'helpTextSnippetKey' => $this->buildSnippetKey(
                $themeTechnicalName,
                true,
                $tab,
                $block,
                $section,
                $fieldName,
            ),
            'type' => $fieldConfig['type'] ?? null,
            'custom' => $custom,
            'fullWidth' => $fieldConfig['fullWidth'],
        ];

        if (!Feature::isActive('v6.8.0.0')) {
            $field['label'] = $fieldConfig['label'];
            $field['helpText'] = $fieldConfig['helpText'] ?? null;
        }

        return $field;
    }

    private function handleAsync(
        string $salesChannelId,
        string $themeId,
        bool $withAssets,
        Context $context
    ): void {
        $this->messageBus->dispatch(
            new CompileThemeMessage(
                $salesChannelId,
                $themeId,
                $withAssets,
                $context
            )
        );

        if ($this->notified !== true && $context->getScope() === Context::USER_SCOPE) {
            $this->notificationService->createNotification(
                [
                    'id' => Uuid::randomHex(),
                    'status' => 'info',
                    'message' => 'The compilation of the changes will be started in the background. You may see the changes with delay (approx. 1 minute). You will receive a notification if the compilation is done.',
                    'requiredPrivileges' => [],
                ],
                $context
            );
            $this->notified = true;
        }
    }

    /**
     * @param array<string, ThemeEntity> $parentThemes
     *
     * @return array<string, ThemeEntity>
     */
    private function getParentThemes(ThemeCollection $themes, ThemeEntity $mainTheme, array $parentThemes = []): array
    {
        foreach ($this->getConfigInheritance($mainTheme) as $parentThemeName) {
            $parentTheme = $themes->filter(fn (ThemeEntity $themeEntry) => $themeEntry->getTechnicalName() === str_replace('@', '', (string) $parentThemeName))->first();

            if ($parentTheme instanceof ThemeEntity && !\array_key_exists($parentTheme->getId(), $parentThemes)) {
                $parentThemes[$parentTheme->getId()] = $parentTheme;

                if ($parentTheme->getParentThemeId()) {
                    $parentThemes = $this->getParentThemes($themes, $mainTheme, $parentThemes);
                }
            }
        }

        if ($mainTheme->getParentThemeId()) {
            $parentTheme = $themes->filter(fn (ThemeEntity $themeEntry) => $themeEntry->getId() === $mainTheme->getParentThemeId())->first();

            if ($parentTheme instanceof ThemeEntity && !\array_key_exists($parentTheme->getId(), $parentThemes)) {
                $parentThemes[$parentTheme->getId()] = $parentTheme;
                if ($parentTheme->getParentThemeId()) {
                    $parentThemes = $this->getParentThemes($themes, $mainTheme, $parentThemes);
                }
            }
        }

        return $parentThemes;
    }

    /**
     * @return array<int, string>
     */
    private function getConfigInheritance(ThemeEntity $mainTheme): array
    {
        if (\is_array($mainTheme->getBaseConfig())
            && \array_key_exists('configInheritance', $mainTheme->getBaseConfig())
            && \is_array($mainTheme->getBaseConfig()['configInheritance'])
            && !empty($mainTheme->getBaseConfig()['configInheritance'])
        ) {
            return $mainTheme->getBaseConfig()['configInheritance'];
        }

        if ($mainTheme->getTechnicalName() !== StorefrontPluginRegistry::BASE_THEME_NAME) {
            return [
                '@' . StorefrontPluginRegistry::BASE_THEME_NAME,
            ];
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private function mergeStaticConfig(ThemeEntity $theme): array
    {
        $configuredTheme = [];

        $pluginConfig = null;
        if ($theme->getTechnicalName()) {
            $pluginConfig = $this->extensionRegistry->getConfigurations()->getByTechnicalName($theme->getTechnicalName());
        }

        if ($pluginConfig !== null) {
            $configuredTheme = $pluginConfig->getThemeConfig();
        }

        if ($theme->getBaseConfig() !== null) {
            $configuredTheme = array_replace_recursive($configuredTheme ?? [], $theme->getBaseConfig());
        }

        if ($theme->getConfigValues() !== null) {
            foreach ($theme->getConfigValues() as $fieldName => $configValue) {
                if (\array_key_exists('value', $configValue)) {
                    $configuredTheme['fields'][$fieldName]['value'] = $configValue['value'];
                }
            }
        }

        return $configuredTheme ?: [];
    }

    /**
     * @param array<string, mixed> $fieldConfig
     */
    private function getTab(array $fieldConfig): string
    {
        $tab = 'default';

        if (isset($fieldConfig['tab'])) {
            $tab = $fieldConfig['tab'];
        }

        return $tab;
    }

    /**
     * @param array<string, mixed> $fieldConfig
     */
    private function getBlock(array $fieldConfig): string
    {
        $block = 'default';

        if (isset($fieldConfig['block'])) {
            $block = $fieldConfig['block'];
        }

        return $block;
    }

    /**
     * @param array<string, mixed> $fieldConfig
     */
    private function getSection(array $fieldConfig): string
    {
        $section = 'default';

        if (isset($fieldConfig['section'])) {
            $section = $fieldConfig['section'];
        }

        return $section;
    }

    /**
     * @param array<string, mixed> $translations
     */
    private function getTabLabel(string $tabName, array $translations): string
    {
        if ($tabName === 'default') {
            return '';
        }

        return $translations['tabs.' . $tabName] ?? $tabName;
    }

    /**
     * @param array<string, mixed> $translations
     */
    private function getBlockLabel(string $blockName, array $translations): string
    {
        if ($blockName === 'default') {
            return '';
        }

        return $translations['blocks.' . $blockName] ?? $blockName;
    }

    /**
     * @param array<string, mixed> $translations
     */
    private function getSectionLabel(string $sectionName, array $translations): string
    {
        if ($sectionName === 'default') {
            return '';
        }

        return $translations['sections.' . $sectionName] ?? $sectionName;
    }

    /**
     * @param array<string, mixed> $themeConfiguration
     * @param array<string, mixed> $translations
     *
     * @return array<string, mixed>
     */
    private function translateLabels(array $themeConfiguration, array $translations): array
    {
        foreach ($themeConfiguration as $key => &$value) {
            $value['label'] = $translations['fields.' . $key] ?? $key;
        }

        return $themeConfiguration;
    }

    /**
     * @param array<string, mixed> $themeConfiguration
     * @param array<string, mixed> $translations
     *
     * @return array<string, mixed>
     */
    private function translateHelpTexts(array $themeConfiguration, array $translations): array
    {
        foreach ($themeConfiguration as $key => &$value) {
            $value['helpText'] = $translations['fields.' . $key] ?? null;
        }

        return $themeConfiguration;
    }

    /**
     * @return array<string, mixed>
     */
    private function getTranslations(string $themeId, Context $context): array
    {
        $theme = $this->themeRepository->search(new Criteria([$themeId]), $context)->getEntities()->first();
        if (!$theme) {
            throw ThemeException::couldNotFindThemeById($themeId);
        }

        $translations = $theme->getLabels() ?: [];

        if ($theme->getTechnicalName() !== StorefrontPluginRegistry::BASE_THEME_NAME) {
            $criteria = (new Criteria())
                ->setTitle('theme-service::load-translations');

            $themes = $this->themeRepository->search($criteria, $context)->getEntities();
            foreach ($this->getParentThemes($themes, $theme) as $parentTheme) {
                $parentTranslations = $parentTheme->getLabels() ?: [];
                $translations = array_replace_recursive($parentTranslations, $translations);
            }
        }

        return $translations;
    }

    /**
     * @param array<string, mixed> $configuration
     */
    private function fieldIsInherited(string $fieldName, array $configuration): bool
    {
        if (!isset($configuration['fields'])) {
            return true;
        }

        if (!\is_array($configuration['fields'])) {
            return true;
        }

        if (!\array_key_exists($fieldName, $configuration['fields'])) {
            return true;
        }

        return false;
    }

    private function isAsyncCompilation(Context $context): bool
    {
        if ($this->configLoader instanceof StaticFileConfigLoader) {
            return false;
        }

        return $this->configService->get(self::CONFIG_THEME_COMPILE_ASYNC) && !$context->hasState(self::STATE_NO_QUEUE);
    }

    private function buildSnippetKey(string $themeTechnicalName, bool $isHelpText, string ...$parts): string
    {
        return implode(
            '.',
            [
                'sw-theme',
                u($themeTechnicalName)->kebab(),
                ...$parts,
                $isHelpText ? 'helpText' : 'label',
            ],
        );
    }

    /**
     * @param array<string,mixed>|null $custom
     * @param string $themeTechnicalName
     *
     * @return ?array<string, mixed>
     */
    private function buildCustom(?array $custom, mixed $themeTechnicalName, string $tab, string $block, string $section, string $fieldName): ?array
    {
        $custom = $custom ?? null;

        if ($custom && \is_array($custom['options'])) {
            foreach ($custom['options'] as $optionIndex => &$option) {
                $option['labelSnippetKey'] = $this->buildSnippetKey(
                    $themeTechnicalName,
                    false,
                    $tab,
                    $block,
                    $section,
                    $fieldName,
                    (string) $optionIndex,
                );
            }
            unset($option);
        }

        return $custom;
    }

    /**
     * @param array<string, mixed> $outputStructure
     * @param array<string, mixed> $translations
     *
     * @return array<string, mixed>
     */
    private function addTranslations(array $outputStructure, string $themeTechnicalName, string $tab, string $block, string $section, array $translations): array
    {
        $tabSnippetKey = $this->buildSnippetKey($themeTechnicalName, false, $tab);
        $blockSnippetKey = $this->buildSnippetKey($themeTechnicalName, false, $tab, $block);
        $sectionSnippetKey = $this->buildSnippetKey($themeTechnicalName, false, $tab, $block, $section);

        // set labels
        $outputStructure['tabs'][$tab]['labelSnippetKey'] = $tabSnippetKey;
        $outputStructure['tabs'][$tab]['blocks'][$block]['labelSnippetKey'] = $blockSnippetKey;
        $outputStructure['tabs'][$tab]['blocks'][$block]['sections'][$section]['labelSnippetKey'] = $sectionSnippetKey;

        if (!Feature::isActive('v6.8.0.0')) {
            // set default tab
            $outputStructure['tabs']['default']['label'] = '';

            // set labels
            $tabLabel = $this->getTabLabel($tab, $translations);
            $blockLabel = $this->getBlockLabel($block, $translations);
            $sectionLabel = $this->getSectionLabel($section, $translations);
            $outputStructure['tabs'][$tab]['label'] = $tabLabel;
            $outputStructure['tabs'][$tab]['blocks'][$block]['label'] = $blockLabel;
            $outputStructure['tabs'][$tab]['blocks'][$block]['sections'][$section]['label'] = $sectionLabel;
        }

        return $outputStructure;
    }
}
