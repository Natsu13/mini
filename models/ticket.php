<?php
namespace Models;

use Database;

/** 
 * @table("tickets") 
 */
class Ticket extends \Model {
    /** @primaryKey */
    public ?int $id;

    /** @length(20) */
    public string $token;

    public int $created;

    public TicketStatus $status;

    public TicketType $type;

    public ?int $entityId;

    public ?string $data;

    public function onCreated() {
        $this->created = time();
        $this->status = TicketStatus::New;
    }

    public function markUsed() {
        $this->status = TicketStatus::Used;
        $this->save();
    }
}

enum TicketStatus: string {
    case New = 'new';
    case Used = 'used';
}

enum TicketType: string {
    case PasswordReset = 'password_reset';
}