# AGENTS.md — `igor:doctor`

Guide for an agent (LLM or human) building the Symfony `igor:doctor` command.
Read this whole file before changing anything.

---

## 1. Goal

Build a Symfony console command, `bin/console igor:doctor`, that **automatically fixes the errors reported by Igor** in a PHP/Symfony project running in worker mode (FrankenPHP).

Target loop:

```
Audit (Igor) → Diagnose → Patch → Re-check → … until 0 findings (iteration cap)
```

Guiding principle: **the sensors are deterministic, the patch is LLM-assisted, and validation is deterministic.**

- Igor (Go, static AST analysis) and, if available, the runtime leak detector measure reality. They do not guess.
- The agent (Symfony AI) proposes an idiomatic patch.
- The loop only counts as successful when Igor runs again and reports no blocking error.

The aim is a reliable ~90-second demo and clean code, not the maximum number of fixes.

---

## 2. Context

- **Igor-PHP**: a static linter written in Go for Symfony applications running in worker mode. It detects, among other things, `exit()`/`die()`, superglobals (`$_GET`, `$_POST`, `$_SESSION`, …), local `static` variables, and state mutations on shared services. It supports `#[WorkerSafe]` and `// @igor-ignore` to suppress false positives. Source: https://pkg.go.dev/github.com/igor-php/igor-php
- **FrankenPHP worker mode**: workers stay in memory between requests. Mutable state (service properties, singletons, `static`, a `$_SESSION` that is not reset) can leak from one user to another.
- **Symfony**: prefer idiomatic mechanisms — `ResetInterface`, `RequestStack` instead of globals, services with `shared: false`, the `kernel.reset` tag.
- **Symfony AI** (`symfony/ai-agent`) and the **PHP MCP SDK**: used for the agent and the tool gateway. Both are pre-release: pin versions and do not assume a stable API.
- **Symfony AI & MCP Hackathon**: submissions close 22 November 2026 at 18:00 CET. Judges clone and run the repositories, so the project must install and run cleanly outside the developer's machine.

> Igor is installed in a project **through Composer**, never through a relative path: `composer require --dev igor-php/igor-php`. This repository must stay independent of the Igor source tree. The package provides the bundle (`IgorPhp\IgorBundle\`) and the `bin/igor-php` binary.
>
> ⚠️ Assumptions to **verify** before hard-coding them (do not invent them):
> - The exact Igor options (`igor-php --help`, `igor-php --version`). The binary name `igor-php` comes from the package's `composer.json`; the Go install docs mention `igor`, so check which one is on the PATH.
> - The exact report format (`var/log/igor-leaks.jsonl`, event types such as `GROWTH`, `OVERWRITE`…) and the code that produces it (PR #92, mentioned in the design notes). If that code is not in the repository, the command must work **with Igor's static output alone**.

---

## 3. Scope

### In scope
- One command, `igor:doctor` (class in `src/Command/`, registered through autoconfiguration).
- An orchestration service (audit → agent → patch → re-check).
- A **restricted** patch-writing tool (see §6).
- An `examples/demo-leak/` test project that reproduces known Igor errors.
- PHPUnit tests for the command and the patch policy.
- A short README for the command.

### Out of scope (do not do without an explicit request)
- Modifying Igor itself (the Go binary).
- Modifying FrankenPHP, Symfony AI or the MCP SDK.
- Fixing errors in files outside the target project or outside the allowed directories.
- Adding dependencies the demo does not need.
- Opening a pull request: **never open one without an explicit request.**

---

## 4. Expected architecture

```
src/
  Command/IgorDoctorCommand.php        # entry point: bin/console igor:doctor
  Doctor/
    AuditRunner.php                    # runs Igor, parses its output (JSON preferred)
    Finding.php                        # DTO: file, line, rule, severity, message
    DoctorLoop.php                     # audit → patch → re-check loop, with a cap
    PatchPolicy.php                    # rules on what the agent may modify
    Agent/
      DoctorAgent.php                  # Symfony AI call, system prompt
      SourcePatchTool.php              # write tool (gated by PatchPolicy)
examples/demo-leak/                    # sample project with deliberate Igor errors
tests/
```

Design rules:
- **Parsing of Igor's output is isolated** in `AuditRunner`. If the format changes, only that file changes.
- **The agent never runs Igor itself**: `DoctorLoop` orchestrates. The agent receives `Finding`s and returns patches.
- No business logic in the command: it only calls `DoctorLoop` and renders the result.

---

## 5. Correction loop

Reference pseudo-code:

```
findings = audit()
for i in 1..MAX_ITERATIONS (default 3):
    if findings is empty: success
    for each finding (or group by file):
        patch = agent.propose(finding, file_context)
        if PatchPolicy rejects the patch: log it and skip
        apply the patch
    findings = audit()
failure if findings is not empty after MAX_ITERATIONS
```

Requirements:
- **An iteration cap is mandatory** (configurable, default 3). No infinite loops.
- **Idempotence**: running the command on an already clean project must change nothing and exit with `0`.
- **Rollback**: back up each file before patching; if re-check fails or the syntax is invalid, restore it.
- **Syntax check** after each patch: `php -l` on the modified file, before re-running Igor.
- **Exit codes**: `0` = no remaining errors; `1` = errors remain after the cap; `2` = tooling error (Igor missing, invalid config).
- The command is **autonomous**: no proposal mode and no confirmation step. Each patch is applied to the real project, then verified by `php -l` and by a fresh Igor audit. A patch that fails verification is rolled back immediately, before the next patch.
- The final report lists the findings fixed, the findings still remaining, and the findings flagged as behaviour-changing (not applied, see §7.3).
- `--max-iterations=N`.

---

## 6. Write policy (security)

Patching is the riskiest part. The restriction is enforced **in code**, never only in the prompt, in three layers:

1. **Single write path.** `SourcePatchTool` is the only tool registered for the agent. No shell, no generic file tool, no read or write outside the project.
2. **Path check before each write** (`PatchPolicy::resolveWritable()`): the path is resolved with `realpath()` on its directory, must stay under the real allowed root (with a trailing `/`), must not be a symbolic link, and must end in `.php`. Anything else throws `PatchRejected`.
3. **Post-write verification.** A hash manifest of the project is taken before the loop. After each patch, any file changed outside the allowed directory is restored and the loop stops.

Strict rules:

- The write tool may only modify **PHP** files under `src/` or under the target directory passed to the command.
- Forbidden: `.env*`, `composer.json` / `composer.lock` (unless explicitly requested), `config/packages/*` (unless explicitly requested), `vendor/`, `var/`, existing test files (new tests may be **added**).
- A patch must be **minimal**: one concern per patch, no global reformatting.
- A patch that removes more than N lines (default 50) is rejected and reported.
- No arbitrary shell command from the agent. Only `php -l` and Igor are executed, through hard-coded calls.
- No secret may appear in prompts, logs or commits.

---

## 7. Agent system prompt (expected content)

The prompt must tell the agent:
1. It fixes one specific Igor error, identified by file, line and rule.
2. It should prefer idiomatic Symfony mechanisms: `ResetInterface`, `RequestStack`, `shared: false`, `kernel.reset` tags.
3. It must not remove a feature just to silence the linter. If a fix changes behaviour, it reports that instead of applying it.
4. If the error is a likely false positive, it may propose `#[WorkerSafe]` with a justification, **without** changing the logic.
5. It replies only with a structured patch (file, diff, one-sentence justification).

---

## 8. Tests and validation

Reference commands (adapt once the project is installed):

```bash
composer install
vendor/bin/phpunit
vendor/bin/phpstan analyse src --level=max   # if configured
bin/console igor:doctor examples/demo-leak
echo $?   # expected: 0
```

Minimum criteria before the work counts as done:
- [ ] Unit tests for `PatchPolicy` and `DoctorLoop` pass (cases: rejected patch, cap reached, rollback, idempotence).
- [ ] `igor:doctor` on `examples/demo-leak` ends with a report of fixed, remaining and flagged findings.
- [ ] `igor:doctor` on `examples/demo-leak` goes from N findings to 0 within `MAX_ITERATIONS`.
- [ ] Running it again on the result changes nothing and exits with `0`.
- [ ] No file outside the scope was modified (check with `git status`).
- [ ] A failure test (Igor missing) exits with `2`.

If a criterion cannot be verified (Igor not installable, missing dependency), **say so explicitly** in the report and do not present it as passing.

---

## 9. Conventions

- **All code and all text in the repository is in English**: class, method and variable names, comments, console messages and help texts, documentation, commit messages, test names.
- PHP 8.2+, `declare(strict_types=1);`, PSR-12.
- One commit per coherent step, imperative English message (`Add PatchPolicy with path allowlist`).
- Never commit `vendor/`, `var/`, or generated reports.
- Do not include model identifiers or personal information in code, commits or documentation.

---

## 10. Recommended work order

1. Skeleton: a minimal `igor:doctor` that prints "audit not implemented" and exits with `2`.
2. `AuditRunner`: run Igor on `examples/demo-leak`, parse the output into `Finding`s. Tests on a frozen output file.
3. `PatchPolicy` with tests (no LLM call yet).
4. `DoctorAgent` wired to Symfony AI, with a single demonstration finding.
5. Full `DoctorLoop`: cap, rollback, idempotence, exit codes.
6. Polish: readable report output, reading the runtime report if available.
7. Demo video: from red (findings) to green (0 findings).

At each step the previous version must still work. If a step is blocked, deliver what works and explain what is blocked.

---

## 11. When to stop and ask

Ask before continuing if:
- Igor's output format does not match what is described here.
- A fix requires modifying a file outside the scope (§6).
- The sample project does not reproduce the expected errors.
- A design decision changes the public behaviour of the command (name, options, exit codes).

For everything else (internal naming, class layout, test choices), decide and state the decision in the report.
