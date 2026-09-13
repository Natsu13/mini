<?php
namespace Models;

use Database;

#[\Table('permissions')]
class Permission extends \Model{
    #[\PrimaryKey]
    public ?int $id;

    public int $level;

    public string $name;

    public ?string $color;

    public function print(): string {
        if ($this->color) {
            return "<span style='color:{$this->color}'>{$this->name}</span>";
        }
        return $this->name;
    }
}