<?php

declare(strict_types=1);

namespace OCA\GridCraft\Service;

/** The client isn't (or is no longer) in the session: it has to join again. */
class NotMemberException extends \RuntimeException {
}
