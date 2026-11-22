- In a controller action, add or reuse a `Symfony\Component\HttpFoundation\Request $request` argument and read
  `$request->query`, `$request->request`, `$request->server`, `$request->cookies`, `$request->files` or the session
  (`$request->getSession()`). Keep the same default value and the same null handling.
- In a service, inject `Symfony\Component\HttpFoundation\RequestStack` and use `getCurrentRequest()`.
- Fix only the line of the finding: other superglobal lines have their own findings.
- Reading `$_ENV` is allowed. Writing to `$_ENV` changes process-wide state: there is no request object to move it to.

## When behaviour changes are allowed

- A write to `$_ENV` (or another superglobal) that only feeds the current request: keep the value in a local variable
  or a request attribute (`$request->attributes->set()`), and read it from there.
