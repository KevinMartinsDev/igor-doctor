- Return a `Symfony\Component\HttpFoundation\Response` with the same content and status, or throw an
  `Symfony\Component\HttpKernel\Exception\HttpException` for an error path.
- Output printed before `exit` (echo, print) belongs in the response body.
- An `exit` whose purpose is to stop the process (a demonstration of the danger) is a feature: unless this
  run allows behaviour changes, report it as behaviour-changing.

## When behaviour changes are allowed

- Replace `exit`/`die` with `return new Response(...)` carrying what the code printed (or an empty body), so the
  worker keeps running. Keep every line before it. Add the `use Symfony\Component\HttpFoundation\Response;` line
  when it is missing.
