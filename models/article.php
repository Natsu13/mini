<?php
namespace Models;

enum ArticleStatus: string {
    case Draft = 'draft';
    case Published = 'published';
}

#[\Table('articles')]
class Article extends \Model {
    #[\PrimaryKey]
    public ?int $id;

    #[\Column('author_id')]
    public int $authorId;

    #[\Column(length: 200)]
    public string $title;

    /** URL part of the public article page (/blog/<slug>), unique. */
    #[\Column(length: 220)]
    public string $slug;

    public string $content;

    public ArticleStatus $status;

    public int $created;

    public int $updated;

    /** Timestamp of the first publication, null for drafts. */
    public ?int $published;

    #[\Relation(\RelationType::HasOne, User::class, 'author_id')]
    public function getAuthor(): ?User {
        return $this->getRelation("author");
    }

    protected function onCreated() {
        $this->created = time();
        $this->updated = time();
        $this->status = ArticleStatus::Draft;
    }

    public function isPublished(): bool {
        return $this->status === ArticleStatus::Published;
    }

    public function publish(): void {
        $this->status = ArticleStatus::Published;
        $this->published ??= time();
    }

    public function unpublish(): void {
        $this->status = ArticleStatus::Draft;
    }

    /**
     * Short plain-text preview for listings.
     */
    public function excerpt(int $length = 160): string {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($this->content)));
        if (mb_strlen($text) <= $length) return $text;
        return rtrim(mb_substr($text, 0, $length)) . "…";
    }

    public static function findBySlug(string $slug): ?Article {
        return self::where(["slug" => $slug])->fetch();
    }

    /**
     * Published articles, newest first, with their authors loaded in one extra query.
     * @return \QueryBuilder<Article>
     */
    public static function published(): \QueryBuilder {
        return self::where(["status" => ArticleStatus::Published->value])->with("author");
    }

    /**
     * Turns a title (or a proposed slug) into a slug that no other article uses, adding -2, -3, ... when needed.
     * Uses a single query: loads every slug equal to "base" or starting with "base-" and picks the first free one in PHP.
     */
    public static function uniqueSlug(string $source, ?int $ignoreId = null): string {
        $base = \Utilities::slugify($source, 200);
        if ($base === "") {
            $base = "article";
        }

        $query = self::where(function (\QueryBuilderWhere $w) use ($base) {
            $w->and(["slug" => $base])
            ->likeOr("slug", $base . "-", \DataTableLike::Right); // slug LIKE 'base-%'
            return $w;
        });

        if ($ignoreId !== null) {
            $query->where(["id <>" => $ignoreId]);
        }

        // Set of taken slugs (lowercase, because MySQL collations are usually case-insensitive)
        $taken = [];
        foreach ($query->fetchAll() as $article) {
            $taken[strtolower($article->slug)] = true;
        }

        if (!isset($taken[strtolower($base)])) {
            return $base;
        }

        $i = 2;
        while (isset($taken[strtolower($base . "-" . $i)])) {
            $i++;
        }

        return $base . "-" . $i;
    }
}