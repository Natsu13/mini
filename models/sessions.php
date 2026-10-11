<?php
namespace Models;

#[\Table('sessions')]
class Sessions extends \Model{
    #[\PrimaryKey(false)]
    #[\Column(length: 100)]
    public string $hash;

    #[\Column("user_id")]
    public int $userId;

    public int $created;

    #[\Column("last_activity")]
    public int $lastActivity;

    #[\Column("expires_at")]
    public int $expiresAt;
    
    public bool $invalid;

    #[\Column("ip", length: 45)] // 45 chars fits IPv6
    public ?string $ip;

    #[\Column("ip_hash", length: 64)]
    public string $ipHash;

    #[\Column("user_agent", length: 255)]
    public string $userAgent;

    public function onCreated() {
        $this->created = time();
        $this->lastActivity = $this->created;
        $this->invalid = false;
    }

    public function updateActivity(): self {
        $this->lastActivity = time();
        return $this;
    }

    public function deactivate(): self {
        $this->ip = null;
        $this->invalid = true;
        return $this;
    }

    #[\Relation(\RelationType::HasOne, User::class, 'user_id')]
    public function getUser(): ?User {
        return $this->getRelation("user");
    }

    /**
     * @return \QueryBuilder<\Models\Sessions>
     */
    public static function getByUserId(int $userId): \QueryBuilder {
        return self::where(["user_id" => $userId])->limit(20);
    }

    /**
     * Finds an active (not revoked, not expired) session by the raw token from the cookie.
     */
    public static function findActive(string $token): ?self {
        return self::where([
            "hash" => self::hashToken($token),
            "invalid" => 0,
            "expires_at >" => time(),
        ])->fetch();
    }

    /**
     * @return \QueryBuilder<\Models\Sessions>
     */
    public static function activeByUserId(int $userId): \QueryBuilder {
        return self::where([
            "user_id" => $userId,
            "invalid" => 0,
            "expires_at >" => time(),
        ])->order("last_activity DESC")->limit(20);
    }

    /**
     * Revokes all sessions of the user, e.g. after a password change.
     */
    public static function revokeAll(int $userId): int {
        return self::where(["user_id" => $userId])->update(["invalid" => true]);
    }

    /**
     * Removes expired and revoked rows. Run from a cron job or randomly on ~1 % of requests.
     */
    public static function cleanup(): int {
        return self::where(["expires_at <" => time()])
            ->whereOr(["invalid" => 1])
            ->delete();
    }

    public static function hashToken(string $token): string {
        return hash('sha256', $token);
    }
}