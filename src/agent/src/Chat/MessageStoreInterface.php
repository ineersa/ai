<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Agent\Chat;

use Symfony\AI\Platform\Message\MessageBag;

interface MessageStoreInterface
{
    public function save(MessageBag $messages): void;

    public function load(): MessageBag;

    public function clear(): void;
}
