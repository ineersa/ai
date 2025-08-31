<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Symfony\AI\Agent\Agent;
use Symfony\AI\Platform\Bridge\OpenAi\Gpt;
use Symfony\AI\Platform\Bridge\OpenAi\PlatformFactory;
use Symfony\AI\Platform\Message\Content\File;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;

require_once dirname(__DIR__).'/bootstrap.php';

$platform = PlatformFactory::create(env('OPENAI_API_KEY'), http_client());
$model = new Gpt(Gpt::GPT_4O_MINI);

$agent = new Agent($platform, $model, logger: logger());
$messages = new MessageBag(
    Message::ofUser(
        'What is this document about?',
        // Note: You can use either `File::fromFile` or `Document::fromFile` here.
        File::fromFile(dirname(__DIR__, 2).'/fixtures/document.pdf'),
    ),
);
$result = $agent->call($messages);

echo $result->getContent().\PHP_EOL;
