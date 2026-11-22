# igor-doctor runtime probe

`bin/console igor:worker-scan` finds the state a Symfony application keeps from one request to the next in
worker mode (FrankenPHP, RoadRunner, Swoole), by running it. Igor reads the code; this probe sends requests to
one booted kernel, as a worker does, and compares what is left in memory after each of them. No model is
involved: the detection is deterministic, and every finding comes with the evidence that proves it.

- [Quick start](#quick-start)
- [Install](#install)
- [Run a scan](#run-a-scan)
- [Logged-in users](#logged-in-users)
- [Forms and POST requests](#forms-and-post-requests)
- [Read the report](#read-the-report)
- [Live dashboard](#live-dashboard)
- [Unblock a route with AI](#unblock-a-route-with-ai)
- [From igor:doctor](#from-igordoctor)
- [How it works](#how-it-works)
- [Limits](#limits)
- [Measured](#measured)

## Quick start

From this repository, on the bundled lab:

```bash
composer install --working-dir=examples/demo-leak
bin/demo --runtime                       # copies the lab to var/demo-leak, installs the probe, runs the doctor
```

On any Symfony application:

```bash
bin/install-runtime /path/to/app
cd /path/to/app
APP_ENV=prod APP_DEBUG=0 php bin/console igor:worker-scan
```

## Install

`bin/install-runtime <target>` does the steps below. To do them by hand:

```bash
cd /path/to/app
composer config repositories.igor-runtime '{"type":"path","url":"/path/to/doctor/runtime","options":{"symlink":false}}'
composer require --dev --no-scripts 'monitaurus/igor-doctor-runtime:@dev'
```

Then register the bundle in `config/bundles.php` (in an `AppKernel`, in `registerBundles()`):

```php
IgorDoctor\Runtime\IgorRuntimeBundle::class => ['all' => true],
```

The bundle registers the command and makes a few framework services readable by it (API Platform's resource
metadata, the firewall map, the user providers, the session factory). It adds nothing to the requests the
application serves.

Requirements: PHP 8.2+, Symfony 5.4, 6.4, 7.x or 8.x. Logged-in scans need SecurityBundle and sessions; the
database rollback of form scans needs Doctrine DBAL.

The application must boot as it does in production: its database, cache and other services reachable, its
environment variables set. Run the scan with the environment the workers use:

```bash
APP_ENV=prod APP_DEBUG=0 php bin/console igor:worker-scan
```

## Run a scan

```bash
php bin/console igor:worker-scan                                   # one probe per GET route, App\ state only
php bin/console igor:worker-scan --include-vendors --tour          # framework and bundles too, and cross-route state
php bin/console igor:worker-scan --route '/^admin_/'               # only the routes whose name matches
php bin/console igor:worker-scan -p '/api/books?title={marker}'    # your own probe
php bin/console igor:worker-scan --json > report.json              # machine-readable report
```

| Option | Effect |
|---|---|
| `--probe`, `-p` | A request to send instead of the generated ones: `"[METHOD ]/path?query"`, with `{marker}` (a value unique to each sending) and `{a\|b\|a}` (one value per sending). Repeatable. |
| `--route` | Only the generated probes of the routes whose name matches this regular expression. With `--forms`, the forms found on their pages are added. |
| `--header` | A header sent with every probe: `"Name: value"`. Repeatable. |
| `--namespace` | Report the state owned by classes of this namespace. Default: `App\`. Repeatable (`--namespace 'Wallabag\'`). |
| `--include-vendors` | Report the state owned by any class, framework and bundles included. |
| `--tour` | Also send every probe in turn to one kernel, to find what a route leaves for another. |
| `--user`, `-u` | Log the requests in as this user. Give two (see [Logged-in users](#logged-in-users)). |
| `--forms` | Also submit the forms found in the pages (see [Forms and POST requests](#forms-and-post-requests)). |
| `--dashboard[=port]` | Watch the scan live in a browser (default port 8765), see [Live dashboard](#live-dashboard). |
| `--events` | Append what the scan does to this file, one JSON object per line (the dashboard reads it). |
| `--unblock` | Ask the model, through Igor Doctor and Symfony AI, for a request that gets this route tested, and replay it, see [Unblock a route with AI](#unblock-a-route-with-ai). |
| `--in-process` | Run every probe in this process: faster, but a request that calls `exit()` ends the scan. |
| `--json` | Print the report as JSON on stdout. |

Exit codes: `0` no certain leak, `1` at least one certain leak, `2` invalid input (an unknown user, a bad pattern).

A scan of a mid-size application (150 to 250 routes) takes from 30 seconds to 2 minutes; `--tour`,
`--include-vendors` and `--forms` add to it.

## Logged-in users

Most pages of a real application are behind a login, and the leaks that matter most go from one user to another.
Give two existing users, by the identifier their user provider loads them with (username or email):

```bash
php bin/console igor:worker-scan --user=alice --user=bob
```

No login form is used: as `KernelBrowser::loginUser()` does in tests, a token for the user is put in a new
session under the key of the request's firewall, and the request carries that session's cookie. The application
then runs its real path: the firewall refreshes the user from its provider and every listener of a logged-in
request runs. Each probe is sent as **alice, bob, alice again, then anonymously**. A value that follows the user
(alice's, bob's, alice's again) and is still there for the anonymous visitor is reported as a certain leak.

- Pick two users with different settings (locale, timezone, currency, data of their own): a leak shows only when
  their values differ.
- The user is loaded by the provider of each request's firewall: with a shop and an admin area, give users of
  the area you want to cover. A firewall that keeps no session (a token API) is visited anonymously.
- The pages behind the login are visited as the first user to find their links and forms.
- Onboarding steps that redirect every page (a first-login wizard, terms to accept) must be completed for both
  users first.
- Listeners that run only on an interactive login (`InteractiveLoginEvent`) are not triggered.

## Forms and POST requests

```bash
php bin/console igor:worker-scan --user=alice --user=bob --forms
```

With `--forms`, every `<form>` of the visited pages becomes a probe, without a model:

- each field gets a value its type accepts, with a marker where the type allows one: text and textarea get the
  marker, an email field `<marker>@example.test`, a URL `https://example.test/<marker>`, numbers, dates and
  phone numbers a valid value; selects and radio groups alternate their first two choices; checked checkboxes
  keep their value; file and disabled fields are left out;
- a GET form (search, filters) is sent with its fields in the query string;
- a POST form is sent after its page is requested again **in the same session**, and the hidden fields that page
  renders (the CSRF token) replace those of the body, so the token is valid.

**Side effects.** Every request of a form scan runs in a transaction of each Doctrine connection, rolled back once
the request is over: a created, edited or deleted record is undone, and the next sending finds the same data.
Mails go to the `null://null` transport (when the application reads `MAILER_DSN`). Other side effects are not
blocked: messages sent to a broker other than Doctrine, calls to HTTP APIs, files written to disk. Run form scans
against a disposable environment (a copy of the database, sandbox credentials).

A form whose generated values do not pass the application's validation is still submitted: the validation runs
and what it keeps is still checked, but the code after a successful submission is not reached (the answer is
then a 422, or the form page again).

## Read the report

```
CROSS_REQUEST_STATE_LEAK (certain) in App\EventSubscriber\LocaleSubscriber
Probe homepage: follows the value each request sent: string fr → string de → string fr
Fix from src/EventSubscriber/LocaleSubscriber.php:23
 * @App\EventSubscriber\LocaleSubscriber->$locale
```

Each finding has a type, whether it is **certain** (proven by the evidence) or a **suspect** (to check by
reading the code), the class that owns the state, the probe that showed it, the evidence, the file and line a fix
starts from, and the paths of the state in memory (`@service->$property`, `Class::$static`, `{process}->timezone`).

| Type | Certain when | Suspect when |
|---|---|---|
| `CROSS_REQUEST_STATE_LEAK` | a marker is still in memory after its request; a value follows the values sent (`false → true → false`); a value follows the logged-in user (alice, bob, alice) and is still there for the anonymous request; the PHP process was changed (timezone, locale, environment); the answer to request 2 shows what only request 1 sent and a fresh worker does not | a value changes after every request without carrying a request value |
| `MONOTONIC_MEMORY_GROWTH` | an array grows on every request | |
| `ZOMBIE_REQUEST_RETENTION` | a Request is still alive after its request, held by the audited code | it is held outside the audited namespaces |
| `EXECUTION_TERMINATED` | a request ended the process (`exit`, `die`, a fatal error) | |

Each finding also has an **impact**, what it does to the next request:

| Impact | When |
|---|---|
| `exposure` | proven: the PHP process was changed, the answer to request 2 shows what only request 1 sent, or a plain request to the route (no parameter) answers differently on the used worker than on a fresh one |
| `retention` | the value stays in memory until the next request, which is not proven to read it (a parser that keeps its last input, a validator that keeps its last context) |
| `growth` | memory grows on every request |
| `crash` | the request ended the process |

A retention is worth reading: the next request may read the value through another route. GHSA-xmq6-3c6q-fr8m is one:
the parameter value kept in the operation metadata is read by the IRI resolution of another operation, not by a
plain request to the same route, so the probe reports it as a certain retention.

State that the first request builds and then keeps unchanged (compiled routes, metadata) is a warmup, as is a
cache filled for the second alternative that stops changing, and is not reported. A value belongs to the object
that directly holds it.

Triage tips:

- A **certain** leak in your namespace is a bug to fix: make the service stateless, or implement
  `Symfony\Contracts\Service\ResetInterface` (autoconfigured services are then reset between requests).
- A finding owned by a vendor class is reported only with `--include-vendors`. Check the package's latest version
  before reporting it: several framework leaks are already fixed upstream.
- A value that is **written again at the start of every request** before being read (the locale of the current
  request) is harmless; it shows as a suspect, or as certain when the anonymous request is redirected before the
  code that writes it runs.
- A **retention** (the last form, the last page of results kept until the next request) is not an exposure: check
  whether the next request reads the value before writing it again.
- `report.errors` lists the probes the scan itself could not run (a missing asset build, an exception in the
  error page), apart from the findings.

## Live dashboard

```bash
php bin/console igor:worker-scan --user=alice --user=bob --forms --dashboard
```

The command prints the address of a local page (served by PHP's built-in server on 127.0.0.1) that follows the scan
as it runs, and keeps it open until you press Enter:

- **The operating table**: the router's routes grouped by their first path segment, a magnifying glass on the route
  being probed, one dot per sending colored by its HTTP status. A route turns red on an exposure, a growth or a
  crash, orange on a retention.
- **Coverage**: how many routes were tested (every sending reached the controller and succeeded), partly tested or
  not tested, with filters. A click on a route opens its detail: the controller, how the requests were built, every
  sending with its status, and why the route is not tested (data missing, role missing, validation refused...).
- **The monitor**: one beat per request, the size of the state compared (nodes) and the memory.
- **The diagnosis**, at the end: every leak with its evidence, the file and line to fix from and the routes that
  showed it, sorted into your code, your dependencies (package and installed version), suspects and known harmless
  framework state. For a leak in a dependency, *Search for an upstream fix* compares the class's file between the
  installed version, the latest release and the development branch on GitHub, and lists the issues that name the
  class, those about state kept between requests first. The leaked value's class is often a plain data object (a
  violation, a token): *Search where it is kept* runs the same search on the class of the same package that keeps
  it, the first property on the path from the service (for the Validator of Symfony 7.4,
  `ConstraintValidator::$context`, made reentrant in 8.1). Only the package name, its version and the class name
  leave the machine.
- A speed control (0.5x, 1x, 4x, live), a replay button, and English or French.

Without `--dashboard`, `--events=<file>` writes the same events; serve them with
`IGOR_SCAN_EVENTS=<file> IGOR_PROJECT_DIR=<app> php -S 127.0.0.1:8765 runtime/resources/dashboard/server.php`.

## Unblock a route with AI

A route the scan could not test (a form refused by its validation, a CSRF token it did not find, an identifier that
does not exist, a role it lacks) can be handed to a model, from the dashboard (*Unblock with AI* in the route's
detail) or from the command line:

```bash
php bin/console igor:worker-scan --unblock=admin_post_new --user=alice --user=bob
```

The model gets the route, its controller's source, the entities it loads with a few real rows, the users and their
roles, and the answers the scan got. It answers with a request recipe (JSON: method, path, query, form or JSON body,
user, the page to read the CSRF token from); it never runs anything. The recipe is checked (a path of the
application, a method of the route, a listed user), replayed like any probe in a rolled-back transaction, and sent
back with the answers when the controller is still not reached, up to three recipes. A recipe that works is saved
in `var/igor-recipes/` and replayed by the next scans run with `--forms`.

The probe asks its question through Igor Doctor, so that it adds no AI library to the application it scans:
`IGOR_AI_COMMAND` names the doctor's `bin/ask`, which reads the question on standard input and answers through
[Symfony AI](https://symfony.com/doc/current/ai/index.html), with the same platform and model as the doctor's agent
(`DOCTOR_AI_MODEL` in the doctor's `.env.local`). The model has no tool, no MCP server and no settings: it can only
answer. Set the variable before the scan, and the dashboard's button uses it too:

```bash
export IGOR_AI_COMMAND=/path/to/doctor/bin/ask
```

## From igor:doctor

`igor:doctor --runtime` runs the probe next to Igor and gives its findings to the agent like Igor's
(`RuntimeStateLeak`, `RuntimeMemoryGrowth`, `RuntimeRequestRetention`, `RuntimeExecutionTerminated`):

```bash
bin/install-runtime /path/to/app
bin/console igor:doctor /path/to/app --runtime
```

A patch is kept only when Igor and the probe both stop reporting its finding. The doctor runs the anonymous GET
scan (`--json --tour`, `APP_ENV=prod`); to scan with users or forms, run `igor:worker-scan` in the application.

## How it works

1. **Probes.** Without `--probe`, they come from the application: one per GET route (a path variable gets two of
   the values its requirement lists, its default, `1` for an identifier, a locale such as `en_US`, or a marker),
   the query parameters API Platform declares, the query parameters, cookies and headers the code and the
   templates read (`->query->get('x')`, `$_COOKIE['x']`, `app.request.query.get('x')`), the real URLs found in
   the links of the HTML pages, and with `--forms` the forms of those pages.
2. **Sendings.** Each probe runs on a new kernel, in its own process, and is sent three times (four with two
   users), with a marker unique to each sending and `{a|b|a}` alternatives. The kernels run in the worker
   runtime mode (`APP_RUNTIME_MODE=web=1&worker=1`), as FrankenPHP's runner sets it.
3. **Between two sendings**, as in a worker: the kernel is terminated, `services_resetter` runs and the garbage is
   collected.
4. **Snapshot.** Every instantiated service (public, private, inlined), static properties, `static` variables of
   methods, closures (`use` variables and bound object) and the PHP process (timezone, locale, environment,
   `error_reporting`, working directory) are walked and compared between sendings.
5. **Oracle.** A fresh kernel answers the second sending again: a value only the first sending carried, found in
   a used worker's answer and not in the fresh one, is a leak even when nothing in memory points at it.
6. **Location.** The file and line a fix starts from: the declaration of the property, the first call that changes
   a process setting, or the controller of a request that ended the process.

## Limits

- Leaks that need an error in the middle of a request (a failed flush, an exception in a listener) or a change
  of data between requests (a stale cache) are out of reach.
- `$_GET` and `$_POST` reads are not followed: FrankenPHP fills them again for each request.
- JSON request bodies (API Platform write operations, `#[MapRequestPayload]`) are not generated yet; give them
  with `--probe` and `--header 'Content-Type: application/json'` if needed.
- Form values do not read the Validator's constraints yet, so some forms are rejected by the validation.

## Measured

| Application | Known leaks | Found before reading the list | Found now | Certain findings on the clean version |
|---|---|---|---|---|
| API Platform 5.0.7 reproduction ([GHSA-xmq6-3c6q-fr8m](https://github.com/api-platform/core/security/advisories/GHSA-xmq6-3c6q-fr8m)) | 1 | 1, with generated probes | 1 | 0 on 5.0.8 |
| [api-platform/demo](https://github.com/api-platform/demo) on API Platform 5.0.7, generated probes only | 1 | 1 (10 parameters on 4 routes, two of them answering 401) | 1 | 0 on 5.0.8 |
| [Leaky Blog](https://github.com/monitaurus/igor-doctor-demo) | 13 | 3 (5, 9, 10) | 5 (1, 5, 9, 10, 11) | 0 on symfony/demo v3.1.0 |
| `examples/demo-leak` | one per route, 19 routes | | 9 distinct (1 as a suspect) | |

Leaks 1 and 11 of the Leaky Blog were found after reading its list: the response oracle and the values chosen by
input name were written for these misses, as generic rules, but they are not a blind result.

On symfony/demo v3.1.0 with its locked dependencies (Symfony 8.1.0), the scan with two users and
`--include-vendors` finds an HttpKernel bug that Symfony fixed in 8.1.1: when the arguments of a controller
cannot be resolved, `kernel.response` and `kernel.finish_request` are not dispatched, and the firewall leaves
one more `kernel.exception` listener registered on every such request.

The probe has also been run on larger open-source applications with two users and forms (150 to 250 routes,
up to 50 forms submitted with the database left unchanged). Its findings there are being verified, and will be
listed once they are reported upstream.
