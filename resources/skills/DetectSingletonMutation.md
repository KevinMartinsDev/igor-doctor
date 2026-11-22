- Restore the previous state in `finally` around the code that needs the change, or use an API that takes the value
  per call. Both keep what the code does inside the request.
- The finding may disappear when the mutated dependency becomes resettable: a fix in that dependency's own class
  (`ResetInterface`) clears this finding too, so it is often fixed by another patch.
