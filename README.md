# igor-doctor

**Symfony AI & MCP Hackathon entry.** Long-running PHP workers (FrankenPHP worker mode) keep memory between requests: one visitor's data can end up in the next visitor's response. Igor Doctor finds those leaks, proves them, and fixes them.

- **Igor** ([igor-php](https://github.com/igor-php/igor-php)) reads the code, and the doctor reads Igor's findings over **MCP**.
- **The runtime probe** runs the application: it logs users in, submits the forms, and replays every route on one booted kernel to show the state a request leaves for the next one (an exposure, a value kept in memory, a growing array, a crash).
- **A Symfony AI agent** writes the fixes, and the probe and Igor check every patch before it is kept. The same agent unblocks the routes the probe could not test: it writes a request recipe, and the probe replays it in a rolled-back transaction.
- **A live dashboard** shows the router being explored, the coverage of each route, and a final diagnosis that searches upstream for a fix when the leak is in a dependency.

[**Watch the 90-second demo**](https://www.youtube.com/watch?v=RzlAw62gMIU) ([file](docs/demo.webm)): symfony/demo on Symfony 7.4. The AI unblocks the post creation form, then the diagnosis finds that Symfony's validators keep the last submitted form and its author between requests, and points to the upstream fix in Symfony 8.1 ([symfony/symfony#63945](https://github.com/symfony/symfony/pull/63945)).

## Try it

You need PHP 8.2+, Composer, and the [Claude Code](https://code.claude.com) CLI logged in (`claude /login`): the Symfony AI agent runs on it through `symfony/ai-claude-code-platform`, with no API key. Everything runs locally, and the probe sends every request in a database transaction that is rolled back.

**1. Install the doctor and run its tests.**

```bash
git clone https://github.com/KevinMartinsDev/igor-doctor.git && cd igor-doctor
composer install
composer install --working-dir=examples/demo-leak
echo "DOCTOR_AI_MODEL=opus" > .env.local
vendor/bin/phpunit
```

**2. Fix the bundled lab** (a Symfony app with one leak of each kind): Igor and the probe find the leaks, the agent patches them, and a patch is kept only when both stop reporting its leak.

```bash
bin/demo --runtime --max-iterations=1
```

**3. Reproduce the video on symfony/demo, Symfony 7.4.**

```bash
git clone --branch v2.9.0 https://github.com/symfony/demo.git ../symfony-demo && cd ../symfony-demo
composer install
../igor-doctor/bin/install-runtime .
export IGOR_AI_COMMAND="$PWD/../igor-doctor/bin/ask"
APP_ENV=prod APP_DEBUG=0 php bin/console igor:worker-scan --user=jane_admin --user=tom_admin --forms --tour --include-vendors --dashboard
```

Open http://127.0.0.1:8765 and watch the probe explore the router. When the scan is done:

- click the `/{_locale}/admin/post/new` route (partly tested), then **Unblock with AI**: the model writes a request recipe and the probe replays it, up to three times;
- open **Diagnosis**: under `symfony/validator`, click **Search where it is kept: ConstraintValidator::$context**. The file changed in 8.1, and PR #63945 comes first.

Press Enter in the terminal to close the dashboard. The guide to every option is [`runtime/README.md`](runtime/README.md).

## What it does

- **Static findings over MCP**: the doctor starts Igor's MCP server (`igor mcp --read-only`) with a Symfony MCP client (`symfony/mcp-bundle`) and reads the findings, the rules and the shared services.
- **Fixes by a Symfony AI agent** (`symfony/ai-agent` on `symfony/ai-platform`): one finding at a time, with the numbered file and a briefing on the rule. The model has no tools, so it can only answer with a patch. The patch is linted, applied, and kept only when Igor and the probe stop reporting the finding and report nothing new; otherwise it is rolled back.
- **Runtime probe** (`igor:worker-scan`): reads the router, builds a request for every route, logs users in (A, B, A, then anonymous), submits the forms with a fresh CSRF token, and compares the state the container keeps after each request. It sorts each leak by impact: **exposure** (another request reads it), **retention** (kept in memory), **growth**, **crash**.
- **Coverage**: each route is tested, partly tested or not tested, with the reason (a missing role, missing data, a refused form).
- **Unblock with AI**: for a route the probe could not test, the agent writes a request recipe from the route, its controller, its entities and the answers the scan got. The probe replays it, rolled back, up to three times, and saves a recipe that works for the next scans.
- **Live dashboard**: the router organ by organ, a magnifier on the route being probed, a monitor of the requests and of the state, and a final diagnosis that splits the leaks between your code and your dependencies.
- **Upstream search**: for a leak in a dependency, it compares the file between the installed version, the latest release and the development branch, and lists the related issues. Only package and class names leave the machine.

## Status: proof of concept

This was built for the hackathon, on top of [igor-php](https://github.com/igor-php/igor-php), the static linter, which is released.

- **Solid**: the detection. It is deterministic (no model is involved in finding a leak) and covered by tests (175). It found the stateful validators of Symfony 7.4 and an API Platform leak that has since been published as an advisory (GHSA-xmq6-3c6q-fr8m).
- **Experimental**: the dashboard and the AI unblock. The model does not always find the recipe in three attempts: on the symfony/demo post form, it succeeds about one time in two with `opus`.
- **Not done yet**: API routes that need a token, DELETE and PATCH routes without a form, and a value one route leaves for another route (the probe replays the same route).
- **A retention is not an exposure**: a value kept in memory is a memory and privacy concern; it becomes a leak between users only when another request reads it. The report says which one it found.


---


`bin/console igor:doctor` fixes the worker-mode findings that [Igor](https://github.com/igor-php/igor-php) reports in a Symfony project, then has Igor verify each fix.

```
Audit (Igor over MCP) → Patch (AI agent) → php -l → Re-audit (Igor) → keep or roll back
```

The sensors are deterministic, the patch comes from the model, and the validation is deterministic: a patch is kept only when Igor no longer reports its finding and reports nothing new.

## Requirements

- PHP 8.2+ and Composer.
- In the **target project**: Igor 0.11 or later, installed with Composer (`composer require --dev igor-php/igor-php:^0.11`). Version 0.11 brought the MCP server (`igor-php mcp`) that the doctor reads findings from.
- The [Claude Code](https://code.claude.com) CLI (`claude`), logged in with your Claude account, needed only when there is something to fix. The agent runs on it through [`symfony/ai-claude-code-platform`](https://symfony.com/packages/ai-claude-code-platform): no API key.

## Install

```bash
composer install
cp .env .env.local   # then set DOCTOR_AI_MODEL in .env.local
```

`DOCTOR_AI_MODEL` takes one of the models of the Claude Code bridge's catalog: when it is missing or unknown, the command lists the accepted values. Set `DOCTOR_CLAUDE_BIN` when `claude` is not on the `PATH`. `DOCTOR_AGENT_TIMEOUT` caps one answer in seconds (default 600): a finding that times out gets no patch and the run goes on. `.env.local` is not committed.

To try it on the bundled sample project:

```bash
composer install --working-dir=examples/demo-leak
bin/demo                      # mise run doctor adds --allow-behaviour-change
```

`bin/demo` copies `examples/demo-leak` to `var/demo-leak` (gitignored) and runs the doctor on the copy, so every run starts from the same leaks and the versioned lab never changes. At the end it prints what the doctor changed (a summary, then the diff) and saves it as `var/demo-leak.diff`; the patched files stay in `var/demo-leak/src` until the next run. Extra arguments go to the command (`bin/demo --max-iterations=1`).

### On the official Symfony demo

```bash
bin/demo-symfony              # or: mise run doctor-symfony
```

`bin/demo-symfony` caches a clone of [symfony/demo](https://github.com/symfony/demo) in `var/symfony-demo-src` (tag `v3.1.0`; set `SYMFONY_DEMO_REF` for another), copies it to `var/symfony-demo`, installs its dependencies plus Igor, adds the same `igor.json` as the lab, and runs the doctor on the copy. It also registers `IgorPhpBundle` in the copy's `config/bundles.php`, which Igor needs to read the container. On `v3.1.0` Igor reports 4 blocking findings in `src/Twig/` and `src/EventSubscriber/`, which the doctor brings to 0 (the agent's output varies between runs, and a patch that does not parse is rolled back). `config/packages/` is never patched, so a finding that needs service configuration is reported as not applied.

The leaks in `examples/demo-leak` are deliberate. `composer install` points Git at the versioned hooks in `.githooks/` (`git config core.hooksPath .githooks`), and the `pre-commit` hook refuses a commit that stages changes under `examples/demo-leak/src` or `examples/demo-leak/config`. To change the lab on purpose, run `ALLOW_DEMO_CHANGES=1 git commit …`. Like any Git hook it is local: `--no-verify` skips it.

## Usage

```bash
bin/console igor:doctor [target] [--max-iterations=3] [--allow-behaviour-change]
```

By default the doctor never changes what the code does: when every fix would (removing `exit()`, a destructor, a closure kept on purpose), the finding is reported as behaviour-changing and left alone. `--allow-behaviour-change` lets the agent apply those fixes too. They go through the same checks (`php -l`, the write policy, a fresh Igor audit) and are marked "Behaviour changed" in the report. Use it on the demo to go from red to green: `bin/demo --allow-behaviour-change`.

| Exit code | Meaning |
|---|---|
| `0` | No blocking finding left. Nothing is written when the project starts with none. |
| `1` | Blocking findings remain after `--max-iterations` rounds. |
| `2` | Tooling error: invalid target, Igor missing, Claude Code CLI missing or not logged in, model not set, or a write outside the patched file. |

Only `ERROR` findings block. `WARNING` findings are sent to the agent too and shown in the report, but they never fail the run.

The report lists the findings that were **fixed**, the ones **flagged as behaviour-changing** (the agent judged that every fix would change what the code does, so nothing was applied), the **patches not applied** (rejected, rolled back, or not proposed) and what **remains**.

## Runtime probe

Igor reads the code; the runtime probe runs it. `igor:worker-scan`, in [`runtime/`](runtime), sends requests to one
booted kernel of the target as a FrankenPHP worker does, and reports the state each request leaves for the next
one: a value of a previous request, a logged-in user's data seen by the next visitor, an array that grows on every
request, a Request kept alive, a request that ends the process. It can log users in and submit forms (in a
database transaction rolled back after each request). The detection is deterministic: no model is involved.

A route the probe could not test can be unblocked with AI: the model, asked through `bin/ask` and Symfony AI like the
doctor's agent, writes a request recipe that the probe replays and checks. The probe adds no AI library to the
application it scans.

```bash
bin/install-runtime /path/to/app                                  # once
cd /path/to/app && APP_ENV=prod php bin/console igor:worker-scan --user=alice --user=bob --forms
bin/console igor:doctor /path/to/app --runtime                    # fix what Igor and the probe report
bin/demo --runtime                                                # on the bundled lab
```

See the [runtime probe guide](runtime/README.md) for the options, how to read the report and the limits.

## How a finding is fixed

1. `AuditRunner` rebuilds the target's container (`var/cache/dev` is removed and `bin/console cache:warmup` runs), starts `igor-php mcp --read-only` and pages through `audit_project`. The server is declared as a client of the Symfony MCP bundle in `config/packages/mcp.yaml`; the binary and the target reach that file through `IGOR_DOCTOR_BIN` and `IGOR_DOCTOR_TARGET`, which the doctor sets itself.
2. `DoctorAgent` (Symfony AI on the Claude Code CLI) receives one finding, the numbered file and a briefing on the finding's rule (see below), and answers with one JSON object: the file, a list of search/replace edits (each `search` quoted exactly from the file, and found exactly once) and a one-sentence justification, or `behaviour_changing: true`. `SourcePatchTool` applies the edits in memory and checks the result against `PatchPolicy`. A rejected answer is sent back with the reason, up to three attempts. Edits keep the answer small on long files, and the lines they do not touch are kept byte for byte. The agent never writes.
3. `DoctorLoop` writes the file, runs `php -l`, checks that no other file changed, and audits again. The patch is kept only if its finding is gone and no new finding appeared. Otherwise the file is restored before the next finding.

### Rule briefing

The agent has no tools, so everything it needs comes with the request. `Briefing` adds, for the finding's rule only:

- **Igor's reference of the rule**, read from the `get_rule` tool of Igor's MCP server (`IgorReference`, cached for the run): why it leaks, the Symfony fix, the known false positives. It ships with Igor, so it follows Igor's version.
- **The doctor's notes**, one Markdown file per rule id in [`resources/skills/`](resources/skills): what a fix needs to pass the re-audit (Igor does not follow `reset()` into `clear()`, YAML is out of reach, …) and when a finding is a feature to report rather than fix. A note's `## When behaviour changes are allowed` section is sent only with `--allow-behaviour-change`.

- **Related files**: Igor's `explain_service` lists the dependencies of the finding's service; the ones named in the finding's code line (`$this->closureLeakService->addListener(...)` → `App\Service\ClosureLeakService`) are sent with their content, at most 3 files of 300 lines. The agent can then see what the call does, and patch that file instead when the fix belongs there (one file per answer, checked the same way). A file is shown only when the write policy would let the doctor patch it, so nothing from `vendor/`, `var/`, configuration or hidden files reaches the prompt.

All of it is optional: a rule without notes, or a reference that cannot be read, only gives a shorter briefing.

## Write policy

The policy is enforced in code (`src/Doctor/Patch/PatchPolicy.php`), not in the prompt:

- only `.php` files inside the target, resolved with `realpath()` on their directory, so `../` and symbolic links cannot leave the project;
- never `vendor/`, `var/`, `config/packages/`, hidden files or directories (`.env*`, `.git/`), or existing tests (new tests may be added);
- at most 50 removed lines per patch;
- the whole project is snapshotted before the loop. A change to any file other than the patched one is restored and stops the run (exit 2).

The model cannot act on the machine: the CLI runs from an empty temporary directory with `--tools ""` (every built-in tool disabled), `--strict-mcp-config` (no MCP server), `--setting-sources ""` (no user or project settings, so no hooks), `--disable-slash-commands` and `--no-session-persistence`. Its only output is the JSON answer.

The only other commands run are `php -l`, Igor, and the target's `bin/console cache:warmup` (needed because Igor reads shared and resettable services from the compiled container). They are hard-coded, with no argument coming from the model.

## Notes on Igor

- `vendor/bin/igor-php` is a PHP wrapper that downloads Igor's Go binary from the GitHub release on first use, without a checksum check. It runs the binary through `passthru()`, which holds back replies on an interactive stdio session. The doctor therefore runs the wrapper once with `--version` (which triggers the download) and starts the MCP server from the downloaded binary. Set `IGOR_BIN` to use another binary.
- Igor 0.11 has no runtime leak log (`var/log/igor-leaks.jsonl`). The doctor works from the static analysis only.

## Tests

```bash
vendor/bin/phpunit                  # everything
vendor/bin/phpunit --exclude-group igor   # without the real Igor
```

The `igor` group runs the real Igor on `examples/demo-leak` (or on a temporary copy of it). It is skipped when that project's dependencies are not installed. The model is never called in tests: the agent is tested with Symfony AI's mock platform, and the loop with a scripted proposer.
