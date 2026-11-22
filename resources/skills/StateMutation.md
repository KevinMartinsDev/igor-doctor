- Prefer the first fix of Igor's list that keeps the behaviour: a value that can be local or passed as an argument
  should not be stored at all.
- Per-request state kept on purpose: implement `Symfony\Contracts\Service\ResetInterface` and add the `use` line.
  Autoconfiguration adds the `kernel.reset` tag: never edit YAML (config/packages is out of the write policy anyway).
- In `reset()`, assign every mutated property directly to its initial value (`$this->cache = [];`,
  `$this->count = 0;`). Igor does not follow a call from `reset()` to another method such as `clear()`, so a
  `reset()` that only delegates is reported as incomplete (IncompleteReset) and the patch is rolled back.
- A static property: turn it into an instance property and reset it, or Igor keeps reporting the static write.
- A class that already implements `ResetInterface`: add the property to the existing `reset()`, do not add a second one.
