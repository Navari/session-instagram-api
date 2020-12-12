<?php

namespace Navari\Instagram\Models;

use Navari\Instagram\Traits\ArrayLikeTrait;
use Navari\Instagram\Traits\InitializerTrait;

/**
 * Class AbstractModel
 * @package Navari\Instagram\Models
 */
abstract class AbstractModel implements \ArrayAccess
{
    use InitializerTrait, ArrayLikeTrait;

    /**
     * @var array
     */
    protected static $initPropertiesMap = [];

    /**
     * @return array
     */
    public static function getColumns(): array
    {
        return \array_keys(static::$initPropertiesMap);
    }
}