<?php
namespace FluidTYPO3\Flux;

/*
 * This file is part of the FluidTYPO3/Flux project under GPLv2 or later.
 *
 * For the full copyright and license information, please read the
 * LICENSE.md file that was distributed with this source code.
 */

use FluidTYPO3\Flux\Content\ContentTypeManager;
use FluidTYPO3\Flux\Hooks\HookHandler;
use FluidTYPO3\Flux\Provider\Provider;
use FluidTYPO3\Flux\Provider\ProviderInterface;
use FluidTYPO3\Flux\Utility\ExtensionNamingUtility;
use Symfony\Component\Finder\Finder;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;
use TYPO3\CMS\Extbase\Object\ObjectManager;
use TYPO3\CMS\Extbase\Object\ObjectManagerInterface;

/**
 * Quick-access API methods to easily integrate with Flux
 */
class Core
{
    const CONTROLLER_ALL = '_all';

    /**
     * @var ObjectManagerInterface
     */
    protected static $objectManager;

    /**
     * Contains all ConfigurationProviders registered with Flux
     * @var array
     */
    protected static $providers = [];

    /**
     * @var array
     */
    protected static $pipes = [];

    /**
     * @var array
     */
    protected static $outlets = [];

    /**
     * Contains all Forms for tables registered with Flux
     * @var array
     */
    private static $forms = [
        'tables' => [],
    ];

    /**
     * Contains ConfigurationProviders which have been unregistered
     * @var array
     */
    private static $unregisteredProviders = [];

    /**
     * Contains all extensions registered with Flux
     * @var array
     */
    protected static $extensions = [
        self::CONTROLLER_ALL => []
    ];

    /**
     * Contains queued instructions to call \TYPO3\CMS\Extbase\Utility\ExtensionUtility::registerPlugin in later hook
     * @var array
     */
    protected static $queuedContentTypeRegistrations = [];

    /**
     * @return array
     */
    public static function getQueuedContentTypeRegistrations()
    {
        return self::$queuedContentTypeRegistrations;
    }

    /**
     * @return void
     */
    public static function clearQueuedContentTypeRegistrations()
    {
        self::$queuedContentTypeRegistrations = [];
    }

    /**
     * @param string $table
     * @param Form $form
     * @return void
     */
    public static function registerFormForTable($table, Form $form)
    {
        if (null === $form->getName()) {
            $form->setName($table);
        }
        if (null === $form->getExtensionName() && true === isset($GLOBALS['_EXTKEY'])) {
            $form->setExtensionName(GeneralUtility::underscoredToUpperCamelCase($GLOBALS['_EXTKEY']));
        }
        self::$forms['tables'][$table] = $form;
    }

    /**
     * @param string $extensionKey
     * @param string $providesControllerName
     * @return void
     */
    public static function registerProviderExtensionKey($extensionKey, $providesControllerName = self::CONTROLLER_ALL)
    {
        if ($providesControllerName === 'Content' && !ExtensionManagementUtility::isLoaded('fluidcontent')) {
            // Special temporary case - when fluidcontent is not installed, Flux takes over and registers all
            // detected template files as native CTypes. Remove if/when fluidcontent is discontinued.
            $legacyKey = ExtensionNamingUtility::getExtensionKey($extensionKey);
            $templateRootPath = ExtensionManagementUtility::extPath($legacyKey, 'Resources/Private/Templates/Content/');
            /** @var ContentTypeManager $contentTypeManager */
            $contentTypeManager = GeneralUtility::makeInstance(ContentTypeManager::class);
            $finder = Finder::create()->in($templateRootPath)->name('*.html')->sortByName();
            foreach ($finder->files() as $file) {
                /** @var \SplFileInfo $file */
                $contentTypeName = str_replace('_', '', $legacyKey)
                    . '_'
                    . strtolower(substr(str_replace(DIRECTORY_SEPARATOR, '', $file->getRelativePathname()), 0, -5));
                self::registerTemplateAsContentType($extensionKey, $file->getPathname(), $contentTypeName);
                $contentTypeManager->registerTypeName($contentTypeName);
            }
        }
        if (false === isset(self::$extensions[$providesControllerName])) {
            self::$extensions[$providesControllerName] = [];
        }

        if (false === in_array($extensionKey, self::$extensions[$providesControllerName])) {
            $overrides = HookHandler::trigger(
                HookHandler::PROVIDER_EXTENSION_REGISTERED,
                [
                    'extensionKey' => $extensionKey,
                    'providesControllerName' => $providesControllerName
                ]
            );
            array_push(self::$extensions[$overrides['providesControllerName']], $overrides['extensionKey']);
        }
    }

    /**
     * @param string $forControllerName
     * @return array
     */
    public static function getRegisteredProviderExtensionKeys($forControllerName)
    {
        if (true === isset(self::$extensions[$forControllerName])) {
            return array_unique(
                array_merge(self::$extensions[self::CONTROLLER_ALL], self::$extensions[$forControllerName])
            );
        }
        return self::$extensions[self::CONTROLLER_ALL];
    }

    /**
     * Registers a class implementing one of the Flux ConfigurationProvider
     * interfaces.
     *
     * @param string|object $classNameOrInstance
     * @return void
     * @throws \RuntimeException
     */
    public static function registerConfigurationProvider($classNameOrInstance)
    {
        $alreadyRegistered = in_array($classNameOrInstance, self::$providers);
        $alreadyUnregistered = in_array($classNameOrInstance, self::$unregisteredProviders);
        if (!$alreadyUnregistered && !$alreadyRegistered) {
            $classNameOrInstance = HookHandler::trigger(
                HookHandler::PROVIDER_REGISTERED,
                [
                    'provider' => $classNameOrInstance
                ]
            )['provider'];
            array_push(self::$providers, $classNameOrInstance);
        }
    }

    /**
     * Registers a Fluid template for use as a Dynamic Flex Form template in the
     * style of Flux's Fluid Content Element and Fluid Page configurations. See
     * documentation web site for more detailed information about how to
     * configure such a FlexForm template.
     *
     * Note: you can point to your Model Object templates and place the
     * configuration in these templates - and get automatically transformed
     * values from your FlexForms, i.e. a Domain Object instance from a "group"
     * type select box or an ObjectStorage from a list of records. Usual output
     * is completely ignored, only the "Configuration" section is considered.
     *
     * @param string $extensionKey The extension key which registered this FlexForm
     * @param string $pluginSignature The plugin signature this FlexForm belongs to
     * @param string $templateFilename Location of the Fluid template containing field definitions
     * @param array $variables Optional array of variables to pass to Fluid template
     * @param string|null $section Optional section name containing the configuration
     * @param array|null $paths Optional paths array / Closure to return paths
     * @param string $fieldName Optional fieldname if not from pi_flexform
     * @return ProviderInterface
     */
    public static function registerFluidFlexFormPlugin(
        $extensionKey,
        $pluginSignature,
        $templateFilename,
        $variables = [],
        $section = null,
        $paths = null,
        $fieldName = 'pi_flexform'
    ) {
        $objectManager = static::getObjectManager();
        /** @var ProviderInterface $provider */
        $provider = $objectManager->get(Provider::class);
        $provider->setTableName('tt_content');
        $provider->setFieldName($fieldName);
        $provider->setExtensionKey($extensionKey);
        $provider->setListType($pluginSignature);
        $provider->setTemplatePathAndFilename($templateFilename);
        $provider->setTemplateVariables($variables);
        $provider->setTemplatePaths($paths);
        $provider->setConfigurationSectionName($section);
        self::registerConfigurationProvider($provider);
        return $provider;
    }

    /**
     * Same as registerFluidFlexFormPlugin, but uses a content object type for
     * resolution - use this if you registered your Extbase plugin as a content
     * object in your localconf.
     *
     * @param string $extensionKey The extension key which registered this FlexForm
     * @param string $contentObjectType The cType of the object you registered
     * @param string $templateFilename Location of the Fluid template containing field definitions
     * @param array $variables Optional array of variables to pass to Fluid template
     * @param string|null $section Optional section name containing the configuration
     * @param array|null $paths Optional paths array / Closure to return paths
     * @param string $fieldName Optional fieldname if not from pi_flexform
     * @return ProviderInterface
     */
    public static function registerFluidFlexFormContentObject(
        $extensionKey,
        $contentObjectType,
        $templateFilename,
        $variables = [],
        $section = null,
        $paths = null,
        $fieldName = 'pi_flexform'
    ) {
        $objectManager = static::getObjectManager();
        /** @var ProviderInterface $provider */
        $provider = $objectManager->get(Provider::class);
        $provider->setTableName('tt_content');
        $provider->setFieldName($fieldName);
        $provider->setExtensionKey($extensionKey);
        $provider->setTemplatePathAndFilename($templateFilename);
        $provider->setTemplateVariables($variables);
        $provider->setTemplatePaths($paths);
        $provider->setConfigurationSectionName($section);
        $provider->setContentObjectType($contentObjectType);
        self::registerConfigurationProvider($provider);
        return $provider;
    }

    /**
     * Same as registerFluidFlexFormPlugin, but enables registering FlexForms
     * for any TCA field (type "flex") or field whose TCA you have overridden
     * to display as a FlexForm.
     *
     * @param string $table The SQL table this FlexForm is bound to
     * @param string $fieldName The SQL field this FlexForm is bound to
     * @param string $templateFilename Location of the Fluid template containing field definitions
     * @param array $variables Optional array of variables to pass to Fluid template
     * @param string|null $section Optional section name containing the configuration
     * @param array|null $paths Optional paths array / Closure to return paths
     * @return ProviderInterface
     */
    public static function registerFluidFlexFormTable(
        $table,
        $fieldName,
        $templateFilename,
        $variables = [],
        $section = null,
        $paths = null
    ) {
        $objectManager = static::getObjectManager();
        /** @var ProviderInterface $provider */
        $provider = $objectManager->get(Provider::class);
        $provider->setTableName($table);
        $provider->setFieldName($fieldName);
        $provider->setTemplatePathAndFilename($templateFilename);
        $provider->setTemplateVariables($variables);
        $provider->setTemplatePaths($paths);
        $provider->setConfigurationSectionName($section);
        self::registerConfigurationProvider($provider);
        return $provider;
    }

    /**
     * Register a template directly for use as a custom CType. Once registered
     * the CType will appear in the "Flux content" tab in the new content
     * wizard, and will be driven by either a custom controller if one is
     * specified or detected by convention; or render through the vanilla
     * ContentController provided with Flux.
     *
     * @param string $providerExtensionName Vendor.ExtensionName format of extension scope of the template file
     * @param string $templateFilename Absolute path to template file containing Flux definition, EXT:... allowed
     * @param string|null $contentTypeName Optional override for the CType value this template will use
     * @param string|null $providerClassName Optional custom class implementing ProviderInterface from Flux
     * @param string|null $pluginName Optional plugin name used when registering the Extbase plugin for the template
     * @return void
     */
    public static function registerTemplateAsContentType(
        $providerExtensionName,
        $templateFilename,
        $contentTypeName = null,
        $providerClassName = Provider::class,
        $pluginName = null
    ) {
        if (!PathUtility::isAbsolutePath($templateFilename)) {
            $templateFilename = static::getAbsolutePathForFilename($templateFilename);
        }

        self::$queuedContentTypeRegistrations[] = [
            $providerExtensionName,
            $templateFilename,
            $providerClassName,
            $contentTypeName,
            $pluginName,
            null
        ];
    }

    /**
     * @param string $providerClassName
     * @return void
     */
    public static function unregisterConfigurationProvider($providerClassName)
    {
        if (true === in_array($providerClassName, self::$providers)) {
            $index = array_search($providerClassName, self::$providers);
            unset(self::$providers[$index]);
        } elseif (false === in_array($providerClassName, self::$unregisteredProviders)) {
            array_push(self::$unregisteredProviders, $providerClassName);
        }
    }

    /**
     * @param string $typeOrClassName
     * @param string $insteadOfNativeType
     * @return void
     */
    public static function registerPipe($typeOrClassName, $insteadOfNativeType = null)
    {
        $key = null === $insteadOfNativeType ? $typeOrClassName : $insteadOfNativeType;
        self::$pipes[$key] = $typeOrClassName;
    }

    /**
     * @param string $typeOrClassName
     * @return void
     */
    public static function unregisterPipe($typeOrClassName)
    {
        if (true === in_array($typeOrClassName, self::$pipes)) {
            $index = array_search($typeOrClassName, self::$pipes);
            unset(self::$pipes[$index]);
        }
    }

    /**
     * @param string $typeOrClassName
     * @param string $insteadOfNativeType
     * @return void
     */
    public static function registerOutlet($typeOrClassName, $insteadOfNativeType = null)
    {
        $key = null === $insteadOfNativeType ? $typeOrClassName : $insteadOfNativeType;
        self::$outlets[$key] = $typeOrClassName;
    }

    /**
     * @param string $typeOrClassName
     * @return void
     */
    public static function unregisterOutlet($typeOrClassName)
    {
        if (true === in_array($typeOrClassName, self::$outlets)) {
            $index = array_search($typeOrClassName, self::$outlets);
            unset(self::$outlets[$index]);
        }
    }

    /**
     * Gets the defined FlexForms configuration providers based on parameters
     * @return ProviderInterface[]
     */
    public static function getRegisteredFlexFormProviders()
    {
        reset(self::$providers);
        return self::$providers;
    }

    /**
     * @return Form[]
     */
    public static function getRegisteredFormsForTables()
    {
        return self::$forms['tables'];
    }

    /**
     * @param string $table
     * @return Form|NULL
     */
    public static function getRegisteredFormForTable($table)
    {
        if (true === isset(self::$forms['tables'][$table])) {
            return self::$forms['tables'][$table];
        }
        return null;
    }

    /**
     * @return array
     */
    public static function getPipes()
    {
        return array_values(self::$pipes);
    }

    /**
     * @return array
     */
    public static function getOutlets()
    {
        return array_values(self::$outlets);
    }

    protected static function getAbsolutePathForFilename(string $filename): string
    {
        if (strpos($filename, '://') !== false) {
            return $filename;
        }
        return realpath($filename) ?: GeneralUtility::getFileAbsFileName($filename);
    }

    protected static function getObjectManager(): ObjectManagerInterface
    {
        if (static::$objectManager === null) {
            /** @var ObjectManagerInterface $objectManager */
            $objectManager = GeneralUtility::makeInstance(ObjectManager::class);
            static::$objectManager = $objectManager;
        }
        return static::$objectManager;
    }
}
