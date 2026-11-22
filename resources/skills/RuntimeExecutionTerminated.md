# RuntimeExecutionTerminated

Found by running the application: the request ended the PHP process. In a worker, `exit()` or `die()` stops the
worker; it restarts and loses everything it kept (connections, caches). The code line is the controller of the
route.

- Return a `Response` instead: a `StreamedResponse`, a `BinaryFileResponse`, or a `Response` with the content and
  the `Content-Disposition` header for a download.
- Never call `exit()` after sending output yourself (`echo`, `header()`, `readfile()`): build the response object.

## When behaviour changes are allowed

Removing `exit()` changes what the code does when something else runs after it; with this run's permission, replace
it with a returned `Response` that sends the same content and headers.
