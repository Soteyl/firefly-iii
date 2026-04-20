## Deployment Layout

This repository contains the Firefly runtime deployment assets under `deploy/`:

- `deploy/local/docker-compose.yml`: active local/self-hosted stack definition
- `deploy/telegram-assistant-bot/`: Telegram assistant bot source code
- `deploy/local/.env*`: local runtime env files (intentionally untracked)
- `deploy/local/enable-banking-keys/`: local private keys (intentionally untracked)

### Why the old `...-staging` name existed

Historically, the stack lived in a separate folder named `MoneyTrackerFirefly-staging`.
It was used as a deployment workspace, not a separate code branch.
This has now been consolidated so code and deployment definitions live together in one repository/workspace.
