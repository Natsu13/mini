<?php
namespace Models;

use Database;

/** 
 * @table("permissions") 
 */
class Permission extends \Model {
    /** @primaryKey */
    public ?int $id;

    public int $level;

    public string $name;

    public ?string $color;
}