<?php

declare(strict_types=1);

namespace SepoFinance;

defined('ABSPATH') || exit;

/** A record that did not pass its checks, with a message per field. */
final class FieldErrors extends \InvalidArgumentException
{
    /** @var array<string,string> */
    public array $errors;

    /** @param array<string,string> $errors */
    public function __construct(array $errors)
    {
        $this->errors = $errors;
        parent::__construct((string) reset($errors));
    }
}
