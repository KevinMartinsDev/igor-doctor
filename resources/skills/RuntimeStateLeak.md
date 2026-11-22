# RuntimeStateLeak

Found by running the application, not by reading it: after a request ended and `services_resetter` ran, a value
from that request was still in memory, or a value followed what each request sent. The code line is the place the
state lives (a property declaration, or the call that changes the PHP process); the route that showed it and the
evidence are in the finding.

- A property of a shared service that holds per-request data: prefer not storing it (a local variable, an
  argument, the `Request` or the session). If it must be kept for the request, implement
  `Symfony\Contracts\Service\ResetInterface` and assign the property its initial value in `reset()`;
  autoconfiguration adds the `kernel.reset` tag, never edit YAML.
- A setting of the PHP process (`date_default_timezone_set()`, `setlocale()`, `putenv()`, `$_ENV`): do not change
  it per request. Pass the value where it is used instead (a `DateTimeZone` to the formatter, a locale to the
  translator), or restore the previous value in a `kernel.response`/`kernel.terminate` listener.
- A value read from a cached object and written back with a setter: work on a copy (`clone`, or a `with*()` method
  that returns one) instead of changing the cached object.
- The patch is verified by running the application again: the finding must disappear from the runtime scan.
