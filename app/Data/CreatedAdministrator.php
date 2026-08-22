<?php

namespace App\Data;

use App\Models\User;

final readonly class CreatedAdministrator
{
    public function __construct(
        public User $user,
        public string $temporaryPassword,
    ) {}
}
