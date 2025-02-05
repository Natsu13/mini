<?php
namespace Models;

use Database;

/** 
 * @table("users") 
 * 
 * @method \Model\Permission|null permission()
 * @method \QueryBuilder articles()
 */
class User extends \Model {
    /** @primaryKey */
    public ?int $id;

    /** @column("login")
     *  @length(100)
    */
    public string $login;

    /** @length(100) */
    public string $password;

    /** @length(100) */
    public string $email;

    /** @column("permission_id") */
    public int $permissionId;

    /**
     * @hasOne("Permission")
     */
    private function permission() { }

    /**
     * @hasMany("Article", "author_id")
     */
    private function articles() { }

    public static function findByEmail($email): ?User {
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