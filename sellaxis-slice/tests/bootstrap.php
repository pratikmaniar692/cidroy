<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// Tests run against the same Postgres the docker-compose stack provides.
// This is deliberate: the failure cases this suite targets (version-checked
// updates, transactional capture-then-commit, unique-constraint dedup) are
// exactly the behaviours that an in-memory fake would either have to
// reimplement (and risk getting subtly wrong) or simply couldn't exercise
// at all -- a real Postgres connection with real transactions and a real
// unique constraint is the only honest way to test "does the unique
// constraint on delivery_id actually stop a duplicate."
putenv('DB_HOST=' . (getenv('DB_HOST') ?: 'localhost'));
putenv('DB_PORT=' . (getenv('DB_PORT') ?: '5433'));
putenv('DB_NAME=' . (getenv('DB_NAME') ?: 'forgeline'));
putenv('DB_USER=' . (getenv('DB_USER') ?: 'forgeline'));
putenv('DB_PASSWORD=' . (getenv('DB_PASSWORD') ?: 'forgeline'));
