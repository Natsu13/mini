<?php
namespace Models;

use Database;

/** 
 * @table("articles") 
 * 
 * @method \Model\User|null author()
 */
class Article extends \Model {
    /** @primaryKey */
    public ?int $id;

    /** @column("author_id") */
    public int $authorId;

    /** @length(200) */
    public string $title;

    public string $content;

    public int $created;

    /**
     * @hasOne("User", "author_id")
     */
    private function author() { }

    protected function onCreated() {
        $this->created = time();
    }
}