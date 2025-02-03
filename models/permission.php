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

    public function print(): string {
        if($this->color) {
            return "<span style='color:{$this->color}'>{$this->name}</span>";
        }
        return $this->name;
    }
}