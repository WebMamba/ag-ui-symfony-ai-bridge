AG-UI Symfony AI Bridge
=======================

Provides integration for [AG-UI](https://docs.ag-ui.com) with [Symfony AI](https://symfony.com/doc/current/ai/index.html):
any Symfony AI agent runs over the AG-UI protocol — text, reasoning, tool calls,
frontend tools, interrupts, typed shared state and generative UI (activities).

The bridge needs no framework. In a Symfony application, install
[`webmamba/ag-ui-bundle`](https://github.com/WebMamba/ag-ui-bundle) instead: it wires all of this into every agent.

Installation
------------

```bash
composer require webmamba/ag-ui-symfony-ai-bridge
```

Symfony AI is in beta: Composer installs it only if your `composer.json` allows it,
with `"minimum-stability": "beta"` or with each `symfony/ai-*` package it needs
required as `^1.0@beta`, as when you installed Symfony AI.

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

`AgUi` takes a secret: it signs the interrupts' ids, so a resume is trusted without
server state. Every server that may run a thread needs the same one.

What a run does, beyond the model's text and reasoning:

* **Shared state**: `$agUi->run($input, $agent, state: $plan)` fills `$plan` from the
  run agent input, and sends each change a tool makes to it as a state delta. A state
  class implementing `DescribesItself` writes what the model reads about it.
* **Frontend tools**: the tools the client declares are offered to the model; the run
  stops when it calls one, and the result comes back in the next run.
* **Interrupts**: a `ResumableTool` returns an `Interrupt` when it needs a human; its
  `resume()` turns the answer into its result in the next run.
* **Generative UI**: a tool returning an `Activity` gives the model a sentence and the
  client something to render.
* **Run context**: `RunContext` tells a tool the thread, the run and the forwarded props.
* **Tool result mappings**: a `ToolResultMapping` adds events after a tool result.

Resources
---------

 * [AG-UI protocol](https://docs.ag-ui.com)
 * [Glossary](https://github.com/WebMamba/ag-ui-php/blob/main/CONTEXT.md) and [design decisions](https://github.com/WebMamba/ag-ui-php/tree/main/docs/adr)
 * [Report issues and send pull requests](https://github.com/WebMamba/ag-ui-php) in the main repository
