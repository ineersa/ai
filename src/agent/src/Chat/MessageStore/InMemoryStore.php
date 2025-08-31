<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Agent\Chat\MessageStore;

use Symfony\AI\Agent\Chat\MessageStoreInterface;
use Symfony\AI\Platform\Message\MessageBag;

final class InMemoryStore implements MessageStoreInterface
{
    private MessageBag $messages;

    public function save(MessageBag $messages): void
    {
        $this->messages = $messages;
    }

    public function load(): MessageBag
    {
        return $this->messages ?? new MessageBag();
    }

    public function clear(): void
    {
        $this->messages = new MessageBag();
    }
}
