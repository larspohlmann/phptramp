# Security Policy

## Supported versions

Only the latest release receives security fixes.

## Reporting a vulnerability

Please **do not open a public issue** for security problems. Instead, use
GitHub's private vulnerability reporting:
[Report a vulnerability](https://github.com/larspohlmann/phptramp/security/advisories/new)
(also reachable via the repository's **Security** tab).

You can expect an initial response within 7 days. Once a fix is released,
the advisory is published and the reporter credited (unless you prefer
otherwise).

phptramp is a static analyzer: it parses PHP source as *data* and never
executes analyzed code. Reports about phptramp being made to crash or
mis-report on crafted input are ordinary bugs — file those as regular
[bug reports](https://github.com/larspohlmann/phptramp/issues/new?template=bug_report.yml).
A vulnerability here means something like path traversal, cache poisoning
that alters findings, or code execution triggered by analyzed input.
