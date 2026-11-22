# RuntimeRequestRetention

Found by running the application: a `Request` object was still alive after its response was sent, held by a
property or by a closure of a shared service. It keeps the request's data (headers, cookies, session) in memory
for the next requests.

- Do not store the `Request`: inject `Symfony\Component\HttpFoundation\RequestStack` and call `getCurrentRequest()`
  where the request is needed, or pass the values the code needs.
- A closure that captures `$request` and is kept by a service: capture only the values it needs, or reset the
  property that keeps the closure (`ResetInterface`).
