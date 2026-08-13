# Site verification adapters

`.ci/site-verify` is the provider-neutral CI boundary. Run it locally or from
any hosted runner after the exact Composer dependencies have been installed.

The included GitHub Actions workflow is an adapter only. Forgejo, Gitea,
GitLab, Jenkins, Buildkite, or a local runner conform by installing the locked
dependencies and invoking this same command. No forge API, credential, event
shape, or repository URL is part of the site contract.
