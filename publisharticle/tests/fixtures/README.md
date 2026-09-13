# Fixture directory for tests

This directory is used by the test bootstrap as the fake FlatPress
content root (CONTENT_DIR) when running tests in isolation.

Contents:
- `content/`      — simulated `fp-content/content/`
- `content/images/` — simulated `fp-content/content/images/`
- `tmp/`          — temporary files created/cleaned by tests (auto-created at runtime)