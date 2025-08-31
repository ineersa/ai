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
use Symfony\AI\Platform\Bridge\VertexAi\Gemini\Model;
use Symfony\AI\Platform\Bridge\VertexAi\PlatformFactory;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\Metadata\TokenUsage;

require_once __DIR__.'/bootstrap.php';

$platform = PlatformFactory::create(env('GOOGLE_CLOUD_LOCATION'), env('GOOGLE_CLOUD_PROJECT'), adc_aware_http_client());
$model = new Model(Model::GEMINI_2_0_FLASH_LITE);

$agent = new Agent($platform, $model, outputProcessors: [new Symfony\AI\Platform\Bridge\VertexAi\TokenOutputProcessor()], logger: logger());
$messages = new MessageBag(
    Message::forSystem('You are an expert assistant in animal study.'),
    Message::ofUser('What does a cat usually eat?'),
);
$result = $agent->call($messages);

$metadata = $result->getMetadata();
$tokenUsage = $metadata->get('token_usage');

assert($tokenUsage instanceof TokenUsage);

echo 'Prompt Tokens: '.$tokenUsage->promptTokens.\PHP_EOL;
echo 'Completion Tokens: '.$tokenUsage->completionTokens.\PHP_EOL;
echo 'Thinking Tokens: '.$tokenUsage->thinkingTokens.\PHP_EOL;
echo 'Utilized Tokens: '.$tokenUsage->totalTokens.\PHP_EOL;
