# SWPRO Server Guardian

Server monitoring and Telegram reporting plan.

## Scope

The guardian is intended to run on the SWPRO Debian server with one systemd service and one timer. It must:

- report boot/reboot events;
- monitor external `swpro.ru` DNS, HTTP/HTTPS and response latency;
- monitor TLS certificate expiry;
- collect critical systemd/journal errors;
- monitor disk usage and inode usage;
- monitor CPU/load, RAM and swap;
- verify Restic backup freshness and last successful run;
- maintain a heartbeat and detect missed heartbeats;
- recover selected failed services and report recovery;
- deduplicate repeated Telegram alerts and apply cooldowns;
- expose read-only server diagnostics through restricted Telegram commands.

## Telegram commands

- `/status` — compact status of all checks.
- `/server` — hostname, public IP, OS, kernel, uptime.
- `/resources` — CPU/load, RAM, swap.
- `/disk` — filesystems, used/free space, inode usage.
- `/services` — nginx, PHP-FPM, MariaDB, SSH, Fail2ban and Telegram tunnel.
- `/website` — DNS, HTTP/HTTPS status and latency.
- `/ssl` — certificate issuer, subject and expiry/remaining days.
- `/backup` — last successful Restic backup and age.
- `/logs` — recent critical journal/system errors.
- `/recovery` — recent automatic recovery actions.
- `/health` — very short health summary.
- `/all` — full server report.

## Security requirements

- Commands and alerts are restricted to configured Telegram administrator user IDs.
- Never expose passwords, tokens, private keys, `.env` contents or full sensitive log lines.
- `/logs` must redact obvious secrets and truncate output.
- Monitoring must be read-only except for an explicit allowlist of recoverable systemd services.
- Recovery must use fixed service names; never execute arbitrary Telegram input as a shell command.
- Use timeouts for external checks and subprocesses.
- Persist state under `/var/lib/swpro-health/` so reboots do not create alert storms.

## Alert policy

States are `OK`, `WARN`, and `CRITICAL`. Send an alert when a state changes to WARN/CRITICAL and a recovery message when it returns to OK. Repeated identical failures are suppressed during a cooldown. Heartbeat is not sent as a frequent Telegram message; missing heartbeat is what creates an alert.

Suggested thresholds:

- disk: WARN 80%, CRITICAL 90%;
- inode: WARN 80%, CRITICAL 90%;
- RAM available: WARN <20%, CRITICAL <10%;
- swap: WARN >25%, CRITICAL >50%;
- load: compare against CPU count rather than a fixed number;
- SSL: WARN <=30 days, CRITICAL <=7 days;
- Restic: WARN when older than 26h, CRITICAL when older than 50h;
- website: WARN after one failed check, CRITICAL after repeated failures.

## Recovery allowlist

Only explicitly configured services may be restarted. Initial candidates:

- nginx
- php8.3-fpm
- mariadb
- swpro-telegram-tunnel

Fail2ban and the backup timer should be monitored but not automatically restarted unless explicitly enabled in configuration.

## Deployment

Do not store production secrets in Git. Configuration belongs in a root-readable environment file on the server, e.g. `/etc/swpro-health/swpro-health.env` with restrictive permissions.

The implementation should remain lightweight for a 4 GB VPS and avoid spawning a large number of persistent processes.
