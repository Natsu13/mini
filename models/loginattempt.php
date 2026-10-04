<?php
namespace Models;

/**
 * One row per failed login; read by LoginThrottle to block brute-force attempts.
 * Delete this model to disable throttling.
 */
#[\Table('login_attempts')]
class LoginAttempt extends \Model {
    #[\PrimaryKey]
    public ?int $id;

    #[\Column(length: 100)]
    public string $login;

    #[\Column(length: 45)]
    public string $ip;

    public int $created;
}
