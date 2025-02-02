<?php
namespace FluidTYPO3\Flux\Form\Field;

/*
 * This file is part of the FluidTYPO3/Flux project under GPLv2 or later.
 *
 * For the full copyright and license information, please read the
 * LICENSE.md file that was distributed with this source code.
 */

use FluidTYPO3\Flux\Form\FieldInterface;

/**
 * DateTime
 */
class DateTime extends Input implements FieldInterface
{

    /**
     * @var string
     */
    protected $validate = 'date';

    /**
     * @param array $settings
     * @return FieldInterface
     */
    public static function create(array $settings = [])
    {
        /** @var FieldInterface $object */
        $object = parent::create($settings);
        return $object;
    }
}
