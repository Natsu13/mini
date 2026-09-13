<?php
namespace Models;

use Database;
use Relation;

#[\Table('articles')]
class Article extends \Model {
    #[\PrimaryKey]
    public ?int $id;

    #[\Column('author_id')]
    public int $authorId;

    #[\Column(length: 200)]
    public string $title;

    public string $content;

    public int $created;

    #[\Relation(\RelationType::HasOne, User::class, 'author_id')]
    private function getAuthor(): ?User {
        return $this->getRelation("author");
    }

    protected function onCreated() {
        $this->created = time();
    }
}