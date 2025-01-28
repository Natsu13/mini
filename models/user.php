<?php
namespace Models;

use Database;

/** 
 * @table("users") 
 * 
 * @method \Model\Permission|null permission()
 */
class User extends \Model {
    /** @primaryKey */
    public ?int $id;

    /** @column("login") */
    public string $login;

    public string $password;

    public string $email;

    /** @column("permission_id") */
    public int $permissionId;

    /**
     * @belongsTo("Permission")
     */
    private function permission() { }

    public static function findByEmail($email): ?User {
        $obj = (new static);
        return $obj->where(["email" => $email])->fetchSingle();
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