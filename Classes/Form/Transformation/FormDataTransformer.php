<?php
namespace FluidTYPO3\Flux\Form\Transformation;

/*
 * This file is part of the FluidTYPO3/Flux project under GPLv2 or later.
 *
 * For the full copyright and license information, please read the
 * LICENSE.md file that was distributed with this source code.
 */

use FluidTYPO3\Flux\Form;
use FluidTYPO3\Flux\Form\ContainerInterface;
use FluidTYPO3\Flux\Form\FieldInterface;
use FluidTYPO3\Flux\Hooks\HookHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\DomainObject\DomainObjectInterface;
use TYPO3\CMS\Extbase\Object\ObjectManagerInterface;
use TYPO3\CMS\Extbase\Persistence\RepositoryInterface;

/**
 * Transforms data according to settings defined in the Form instance.
 */
class FormDataTransformer
{
    /**
     * @var ObjectManagerInterface
     */
    protected $objectManager;

    /**
     * @param ObjectManagerInterface $objectManager
     * @return void
     */
    public function injectObjectManager(ObjectManagerInterface $objectManager)
    {
        $this->objectManager = $objectManager;
    }

    /**
     * Transforms members on $values recursively according to the provided
     * Flux configuration extracted from a Flux template. Uses "transform"
     * attributes on fields to determine how to transform values.
     *
     * @param array $values
     * @param Form $form
     * @param string $prefix
     * @return array
     */
    public function transformAccordingToConfiguration($values, Form $form, $prefix = '')
    {
        foreach ((array) $values as $index => $value) {
            if (is_array($value)) {
                $value = $this->transformAccordingToConfiguration($value, $form, $prefix . $index . '.');
            } else {
                /** @var FieldInterface|ContainerInterface $object */
                $object = $this->extractTransformableObjectByPath($form, $prefix . $index);
                if (is_object($object)) {
                    $transformType = $object->getTransform();

                    if ($transformType) {
                        $originalValue = $value;
                        $value = HookHandler::trigger(
                            HookHandler::VALUE_BEFORE_TRANSFORM,
                            [
                                'value' => $value,
                                'object' => $object,
                                'type' => $transformType,
                                'form' => $form
                            ]
                        )['value'];
                        if ($value === $originalValue) {
                            $value = $this->transformValueToType($value, $transformType);
                        }
                        $value = HookHandler::trigger(
                            HookHandler::VALUE_AFTER_TRANSFORM,
                            [
                                'value' => $value,
                                'object' => $object,
                                'type' => $transformType,
                                'form' => $form
                            ]
                        )['value'];
                    }
                }
            }
            $values[$index] = $value;
        }
        return $values;
    }

    /**
     * @param ContainerInterface $subject
     * @param string $path
     * @return mixed
     */
    protected function extractTransformableObjectByPath(ContainerInterface $subject, $path)
    {
        $pathAsArray = explode('.', $path);
        $subPath = array_shift($pathAsArray);
        $child = null;
        while (count($pathAsArray)) {
            $child = $subject->get($subPath, $subject instanceof Form);
            if ($child) {
                if ($child instanceof Form\Container\Section) {
                    array_shift($pathAsArray);
                }
                if ($child instanceof ContainerInterface && count($pathAsArray)) {
                    return $this->extractTransformableObjectByPath($child, implode('.', $pathAsArray));
                }
            }
            $subPath .= '.' . array_shift($pathAsArray);
        }
        return $subject->get($path, true);
    }

    /**
     * Transforms a single value to $dataType
     *
     * @param string $value
     * @param string $dataType
     * @return mixed
     */
    protected function transformValueToType($value, $dataType)
    {
        if ('int' === $dataType || 'integer' === $dataType) {
            return intval($value);
        } elseif ('float' === $dataType) {
            return floatval($value);
        } elseif ('array' === $dataType) {
            return explode(',', $value);
        } elseif ('bool' === $dataType || 'boolean' === $dataType) {
            return boolval($value);
        } elseif (strpos($dataType, '->')) {
            /** @var class-string $class */
            list ($class, $function) = explode('->', $dataType);
            /** @var object $object */
            $object = $this->objectManager->get($class);
            return $object->{$function}($value);
        } else {
            return $this->getObjectOfType($dataType, $value);
        }
    }

    /**
     * Gets DomainObject(s) or instance of $dataType identified by, or constructed with parameter $uids
     *
     * @param string|class-string $dataType
     * @param string|array $uids
     * @return DomainObjectInterface|DomainObjectInterface[]|object|null
     */
    protected function getObjectOfType($dataType, $uids)
    {
        $identifiers = true === is_array($uids) ? $uids : GeneralUtility::trimExplode(',', trim($uids, ','), true);
        $identifiers = array_map('intval', $identifiers);
        $isModel = $this->isDomainModelClassName($dataType);
        if (false !== strpos($dataType, '<')) {
            /** @var class-string $container */
            /** @var class-string $object */
            list ($container, $object) = explode('<', trim($dataType, '>'));
        } else {
            $container = null;
            $object = $dataType;
        }
        $repositoryClassName = $this->resolveRepositoryClassName($object);
        // Fast decisions
        if (true === $isModel && null === $container) {
            if (true === class_exists($repositoryClassName)) {
                /** @var RepositoryInterface $repository */
                $repository = $this->objectManager->get($repositoryClassName);
                $repositoryObjects = $this->loadObjectsFromRepository($repository, $identifiers);
                /** @var DomainObjectInterface|false $firstRepositoryObject */
                $firstRepositoryObject = reset($repositoryObjects);
                return $firstRepositoryObject ?: null;
            }
        } elseif (true === class_exists($dataType)) {
            // using constructor value to support objects like DateTime
            return $this->objectManager->get($dataType, $uids);
        }
        // slower decisions with support for type-hinted collection objects
        if ($container && $object) {
            if (true === $isModel && true === class_exists($repositoryClassName) && 0 < count($identifiers)) {
                /** @var RepositoryInterface $repository */
                $repository = $this->objectManager->get($repositoryClassName);
                return $this->loadObjectsFromRepository($repository, $identifiers);
            } else {
                $container = $this->objectManager->get($container);
                return $container;
            }
        }
        return null;
    }

    /**
     * @param string $object
     * @return string
     */
    protected function resolveRepositoryClassName($object)
    {
        return str_replace('\\Domain\\Model\\', '\\Domain\\Repository\\', $object) . 'Repository';
    }

    /**
     * @param string $dataType
     * @return boolean
     */
    protected function isDomainModelClassName($dataType)
    {
        return (false !== strpos($dataType, '\\Domain\\Model\\'));
    }

    /**
     * @param RepositoryInterface $repository
     * @param array $identifiers
     * @return DomainObjectInterface[]
     */
    protected function loadObjectsFromRepository(RepositoryInterface $repository, array $identifiers)
    {
        /** @var DomainObjectInterface[] $objects */
        $objects = array_map([$repository, 'findByUid'], $identifiers);
        return $objects;
    }
}
