<?php
namespace Models;

#[\Table('permissions')]
class Permission extends \Model{
    /** Default levels; #[Authorize(level: N)] compares against $level. */
    public const LEVEL_USER = 1;
    public const LEVEL_EDITOR = 500;
    public const LEVEL_ADMIN = 10000;

    #[\PrimaryKey]
    public ?int $id;

    public int $level;

    #[\Column(length: 50)]
    public string $name;

    #[\Column(length: 20)]
    public ?string $color;

    /**
     * HTML badge for the permission. Returns markup, so output it with {!$permission->print()} in templates.
     */
    public function print(): string {
        $name = htmlspecialchars($this->name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        if ($this->color) {
            $color = htmlspecialchars($this->color, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            return "<span class='badge' style='background:{$color}'>{$name}</span>";
        }
        return "<span class='badge'>{$name}</span>";
    }

    public static function findByLevel(int $level): ?Permission {
        return self::where(["level" => $level])->order("id ASC")->fetch();
    }

    /**
     * Creates the three default permissions when the table is empty. Returns how many rows were added.
     */
    public static function seedDefaults(): int {
        if (self::toQuery()->count() > 0) return 0;

        $defaults = [
            [self::LEVEL_USER, "user", "#6b7280"],
            [self::LEVEL_EDITOR, "editor", "#2563eb"],
            [self::LEVEL_ADMIN, "admin", "#dc2626"],
        ];
        foreach ($defaults as [$level, $name, $color]) {
            $permission = new self();
            $permission->level = $level;
            $permission->name = $name;
            $permission->color = $color;
            $permission->save();
        }
        return count($defaults);
    }
}