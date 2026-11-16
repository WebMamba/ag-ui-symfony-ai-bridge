AG-UI Symfony AI Bridge
=======================

Provides integration for [AG-UI](https://docs.ag-ui.com) with [Symfony AI](https://symfony.com/doc/current/ai/index.html):
any Symfony AI agent runs over the AG-UI protocol — text, reasoning, tool calls,
frontend tools, interrupts, typed shared state and generative UI (activities).

The bridge needs no framework. In a Symfony application, install
[`webmamba/ag-ui-bundle`](../bundle) instead: it wires all of this into every agent.

Installation
------------

```bash
composer require webmamba/ag-ui-symfony-ai-bridge
```

Usage
-----

An agent takes the bridge's toolbox, tool executor and input processor; they stay out
of the way until a run is opened for that agent:

```php
use AgUi\Bridge\SymfonyAi\AgUi;
use AgUi\Encoder\SseEncoder;
use AgUi\Run\RunAgentInput;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\Toolbox\Toolbox;

$agUi = new AgUi(secret: $_ENV['APP_SECRET']); // signs the interrupts: the same on every server
$toolbox = $agUi->toolbox(new Toolbox([new GetForecast()]));
$agent = new Agent($platform, 'claude-sonnet-5-5',
    inputProcessors: [$agUi->inputProcessor()],
    toolbox: $toolbox,
    toolExecutor: $agUi->toolExecutor($toolbox),
);

$input = RunAgentInput::fromArray(json_decode($body, true));
$encoder = new SseEncoder();

foreach ($agUi->run($input, $agent) as $event) {
    echo $encoder->encode($event);
    flush();
}
```

Resources
---------

 * [AG-UI protocol](https://docs.ag-ui.com)
 * [Glossary](../../CONTEXT.md) and [design decisions](../../docs/adr)
 * [Report issues](https://github.com/WebMamba/ag-ui-php/issues)
