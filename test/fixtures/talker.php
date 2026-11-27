<?php

declare(strict_types=1);

// Talks the way a CLI talks to a person: asks, waits for a line, answers.

echo "\x1b[1mWhat is your name?\x1b[0m ";
flush();
$name = trim((string) fgets(STDIN));
echo "\r\nhello ", $name, "\n";
