<?php declare(strict_types=1);

namespace Shopware\Storefront\Theme;

use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\Visibility;
use Psr\Log\LoggerInterface;
use ScssPhp\ScssPhp\OutputStyle;
use Shopware\Core\Framework\Adapter\Cache\CacheInvalidator;
use Shopware\Core\Framework\Adapter\Filesystem\Plugin\CopyBatch;
use Shopware\Core\Framework\Adapter\Filesystem\Plugin\CopyBatchInput;
use Shopware\Core\Framework\Adapter\Filesystem\Plugin\CopyBatchInputFactory;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Feature;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Storefront\Event\ThemeCompilerConcatenatedStylesEvent;
use Shopware\Storefront\Theme\Event\ThemeCompilerEnrichScssVariablesEvent;
use Shopware\Storefront\Theme\Exception\ThemeException;
use Shopware\Storefront\Theme\StorefrontPluginConfiguration\File;
use Shopware\Storefront\Theme\StorefrontPluginConfiguration\FileCollection;
use Shopware\Storefront\Theme\StorefrontPluginConfiguration\StorefrontPluginConfiguration;
use Shopware\Storefront\Theme\StorefrontPluginConfiguration\StorefrontPluginConfigurationCollection;
use Shopware\Storefront\Theme\Validator\SCSSValidator;
use Symfony\Component\Asset\Package as AssetPackage;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Finder\Exception\DirectoryNotFoundException;
use Symfony\Component\Finder\Finder;

#[Package('framework')]
class ThemeCompiler implements ThemeCompilerInterface
{
    /**
     * @internal
     *
     * @param array<string, AssetPackage> $packages
     * @param array<int, string> $customAllowedRegex
     * @param array{visibility?: string} $themeFilesystemConfig
     */
    public function __construct(
        private readonly FilesystemOperator $filesystem,
        private readonly FilesystemOperator $tempFilesystem,
        private readonly CopyBatchInputFactory $copyBatchInputFactory,
        private readonly ThemeFileResolver $themeFileResolver,
        private readonly bool $debug,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly ThemeFilesystemResolver $themeFilesystemResolver,
        private readonly iterable $packages,
        private readonly CacheInvalidator $cacheInvalidator,
        private readonly LoggerInterface $logger,
        private readonly AbstractThemePathBuilder $themePathBuilder,
        private readonly AbstractScssCompiler $scssCompiler,
        private readonly array $customAllowedRegex = [],
        private readonly bool $validate = false,
        private readonly array $themeFilesystemConfig = [],
    ) {
    }

    public function compileTheme(
        string $salesChannelId,
        string $themeId,
        StorefrontPluginConfiguration $themeConfig,
        StorefrontPluginConfigurationCollection $configurationCollection,
        bool $withAssets,
        Context $context
    ): void {
        try {
            $resolvedFiles = $this->themeFileResolver->resolveFiles($themeConfig, $configurationCollection, false);

            $styleFiles = $resolvedFiles[ThemeFileResolver::STYLE_FILES];
        } catch (\Throwable $e) {
            throw ThemeException::themeCompileException(
                $themeConfig->getName() ?? '',
                'Files could not be resolved with error: ' . $e->getMessage(),
                $e
            );
        }

        try {
            $concatenatedStyles = $this->concatenateStyles($styleFiles, $salesChannelId);
        } catch (\Throwable $e) {
            throw ThemeException::themeCompileException(
                $themeConfig->getName() ?? '',
                'Error while trying to concatenate Styles: ' . $e->getMessage(),
                $e
            );
        }

        $compiled = $this->compileStyles(
            $concatenatedStyles,
            $themeConfig,
            $styleFiles->getResolveMappings(),
            $salesChannelId,
            $themeId,
            $context
        );

        $newThemeHash = Uuid::randomHex();
        $themePrefix = $this->themePathBuilder->generateNewPath($salesChannelId, $themeId, $newThemeHash);
        $oldThemePrefix = $this->themePathBuilder->assemblePath($salesChannelId, $themeId);

        // If the system does not use seeded theme paths,
        // we have to delete the complete folder before to ensure that old files are deleted
        if ($oldThemePrefix === $themePrefix) {
            $path = 'theme' . \DIRECTORY_SEPARATOR . $themePrefix;

            $this->filesystem->deleteDirectory($path);
        }

        try {
            $assets = $this->collectCompiledFiles($themePrefix, $themeId, $compiled, $withAssets, $themeConfig, $configurationCollection);
        } catch (\Throwable $e) {
            throw ThemeException::themeCompileException(
                $themeConfig->getName() ?? '',
                'Error while trying to write compiled files: ' . $e->getMessage(),
                $e
            );
        }

        $scriptFiles = $this->copyScriptFilesToTheme($configurationCollection, $themePrefix);

        CopyBatch::copy($this->filesystem, ...$assets, ...$scriptFiles);

        $this->themePathBuilder->saveSeed($salesChannelId, $themeId, $newThemeHash);

        $this->cacheInvalidator->invalidate([
            CachedResolvedConfigLoader::buildName($themeId),
        ]);
    }

    /**
     * @param array<string, string> $resolveMappings
     */
    public function getResolveImportPathsCallback(array $resolveMappings): \Closure
    {
        return function (string $originalPath) use ($resolveMappings): ?string {
            foreach ($resolveMappings as $resolve => $resolvePath) {
                $resolve = '~' . $resolve;
                if (mb_strpos($originalPath, $resolve) === 0) {
                    $dirname = $resolvePath . \dirname(mb_substr($originalPath, mb_strlen($resolve)));

                    $filename = basename($originalPath);
                    $extension = $this->getImportFileExtension(pathinfo($filename, \PATHINFO_EXTENSION));
                    $path = $dirname . \DIRECTORY_SEPARATOR . $filename . $extension;
                    if (file_exists($path)) {
                        return $path;
                    }

                    $path = $dirname . \DIRECTORY_SEPARATOR . '_' . $filename . $extension;
                    if (file_exists($path)) {
                        return $path;
                    }
                }
            }

            return null;
        };
    }

    /**
     * @return list<CopyBatchInput>
     */
    private function copyScriptFilesToTheme(
        StorefrontPluginConfigurationCollection $configurationCollection,
        string $themePrefix
    ): array {
        $scriptsDist = $this->getScriptDistFolders($configurationCollection);
        $themePath = 'theme/' . $themePrefix;
        $distRelativePath = 'Resources/app/storefront/dist/storefront';

        $copyFiles = [];

        foreach ($scriptsDist as $folderName => $pluginConfig) {
            // For themes, we get basePath with Resources and for Plugins without, so we always remove and add it again
            $pathToJsFiles = $distRelativePath;
            if ($folderName !== 'storefront') {
                $pathToJsFiles .= '/js/' . $folderName;
            }

            $fs = $this->themeFilesystemResolver->getFilesystemForStorefrontConfig($pluginConfig);

            if ($fs->has($pathToJsFiles)) {
                $pathToJsFiles = $fs->realpath($pathToJsFiles);
            }

            $files = $this->getScriptDistFiles($pathToJsFiles);

            if ($files === null) {
                continue;
            }

            $targetPath = $themePath . '/js/' . $folderName;
            foreach ($files as $file) {
                if (file_exists($file->getRealPath())) {
                    $copyFiles[] = new CopyBatchInput($file->getRealPath(), [$targetPath . '/' . $file->getFilename()], $this->themeFilesystemConfig['visibility'] ?? Visibility::PUBLIC);
                }
            }
        }

        return $copyFiles;
    }

    /**
     * @return array<string, StorefrontPluginConfiguration>
     */
    private function getScriptDistFolders(StorefrontPluginConfigurationCollection $configurationCollection): array
    {
        $scriptsDistFolders = [];
        foreach ($configurationCollection as $configuration) {
            $scripts = $configuration->getScriptFiles();
            foreach ($scripts as $key => $script) {
                if ($script->getFilepath() === '@Storefront') {
                    $scripts->remove($key);
                }
            }
            if ($scripts->count() === 0) {
                continue;
            }

            $scriptsDistFolders[$configuration->getAssetName()] = $configuration;
        }

        return $scriptsDistFolders;
    }

    private function getScriptDistFiles(string $path): ?Finder
    {
        try {
            $finder = (new Finder())->files()->followLinks()->in($path)->exclude('js');
        } catch (DirectoryNotFoundException $e) {
            $this->logger->error($e->getMessage());
        }

        return $finder ?? null;
    }

    /**
     * @return list<CopyBatchInput>
     */
    private function getAssets(
        StorefrontPluginConfiguration $configuration,
        StorefrontPluginConfigurationCollection $configurationCollection,
        string $outputPath
    ): array {
        $collected = [];

        if (!$configuration->getAssetPaths()) {
            return [];
        }

        foreach ($configuration->getAssetPaths() as $asset) {
            if (mb_strpos((string) $asset, '@') === 0) {
                $name = mb_substr((string) $asset, 1);
                $config = $configurationCollection->getByTechnicalName($name);
                if (!$config) {
                    throw ThemeException::couldNotFindThemeByName($name);
                }

                $collected = [...$collected, ...$this->getAssets($config, $configurationCollection, $outputPath)];

                continue;
            }

            $fs = $this->themeFilesystemResolver->getFilesystemForStorefrontConfig($configuration);
            if ($asset[0] !== '/' && $fs->has('Resources', $asset)) {
                $asset = $fs->path('Resources', $asset);
            }

            $collected = [...$collected, ...$this->copyBatchInputFactory->fromDirectory($asset, $outputPath, $this->themeFilesystemConfig['visibility'] ?? Visibility::PUBLIC)];
        }

        return array_values($collected);
    }

    /**
     * @param array<string, string> $resolveMappings
     */
    private function compileStyles(
        string $concatenatedStyles,
        StorefrontPluginConfiguration $configuration,
        array $resolveMappings,
        string $salesChannelId,
        string $themeId,
        Context $context
    ): string {
        try {
            $variables = $this->dumpVariables($configuration->getThemeConfig() ?? [], $themeId, $salesChannelId, $context);
            $features = $this->getFeatureConfigScssMap();
            $resolveImportPath = $this->getResolveImportPathsCallback($resolveMappings);

            $importPaths = [];

            $cwd = \getcwd();
            if ($cwd !== false) {
                $importPaths[] = $cwd;
            }

            $importPaths[] = $resolveImportPath;

            $compilerConfig = new CompilerConfiguration(
                [
                    'importPaths' => $importPaths,
                    'outputStyle' => $this->debug ? OutputStyle::EXPANDED : OutputStyle::COMPRESSED,
                ]
            );

            $cssOutput = $this->scssCompiler->compileString(
                $compilerConfig,
                $features . $variables . $concatenatedStyles
            );
        } catch (\Throwable $exception) {
            throw ThemeException::themeCompileException(
                $configuration->getTechnicalName(),
                $exception->getMessage(),
                $exception
            );
        }

        return $cssOutput;
    }

    private function getImportFileExtension(string $extension): string
    {
        // If the import has no extension, it must be a SCSS module.
        if ($extension === '') {
            return '.scss';
        }

        // If the import has a .min extension, we assume it must be a compiled CSS file.
        if ($extension === 'min') {
            return '.css';
        }

        // If it has any other extension, we don't assume a specific extension.
        return '';
    }

    /**
     * Converts the feature config array to a SCSS map syntax.
     * This allows reading of the feature flag config inside SCSS via `map.get` function.
     *
     * Output example:
     * $sw-features: ("FEATURE_NEXT_1234": false, "FEATURE_NEXT_1235": true);
     *
     * @see https://sass-lang.com/documentation/values/maps
     */
    private function getFeatureConfigScssMap(): string
    {
        $allFeatures = Feature::getAll();

        $featuresScss = implode(',', array_map(fn ($value, $key) => \sprintf('"%s": %s', $key, json_encode($value, \JSON_THROW_ON_ERROR)), $allFeatures, array_keys($allFeatures)));

        return \sprintf('$sw-features: (%s);', $featuresScss);
    }

    /**
     * Creates the strings that will be written to the SCSS file.
     * If variables have no or nullish value they will be written as "null" in SCSS.
     *
     * @param array<string, string|int|null> $variables
     *
     * @return array<string>
     */
    private function formatVariables(array $variables): array
    {
        return array_map(fn ($value, $key) => \sprintf(
            '$%s: %s;',
            $key,
            isset($value) && $value !== '' ? $value : 'null'
        ), $variables, array_keys($variables));
    }

    /**
     * @param array{fields?: array{value: string|array<mixed>|null, scss?: bool, type: string}[]} $config
     *
     * @throws FilesystemException
     */
    private function dumpVariables(array $config, string $themeId, string $salesChannelId, Context $context): string
    {
        $variables = [
            'theme-id' => $themeId,
        ];

        foreach ($config['fields'] ?? [] as $key => $data) {
            if (
                !\is_array($data)
                || (\array_key_exists('scss', $data) && $data['scss'] === false)
                || !isset($data['type'])
            ) {
                continue;
            }

            if ($this->validate) {
                $data['value'] = SCSSValidator::validate($this->scssCompiler, $data, $this->customAllowedRegex, true);
            }

            if (!\array_key_exists('value', $data)) {
                // If a variable does not exist, it should still be written with a null value.
                $variables[$key] = null;
                continue;
            }

            if (
                \in_array($data['type'], ['media', 'textarea'], true)
                && \is_string($data['value'])
                && !\str_starts_with($data['value'], '\'')
                && !\str_ends_with($data['value'], '\'')
            ) {
                $variables[$key] = '\'' . $data['value'] . '\'';
            } elseif ($data['type'] === 'switch' || $data['type'] === 'checkbox') {
                $variables[$key] = (int) $data['value'];
            } elseif (!\is_array($data['value'])) {
                $variables[$key] = (string) $data['value'];
            }
        }

        foreach ($this->packages as $key => $package) {
            $variables[\sprintf('sw-asset-%s-url', $key)] = \sprintf('\'%s\'', $package->getUrl(''));
        }

        $themeVariablesEvent = new ThemeCompilerEnrichScssVariablesEvent(
            $variables,
            $salesChannelId,
            $context
        );

        $this->eventDispatcher->dispatch($themeVariablesEvent);

        $dump = str_replace(
            ['#class#', '#variables#'],
            [self::class, implode(\PHP_EOL, $this->formatVariables($themeVariablesEvent->getVariables()))],
            $this->getVariableDumpTemplate()
        );

        $this->tempFilesystem->write('theme-variables.scss', $dump);
        $this->tempFilesystem->write('theme-variables/' . $themeId . '.scss', $dump);

        return $dump;
    }

    private function getVariableDumpTemplate(): string
    {
        return <<<PHP_EOL
// ATTENTION! This file is auto generated by the #class# and should not be edited.

#variables#

PHP_EOL;
    }

    private function concatenateStyles(
        FileCollection $styleFiles,
        string $salesChannelId
    ): string {
        $styles = $styleFiles->map(fn (File $file) => \sprintf('@import \'%s\';', $file->getFilepath()));

        $concatenatedStylesEvent = new ThemeCompilerConcatenatedStylesEvent(
            implode("\n", $styles),
            $salesChannelId
        );
        $this->eventDispatcher->dispatch($concatenatedStylesEvent);

        return $concatenatedStylesEvent->getConcatenatedStyles();
    }

    /**
     * @return list<CopyBatchInput>
     */
    private function collectCompiledFiles(
        string $themePrefix,
        string $themeId,
        string $compiled,
        bool $withAssets,
        StorefrontPluginConfiguration $themeConfig,
        StorefrontPluginConfigurationCollection $configurationCollection
    ): array {
        $compileLocation = 'theme' . \DIRECTORY_SEPARATOR . $themePrefix;

        $tempStream = fopen('php://temp', 'rwb');

        \assert(\is_resource($tempStream));
        fwrite($tempStream, $compiled);
        rewind($tempStream);

        $files = [
            new CopyBatchInput(
                $tempStream,
                [
                    $compileLocation . \DIRECTORY_SEPARATOR . 'css' . \DIRECTORY_SEPARATOR . 'all.css',
                ],
                $this->themeFilesystemConfig['visibility'] ?? Visibility::PUBLIC
            ),
        ];

        // assets
        if ($withAssets) {
            $assetPath = 'theme' . \DIRECTORY_SEPARATOR . $themeId;

            try {
                $this->filesystem->deleteDirectory($assetPath);
            } catch (UnableToDeleteDirectory) {
            }

            $files = [...$files, ...$this->getAssets($themeConfig, $configurationCollection, $assetPath)];
        }

        return $files;
    }
}
