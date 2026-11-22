- Prefer passing the value per call (`new \DateTimeZone(...)`, an explicit encoding, an absolute path): it removes
  the call that Igor reports.
- Igor 0.11 reports the call itself, even when the previous value is restored in `finally`: a patch that keeps the
  call is rolled back. The fix has to remove the call (pass the value per call, or set it once in configuration).
- A method whose only purpose is to change the process setting (a demonstration, a reset action) is a feature:
  unless this run allows behaviour changes, report it as behaviour-changing.

## When behaviour changes are allowed

- A demonstration that changes the process setting: remove the call and give its value to the code that needs it
  instead (`new \DateTimeZone('America/New_York')` for the dates it formats, an absolute path instead of `chdir()`,
  the encoding argument of the `mb_*` call). When nothing uses the setting, remove the call and keep the method
  returning what it returned. A `try`/`finally` restore is not enough: Igor still reports the call.
- A reset action that restores a process setting: remove the call and let the response say the setting no longer
  needs a reset, since the poisoning side no longer leaks.
