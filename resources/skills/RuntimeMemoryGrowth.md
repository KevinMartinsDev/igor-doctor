# RuntimeMemoryGrowth

Found by running the application: an array of a shared service grows by one entry on every request, and nothing
empties it between requests. In a worker it grows until the worker restarts.

- If the entries belong to one request (breadcrumbs, a log of what the request did, listeners added per request),
  implement `Symfony\Contracts\Service\ResetInterface` and empty the array in `reset()` (`$this->items = [];`).
- If it is a cache, bound it (a fixed size, keys that cannot come from user input) or move it to a cache pool.
- A static array: turn it into an instance property of the service and reset it.
- The patch is verified by running the application again: the array must stop growing.
