<?php
namespace Models;

use Database;

#[\Table('users')]
class User extends \Model {
    #[\PrimaryKey]
    public ?int $id;

    #[\Column('login', length: 100)]
    public string $login;

    #[\Column('password', length: 100)]
    public string $password;

    #[\Column('email', length: 100)]
    public string $email;

    #[\Column('gender', length: 10)]
    public string $gender;

    #[\Column('permission_id')]
    public int $permissionId;

    #[\Relation(\RelationType::HasOne, Permission::class)]
    public function getPermission(): ?Permission {
        return $this->getRelation("permission");
    }

    /**
     * @return \QueryBuilder<\Models\Article>
     */
    #[\Relation(\RelationType::HasMany, Article::class, 'author_id')]
    public function getArticles(): \QueryBuilder {
        return $this->getRelation("articles");
    }

    public static function findByEmail(string $email): ?User {
        $obj = (new static);
        return $obj->where(["email" => $email])->fetch();
    }

    public static function find(string $login, string $email): ?User {
        $db = \Container::getInstance()->get(Database::class)->getConnection();
        $stmt = $db->prepare("SELECT * FROM users WHERE login = :login OR email = :email");
        $stmt->bindParam(':login', $login, \PDO::PARAM_STR);
        $stmt->bindParam(':email', $email, \PDO::PARAM_STR);
        $stmt->execute();
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $result ? new User($result) : null;
    }
}